<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

#[Scoped]
class InstallationInstitution
{
    public function current(): ?Organization
    {
        $id = config('eduflow.institution_id');
        $configured = $id !== null && $id !== '';

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

        if ($configured && $institution->id !== (int) $id) {
            return null;
        }

        return $institution;
    }

    public function require(): Organization
    {
        return $this->current() ?? throw new RuntimeException(
            'Institution context unavailable. Configure EDUFLOW_INSTITUTION_ID for a database containing exactly one institution.'
        );
    }
}
