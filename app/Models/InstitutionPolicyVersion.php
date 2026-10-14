<?php

declare(strict_types=1);

namespace App\Models;

use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Http\Requests\StoreInstitutionPolicyRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use LogicException;
use Throwable;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $created_by
 * @property int|null $predecessor_id
 * @property int $finance_policy_version_id
 * @property string $request_key
 * @property string $title
 * @property array<string, mixed> $content
 * @property string $content_digest
 * @property-read FinancePolicyVersion $financePolicy
 */
class InstitutionPolicyVersion extends Model
{
    protected $fillable = ['organization_id', 'created_by', 'predecessor_id', 'finance_policy_version_id', 'request_key', 'title', 'content', 'content_digest'];

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'created_by' => 'integer', 'predecessor_id' => 'integer', 'finance_policy_version_id' => 'integer', 'content' => 'array'];
    }

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $version): void {
            if (! $version->hasValidContent()) {
                throw new LogicException('Institution policy requires intact source and financial control evidence.');
            }
        });
        static::updating(function (): never {
            throw new LogicException('Policy versions are immutable. Save a new revision.');
        });
        static::deleting(function (): never {
            throw new LogicException('Policy evidence cannot be deleted.');
        });
    }

    public function hasValidContent(): bool
    {
        try {
            $finance = $this->financePolicy;
            $content = $this->content;
            $input = [...$content, 'request_key' => $this->request_key];
            if (Validator::make($input, StoreInstitutionPolicyRequest::contentRules())->fails()) {
                return false;
            }
            $clauses = array_column($content['clauses'], 'id');
            $categories = [...array_column($content['categories'], 'id'), 'needs_review'];
            foreach ($content['categories'] as $category) {
                if (array_diff($category['clause_ids'], $clauses) !== []) {
                    return false;
                }
            }
            foreach ($content['examples'] as $example) {
                if (! in_array($example['category_id'], $categories, true)) {
                    return false;
                }
            }
            foreach (['minimum_reserve' => 'minimum_reserve_base_units', 'max_auto_payment' => 'max_auto_payment_base_units',
                'max_daily_disbursement' => 'max_daily_disbursement_base_units', 'max_fee' => 'max_fee_base_units'] as $key => $field) {
                if (Money::fromDecimal($content['controls'][$key], CurrencyCode::USDC)->minorUnits !== $finance->getAttribute($field)) {
                    return false;
                }
            }

            return $finance->exists && $finance->organization_id === $this->organization_id && $finance->created_by === $this->created_by
                && $finance->hasValidContent() && ($this->content['schema_version'] ?? null) === 1
                && ($this->content['title'] ?? null) === $this->title && ($this->content['institution_id'] ?? null) === $this->organization_id
                && ($this->content['created_by'] ?? null) === $this->created_by && ($this->content['predecessor_id'] ?? null) === $this->predecessor_id
                && ($this->content['can_execute'] ?? null) === false
                && ($this->content['finance_policy_digest'] ?? null) === $finance->content_digest
                && hash_equals($this->content_digest, PaymentIntent::digest($this->content));
        } catch (Throwable) {
            return false;
        }
    }

    /** @return BelongsTo<FinancePolicyVersion, $this> */
    public function financePolicy(): BelongsTo
    {
        return $this->belongsTo(FinancePolicyVersion::class, 'finance_policy_version_id');
    }
}
