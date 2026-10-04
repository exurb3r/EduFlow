<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CurrencyCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $base_code
 * @property string $quote_code
 * @property int $units_per_usdc
 * @property string $provider
 * @property Carbon $quoted_at
 * @property Carbon|null $expires_at
 * @property bool $is_fallback
 */
class CurrencyRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'base_code',
        'quote_code',
        'units_per_usdc',
        'provider',
        'quoted_at',
        'expires_at',
        'is_fallback',
    ];

    protected function casts(): array
    {
        return [
            'units_per_usdc' => 'integer',
            'quoted_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_fallback' => 'boolean',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lessThanOrEqualTo(now());
    }

    public function quoteCurrency(): CurrencyCode
    {
        return CurrencyCode::from($this->quote_code);
    }
}
