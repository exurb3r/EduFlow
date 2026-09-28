<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TransactionType: string implements HasColor, HasLabel
{
    case VENDOR_PAYMENT = 'vendor_payment';
    case STUDENT_ASSISTANCE = 'student_assistance';
    case TUITION_REVENUE = 'tuition_revenue';
    case REFUND = 'refund';

    public function getLabel(): string
    {
        return match ($this) {
            self::VENDOR_PAYMENT => 'Vendor Payment',
            self::STUDENT_ASSISTANCE => 'Student Assistance',
            self::TUITION_REVENUE => 'Tuition Revenue',
            self::REFUND => 'Student Refund',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::VENDOR_PAYMENT => 'info',
            self::STUDENT_ASSISTANCE => 'primary',
            self::TUITION_REVENUE => 'success',
            self::REFUND => 'warning',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
