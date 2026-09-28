<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TransactionStatus: string implements HasColor, HasLabel
{
    case PENDING = 'pending';
    case CONFIRMED = 'confirmed';
    case FAILED = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING => 'Pending On-Chain',
            self::CONFIRMED => 'Confirmed (Arc)',
            self::FAILED => 'Failed',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::PENDING => 'warning',
            self::CONFIRMED => 'success',
            self::FAILED => 'danger',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
