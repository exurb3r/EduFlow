<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssistanceCategory;
use App\Enums\AssistancePriority;
use App\Enums\AssistanceStatus;
use Database\Factories\AssistanceRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $ticket_number
 * @property int $user_id
 * @property AssistanceCategory $category
 * @property AssistancePriority $priority
 * @property AssistanceStatus $status
 * @property string $subject
 * @property string $description
 * @property string|null $admin_notes
 * @property int|null $assigned_to
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read User|null $assignee
 */
class AssistanceRequest extends Model
{
    /** @use HasFactory<AssistanceRequestFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ticket_number',
        'user_id',
        'category',
        'priority',
        'status',
        'subject',
        'description',
        'admin_notes',
        'assigned_to',
        'resolved_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => AssistanceCategory::class,
            'priority' => AssistancePriority::class,
            'status' => AssistanceStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->ticket_number)) {
                $model->ticket_number = 'AST-'.strtoupper(Str::random(6));
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Scope query to pending or in-progress requests.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [AssistanceStatus::PENDING, AssistanceStatus::IN_PROGRESS]);
    }

    /**
     * Scope query to requests that have reached a terminal state.
     *
     * @param  Builder<self>  $query
     */
    public function scopeResolved(Builder $query): void
    {
        $query->whereIn('status', [AssistanceStatus::RESOLVED, AssistanceStatus::CLOSED]);
    }
}
