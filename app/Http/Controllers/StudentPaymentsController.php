<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\StudentBillPayment;
use App\Models\StudentTuitionPayment;
use App\Models\Transaction;
use App\Services\StudentWalletService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

class StudentPaymentsController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $student = $user->student()->firstOrFail();
        $walletAddress = app(StudentWalletService::class)->addressFor($student);

        $transactions = Transaction::query()
            ->paidTo($walletAddress)
            ->whereIn('type', ['student_assistance', 'refund'])
            ->latest('executed_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Transaction $transaction): array => [
                'id' => $transaction->id,
                'type' => $transaction->type->value,
                'type_label' => $transaction->type->getLabel(),
                'amount' => (float) $transaction->amount,
                'currency' => $transaction->currency,
                'status' => $transaction->status->value,
                'status_label' => $transaction->status->getLabel(),
                'tx_hash' => $transaction->provider_tx_hash,
                'network' => $transaction->network,
                'executed_at' => $transaction->executed_at?->format('M d, Y h:i A'),
            ]);

        $confirmedTotal = (float) Transaction::query()
            ->paidTo($walletAddress)
            ->whereIn('type', ['student_assistance', 'refund'])
            ->where('status', 'confirmed')
            ->sum('amount');

        $tuitionReceipts = StudentTuitionPayment::query()
            ->where('student_id', $student->id)
            ->with(['tuitionAccount.academicTerm', 'attempt'])
            ->latest('executed_at')
            ->limit(25)
            ->get()
            ->map(function (StudentTuitionPayment $payment) use ($user, $student): array {
                $attempt = $payment->attempt;
                $account = $payment->tuitionAccount;
                $explorerUrl = $this->explorerUrl($payment->tx_hash);

                $rawBlock = $attempt?->settlement_evidence['receipt']['blockNumber'] ?? null;
                $blockNumber = null;
                if (is_string($rawBlock)) {
                    $blockNumber = str_starts_with($rawBlock, '0x') ? (string) hexdec($rawBlock) : $rawBlock;
                } elseif (is_int($rawBlock)) {
                    $blockNumber = (string) $rawBlock;
                }

                return [
                    'receipt_number' => sprintf('OR-ARC-T%s-%06d', ($payment->executed_at ?? $payment->created_at ?? now())->format('Y'), $payment->id),
                    'payment_type' => 'tuition',
                    'student_name' => $user->name,
                    'student_number' => $student->student_number,
                    'program' => $student->program,
                    'term' => $account?->academicTerm?->name ?? 'Current Term',
                    'description' => 'Official Tuition Settlement'.($account?->academicTerm?->name ? ' · '.$account->academicTerm->name : ''),
                    'amount_base_units' => (string) $payment->amount_base_units,
                    'applied_base_units' => (string) $payment->applied_base_units,
                    'overpayment_base_units' => (string) $payment->overpayment_base_units,
                    'remaining_balance_base_units' => $account ? (string) $account->remainingAmount() : '0',
                    'total_tuition_base_units' => $account ? (string) $account->total_amount : '0',
                    'paid_tuition_base_units' => $account ? (string) $account->paid_amount : '0',
                    'network_fee' => $payment->network_fee ?: '0.00',
                    'fee_guarantee' => $attempt?->fee_guarantee ?? 'relayer_sponsored',
                    'payer_address' => $payment->payer_address,
                    'recipient_address' => $payment->recipient_address,
                    'chain' => $payment->chain ?: 'ARC-TESTNET',
                    'chain_id' => $attempt?->chain_id ?: 5042002,
                    'block_number' => $blockNumber,
                    'verified_status' => 'Verified on Arc Testnet',
                    'tx_hash' => $payment->tx_hash,
                    'explorer_url' => $explorerUrl,
                    'settlement_digest' => $attempt?->settlement_evidence_digest,
                    'consent_digest' => $attempt?->consent_digest,
                    'issued_at' => ($payment->executed_at ?? $payment->created_at ?? now())->format('M d, Y h:i:s A'),
                ];
            });

        $billReceipts = StudentBillPayment::query()
            ->where('student_id', $student->id)
            ->where('tx_verified_on_chain', true)
            ->with('studentBill')
            ->latest('executed_at')
            ->limit(25)
            ->get()
            ->map(function (StudentBillPayment $payment) use ($user, $student): array {
                $explorerUrl = $this->explorerUrl($payment->tx_hash);

                $rawBlock = $payment->settlement_evidence['receipt']['blockNumber'] ?? null;
                $blockNumber = null;
                if (is_string($rawBlock)) {
                    $blockNumber = str_starts_with($rawBlock, '0x') ? (string) hexdec($rawBlock) : $rawBlock;
                } elseif (is_int($rawBlock)) {
                    $blockNumber = (string) $rawBlock;
                }

                return [
                    'receipt_number' => sprintf('OR-ARC-B%s-%06d', ($payment->executed_at ?? $payment->created_at ?? now())->format('Y'), $payment->id),
                    'payment_type' => 'bill',
                    'student_name' => $user->name,
                    'student_number' => $student->student_number,
                    'program' => $student->program,
                    'term' => $payment->studentBill?->category->label() ?? 'Campus Bill',
                    'description' => $payment->studentBill?->description ?? 'Campus Bill Payment',
                    'amount_base_units' => (string) $payment->amount_base_units,
                    'applied_base_units' => (string) $payment->applied_base_units,
                    'overpayment_base_units' => (string) $payment->overpayment_base_units,
                    'remaining_balance_base_units' => (string) ($payment->studentBill?->remainingAmount() ?? 0),
                    'network_fee' => $payment->network_fee ?? '0.00',
                    'fee_guarantee' => $payment->fee_guarantee ?? 'relayer_sponsored',
                    'payer_address' => $payment->payer_address,
                    'recipient_address' => $payment->recipient_address,
                    'chain' => $payment->chain ?: 'ARC-TESTNET',
                    'chain_id' => 5042002,
                    'block_number' => $blockNumber,
                    'verified_status' => 'Verified on Arc Testnet',
                    'tx_hash' => $payment->tx_hash,
                    'explorer_url' => $explorerUrl,
                    'settlement_digest' => $payment->settlement_evidence_digest,
                    'consent_digest' => $payment->consent_digest,
                    'issued_at' => ($payment->executed_at ?? $payment->settlement_verified_at ?? $payment->created_at ?? now())->format('M d, Y h:i:s A'),
                ];
            });

        $receipts = $tuitionReceipts->concat($billReceipts)->values()->all();

        $tuitionPaidBaseUnits = (int) StudentTuitionPayment::query()
            ->where('student_id', $student->id)
            ->where('tx_verified_on_chain', true)
            ->sum('applied_base_units');

        return Inertia::render('payments', [
            'transactions' => $transactions,
            'receipts' => $receipts,
            'wallet' => [
                'address' => $walletAddress,
            ],
            'totals' => [
                'confirmed' => $confirmedTotal,
                'tuition_paid' => round($tuitionPaidBaseUnits / 1_000_000, 2),
                'currency' => 'USDC',
            ],
        ]);
    }

    private function explorerUrl(?string $txHash): ?string
    {
        if (! is_string($txHash) || trim($txHash) === '') {
            return null;
        }

        try {
            return app(ArcNetworkGateway::class)->explorerUrl($txHash);
        } catch (Throwable) {
            return 'https://testnet.arcscan.app/tx/'.$txHash;
        }
    }
}
