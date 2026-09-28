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

    public function getLabel(): string
    {
        return match ($this) {
            self::AUTO_APPROVE => 'Auto Approved',
            self::PARTIAL_APPROVAL => 'Partial Approval',
            self::HOLD => 'Held for Safety',
            self::ESCALATE => 'Escalated to Human',
            self::REJECT => 'Rejected by Policy',
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
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
