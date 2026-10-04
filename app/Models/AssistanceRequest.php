<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssistanceCategory;
use App\Enums\AssistancePriority;
use App\Enums\AssistanceStatus;
use Database\Factories\AssistanceRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $ticket_number
 * @property int $user_id
 * @property int|null $student_id
 * @property int|null $academic_term_id
 * @property AssistanceCategory|string $category
 * @property AssistancePriority|string $priority
 * @property AssistanceStatus|string $status
 * @property string|null $type
 * @property int|string|null $requested_amount
 * @property string|null $subject
 * @property string|null $description
 * @property string|null $reason
 * @property string|null $submission_key
 * @property Carbon|null $submitted_at
 * @property string|null $admin_notes
 * @property int|null $assigned_to
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Student|null $student
 * @property-read AcademicTerm|null $academicTerm
 * @property-read User|null $assignee
 * @property-read Collection<int, AgentDecision> $agentDecisions
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
        'student_id',
        'academic_term_id',
        'category',
        'priority',
        'status',
        'subject',
        'description',
        'admin_notes',
        'assigned_to',
        'resolved_at',
        'type',
        'requested_amount',
        'reason',
        'submission_key',
        'submitted_at',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'emergency',
        'status' => 'submitted',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'student_id' => 'integer',
            'academic_term_id' => 'integer',
            'requested_amount' => 'integer',
            'submitted_at' => 'datetime',
            'category' => AssistanceCategory::class,
            'priority' => AssistancePriority::class,
            'resolved_at' => 'datetime',
        ];
    }

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->ticket_number)) {
                $model->ticket_number = 'AST-'.strtoupper(Str::random(6));
            }
            if (empty($model->user_id) && ! empty($model->student_id)) {
                $student = $model->student ?: Student::find($model->student_id);
                if ($student) {
                    $model->user_id = $student->user_id;
                }
            }
            if (empty($model->subject)) {
                $model->subject = empty($model->type) ? 'Assistance Request' : ucfirst((string) $model->type).' Assistance';
            }
            if (empty($model->description)) {
                $model->description = $model->reason ?? '';
            }
            if (empty($model->reason)) {
                $model->reason = $model->description ?? '';
            }
            if (empty($model->submitted_at)) {
                $model->submitted_at = now();
            }
        });
    }

    public function getStatusAttribute(mixed $value): mixed
    {
        if ($value instanceof AssistanceStatus) {
            return $value;
        }

        $enum = AssistanceStatus::tryFrom((string) $value);

        return $enum ?? $value;
    }

    public function setStatusAttribute(mixed $value): void
    {
        $this->attributes['status'] = $value instanceof \BackedEnum ? $value->value : $value;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<AcademicTerm, $this>
     */
    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    /**
     * @return HasMany<AgentDecision, $this>
     */
    public function agentDecisions(): HasMany
    {
        return $this->hasMany(AgentDecision::class, 'reference_id')
            ->where('reference_type', static::class);
    }

    /**
     * Latest deterministic policy decision recorded for this request, if any.
     */
    public function latestAgentDecision(): ?AgentDecision
    {
        return $this->agentDecisions()->latest('id')->first();
    }

    /**
     * USDC base units still awaiting human approval (requested minus auto-approved).
     */
    public function pendingReviewBaseUnits(): int
    {
        $requested = (int) ($this->requested_amount ?? 0);
        $decision = $this->latestAgentDecision();

        if (! $decision instanceof AgentDecision || ! $decision->requires_approval) {
            return 0;
        }

        $approved = (int) round((float) $decision->approved_amount * 1000000);

        return max(0, $requested - $approved);
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
     * Submitted intake requests count as active until triaged.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [AssistanceStatus::SUBMITTED->value, AssistanceStatus::PENDING->value, AssistanceStatus::IN_PROGRESS->value]);
    }

    /**
     * Scope query to requests that have reached a terminal state.
     *
     * @param  Builder<self>  $query
     */
    public function scopeResolved(Builder $query): void
    {
        $query->whereIn('status', [AssistanceStatus::RESOLVED->value, AssistanceStatus::CLOSED->value]);
    }
}
