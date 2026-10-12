<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\FundingWindow;
use App\Models\FundingWindowApproval;
use App\Models\Organization;
use App\Models\PaymentAuthorization;
use App\Models\PaymentIntent;
use App\Models\PaymentReservation;
use App\Models\PaymentReviewerEnrollment;
use App\Models\User;
use App\Services\InstallationInstitution;
use App\Services\PaymentReviewMfa;
use App\Services\ReservedPaymentContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use SensitiveParameter;

final readonly class ReviewVendorPayment
{
    public function __construct(private InstallationInstitution $institutions, private ReservedPaymentContext $reservations,
        private PaymentReviewMfa $mfa, private EnqueuePaymentSubmission $submissions) {}

    /** @return array<string, mixed> */
    public static function inputRules(): array
    {
        return ['request_key' => ['required', 'uuid'], 'payment_reservation_id' => ['required', 'integer', 'min:1'],
            'intent_digest' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/D'],
            'reservation_digest' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/D'],
            'decision' => ['required', Rule::in(['approve_payment', 'reject_payment', 'hold_payment'])],
            'reason' => ['required', 'string', 'max:1000'], 'valid_until' => ['nullable', 'date_format:Y-m-d\TH:i:sP', 'regex:/\+00:00$/D'],
            'password' => ['required', 'string', 'max:1024'], 'mfa_code' => ['required', 'string', 'regex:/^[0-9]{6}$/D'],
            'mfa_method' => ['required', Rule::in(['filament_app', 'fortify_totp'])],
            'reviewed_by' => ['prohibited'], 'organization_id' => ['prohibited'], 'snapshot' => ['prohibited'],
            'amount_base_units' => ['prohibited'], 'max_fee_base_units' => ['prohibited'], 'recipient_address' => ['prohibited'],
            'source_address' => ['prohibited'], 'chain' => ['prohibited'], 'can_execute' => ['prohibited'], 'authority_scope' => ['prohibited']];
    }

    /** @param array<string, mixed> $input */
    public function handle(User $reviewer, PaymentIntent $intent, #[SensitiveParameter] array $input): PaymentAuthorization
    {
        Gate::forUser($reviewer)->authorize('authorizePayment', $intent);
        $data = Validator::make($input, self::inputRules())->validate();
        $data['request_key'] = Str::lower($data['request_key']);
        $data['reason'] = trim($data['reason']);
        if ($data['reason'] === '') {
            throw ValidationException::withMessages(['reason' => 'Payment review needs a nonempty reason.']);
        }
        $validUntil = $data['valid_until'] ?? null;
        if (($data['decision'] === 'approve_payment') !== ($validUntil !== null)) {
            throw ValidationException::withMessages(['valid_until' => 'Approval requires a bounded UTC expiry; rejection or hold cannot carry payment validity.']);
        }
        $institution = $this->institutions->require();
        // Failed review transactions must not roll back their authentication attempt counters.
        $this->mfa->hitAttempt($reviewer);

        return DB::transaction(function () use ($reviewer, $intent, $institution, $data, $validUntil): PaymentAuthorization {
            Organization::query()->whereKey($institution->id)->lockForUpdate()->firstOrFail();
            /** @var User $actor */
            $actor = User::query()->whereKey($reviewer->id)->lockForUpdate()->firstOrFail();
            /** @var PaymentIntent $stored */
            $stored = PaymentIntent::query()->where('organization_id', $institution->id)->whereKey($intent->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('authorizePayment', $stored);
            abort_unless($actor->hasVerifiedEmail() && $actor->hasAnyRole(['finance_officer', 'admin', 'super_admin'])
                && $actor->checkPermissionTo('AuthorizePayment:PaymentIntent', 'web')
                && $actor->hasDirectPermission('AuthorizePayment:PaymentIntent'), 403);
            /** @var PaymentReservation $hold */
            $hold = PaymentReservation::query()->where('organization_id', $institution->id)
                ->where('payment_intent_id', $stored->id)->whereKey($data['payment_reservation_id'])->lockForUpdate()->firstOrFail();
            if ($actor->isImpersonated() || $actor->id === $stored->prepared_by || $actor->id === $hold->reserved_by
                || ! hash_equals($stored->snapshot_digest, $data['intent_digest'])
                || ! hash_equals($hold->snapshot_digest, $data['reservation_digest'])) {
                throw ValidationException::withMessages(['payment' => 'Independent real reviewer and exact displayed draft/reservation digests are required.']);
            }
            /** @var PaymentAuthorization|null $existing */
            $existing = PaymentAuthorization::query()->where('invoice_id', $stored->invoice_id)
                ->orWhere('request_key', $data['request_key'])->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->organization_id !== $institution->id || $existing->payment_intent_id !== $stored->id
                    || $existing->payment_reservation_id !== $hold->id || $existing->request_key !== $data['request_key']
                    || $existing->reviewed_by !== $actor->id || $existing->decision !== $data['decision']
                    || ($existing->snapshot['reason'] ?? null) !== $data['reason'] || ($existing->snapshot['valid_until'] ?? null) !== $validUntil
                    || $existing->mfa_method !== $data['mfa_method'] || ! $existing->hasValidEvidence($stored, $hold)) {
                    throw ValidationException::withMessages(['request_key' => 'Payment already reviewed or review identity conflicts; no new authority granted.']);
                }

                return $existing;
            }
            if ($stored->chain !== 'ARC-TESTNET' || $stored->chain_id !== 5042002
                || config('lepton.arc.chain') !== 'ARC-TESTNET' || config('lepton.arc.chain_id') !== 5042002) {
                throw ValidationException::withMessages(['payment' => 'This authorization release is limited to explicitly configured Arc testnet; mainnet remains blocked.']);
            }
            $context = null;
            if ($data['decision'] === 'approve_payment') {
                $expiry = Carbon::parse($validUntil);
                if ($expiry->lte(now()) || $expiry->gt(now()->addMinutes(5))) {
                    throw ValidationException::withMessages(['valid_until' => 'Approval expires within five minutes; no backdated or open-ended authority.']);
                }
                $context = $this->reservations->requireCurrent($actor, $stored, $hold);
                if ($expiry->gt(Carbon::parse($context['window']->snapshot['valid_until']))) {
                    throw ValidationException::withMessages(['valid_until' => 'Approval cannot outlive its reviewed funding window.']);
                }
            }
            /** @var FundingWindowApproval $fundingApproval */
            $fundingApproval = FundingWindowApproval::query()->where('organization_id', $institution->id)
                ->whereKey($hold->funding_window_approval_id)->firstOrFail();
            /** @var FundingWindow $window */
            $window = FundingWindow::query()->where('organization_id', $institution->id)->whereKey($fundingApproval->funding_window_id)->firstOrFail();
            if (! $fundingApproval->hasValidEvidence($window) || ! $hold->hasValidEvidence($stored, $fundingApproval)) {
                throw ValidationException::withMessages(['payment' => 'Payment review requires intact funding and reservation evidence.']);
            }
            /** @var PaymentReviewerEnrollment|null $enrollment */
            $enrollment = PaymentReviewerEnrollment::query()->where('organization_id', $institution->id)->where('reviewer_id', $actor->id)->first();
            if ($enrollment === null || $enrollment->mfa_method !== $data['mfa_method'] || ! $enrollment->matchesCurrentFactor($actor)) {
                throw ValidationException::withMessages(['mfa_method' => 'Current payment-review authenticator needs independent enrollment; changed or disabled factors cannot approve payments.']);
            }
            $mfa = $this->mfa->verify($actor, $data['password'], $data['mfa_code'], $data['mfa_method']);
            $isFake = $hold->snapshot['balance_observation']['is_fake'] ?? null;
            $snapshot = ['schema_version' => 1, 'purpose' => 'reserved_vendor_payment_review', 'request_key' => $data['request_key'],
                'institution_id' => $institution->id, 'payment_intent_id' => $stored->id, 'payment_reservation_id' => $hold->id,
                'invoice_id' => $stored->invoice_id, 'reviewed_by' => $actor->id, 'decision' => $data['decision'], 'reason' => $data['reason'],
                'intent_digest' => $stored->snapshot_digest, 'reservation_digest' => $hold->snapshot_digest,
                'funding_approval_digest' => $fundingApproval->approval_digest, 'funding_window_digest' => $window->snapshot_digest,
                'chain' => $stored->chain, 'chain_id' => $stored->chain_id, 'currency' => 'USDC',
                'source_address' => $stored->source_address, 'recipient_address' => $stored->recipient_address,
                'amount_base_units' => (string) $stored->amount_base_units, 'max_fee_base_units' => (string) $stored->max_fee_base_units,
                'valid_until' => $validUntil, 'authority_scope' => $isFake === true ? 'simulation_only' : 'network_payment',
                'is_fake' => $isFake, 'mfa' => $mfa, 'reviewer_enrollment_id' => $enrollment->id,
                'reviewer_enrollment_digest' => $enrollment->snapshot_digest, 'balance_observation' => $context['balance_observation'] ?? null];
            /** @var PaymentAuthorization $authorization */
            $authorization = PaymentAuthorization::query()->create(['request_key' => $data['request_key'], 'organization_id' => $institution->id,
                'payment_intent_id' => $stored->id, 'payment_reservation_id' => $hold->id, 'invoice_id' => $stored->invoice_id,
                'reviewed_by' => $actor->id, 'decision' => $data['decision'], 'mfa_method' => $mfa['method'],
                'factor_fingerprint' => $mfa['factor_fingerprint'], 'mfa_timestep' => $mfa['timestep'],
                'snapshot' => $snapshot, 'snapshot_digest' => PaymentIntent::digest($snapshot)]);
            activity('finance')->causedBy($actor)->performedOn($authorization)->event('reserved_vendor_payment_reviewed')
                ->withProperties(['payment_intent_id' => $stored->id, 'payment_reservation_id' => $hold->id,
                    'decision' => $data['decision'], 'snapshot_digest' => $authorization->snapshot_digest,
                    'can_execute' => false, 'payments_submitted' => 0])
                ->log('Independent MFA-backed payment review recorded; no transfer or reservation release');

            if ($data['decision'] === 'approve_payment' && ($authorization->evidence()['payment_approved'] ?? false) === true) {
                /**
                 * Written inside this same transaction on purpose. An approved
                 * payment must never exist without a durable obligation to
                 * submit it, so the commit-to-broker gap is closed by
                 * construction rather than by a retry that might not come.
                 *
                 * A simulated approval is deliberately not queued: fake-driver
                 * evidence is rehearsal, and rehearsal must never become work
                 * a rail could act on.
                 */
                $this->submissions->handle($authorization, self::submissionKey($authorization));
            }

            return $authorization;
        }, 3);
    }

    /**
     * A deterministic submission identity derived from the authorization.
     *
     * Replaying the same review recomputes the same UUID, so a retry cannot
     * mint a second owed payment for one authorization.
     */
    public static function submissionKey(PaymentAuthorization $authorization): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'eduflow:payment-submission:'.$authorization->id.':'.$authorization->snapshot_digest)->toString();
    }
}
