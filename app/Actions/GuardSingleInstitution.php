<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Organization;
use App\Settings\InstallationSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelSettings\Models\SettingsProperty;

class GuardSingleInstitution
{
    public function handle(): void
    {
        if (! Schema::hasTable((new SettingsProperty)->getTable())) {
            return;
        }

        DB::transaction(function (): void {
            $payload = SettingsProperty::query()->where('group', InstallationSettings::group())
                ->where('name', 'institution_id')->lockForUpdate()->value('payload');
            $configured = config('eduflow.institution_id');
            $selected = false;

            if ($payload !== null) {
                $identity = json_decode((string) $payload, true);
                $selected = json_last_error() !== JSON_ERROR_NONE || $identity !== null;
            }

            if ($selected || ($configured !== null && $configured !== '' && Organization::query()->exists())) {
                throw ValidationException::withMessages([
                    'name' => 'This installation already belongs to an institution. Create a separate instance for another school.',
                ]);
            }
        });
    }
}
