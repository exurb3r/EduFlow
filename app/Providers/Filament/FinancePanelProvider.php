<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Finance\Widgets\ApprovalInboxWidget;
use App\Filament\Finance\Widgets\ArcSettlementWidget;
use App\Filament\Finance\Widgets\AutonomyLaneWidget;
use App\Filament\Finance\Widgets\BudgetCapacityWidget;
use App\Filament\Finance\Widgets\BudgetHeadroomChart;
use App\Filament\Finance\Widgets\CollectionsOverviewWidget;
use App\Filament\Finance\Widgets\CollectionsTrendChart;
use App\Filament\Finance\Widgets\EvidenceTimelineWidget;
use App\Filament\Finance\Widgets\OperationsHealthWidget;
use App\Filament\Finance\Widgets\PaymentDecisionChart;
use App\Filament\Finance\Widgets\WorkflowRunStateChart;
use App\Filament\Finance\Widgets\WorkflowRunWidget;
use App\Filament\Pages\Collections;
use App\Filament\Pages\FinanceDashboard;
use App\Filament\Pages\FinanceSupervisor;
use App\Filament\Pages\PaymentReviews;
use App\Filament\Resources\AssistanceRequests\AssistanceRequestResource;
use App\Filament\Resources\Students\StudentResource;
use App\Filament\Resources\TuitionAccounts\TuitionAccountResource;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class FinancePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('finance')
            ->path('finance')
            ->brandName(config('app.name').' Finance')
            ->viteTheme('resources/css/filament/finance/theme.css')
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->login()
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable(),
                EmailAuthentication::make(),
            ])
            ->colors([
                'primary' => Color::Amber,
            ])
            ->navigationGroups([
                NavigationGroup::make('Financial Operations')
                    ->collapsible(false),
                NavigationGroup::make('Student Services')
                    ->collapsed(),
            ])
            ->pages([
                FinanceDashboard::class,
                FinanceSupervisor::class,
                Collections::class,
                PaymentReviews::class,
            ])
            ->widgets([
                ApprovalInboxWidget::class,
                CollectionsOverviewWidget::class,
                CollectionsTrendChart::class,
                BudgetCapacityWidget::class,
                BudgetHeadroomChart::class,
                ArcSettlementWidget::class,
                WorkflowRunWidget::class,
                WorkflowRunStateChart::class,
                AutonomyLaneWidget::class,
                EvidenceTimelineWidget::class,
                PaymentDecisionChart::class,
                OperationsHealthWidget::class,
            ])
            ->resources([
                StudentResource::class,
                TuitionAccountResource::class,
                AssistanceRequestResource::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ], isPersistent: true);
    }
}
