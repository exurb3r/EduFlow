<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $institution_policy_version_id
 * @property int|null $requested_by
 * @property string $request_key
 * @property string $kind
 * @property string $state
 * @property array<string, mixed> $input
 * @property string $policy_digest
 * @property string $input_digest
 * @property array<string, mixed>|null $result
 * @property string|null $result_digest
 * @property string|null $provider
 * @property string|null $model
 * @property string|null $last_error
 * @property Carbon|null $completed_at
 */
class InstitutionPolicyAssessment extends Model
{
    protected $fillable = ['organization_id', 'institution_policy_version_id', 'requested_by', 'request_key', 'kind', 'state',
        'input', 'policy_digest', 'input_digest', 'result', 'result_digest', 'provider', 'model', 'last_error', 'completed_at'];

    protected $attributes = ['state' => 'queued'];

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'institution_policy_version_id' => 'integer', 'requested_by' => 'integer',
            'input' => 'array', 'result' => 'array', 'completed_at' => 'datetime'];
    }

    public function hasValidResult(): bool
    {
        $version = InstitutionPolicyVersion::query()->find($this->institution_policy_version_id);

        return $version !== null && $version->organization_id === $this->organization_id && $version->hasValidContent()
            && $version->content_digest === $this->policy_digest
            && $this->state === 'completed' && is_array($this->result) && is_string($this->result_digest)
            && ($this->result['can_execute'] ?? null) === false
            && hash_equals($this->input_digest, PaymentIntent::digest($this->input))
            && hash_equals($this->result_digest, PaymentIntent::digest($this->result));
    }

    #[\Override]
    protected static function booted(): void
    {
        static::updating(function (self $assessment): void {
            if ($assessment->isDirty(['organization_id', 'institution_policy_version_id', 'requested_by', 'request_key', 'kind', 'input', 'input_digest', 'policy_digest'])
                || ($assessment->getOriginal('completed_at') !== null && $assessment->isDirty())) {
                throw new LogicException('Assessment identity and concluded evidence are immutable.');
            }
        });
        static::deleting(function (): never {
            throw new LogicException('Assessment evidence cannot be deleted.');
        });
    }
}
