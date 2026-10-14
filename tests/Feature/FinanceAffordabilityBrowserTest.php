<?php

declare(strict_types=1);

use App\Filament\Pages\TreasuryWallet;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Services\DefaultInstitutionPolicy;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Vite;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

/** @return array<string, mixed> */
function financeAffordabilityBrowserContext(): array
{
    $context = paymentAuthorizationContext(withFunding: false);
    config(['queue.connections.database.retry_after' => 360, 'lepton.arc.treasury' => $context['wallet']->address]);
    $template = DefaultInstitutionPolicy::template();
    $template['controls']['minimum_reserve'] = '10';
    pilotPublishedDefaultPolicy($context, $template);
    $arc = Mockery::mock(ArcNetworkGateway::class);
    $arc->shouldReceive('chainCode')->andReturn('ARC-TESTNET');
    $arc->shouldReceive('chainId')->andReturn(5042002);
    $arc->shouldReceive('addressExplorerUrl')->andReturn('https://example.test/treasury');
    mockExpectation($arc, 'rpc')->andReturnUsing(static fn (string $method): mixed => match ($method) {
        'eth_chainId' => '0x4cef52',
        'eth_getBlockByNumber' => ['number' => '0x64', 'hash' => '0x'.str_repeat('a', 64), 'timestamp' => '0x'.BigInteger::of(now()->timestamp - 1)->toBase(16)],
        'eth_getBalance' => '0x'.BigDecimal::of('69.9')->withPointMovedRight(18)->toBigInteger()->toBase(16),
        default => throw new RuntimeException('Unexpected read'),
    });
    app()->instance(ArcNetworkGateway::class, $arc);
    Filament::setCurrentPanel('finance');

    return $context;
}

test('finance sees bill gap exact observed balance and policy path without approving payment', function (): void {
    $this->withVite();
    Vite::useHotFile(storage_path('framework/testing-no-vite-hot'));
    $context = financeAffordabilityBrowserContext();
    $context['institution']->update(['name' => 'Northstar Learning Center']);
    $invoice = Invoice::query()->firstOrFail();
    $this->actingAs($context['reviewer']);
    $page = visit(TreasuryWallet::getUrl(panel: 'finance'))->assertSee('Bills & funding')
        ->assertSee('69.900000 USDC')->assertSee('59.900000 USDC')->assertSee('55.000002 USDC')
        ->assertSee('Ledger and observed wallet balance differ')->assertSee($invoice->reference)
        ->assertSee('Review policy')->assertSee('no payment approved')->assertNoJavaScriptErrors();
    $page->screenshot(fullPage: false, filename: 'finance-bills-funding-desktop');
    $page->click('Bill details')->assertSee('Bill funding evidence')->assertSee('Exact obligation')->assertSee('This screen cannot pay.')
        ->assertNoJavaScriptErrors();
    expect(Transaction::query()->count())->toBe(0);
})->group('browser');

test('bill funding view stays readable on mobile dark mode', function (): void {
    $this->withVite();
    Vite::useHotFile(storage_path('framework/testing-no-vite-hot'));
    $context = financeAffordabilityBrowserContext();
    $this->actingAs($context['reviewer']);
    $page = visit(TreasuryWallet::getUrl(panel: 'finance'))->on()->mobile()->inDarkMode()
        ->assertSee('After protections')->assertSee('Funding gap')->assertNoJavaScriptErrors();
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->screenshot(fullPage: false, filename: 'finance-bills-funding-mobile-dark');
})->group('browser');

test('missing policy displays unknown spendable balance and an explicit pre-protection shortfall', function (): void {
    $this->withVite();
    Vite::useHotFile(storage_path('framework/testing-no-vite-hot'));
    $context = paymentAuthorizationContext(withFunding: false);
    config(['lepton.arc.treasury' => $context['wallet']->address]);
    $arc = Mockery::mock(ArcNetworkGateway::class);
    $arc->shouldReceive('chainCode')->andReturn('ARC-TESTNET');
    $arc->shouldReceive('chainId')->andReturn(5042002);
    $arc->shouldReceive('addressExplorerUrl')->andReturn('https://example.test/treasury');
    mockExpectation($arc, 'rpc')->andReturnUsing(static fn (string $method): mixed => match ($method) {
        'eth_chainId' => '0x4cef52',
        'eth_getBlockByNumber' => ['number' => '0x64', 'hash' => '0x'.str_repeat('a', 64), 'timestamp' => '0x'.BigInteger::of(now()->timestamp - 1)->toBase(16)],
        'eth_getBalance' => '0x'.BigDecimal::of('5')->withPointMovedRight(18)->toBigInteger()->toBase(16),
        default => throw new RuntimeException('Unexpected read'),
    });
    app()->instance(ArcNetworkGateway::class, $arc);
    $this->actingAs($context['reviewer']);
    visit(TreasuryWallet::getUrl(panel: 'finance'))->assertSee('Even before protections')->assertSee('50.000002 USDC')
        ->assertSee('None published')->assertSee('Exact amount unavailable')->assertNoJavaScriptErrors();
})->group('browser');

test('finance overview dashboard renders affordability widget', function (): void {
    $this->withVite();
    Vite::useHotFile(storage_path('framework/testing-no-vite-hot'));
    $context = financeAffordabilityBrowserContext();
    $this->actingAs($context['reviewer']);
    $page = visit('/finance')->assertSee('Can we cover our bills?')
        ->assertSee('Observed Arc balance')
        ->assertSee('69.900000 USDC')
        ->assertSee('Bills & funding')
        ->assertNoJavaScriptErrors();
    $page->screenshot(fullPage: false, filename: 'finance-overview-dashboard');
})->group('browser');
