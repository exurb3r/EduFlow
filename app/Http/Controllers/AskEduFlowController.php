<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AskEduFlow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AskEduFlowController extends Controller
{
    /**
     * Handle student interactive natural-language queries.
     */
    public function ask(Request $request, AskEduFlow $ask): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'min:2', 'max:500'],
        ]);

        $user = $request->user();
        $student = $user->student;

        $account = $student?->tuitionAccounts()
            ->whereHas('academicTerm', fn ($query) => $query->whereDate('starts_on', '<=', today())->whereDate('ends_on', '>=', today()))
            ->first();

        $balanceBase = $account ? $account->remainingAmount() : 0;
        $displayCurrency = (string) config('eduflow.display_currency', 'PHP');

        $context = [
            'tuition_balance_base_units' => $balanceBase,
            'display_currency' => $displayCurrency,
            'student_name' => $user->name,
            'student_number' => $student?->student_number,
        ];

        $response = $ask->query($validated['question'], $context);

        return response()->json([
            'success' => true,
            'data' => $response,
        ]);
    }
}
