<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Money;
use App\Models\Budget;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\PaymentIntentChange;
use App\Models\PaymentReservation;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Wallet;
use App\Services\InstallationInstitution;
use App\Services\PaymentIntentLifecycle;
use App\Services\VendorPaymentSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class ProposePaymentIntentChange
{
    public function __construct(
        private InstallationInstitution $institutions,
        private PaymentIntentLifecycle $lifecycle,
        private VendorPaymentSnapshot $snapshots,
    ) {}

    public function handle(User $actor, PaymentIntent $source, string $requestKey, string $expectedDigest, string $kind, string $reason,
        ?string $replacementKey = null, ?Wallet $wallet = null, ?Money $maxFee = null): PaymentIntentChange
    {
        Gate::forUser($actor)->authorize('proposeChange', $source);
        if (! $source->exists || ! Str::isUuid($requestKey) || ! in_array($kind, ['cancel', 'replace'], true)
            || trim($reason) === '' || mb_strlen($reason) > 1000
            || ($kind === 'cancel' && ($replacementKey !== null || $wallet instanceof Wallet || $maxFee instanceof Money))
            || ($kind === 'replace' && ($replacementKey === null || ! Str::isUuid($replacementKey) || ! $wallet instanceof Wallet || ! $wallet->exists || ! $maxFee instanceof Money))) {
            throw ValidationException::withMessages(['change' => 'A UUID, explicit reason and complete cancel or replacement proposal are required.']);
        }
        $requestKey = strtolower($requestKey);
        $replacementKey = $replacementKey === null ? null : strtolower($replacementKey);
        $institution = $this->institutions->require();

        return DB::transaction(function () use ($actor, $source, $requestKey, $expectedDigest, $kind, $reason, $replacementKey, $wallet, $maxFee, $institution): PaymentIntentChange {
            Organization::query()->whereKey($institution->id)->lockForUpdate()->firstOrFail();
            /** @var PaymentIntent $stored */
            $stored = PaymentIntent::query()->where('organization_id', $institution->id)->whereKey($source->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('proposeChange', $stored);
            $this->lifecycle->resolve($institution->id, $stored->invoice_id);
            if (! hash_equals($stored->snapshot_digest, $expectedDigest)) {
                throw ValidationException::withMessages(['expected_digest' => 'The exact stored draft digest is required.']);
            }
            /** @var PaymentIntentChange|null $existing */
            $existing = PaymentIntentChange::query()->where('request_key', $requestKey)->first();
            if ($existing !== null) {
                if (! $existing->hasValidEvidence($stored) || $existing->proposed_by !== $actor->id || $existing->kind !== $kind
                    || $existing->reason !== $reason || $existing->replacement_intent_key !== $replacementKey
                    || ($kind === 'replace' && (($existing->replacement_snapshot['treasury']['wallet_id'] ?? null) !== $wallet->id
                        || ($existing->replacement_snapshot['max_fee_base_units'] ?? null) !== (string) $maxFee->minorUnits))) {
                    throw ValidationException::withMessages(['request_key' => 'Change identity already has different bound evidence or feedback.']);
                }

                return $existing;
            }
            $this->lifecycle->requireActive($stored);
            $liveHold = PaymentReservation::query()
                ->where('invoice_id', $stored->invoice_id)
                ->get()
                ->reject(fn (PaymentReservation $hold): bool => $hold->isReleased())
                ->isNotEmpty();

            if ($liveHold) {
                throw ValidationException::withMessages(['payment' => 'Bill has held capacity; reviewed reservation release is required before draft recovery.']);
            }
            $snapshot = null;
            if ($kind === 'replace') {
                if ($replacementKey === $stored->intent_key || PaymentIntent::query()->where('intent_key', $replacementKey)->exists()
                    || PaymentIntentChange::query()->where('replacement_intent_key', $replacementKey)->exists()) {
                    throw ValidationException::withMessages(['replacement_intent_key' => 'Replacement requires a fresh unused intent and provider identity.']);
                }
                /** @var Invoice $invoice */
                $invoice = Invoice::query()->where('organization_id', $institution->id)->whereKey($stored->invoice_id)->lockForUpdate()->firstOrFail();
                /** @var Wallet $lockedWallet */
                $lockedWallet = Wallet::query()->where('organization_id', $institution->id)->whereKey($wallet->id)->lockForUpdate()->firstOrFail();
                $invoice->setRelation('vendor', Vendor::query()->whereKey($invoice->vendor_id)->lockForUpdate()->firstOrFail());
                if ($invoice->budget_id !== null) {
                    $invoice->setRelation('budget', Budget::query()->whereKey($invoice->budget_id)->lockForUpdate()->firstOrFail());
                }
                $snapshot = $this->snapshots->build($institution, $invoice, $lockedWallet, $maxFee);
                $snapshot['intent_key'] = $replacementKey;
                $snapshot['prepared_by'] = $actor->id;
                $snapshot['recovery'] = ['revision' => $stored->revision + 1, 'predecessor_id' => $stored->id,
                    'source_digest' => $stored->snapshot_digest, 'request_key' => $requestKey];
            }
            $change = new PaymentIntentChange(['request_key' => $requestKey, 'organization_id' => $institution->id,
                'invoice_id' => $stored->invoice_id, 'payment_intent_id' => $stored->id, 'proposed_by' => $actor->id,
                'kind' => $kind, 'reason' => $reason, 'source_digest' => $stored->snapshot_digest,
                'replacement_intent_key' => $replacementKey, 'replacement_snapshot' => $snapshot]);
            $change->content_digest = PaymentIntent::digest($change->content());
            $change->save();
            activity('finance')->causedBy($actor)->performedOn($change)->event('payment_intent_change_proposed')
                ->withProperties(['payment_intent_id' => $stored->id, 'kind' => $kind, 'content_digest' => $change->content_digest,
                    'payment_approved' => false, 'payments_submitted' => 0])
                ->log('Immutable draft recovery proposed; no payment authority');

            return $change;
        }, 3);
    }
}
