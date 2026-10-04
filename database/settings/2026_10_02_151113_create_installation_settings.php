<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('installation.institution_id', null);
        $this->migrator->add('installation.initialized_at', null);
        $this->migrator->add('installation.country', '');
        $this->migrator->add('installation.locale', 'en');
        $this->migrator->add('installation.timezone', 'UTC');
        $this->migrator->add('installation.currency', 'USDC');
    }

    public function down(): void
    {
        foreach (['institution_id', 'initialized_at', 'country', 'locale', 'timezone', 'currency'] as $property) {
            $this->migrator->delete('installation.'.$property);
        }
    }
};
