<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $agent_decision_id
 * @property int|null $approver_id
 * @property string $status
 * @property string|null $comment
 * @property Carbon|null $approved_at
 * @property-read Organization $organization
 * @property-read AgentDecision $agentDecision
 * @property-read User|null $approver
 */
class Approval extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'agent_decision_id',
        'approver_id',
        'status',
        'comment',
        'approved_at',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function agentDecision(): BelongsTo
    {
        return $this->belongsTo(AgentDecision::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
