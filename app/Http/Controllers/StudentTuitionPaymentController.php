<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\TuitionAccount;
use App\Services\StudentTuitionPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tuition paid from the student's own wallet into the institution treasury.
 *
 * The recipient is read from LEPTON_TREASURY_ADDRESS on the server and is never
 * taken from the request, and the student is always the authenticated user's
 * own record, so there is no id or address to forge.
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

        return response()->json($quote);
    }

    public function store(Request $request): JsonResponse
    {
        [$student, $account] = $this->resolve($request);

        $validated = $request->validate([
            'amount_base_units' => ['required', 'integer', 'min:1'],
        ]);

        $payment = $this->payments->pay($student, $account, (int) $validated['amount_base_units']);

        return response()->json([
            'tx_hash' => $payment->tx_hash,
            'amount' => $payment->amount(),
            'network_fee' => $payment->fee(),
            'recipient' => $payment->recipient_address,
            'chain' => $payment->chain,
            'verified' => $payment->tx_verified_on_chain,
            'remaining' => $account->fresh()->remainingAmount() / 10 ** 6,
        ]);
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
