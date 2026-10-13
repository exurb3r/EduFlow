<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Ai\Advisory\AdvisoryEnvelope;
use App\Ai\Advisory\AdvisoryGate;
use App\Models\Student;
use App\Models\StudentBill;
use App\Models\StudentBillPayment;
use App\Services\StudentBillPaymentQuote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A student's own non-tuition bills, and the pilot payment lane over them.
 *
 * Money ARRIVES here. Nothing on this controller can move money out of the
 * institution, and the classification it may display is triage metadata: the
 * deterministic policy still decides, and its verdict cannot be widened by
 * anything the student or the model sends.
 *
 * The classification is fetched on demand through {@see AdvisoryGate::classifyBill()}
 * and handed back to the quote endpoint rather than being computed during it.
 * That is deliberate. A model on a payment's critical path turns a provider
 * outage into a failed payment, so classification is something the screen asks
 * for and the quote merely accepts — and a quote with no classification is still
 * perfectly valid, which is exactly what the conservative policy branch expects.
 */
class StudentBillController extends Controller
{
    public function __construct(
        private readonly StudentBillPaymentQuote $payments,
        private readonly AdvisoryGate $advisory,
    ) {}

    /**
     * The student's bills, their payment history, and what the model last said.
     */
    public function index(Request $request): Response
    {
        $student = $this->studentFor($request);

        return Inertia::render('student/bills', [
            'bills' => StudentBill::query()
                ->where('student_id', $student->id)
                ->orderByDesc('id')
                ->get()
                ->map(fn (StudentBill $bill): array => $this->billData($bill))
                ->all(),
            'payments' => StudentBillPayment::query()
                ->where('student_id', $student->id)
                ->latest('id')
                ->limit(25)
                ->get()
                ->map(fn (StudentBillPayment $payment): array => $this->paymentData($payment))
                ->all(),
            'pilot_enabled' => (bool) config('eduflow.student_bills.enabled', false),
            'pilot_bill_ids' => config('eduflow.student_bills.pilot_bill_ids', []),
        ]);
    }

    /**
     * Ask the model to characterise one bill.
     *
     * Returns null — never an error — when AI is switched off, unconfigured or
     * unreachable, because the screen must still work with no model at all. The
     * answer is advisory in both directions: it can explain a bill, and it can
     * never approve one.
     */
    public function classify(Request $request): JsonResponse
    {
        $student = $this->studentFor($request);
        $validated = $request->validate([
            'student_bill_id' => ['required', 'integer', Rule::exists('student_bills', 'id')->where('student_id', $student->id)],
        ]);

        /** @var StudentBill $bill */
        $bill = StudentBill::query()->findOrFail($validated['student_bill_id']);

        $envelope = $this->advisory->classifyBill([
            'description' => $bill->description,
            'category' => $bill->category->value,
            'amount_base_units' => $bill->amount_base_units,
            'due_date' => $bill->due_date?->toDateString(),
        ]);

        return response()->json([
            'classification' => $envelope?->isBillClassification() === true
                ? [
                    'category' => $envelope->category,
                    'beneficiary_alias' => $envelope->beneficiaryAlias,
                    'confidence' => $envelope->confidence,
                    'narrative' => $envelope->narrative,
                    'anomaly_flags' => $envelope->anomalyFlags,
                ]
                : null,
            // Said plainly rather than left as a blank panel, so nobody reads a
            // missing answer as a low-confidence one.
            'unavailable_reason' => $envelope instanceof AdvisoryEnvelope
                ? null
                : 'Advisory classification is unavailable: AI may be switched off, unconfigured, or the provider did not answer. Nothing about this bill or this payment depends on it.',
        ]);
    }

    /**
     * Mount an immutable quote for one bill and one principal.
     */
    public function quote(Request $request): JsonResponse
    {
        $student = $this->studentFor($request);

        $validated = $request->validate([
            'student_bill_id' => ['required', 'integer', Rule::exists('student_bills', 'id')->where('student_id', $student->id)],
            'amount_base_units' => ['required', 'integer', 'min:1'],
        ]);

        /** @var StudentBill $bill */
        $bill = StudentBill::query()->findOrFail($validated['student_bill_id']);

        // A classification the screen already fetched and sanitized. Optional on
        // purpose: the payment path must work with no model configured at all.
        $advisory = null;
        if (is_array($request->input('classification'))) {
            $advisory = $this->advisory->sanitizeClassification($request->input('classification'));
        }

        return response()->json($this->payments->quote(
            $student,
            $bill,
            (int) $validated['amount_base_units'],
            is_string($request->input('request_key')) ? $request->input('request_key') : null,
            $advisory,
        ));
    }

