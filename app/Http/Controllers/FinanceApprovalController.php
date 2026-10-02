<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Ai\Approvals\ApprovalResumeGate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Human review of a paused `SettlementOperator` proposal.
 *
 * The controller stays deliberately thin. It validates shape and translates
 * refusals into status codes; every decision about *whether* this may happen
 * lives in `ApprovalResumeGate`, so a route added later cannot skip a check by
 * forgetting to call something here.
 */
class FinanceApprovalController extends Controller
{
    /**
     * List the tool calls a paused conversation is waiting on.
     */
    public function pending(Request $request, ApprovalResumeGate $gate): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => ['required', 'uuid'],
        ]);

        $pending = $this->guard(
            fn (): array => $gate->pendingFor($request->user(), $validated['conversation_id']),
        );

        return response()->json([
            'success' => true,
            'data' => [
                'conversation_id' => $validated['conversation_id'],
                'pending' => array_map(fn ($approval): array => [
                    'id' => $approval->id,
                    'tool' => $approval->tool,
                    'arguments' => $approval->arguments,
                    'reason' => $approval->reason,
                ], $pending),
            ],
        ]);
    }

    /**
     * Approve or reject the paused tool calls and resume the run.
     */
    public function resume(Request $request, ApprovalResumeGate $gate): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => ['required', 'uuid'],
            'decisions' => ['required', 'array', 'min:1'],
            // The submitted payload is only ever a map of id => bool. Amounts,
            // recipients and tool arguments are read from the stored pause, so
            // there is deliberately no field here a caller could use to
            // redirect a disbursement.
            'decisions.*' => ['required', 'boolean'],
        ]);

        $outcome = $this->guard(fn () => $gate->resume(
            $request->user(),
            $validated['conversation_id'],
            $validated['decisions'],
        ));

        return response()->json([
            'success' => $outcome->status !== 'nothing_pending',
            'data' => $outcome->toArray(),
        ]);
    }

    /**
     * Run the gate, turning a refusal into a 403 rather than a 500.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function guard(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (AuthorizationException $e) {
            // Not a 404: the conversation may well exist, the caller simply may
            // not touch it. Leaking existence either way is not a concern here
            // because a 403 and a 404 are indistinguishable in the response body.
            throw new AccessDeniedHttpException($e->getMessage(), $e);
        }
    }
}
