<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentDecisionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $action_type
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property array<string, mixed> $input_snapshot
 * @property array<string, mixed>|null $metadata
 * @property string $reasoning_summary
 * @property string $policy_checked
 * @property AgentDecisionType $decision
 * @property float $requested_amount
 * @property float $approved_amount
 * @property bool $requires_approval
 * @property string $status
 * @property-read Organization $organization
 * @property-read Approval|null $approval
 */
class AgentDecision extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'action_type',
        'reference_type',
        'reference_id',
        'input_snapshot',
        'metadata',
        'reasoning_summary',
        'policy_checked',
        'decision',
        'requested_amount',
        'approved_amount',
        'requires_approval',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'decision' => AgentDecisionType::class,
            'input_snapshot' => 'array',
            'metadata' => 'array',
            'requested_amount' => 'float',
            'approved_amount' => 'float',
            'requires_approval' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function approval(): HasOne
    {
        return $this->hasOne(Approval::class);
    }
}
