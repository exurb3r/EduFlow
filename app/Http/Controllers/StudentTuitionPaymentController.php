<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\TuitionAccount;
use App\Services\StudentTuitionPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
        [$student, $account] = $this->resolve($request);

        $validated = $request->validate([
            'amount_base_units' => ['required', 'integer', 'min:1'],
        ]);

        $quote = $this->payments->quote($student, $account, (int) $validated['amount_base_units']);

        return response()->json([
            ...$quote,
            'request_key' => $this->requestKey($request),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        [$student, $account] = $this->resolve($request);

        $validated = $request->validate([
            'amount_base_units' => ['required', 'integer', 'min:1'],
            // Echoed back from the quote, so the same identity is used for the
            // transfer and for the posting that consumes its verification.
            'request_key' => ['required', 'uuid'],
        ]);

        $requestKey = (string) $validated['request_key'];

        $payment = $this->payments->pay($student, $account, (int) $validated['amount_base_units'], $requestKey);

        return response()->json([
            'request_key' => $requestKey,
            'tx_hash' => $payment->tx_hash,
            'amount' => $payment->amount(),
            'network_fee' => $payment->fee(),
            'recipient' => $payment->recipient_address,
            'chain' => $payment->chain,
            'verified' => $payment->tx_verified_on_chain,
            // A rehearsal says so, rather than presenting a receipt the payer
            // could mistake for a settled payment.
            'simulated' => $payment->simulated,
            'applied_base_units' => $payment->applied_base_units,
            'overpayment_base_units' => $payment->overpayment_base_units,
            'remaining_base_units' => $account->fresh()->remainingAmount(),
        ]);
    }

    /**
     * The identity a payment attempt keeps for its whole life.
     *
     * A client that already holds a key sends it back and keeps it; otherwise
     * the server mints one. Either way the key is not derived from anything the
     * client can influence about the transfer itself.
     */
    private function requestKey(Request $request): string
    {
        $provided = $request->input('request_key');

        if (is_string($provided) && Str::isUuid($provided)) {
            return $provided;
        }

        return Str::uuid()->toString();
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
