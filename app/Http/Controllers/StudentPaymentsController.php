<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StudentPaymentsController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request): Response
    {
        $user = $request->user();

        $transactions = Transaction::query()
            ->where('recipient_address', $user->wallet_address ?? '')
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
            ->where('recipient_address', $user->wallet_address ?? '')
            ->whereIn('type', ['student_assistance', 'refund'])
            ->where('status', 'confirmed')
            ->sum('amount');

        return Inertia::render('payments', [
            'transactions' => $transactions,
            'wallet' => [
                'address' => $user->wallet_address,
            ],
            'totals' => [
                'confirmed' => $confirmedTotal,
                'currency' => 'USDC',
            ],
        ]);
    }
}
