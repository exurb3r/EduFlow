<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string $category
 * @property string|null $email
 * @property string $wallet_address
 * @property string $status
 * @property string $risk_level
 * @property-read Organization $organization
 * @property-read Collection<int, Invoice> $invoices
 */
class Vendor extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'category',
        'email',
        'wallet_address',
        'status',
        'risk_level',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function isVerified(): bool
    {
        return $this->status === 'verified';
    }
}
