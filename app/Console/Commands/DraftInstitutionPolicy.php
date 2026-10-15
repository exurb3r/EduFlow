<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\InstitutionPolicyVersion;
use App\Models\User;
use App\Services\DefaultInstitutionPolicy;
use App\Services\InstallationInstitution;
use App\Services\InstitutionPolicyWorkspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Throwable;

#[Signature('app:draft-institution-policy {author : Verified staff user ID} {--save : Persist an editable draft; never publish or enable runtime}', aliases: ['eduflow:draft-institution-policy'])]
#[Description('Preview or save the institution-specific collections and bill-allocation draft without granting spending authority.')]
class DraftInstitutionPolicy extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(InstallationInstitution $institutions, InstitutionPolicyWorkspace $workspace): int
    {
        try {
            $institution = $institutions->require();
            $authorId = filter_var($this->argument('author'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($authorId === false) {
                $this->error('Verified authorized staff user ID required.');

                return self::FAILURE;
            }
            $author = User::query()->whereKey($authorId)->firstOrFail();
            Gate::forUser($author)->authorize('create', InstitutionPolicyVersion::class);
            $this->warn('Staff ID records operator attribution, not proof of personal sign-in. This command cannot publish or approve payments.');
            $template = DefaultInstitutionPolicy::forInstitution($institution);
            $this->line($template['title']);
            foreach ($template['clauses'] as $clause) {
                $this->line($clause['title'].': '.$clause['text']);
            }
            if (! $this->option('save')) {
                $this->line('Preview only. Add --save to store an editable draft. No policy, runtime or funds changed.');

                return self::SUCCESS;
            }
            $previous = InstitutionPolicyVersion::query()->where('organization_id', $institution->id)->latest('id')->first();
            if ($previous !== null) {
                if (! $previous->hasValidContent()) {
                    $this->error('Existing policy evidence is damaged. Investigate before drafting a successor.');

                    return self::FAILURE;
                }
                $matches = collect(['clauses', 'categories', 'examples', 'priorities'])->every(fn (string $field): bool => $previous->content[$field] === $template[$field]);
                if ($matches) {
                    $this->info('Matching draft already exists as revision #'.$previous->id.'. Nothing rewritten or published.');

                    return self::SUCCESS;
                }
                $template['controls'] = $previous->content['controls'];
            }
            $template['request_key'] = (string) Str::uuid();
            $template['predecessor_id'] = $previous?->id;
            $template['expected_current_digest'] = $workspace->current()?->publication_digest;
            $version = $workspace->save($author, $template);
            $this->info('Saved draft revision #'.$version->id.'. Existing controls preserved; no policy activated.');
            $this->line('Open Institution policies, assess all examples, then have a different authorized reviewer publish.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
