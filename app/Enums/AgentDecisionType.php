<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AgentDecisionType: string implements HasColor, HasLabel
{
    case AUTO_APPROVE = 'auto_approve';
    case PARTIAL_APPROVAL = 'partial_approval';
    case HOLD = 'hold';
    case ESCALATE = 'escalate';
    case REJECT = 'reject';

    /**
     * A model's read on a bill, recorded as commentary and nothing more.
     *
     * Added after the decision cases because it is not one: this value carries
     * no authority to release, hold or decline anything, and it exists only so
     * an advisory classification can be stored and audited alongside the
     * decisions it explains.
     */
    case ADVISORY_CLASSIFICATION = 'advisory_classification';

    public function getLabel(): string
    {
        return match ($this) {
            self::AUTO_APPROVE => 'Auto Approved',
            self::PARTIAL_APPROVAL => 'Partial Approval',
            self::HOLD => 'Held for Safety',
            self::ESCALATE => 'Escalated to Human',
            self::REJECT => 'Rejected by Policy',
            self::ADVISORY_CLASSIFICATION => 'Advisory Classification (No Authority)',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::AUTO_APPROVE => 'success',
            self::PARTIAL_APPROVAL => 'info',
            self::HOLD => 'warning',
            self::ESCALATE => 'danger',
            self::REJECT => 'gray',
            self::ADVISORY_CLASSIFICATION => 'info',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
