<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class InstallationSettings extends Settings
{
    public ?int $institution_id = null;

    public ?string $initialized_at = null;

    public string $country = '';

    public string $locale = 'en';

    public string $timezone = 'UTC';

    public string $currency = 'USDC';

    public static function group(): string
    {
        return 'installation';
    }
}
