<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\CollectionBatch;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\StudentBillPayment;
use App\Models\StudentTuitionPayment;
use App\Models\User;
use App\Services\FinanceWorkflows;
use App\Services\InstallationInstitution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use OverflowException;

final readonly class CaptureCollectionBatch
{
    /**
     * Movements already settled into this installation's treasury.
     *
     * The boundary is the destination, not a student record: an installation is
     * single-organization by construction — `InstallationInstitution::current()`
     * returns null unless exactly one organization exists — so "whose money is
     * this" is answered by where it was sent. A payment addressed to this
     * installation's treasury arrived here; one addressed elsewhere did not.
     *
     * A cashier holding the Arc explorer hash will type it into the
     * receipt/reference field. That is the realistic way one settled movement
     * gets counted twice — once as a digital receipt and once as a manual bank
     * credit — so the hash is refused as a reference here and the cashier is
     * pointed at the reconciliation instead.
     *
     * @return Builder<StudentTuitionPayment>
     */
    private function settledMovements(string $treasuryAddress): Builder
    {
        return StudentTuitionPayment::query()
            ->where('simulated', false)
            ->where('tx_verified_on_chain', true)
            ->where('recipient_address', $treasuryAddress);
    }

    public function __construct(private InstallationInstitution $institutions, private FinanceWorkflows $workflows) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, array $data, ?UploadedFile $sourceDocument = null): CollectionBatch
    {
        Gate::forUser($actor)->authorize('create', CollectionBatch::class);
        $this->assertNotADigitalMovement($data);
        $contents = null;
        if ($sourceDocument instanceof UploadedFile) {
            Validator::make(['source_document' => $sourceDocument], ['source_document' => self::documentRules()])->validate();
            $contents = $sourceDocument instanceof TemporaryUploadedFile ? $sourceDocument->get() : $sourceDocument->getContent();
            if (! is_string($contents) || $contents === '' || strlen($contents) > 10 * 1024 * 1024) {
                throw ValidationException::withMessages(['source_document' => 'Upload a nonempty receipt or report, up to 10 MB.']);
            }
            $data['source_document_digest'] = hash('sha256', $contents);
        }
        $data = Validator::make($data, self::inputRules())->validate();
        $currency = CurrencyCode::from($data['currency']);
        try {
            $received = Money::fromDecimal($data['received_amount'], $currency)->minorUnits;
            $restricted = Money::fromDecimal($data['restricted_amount'], $currency)->minorUnits;
        } catch (InvalidArgumentException|OverflowException) {
            throw ValidationException::withMessages(['received_amount' => 'Exact bounded amounts within currency precision required.']);
        }
        if ($received <= 0 || $restricted < 0 || $restricted > $received) {
            throw ValidationException::withMessages(['restricted_amount' => 'Received funds must be positive; restrictions must be within received funds.']);
        }
        $institution = $this->institutions->require();
        $from = Carbon::parse($data['collected_from'])->utc();
        $until = Carbon::parse($data['collected_until'])->utc();
        $snapshot = ['schema_version' => 1, 'purpose' => 'aggregate_realized_fee_receipts', 'capture_key' => Str::lower($data['capture_key']),
            'institution_id' => $institution->id, 'prepared_by' => $actor->id, 'source_stream' => $data['source_stream'],
            'source_reference' => Str::lower(trim($data['source_reference'])), 'source_document_digest' => $data['source_document_digest'],
            'currency' => $currency->value, 'received_minor_units' => (string) $received, 'restricted_minor_units' => (string) $restricted,
            'collected_from' => $from->toIso8601String(), 'collected_until' => $until->toIso8601String(),
            'cash_evidence_reference' => $data['cash_evidence_reference'], 'source_stream_disjoint_attested' => true, 'received_not_forecast_attested' => true];
        if ($sourceDocument instanceof UploadedFile) {
            $name = basename(str_replace('\\', '/', $sourceDocument->getClientOriginalName()));
            $name = mb_substr(preg_replace('/[\x00-\x1f\x7f]/', '', $name), 0, 255);
            $snapshot['source_document'] = ['disk' => 'local',
                'path' => 'collection-evidence/'.$institution->id.'/'.$snapshot['capture_key'].'/'.$snapshot['source_document_digest'],
                'name' => trim($name) === '' ? 'receipt' : $name, 'mime_type' => $sourceDocument->getMimeType(), 'size_bytes' => strlen($contents)];
        }

        return DB::transaction(function () use ($actor, $institution, $snapshot, $from, $until, $received, $restricted, $contents): CollectionBatch {
            Organization::query()->whereKey($institution->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('create', CollectionBatch::class);
            /** @var CollectionBatch|null $existing */
            $existing = CollectionBatch::query()->where('capture_key', $snapshot['capture_key'])->first();
            if ($existing !== null) {
                if (! $existing->hasValidSnapshot() || ! hash_equals($existing->snapshot_digest, PaymentIntent::digest($snapshot))) {
                    throw ValidationException::withMessages(['capture_key' => 'Collection capture identity already binds different source or amounts.']);
                }

                return $existing;
            }
            $duplicate = CollectionBatch::query()->where('organization_id', $institution->id)
                ->where(function (Builder $query) use ($snapshot, $from, $until): void {
                    $query->where('source_document_digest', $snapshot['source_document_digest'])
                        ->orWhere(function (Builder $stream) use ($snapshot, $from, $until): void {
                            $stream->where('source_stream', $snapshot['source_stream'])
                                ->where(function (Builder $interval) use ($snapshot, $from, $until): void {
                                    $interval->where('source_reference', $snapshot['source_reference'])
                                        ->orWhere(function (Builder $overlap) use ($from, $until): void {
                                            $overlap->where('collected_from', '<', $until)->where('collected_until', '>', $from);
                                        });
                                });
                        });
                })->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['source_reference' => 'This document, receipt/report number, or payment-channel period is already recorded. Open the existing collection instead; do not change its channel to bypass this check.']);
            }
            if ($contents !== null) {
                $disk = Storage::disk('local');
                $path = $snapshot['source_document']['path'];
                if ($disk->exists($path)) {
                    if (! hash_equals($snapshot['source_document_digest'], hash('sha256', $disk->get($path)))) {
                        throw ValidationException::withMessages(['source_document' => 'Stored receipt differs from this upload. Ask finance staff to investigate.']);
                    }
                } elseif (! $disk->put($path, $contents, ['visibility' => 'private'])) {
                    throw ValidationException::withMessages(['source_document' => 'Receipt could not be stored. Retry without changing the report details.']);
                }
            }
            /** @var CollectionBatch $batch */
            $batch = CollectionBatch::query()->create(['capture_key' => $snapshot['capture_key'], 'organization_id' => $institution->id,
                'prepared_by' => $actor->id, 'source_stream' => $snapshot['source_stream'], 'source_reference' => $snapshot['source_reference'],
                'source_document_digest' => $snapshot['source_document_digest'], 'currency' => $snapshot['currency'],
                'received_minor_units' => $received, 'restricted_minor_units' => $restricted, 'collected_from' => $from, 'collected_until' => $until,
                'snapshot' => $snapshot, 'snapshot_digest' => PaymentIntent::digest($snapshot)]);
            $this->workflows->collectionCaptured($batch);
            activity('finance')->causedBy($actor)->performedOn($batch)->event('collection_batch_captured')
                ->withProperties(['snapshot_digest' => $batch->snapshot_digest, 'can_execute' => false])
                ->log('Aggregate realized fee evidence captured; no cash posting, student allocation or transfer');

            return $batch;
        }, 3);
    }

    /**
     * Refuse a hand-typed receipt for money that already arrived by chain.
     *
     * Two shapes are refused: the channel reserved for reconciled movements,
     * and any receipt/reference number that is a settled movement's hash. Both
     * are attempts to record the same cash a second time — once as a digital
     * receipt and once as a manual bank credit — which is the one double-count
     * a duplicate check on document hashes alone cannot see.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertNotADigitalMovement(array $data): void
    {
        if (in_array($data['source_stream'] ?? null, [CollectionBatch::TUITION_PORTAL_STREAM, CollectionBatch::BILL_PORTAL_STREAM], true)) {
            throw ValidationException::withMessages(['source_stream' => 'This channel records payments already settled on chain. Reconcile them from Student payments so the same money is not counted twice.']);
        }

        $reference = strtolower(trim((string) ($data['source_reference'] ?? '')));

        if ($reference === '' || preg_match('/^0x[0-9a-f]{64}$/D', $reference) !== 1) {
            return;
        }

        $treasury = (string) config('lepton.arc.treasury');

        if ($this->settledMovements($treasury)->where('tx_hash', $reference)->exists()
            || StudentBillPayment::query()->where('simulated', false)->where('tx_verified_on_chain', true)
                ->where('recipient_address', $treasury)->where('tx_hash', $reference)->exists()) {
            throw ValidationException::withMessages(['source_reference' => 'This reference is a settled student payment, not a new cash arrival. Reconcile it from Student payments so the same money is not counted twice.']);
        }
    }

    /** @return array<string, mixed> */
    public static function inputRules(): array
    {
        return ['capture_key' => ['required', 'string', 'uuid'], 'source_stream' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9._-]{0,63}$/D'],
            'source_reference' => ['required', 'string', 'min:3', 'max:120'], 'source_document_digest' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/D'],
            'currency' => ['required', Rule::in(CurrencyCode::values())], 'received_amount' => ['required', 'string', 'max:30'],
            'restricted_amount' => ['required', 'string', 'max:30'], 'collected_from' => ['required', 'date_format:Y-m-d\TH:i:sP', 'before:collected_until'],
            'collected_until' => ['required', 'date_format:Y-m-d\TH:i:sP', 'before_or_equal:now'],
            'cash_evidence_reference' => ['required', 'string', 'min:3', 'max:255'], 'source_stream_disjoint' => ['required', 'accepted'],
            'received_not_forecast' => ['required', 'accepted']];
    }

    /** @return list<string> */
    public static function documentRules(): array
    {
        return ['required', 'file', 'max:10240', 'extensions:pdf,jpg,jpeg,png,csv', 'mimetypes:application/pdf,image/jpeg,image/png,text/csv,text/plain,application/csv'];
    }
}
