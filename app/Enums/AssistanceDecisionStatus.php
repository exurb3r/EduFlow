<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AssistanceDecisionStatus: string implements HasColor, HasLabel
{
    case AUTO_APPROVED = 'auto_approved';
    case ESCALATED = 'escalated';
    case APPROVED_BY_HUMAN = 'approved_by_human';
    case REJECTED = 'rejected';
    case PAID = 'paid';

    public function getLabel(): string
    {
        return match ($this) {
            self::AUTO_APPROVED => 'Auto-Approved',
            self::ESCALATED => 'Awaiting Review',
            self::APPROVED_BY_HUMAN => 'Approved by Reviewer',
            self::REJECTED => 'Rejected',
            self::PAID => 'Paid Out',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::AUTO_APPROVED => 'success',
            self::ESCALATED => 'warning',
            self::APPROVED_BY_HUMAN => 'info',
            self::REJECTED => 'danger',
            self::PAID => 'primary',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
