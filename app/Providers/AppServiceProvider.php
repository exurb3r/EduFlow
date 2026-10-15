<?php

namespace App\Providers;

use App\Features\FeatureRegistry;
use App\Policies\SettlementApprovalPolicy;
use App\Services\CircleAgentWalletCli;
use App\Services\Payments\AsyncPaymentTransport;
use App\Services\Payments\CircleAgentWalletTransport;
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
use Yukazakiri\Lepton\Support\CliRunner;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        $this->ensureHomeForSubprocesses();
        $this->registerFilamentPlugins();
        $this->registerAiContracts();
        $this->registerApprovalPolicy();
        $this->registerCircleWalletCli();

        // The asynchronous rail is resolved from the container so it can be
        // substituted wholesale. Nothing about settlement should depend on
        // shelling out to a real binary just to be exercised.
        $this->app->scoped(AsyncPaymentTransport::class, fn (): AsyncPaymentTransport => CircleAgentWalletTransport::fromConfig());
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

    /**
     * Give subprocesses a home directory when the runtime did not supply one.
     *
     * The Circle CLI keeps its agent session under $HOME. Some PHP runtimes hand
     * the built-in web server a scrubbed environment that retains only PATH and
     * APP_ENV, and every wallet read and transfer then failed with "no agent
     * session is active" — a rail error that looked like a credential problem but
     * was really a missing variable. Resolve the directory from the process uid
     * instead of guessing a path, and only fill a gap: a HOME that is already
     * set is left exactly as it is.
     */
    protected function ensureHomeForSubprocesses(): void
    {
        if ((string) getenv('HOME') !== '') {
            return;
        }

        $home = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['dir'] ?? null : null;

        if (! is_string($home) || $home === '') {
            return;
        }

        putenv('HOME='.$home);
        $_ENV['HOME'] = $home;
        $_SERVER['HOME'] = $home;
    }

    protected function registerCircleWalletCli(): void
    {
        // `circle wallet create` and `circle wallet fund` are the two verbs
        // Lepton does not wrap, so the app runs them itself through the same
        // argv runner the package uses. Scoped, and swapped for a fake in tests.
        $this->app->scoped(CircleAgentWalletCli::class, fn ($app): CircleAgentWalletCli => new CircleAgentWalletCli(
            new CliRunner(
                (string) config('lepton.circle.bin', 'circle'),
                (int) config('lepton.circle.timeout', 60),
            ),
        ));
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
