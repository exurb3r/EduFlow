<?php

declare(strict_types=1);

namespace App\DTOs;

readonly class TreasuryForecast
{
    /**
     * @param  array<string, mixed>  $breakdown
     */
    public function __construct(
        public float $currentBalance,
        public float $expectedInflow,
        public float $scheduledObligations,
        public float $projectedBalance,
        public float $minimumReserve,
        public string $healthStatus, // 'SAFE', 'WARNING', 'CRITICAL'
        public ?int $daysUntilReserveBreach = null,
        public array $breakdown = [],
    ) {}

    public function isSafe(): bool
    {
        return $this->healthStatus === 'SAFE';
    }

    public function isWarning(): bool
    {
        return $this->healthStatus === 'WARNING';
    }

    public function isCritical(): bool
    {
        return $this->healthStatus === 'CRITICAL';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'current_balance' => $this->currentBalance,
            'expected_inflow' => $this->expectedInflow,
            'scheduled_obligations' => $this->scheduledObligations,
            'projected_balance' => $this->projectedBalance,
            'minimum_reserve' => $this->minimumReserve,
            'health_status' => $this->healthStatus,
            'days_until_breach' => $this->daysUntilReserveBreach,
            'breakdown' => $this->breakdown,
        ];
    }
}
