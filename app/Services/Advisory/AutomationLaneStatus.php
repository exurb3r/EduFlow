<?php

declare(strict_types=1);

namespace App\Services\Advisory;

use App\Models\PaymentSubmissionOutbox;

/**
 * Counts what the automated submission lane is holding.
 *
 * Extracted from the widget so the counting is testable without a Livewire
 * context, and so a future surface — a command, an API, a supervisor dashboard
 * — reads the same numbers the admin panel shows instead of its own.
 *
 * The buckets are deliberately about *certainty*, not volume. "Owed" is work a
 * worker may still pick up. "In flight" is work the rail already owns, where a
 * resend would pay twice. "Unresolved" is work nobody can characterise, and is
 * therefore the one a human must look at.
 */
final readonly class AutomationLaneStatus
{
    /**
     * @return array{owed:int, in_flight:int, unresolved:int, verified:int}
     */
    public function counts(): array
    {
        return [
            'owed' => $this->count(PaymentSubmissionOutbox::DISPATCHABLE_STATES),
            'in_flight' => $this->count(PaymentSubmissionOutbox::IN_FLIGHT_STATES),
            'unresolved' => $this->count(['running', 'unknown', 'blocked', 'failed']),
            'verified' => $this->count(['completed']),
        ];
    }

    /**
     * @param  array<int, string>  $states
     */
    private function count(array $states): int
    {
        return PaymentSubmissionOutbox::query()->whereIn('state', $states)->count();
    }
}
