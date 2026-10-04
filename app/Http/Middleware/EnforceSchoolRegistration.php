<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Settings\ApplicationFeaturesSettings;
use App\Settings\InstallationSettings;
use Closure;
use Illuminate\Http\Request;
use Spatie\LaravelSettings\Models\SettingsProperty;
use Symfony\Component\HttpFoundation\Response;

class EnforceSchoolRegistration
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('register', 'register.store')) {
            $payload = SettingsProperty::query()->where('group', InstallationSettings::group())
                ->where('name', 'institution_id')->value('payload');
            $installed = $payload !== null && json_decode((string) $payload, true) !== null;

            if ($installed || ! app()->environment(['local', 'testing'])) {
                abort_unless(app(ApplicationFeaturesSettings::class)->registration_enabled, 403, 'Public registration is disabled. Contact the school.');
            }
        }

        return $next($request);
    }
}
