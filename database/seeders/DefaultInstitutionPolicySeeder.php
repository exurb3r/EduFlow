<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\InstitutionPolicyVersion;
use App\Models\Organization;
use App\Models\User;
use App\Services\DefaultInstitutionPolicy;
use App\Services\InstallationInstitution;
use App\Services\InstitutionPolicyWorkspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Installs the editable starting policy for a school.
 *
 * It writes a draft revision only. Publication stays a separate, human,
 * two-person act, because a seeded "active policy" would be an institution
 * policy nobody at that institution reviewed — exactly the thing the policy
 * lifecycle exists to prevent.
 *
 * The revision is created against a real staff author so the audit trail names
 * a person. Re-running is safe: it is idempotent on request key and leaves an
 * existing published revision untouched.
 */
class DefaultInstitutionPolicySeeder extends Seeder
{
    public function run(): void
    {
        /** @var Organization|null $institution */
        $institution = app(InstallationInstitution::class)->current();
        if ($institution === null) {
            $this->command?->warn('No institution found; skipping default policy.');

            return;
        }

        $author = $this->author();
        if ($author === null) {
            $this->command?->warn('No finance officer or administrator to author the default policy; skipping.');

            return;
        }

        if (InstitutionPolicyVersion::query()->where('organization_id', $institution->id)->exists()) {
            $this->command?->line('An institution policy already exists for this school; default template not applied.');

            return;
        }

        $template = DefaultInstitutionPolicy::forInstitution($institution);
        $template['request_key'] = (string) Str::uuid();
        $template['controls'] = [
            'minimum_reserve' => '0',
            'max_auto_payment' => '0',
            'max_daily_disbursement' => '0',
            'max_fee' => '0',
        ];

        $version = DB::transaction(fn () => app(InstitutionPolicyWorkspace::class)->save($author, $template));

        $this->command?->info("Default institution policy drafted (revision #{$version->id}) for {$institution->name}.");
        $this->command?->line('Open /finance/policies to test it against its examples and have a second person publish it. Nothing is active yet.');
    }

    private function author(): ?User
    {
        return User::query()->whereNotNull('email_verified_at')->whereHas('roles', fn ($query) => $query->whereIn('name', ['finance_officer', 'admin', 'super_admin'])
            ->where('guard_name', 'web'))->orderBy('id')->first();
    }
}
