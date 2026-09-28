<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $version
 * @property int|null $organization_id
 * @property int $auto_limit_base_units
 * @property int $semester_cap_base_units
 * @property float $min_attendance_rate
 * @property string $required_enrollment_status
 * @property string $required_academic_status
 * @property bool $is_active
 * @property array<string,mixed>|null $rules
 */
class AssistancePolicyVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'version',
        'organization_id',
        'auto_limit_base_units',
        'semester_cap_base_units',
        'min_attendance_rate',
        'required_enrollment_status',
        'required_academic_status',
        'is_active',
        'rules',
    ];

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'auto_limit_base_units' => 'integer',
            'semester_cap_base_units' => 'integer',
            'min_attendance_rate' => 'float',
            'is_active' => 'boolean',
            'rules' => 'array',
        ];
    }

    public static function active(?int $organizationId = null): ?self
    {
        return self::query()
            ->where('is_active', true)
            ->where(function ($query) use ($organizationId): void {
                $query->whereNull('organization_id');
                if ($organizationId !== null) {
                    $query->orWhere('organization_id', $organizationId);
                }
            })
            ->orderByDesc('id')
            ->first();
    }
}
