<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\StudentTuitionPayment;
use App\Models\StudentTuitionPaymentAttempt;
use App\Models\TuitionAccount;
use App\Services\StudentTuitionPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

/**
 * Tuition paid from the student's own wallet into the institution treasury.
 *
 * The recipient is read from LEPTON_TREASURY_ADDRESS on the server and is never
 * taken from the request, and the student is always the authenticated user's
 * own record, so there is no id or address to forge.
 *
 * The `request_key` is minted by the server and echoed back to the payer on
 * both the quote and the payment, so a confirmation, a retry and a page reload
 * all converge on one durable payment attempt instead of a second transfer.
 */
class StudentTuitionPaymentController extends Controller
{
    public function __construct(
        private readonly StudentTuitionPaymentService $payments,
    ) {}

    public function quote(Request $request): JsonResponse
    {
        set_time_limit(120);

        [$student, $account] = $this->resolve($request);

        $validated = $request->validate([
            'amount_base_units' => ['required', 'integer', 'min:1'],
            'request_key' => ['sometimes', 'nullable', 'uuid'],
        ]);

        return response()->json($this->payments->quote($student, $account, (int) $validated['amount_base_units'], $validated['request_key'] ?? null));
    }

    public function store(Request $request): JsonResponse
    {
        set_time_limit(120);

        [$student, $account] = $this->resolve($request);

        $validated = $request->validate([
            'amount_base_units' => ['required', 'integer', 'min:1'],
            // Echoed back from the quote, so the same identity is used for the
            // transfer and for the posting that consumes its verification.
            'request_key' => ['required', 'uuid'],
            'request_digest' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/D'],
            'consent' => ['required', 'accepted'],
        ]);

        $requestKey = (string) $validated['request_key'];

        $attempt = $this->payments->pay($student, $account, (int) $validated['amount_base_units'], $requestKey, $validated['request_digest'], true);

        return response()->json(self::statusData($attempt->refresh()), 202);
    }

    /**
     * The identity a payment attempt keeps for its whole life.
     *
     * A client that already holds a key sends it back and keeps it; otherwise
     * the server mints one. Either way the key is not derived from anything the
     * client can influence about the transfer itself.
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasRole('student'), 403);
        $student = $request->user()->student()->firstOrFail();

        return response()->json(['data' => StudentTuitionPaymentAttempt::query()->where('student_id', $student->id)
            ->latest('id')->limit(50)->get()->map(fn (StudentTuitionPaymentAttempt $a): array => self::statusData($a))]);
    }

    /** @return array<string, mixed> */
    public static function statusData(StudentTuitionPaymentAttempt $attempt): array
    {
        $payment = StudentTuitionPayment::query()->where('request_key', $attempt->request_key)->first();
        $explorerUrl = null;
        if (is_string($attempt->provider_reference) && trim($attempt->provider_reference) !== '') {
            try {
                $explorerUrl = app(ArcNetworkGateway::class)->explorerUrl($attempt->provider_reference);
            } catch (\Throwable) {
                $explorerUrl = 'https://testnet.arcscan.app/tx/'.$attempt->provider_reference;
            }
        }

        return ['id' => $attempt->id, 'request_key' => $attempt->request_key, 'state' => $attempt->state,
            'state_label' => $attempt->state()->label(), 'guidance' => $attempt->state()->guidance(), 'stage' => $attempt->stage,
            'amount_base_units' => (string) $attempt->amount_base_units, 'quoted_network_fee' => $attempt->quoted_network_fee,
            'fee_ceiling_base_units' => (string) $attempt->fee_ceiling_base_units, 'actual_network_fee' => $attempt->actual_network_fee,
            'tx_hash' => $attempt->provider_reference, 'provider_handle' => $attempt->provider_handle,
            'explorer_url' => $explorerUrl,
            'simulated' => $attempt->state === 'simulated', 'verified' => $attempt->isVerified() && $attempt->hasValidSettlement(),
            'posted' => $attempt->posted_at !== null, 'applied_base_units' => (string) ($payment instanceof StudentTuitionPayment ? $payment->applied_base_units : 0),
            'overpayment_base_units' => (string) ($payment instanceof StudentTuitionPayment ? $payment->overpayment_base_units : 0),
            'requires_investigation' => $attempt->stage === 'needs_operator_review', 'created_at' => $attempt->created_at->toIso8601String(),
            'last_error' => $attempt->last_error];
    }

    /**
     * The authenticated student and the tuition account being paid.
     *
     * @return array{Student, TuitionAccount}
     */
    private function resolve(Request $request): array
    {
        abort_unless($request->user()?->hasRole('student'), 403);

        $student = $request->user()->student()->firstOrFail();

        $validated = validator($request->all(), [
            'tuition_account_id' => ['required', 'integer'],
        ])->validate();

        $account = $student->tuitionAccounts()
            ->whereKey((int) $validated['tuition_account_id'])
            ->first();

        abort_if($account === null, 404);

        return [$student, $account];
    }
}
