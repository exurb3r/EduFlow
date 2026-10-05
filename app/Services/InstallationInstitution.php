<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use App\Settings\InstallationSettings;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Spatie\LaravelSettings\Models\SettingsProperty;

#[Scoped]
class InstallationInstitution
{
    public function current(): ?Organization
    {
        $id = config('eduflow.institution_id');
        $configured = $id !== null && $id !== '';
        $storedId = null;

        if (Schema::hasTable((new SettingsProperty)->getTable())) {
            $payload = SettingsProperty::query()->where('group', InstallationSettings::group())
                ->where('name', 'institution_id')->value('payload');
            $storedId = $payload === null ? null : json_decode((string) $payload, true);

            if ($payload !== null && json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }

            if ($storedId !== null && (! is_int($storedId) || $storedId < 1)) {
                return null;
            }
        }

        if (! $configured && $storedId !== null) {
            $id = $storedId;
            $configured = true;
        }

        if (! $configured && ! app()->environment(['local', 'testing'])) {
            return null;
        }

        if ($configured && (! is_int($id) && ! is_string($id) || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false)) {
            return null;
        }

        // A configured ID does not make a shared-school database safe.
        /** @var Collection<int, Organization> $institutions */
        $institutions = Organization::query()->limit(2)->get();

        if ($institutions->count() !== 1) {
            return null;
        }

        $institution = $institutions->first();

        if (! $institution instanceof Organization) {
            return null;
        }

        if (($configured && $institution->id !== (int) $id)
            || ($storedId !== null && $institution->id !== $storedId)) {
            return null;
        }

        return $institution;
    }

    public function require(): Organization
    {
        return $this->current() ?? throw new RuntimeException(
            'Institution context unavailable. Run eduflow:install or configure EDUFLOW_INSTITUTION_ID for exactly one institution.'
        );
    }
}
