<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AssistanceCategory: string implements HasColor, HasLabel
{
    case ACADEMIC = 'academic';
    case TECHNICAL = 'technical';
    case ENROLLMENT = 'enrollment';
    case FINANCIAL = 'financial';
    case GENERAL = 'general';

    public function getLabel(): string
    {
        return match ($this) {
            self::ACADEMIC => 'Academic & Coursework',
            self::TECHNICAL => 'Technical & IT Support',
            self::ENROLLMENT => 'Enrollment & Records',
            self::FINANCIAL => 'Tuition & Billing',
            self::GENERAL => 'General Inquiry',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::ACADEMIC => 'info',
            self::TECHNICAL => 'warning',
            self::ENROLLMENT => 'primary',
            self::FINANCIAL => 'danger',
            self::GENERAL => 'gray',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
