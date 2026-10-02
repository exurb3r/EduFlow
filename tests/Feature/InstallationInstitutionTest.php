<?php

declare(strict_types=1);

use App\Ai\Agents\SettlementOperatorFactory;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\LeptonNetworkWidget;
use App\Models\Organization;
use App\Models\User;
use App\Services\InstallationInstitution;
use App\Services\LeptonTreasuryService;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    config(['eduflow.institution_id' => null]);
});

test('production institution selection requires an explicit persisted ID', function (string $environment): void {
    $institution = Organization::factory()->create();
    app()->instance('env', $environment);

    expect(app(InstallationInstitution::class)->current())->toBeNull();

    config(['eduflow.institution_id' => (string) $institution->id]);

    expect(app(InstallationInstitution::class)->require()->id)->toBe($institution->id);
})->with(['production', 'staging']);

test('demo institution selection permits a single institution but never chooses between two', function (): void {
    $institution = Organization::factory()->create();
    $resolver = app(InstallationInstitution::class);

    expect($resolver->require()->id)->toBe($institution->id);

    Organization::factory()->create();

    expect($resolver->current())->toBeNull();
});

test('configured institution selection refuses missing invalid and ambiguous context', function (mixed $id): void {
    Organization::factory()->create();
    config(['eduflow.institution_id' => $id]);

    expect(app(InstallationInstitution::class)->current())->toBeNull()
        ->and(fn () => app(InstallationInstitution::class)->require())->toThrow(RuntimeException::class);
})->with([0, -1, 'not-an-id', '1.5', '999999999999999999999', 9999]);

test('configured ID cannot authorize a database with multiple institutions', function (): void {
    $institution = Organization::factory()->create();
    Organization::factory()->create();
    config(['eduflow.institution_id' => $institution->id]);

    expect(app(InstallationInstitution::class)->current())->toBeNull();
});

test('missing institution blocks wallet diagnostics before external calls', function (): void {
    app()->instance('env', 'production');
    $this->mock(LeptonTreasuryService::class)->shouldNotReceive('status');

    $this->artisan('lepton:doctor', ['--skip-transfer' => true])
        ->expectsOutputToContain('Institution context unavailable.')
        ->assertFailed();
});

test('ambiguous institution hides treasury widget actions and refuses operator construction', function (): void {
    $first = Organization::factory()->create();
    Organization::factory()->create();
    $this->mock(LeptonTreasuryService::class)->shouldNotReceive('status');

    $widget = new class extends LeptonNetworkWidget
    {
        public function exposedActions(): array
        {
            return $this->getHeaderActions();
        }

        public function exposedStats(): array
        {
            return $this->getStats();
        }
    };

    expect($widget->exposedActions())->toBe([])
        ->and($widget->exposedStats()[0]->getValue())->toBe('Not configured')
        ->and(SettlementOperatorFactory::make($first))->toBeNull();
});

test('dashboard remains accessible with ambiguous institution context and no wallet reads', function (): void {
    Organization::factory()->count(2)->create();
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->mock(LeptonTreasuryService::class)->shouldNotReceive('status');

    $this->actingAs($admin)->get(Dashboard::getUrl())
        ->assertSuccessful()
        ->assertSee('EduFlow AI');
});

test('institution resolver keeps no cached model when configuration changes', function (): void {
    $institution = Organization::factory()->create();
    $resolver = app(InstallationInstitution::class);
    config(['eduflow.institution_id' => $institution->id]);

    expect($resolver->require()->id)->toBe($institution->id);

    config(['eduflow.institution_id' => $institution->id + 1]);

    expect($resolver->current())->toBeNull();
});