    /**
     * Record the student's acceptance of one exact quote.
     *
     * This creates consent and nothing more. No signer is reachable from an HTTP
     * request, so accepting a quote cannot move money by itself — it only allows
     * the queued worker to act on a decision the student already made.
     */
    public function consent(Request $request): JsonResponse
    {
        $student = $this->studentFor($request);

        $validated = $request->validate([
            'student_bill_id' => ['required', 'integer', Rule::exists('student_bills', 'id')->where('student_id', $student->id)],
            'amount_base_units' => ['required', 'integer', 'min:1'],
            'request_key' => ['required', 'uuid'],
            'request_digest' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/D'],
            'consent' => ['required', 'accepted'],
        ]);

        /** @var StudentBill $bill */
        $bill = StudentBill::query()->findOrFail($validated['student_bill_id']);

        $payment = $this->payments->consent(
            $student,
            $bill,
            (int) $validated['amount_base_units'],
            $validated['request_key'],
            $validated['request_digest'],
            true,
        );

        return response()->json($this->paymentData($payment->refresh()), 202);
    }

    /**
     * Read persisted payment state. Never submits, never resolves.
     *
     * A GET that could advance a payment would make a refresh button capable of
     * spending money, so this only reads rows.
     */
    public function status(Request $request): JsonResponse
    {
        $student = $this->studentFor($request);

        $validated = $request->validate([
            'request_key' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $requestKey = $validated['request_key'] ?? null;

        $payments = StudentBillPayment::query()
            ->where('student_id', $student->id)
            ->when(is_string($requestKey), fn ($q) => $q->where('request_key', $requestKey))
            ->latest('id')
            ->limit(25)
            ->get();

        return response()->json([
            'data' => $payments->map(fn (StudentBillPayment $payment): array => $this->paymentData($payment))->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function billData(StudentBill $bill): array
    {
        return [
            'id' => $bill->id,
            'category' => $bill->category->value,
            'category_label' => $bill->category->label(),
            'description' => $bill->description,
            'amount_base_units' => (string) $bill->amount_base_units,
            'paid_base_units' => (string) $bill->paid_base_units,
            'remaining_base_units' => (string) $bill->remainingAmount(),
            'due_date' => $bill->due_date?->toDateString(),
            'status' => $bill->status,
            'is_pilot' => in_array($bill->id, config('eduflow.student_bills.pilot_bill_ids', []), true),
        ];
    }

    /**
     * The student's own view of one payment attempt.
     *
     * Every field here is a fact the server holds, not an inference the client
     * makes: the rail's handle and hash are separate because a Circle agent
     * wallet has no hash at acceptance, and `requires_human_approval` is the
     * deterministic policy's word rather than the classifier's.
     *
     * @return array<string, mixed>
     */
    private function paymentData(StudentBillPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'request_key' => $payment->request_key,
            'student_bill_id' => $payment->student_bill_id,
            'amount_base_units' => (string) $payment->amount_base_units,
            'applied_base_units' => (string) $payment->applied_base_units,
            'overpayment_base_units' => (string) $payment->overpayment_base_units,
            'quoted_network_fee' => $payment->snapshot['quoted_network_fee'] ?? null,
            'fee_paid_by' => $payment->fee_guarantee,
            'tx_hash' => $payment->tx_hash,
            'provider_handle' => $payment->provider_handle,
            'submission_state' => $payment->submission_state,
            'submission_label' => $this->stateLabel($payment),
            'policy_decision' => $payment->policy_decision,
            'policy_code' => $payment->policy_code,
            'requires_human_approval' => $payment->requires_human_approval,
            'authorizes_outgoing_spend' => $payment->authorizesOutgoingSpend(),
            'is_rehearsal' => $payment->isRehearsal(),
            'is_allocated' => $payment->isAllocated(),
            'verified' => $payment->hasValidSettlement(),
            'last_error' => $payment->last_error,
            'created_at' => $payment->created_at?->toIso8601String(),
        ];
    }

    /**
     * What the rail actually knows, in the words a payer needs.
     */
    private function stateLabel(StudentBillPayment $payment): string
    {
        if ($payment->isRehearsal()) {
            return 'Rehearsal — nothing was sent.';
        }

        return match ($payment->submission_state) {
            StudentBillPayment::SUBMISSION_CONSENTED => 'Accepted. Waiting for the queue to dispatch it.',
            StudentBillPayment::SUBMISSION_PREPARED => 'Queued for sending. Not sent yet.',
            StudentBillPayment::SUBMISSION_SUBMITTING => 'Sent to the payment provider. The outcome is not known yet — do not send it again.',
            StudentBillPayment::SUBMISSION_ACCEPTED => 'The provider accepted it. Waiting for it to appear on chain.',
            StudentBillPayment::SUBMISSION_AWAITING_VERIFICATION => 'On chain. Checking that the exact amount moved.',
            StudentBillPayment::SUBMISSION_VERIFIED => $payment->isAllocated()
                ? 'Verified on chain and applied to your bill.'
                : 'Verified on chain. Applying it to your bill.',
            StudentBillPayment::SUBMISSION_NEEDS_REVIEW => 'A staff member must finish this one. Do not send it again.',
            default => 'Status unavailable.',
        };
    }

    private function studentFor(Request $request): Student
    {
        abort_unless($request->user()?->hasRole('student'), 403);

        $student = $request->user()->student()->firstOrFail();
        abort_unless($student instanceof Student, 403);

        return $student;
    }
}
