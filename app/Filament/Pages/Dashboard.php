<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\AdoptionActivityChart;
use App\Filament\Widgets\AgentActivityFeedWidget;
use App\Filament\Widgets\InstallationReadinessWidget;
use App\Filament\Widgets\LeptonNetworkWidget;
use App\Filament\Widgets\TreasuryOverviewWidget;
use App\Models\AiProvider;
use App\Models\Organization;
use App\Models\Student;
use App\Models\User;
use App\Services\InstallationInstitution;
use App\Services\LeptonTreasuryService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\Grid as InfoGrid;
use Filament\Schemas\Components\Section as InfoSection;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Installation and operations console for the super administrator.
 *
 * This panel governs the instance — identity, rail binding, staff, providers,
 * settings — and deliberately does not move money. The cashier, accounting
 * and supervisor workflow lives in the finance panel, so the two never blur
 * into one surface that looks equally safe to click from.
 */
class Dashboard extends BaseDashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?string $navigationLabel = 'Overview';

    protected static ?string $title = 'Installation & operations';

    #[\Override]
    public function getSubheading(): ?string
    {
        $institution = app(InstallationInstitution::class)->current();

        if ($institution === null) {
            return 'Institution context unavailable. Run php artisan app:install; data is never selected by row order.';
        }

        $status = app(LeptonTreasuryService::class)->status($institution->primaryWallet());

        // Deliberately no raw finance-panel URL: a full https:// string in the
        // subheading reads as a mistake, and the "Finance console" header action
        // already links there.
        //
        // The chain id and driver are omitted because the Settlement rail tile
        // states both, and repeating a 34-character summary made this line wrap
        // mid-token into "ARC- / TESTNET 5042002".
        return sprintf(
            '%s · %s%s',
            $institution->name,
            strtoupper((string) $status['chain']),
            $status['is_fake'] ? ' · FAKE DRIVER' : '',
        );
    }

    /**
     * Explicit rather than inherited: without this the panel renders every
     * registered widget, so an unrelated plugin widget can appear beside
     * settlement figures that operators will read as authoritative.
     *
     * AccountWidget is deliberately absent: it rendered a "Welcome / sign out"
     * card that only repeated the header and pushed the operations bands below
     * the fold. Sign-out belongs in the topbar user menu.
     *
     * @return array<class-string<Widget>>
     */
    #[\Override]
    public function getWidgets(): array
    {
        return [
            InstallationReadinessWidget::class,
            AdoptionActivityChart::class,
            LeptonNetworkWidget::class,
            TreasuryOverviewWidget::class,
            AgentActivityFeedWidget::class,
        ];
    }

    /** @return int|array<string, int|null> */
    #[\Override]
    public function getColumns(): int|array
    {
        return ['default' => 1, 'lg' => 2, 'xl' => 3];
    }

    /**
     * The legacy autonomous cycle writes a ledger row without touching the
     * chain, which is the fabricated-receipt pattern the pilot contract
     * forbids. Recorded receipts belong in cashier collection batches, which
     * require independent review before they can fund anything.
     */
    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('createUser')
                ->label('Add user')
                ->icon(Heroicon::UserPlus)
                ->color('primary')
                ->modalHeading('Create system or staff user')
                ->modalDescription('Provisions an operator or student account with assigned roles and credentials.')
                ->schema([
                    TextInput::make('name')
                        ->label('Full name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('email')
                        ->label('Email address')
                        ->email()
                        ->required()
                        ->unique('users', 'email'),
                    Select::make('role')
                        ->label('Initial role')
                        ->options([
                            'admin' => 'Administrator',
                            'finance_officer' => 'Finance officer',
                            'user' => 'Standard user',
                        ])
                        ->default('finance_officer')
                        ->required(),
                    TextInput::make('password')
                        ->label('Password')
                        ->password()
                        ->revealable()
                        ->required()
                        ->minLength(8),
                ])
                ->action(function (array $data): void {
                    $user = User::query()->create([
                        'name' => $data['name'],
                        'email' => $data['email'],
                        'password' => Hash::make((string) $data['password']),
                        'email_verified_at' => now(),
                    ]);

                    if (Role::query()->where('name', $data['role'])->exists()) {
                        $user->assignRole($data['role']);
                    }

                    Notification::make()
                        ->title('User created successfully')
                        ->body("User {$user->email} has been created and assigned the {$data['role']} role.")
                        ->success()
                        ->send();
                }),

            Action::make('systemDiagnostics')
                ->label('Diagnostics')
                ->icon(Heroicon::CpuChip)
                ->color('gray')
                ->modalHeading('System health & infrastructure status')
                ->modalDescription('Real-time operational status of infrastructure components.')
                ->infolist([
                    InfoSection::make('Environment & Runtime')
                        ->schema([
                            InfoGrid::make(3)->schema([
                                TextEntry::make('php_version')
                                    ->label('PHP Version')
                                    ->badge()
                                    ->color('info')
                                    ->state(fn (): string => PHP_VERSION),
                                TextEntry::make('laravel_version')
                                    ->label('Laravel Framework')
                                    ->badge()
                                    ->color('info')
                                    ->state(fn (): string => app()->version()),
                                TextEntry::make('environment')
                                    ->label('Environment')
                                    ->badge()
                                    ->color(app()->isProduction() ? 'success' : 'warning')
                                    ->state(fn (): string => app()->environment()),
                            ]),
                        ]),
                    InfoSection::make('Services & Storage')
                        ->schema([
                            InfoGrid::make(3)->schema([
                                TextEntry::make('database_status')
                                    ->label('Database Connection')
                                    ->badge()
                                    ->color('success')
                                    ->state(function (): string {
                                        try {
                                            DB::connection()->getPdo();

                                            return 'Connected ('.config('database.default').')';
                                        } catch (\Throwable $e) {
                                            return 'Disconnected: '.$e->getMessage();
                                        }
                                    }),
                                TextEntry::make('queue_status')
                                    ->label('Queue Driver')
                                    ->badge()
                                    ->color('info')
                                    ->state(fn (): string => (string) config('queue.default')),
                                TextEntry::make('ai_providers_count')
                                    ->label('Active AI Providers')
                                    ->badge()
                                    ->color(AiProvider::query()->where('is_active', true)->exists() ? 'success' : 'gray')
                                    ->state(fn (): string => (string) AiProvider::query()->where('is_active', true)->count()),
                            ]),
                        ]),
                    InfoSection::make('Installation Boundary')
                        ->schema([
                            InfoGrid::make(2)->schema([
                                TextEntry::make('current_institution')
                                    ->label('Institution Context')
                                    ->state(function (): string {
                                        $institution = app(InstallationInstitution::class)->current();

                                        return $institution instanceof Organization ? $institution->name : 'Not configured';
                                    }),
                                TextEntry::make('students_enrolled')
                                    ->label('Enrolled Students')
                                    ->state(fn (): string => (string) Student::query()->count()),
                            ]),
                        ]),
                ])
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close'),

            // "AI intelligence" expanded to "Artificial Intelligence
            // intelligence", which says nothing. "Advisory AI" names the mode
            // instead and matches the Advisory AI readiness stat below.
            Action::make('aiIntelligence')
                ->label('Advisory AI')
                ->icon(Heroicon::Sparkles)
                ->color('gray')
                ->tooltip('Ask the advisory assistant. It can read and explain, but never moves money.')
                ->url(fn (): string => AdminAiChat::getUrl()),

            // Navigation, not a warning: colouring this amber made an ordinary
            // panel switcher read as a caution.
            Action::make('financeConsole')
                ->label('Finance console')
                ->icon(Heroicon::Banknotes)
                ->color('gray')
                ->url(fn (): string => Filament::getPanel('finance')->getUrl()),

            Action::make('clearCache')
                ->label('Purge cache')
                ->icon(Heroicon::ArrowPath)
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Purge application cache?')
                ->modalDescription('Flushes the system configuration, route and compiled template caches.')
                ->action(function (): void {
                    Artisan::call('cache:clear');
                    Artisan::call('view:clear');

                    Notification::make()
                        ->title('System caches purged')
                        ->body('Configuration and view caches have been cleared.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
