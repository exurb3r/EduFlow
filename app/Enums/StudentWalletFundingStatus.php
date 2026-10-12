<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StudentWalletFundingStatus: string implements HasColor, HasLabel
{
    /** The faucet accepted the drip. */
    case FUNDED = 'funded';

    /** The faucet refused, usually the per-user rate limit. */
    case FAILED = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::FUNDED => 'Funded',
            self::FAILED => 'Failed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::FUNDED => 'success',
            self::FAILED => 'danger',
        };
    }
}
