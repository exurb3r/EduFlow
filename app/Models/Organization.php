<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $type
 * @property string $currency
 * @property float $minimum_reserve
 * @property float $max_auto_payment
 * @property float $max_daily_disbursement
 * @property float $human_approval_threshold
 * @property-read Collection<int, Wallet> $wallets
 * @property-read Collection<int, Budget> $budgets
 * @property-read Collection<int, Vendor> $vendors
 * @property-read Collection<int, Invoice> $invoices
 * @property-read Collection<int, Transaction> $transactions
 * @property-read Collection<int, AgentDecision> $agentDecisions
 */
class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'currency',
        'minimum_reserve',
        'max_auto_payment',
        'max_daily_disbursement',
        'human_approval_threshold',
    ];

    protected function casts(): array
    {
        return [
            'minimum_reserve' => 'float',
            'max_auto_payment' => 'float',
            'max_daily_disbursement' => 'float',
            'human_approval_threshold' => 'float',
        ];
    }

    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class);
    }

    public function primaryWallet(): ?Wallet
    {
        return $this->wallets()->first();
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    public function vendors(): HasMany
    {
        return $this->hasMany(Vendor::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function agentDecisions(): HasMany
    {
        return $this->hasMany(AgentDecision::class);
    }
}
