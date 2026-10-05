<?php

namespace App\Providers;

use App\Features\FeatureRegistry;
use App\Policies\SettlementApprovalPolicy;
use Carbon\CarbonImmutable;
use Filament\Panel;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\ResolvesPendingApprovals;
use Laravel\Ai\Contracts\VerifiesConversationOwnership;
use Laravel\Pennant\FeatureManager;
use Nwidart\Modules\Facades\Module;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        $this->registerFilamentPlugins();
        $this->registerAiContracts();
        $this->registerApprovalPolicy();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureFeatures();
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null
        );
    }

    protected function configureFeatures(): void
    {
        FeatureRegistry::initialize();

        $featureManager = app(FeatureManager::class);

        foreach (FeatureRegistry::all() as $feature) {
            $featureManager->define($feature->key, fn () => $feature->default);
        }
    }

    /**
     * Bind the AI SDK's narrower capability interfaces to the same store.
     *
     * `laravel/ai` binds only `ConversationStore`, yet `DatabaseConversationStore`
     * also implements `VerifiesConversationOwnership` and
     * `ResolvesPendingApprovals`. Those two are exactly what an approval
     * endpoint needs, and resolving them by interface name failed with
     * "Target class is not instantiable" until they were aliased here.
     */
    protected function registerAiContracts(): void
    {
        $this->app->alias(
            ConversationStore::class,
            VerifiesConversationOwnership::class,
        );

        $this->app->alias(
            ConversationStore::class,
            ResolvesPendingApprovals::class,
        );
    }

    /**
     * Register the settlement approval policy against its ability.
     *
     * There is no model to hang this off: the thing being authorised is an
     * action on a conversation, not an operation on a record. Declaring it
     * explicitly keeps the Gate call in the gate honest rather than relying on
     * convention.
     */
    protected function registerApprovalPolicy(): void
    {
        Gate::policy(SettlementApprovalPolicy::class, SettlementApprovalPolicy::class);
        Gate::define(
            'approveSettlementProposals',
            [SettlementApprovalPolicy::class, 'approveSettlementProposals'],
        );
    }

    protected function registerFilamentPlugins(): void
    {
        Panel::configureUsing(function (Panel $panel): void {
            if ($panel->getId() !== 'admin') {
                return;
            }

            foreach (Module::allEnabled() as $module) {
                $plugin = sprintf('Modules\\%s\\%sPlugin', $module->getStudlyName(), $module->getStudlyName());

                if (class_exists($plugin) && method_exists($plugin, 'make')) {
                    $panel->plugin($plugin::make());
                }
            }
        });
    }
}
