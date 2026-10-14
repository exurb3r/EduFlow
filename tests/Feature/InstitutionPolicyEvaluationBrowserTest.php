<?php

declare(strict_types=1);

use App\Actions\CaptureCollectionBatch;
use App\Jobs\EvaluateInstitutionPolicy;
use App\Models\InstitutionPolicyEvaluation;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InstitutionPolicyEvaluations;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

test('admin requests fresh allocation sees live progress citations and preserved run history', function (): void {
    $this->withVite();
    Vite::useHotFile(storage_path('framework/testing-no-vite-hot'));
    $context = paymentAuthorizationContext(withFunding: false);
    config(['queue.connections.database.retry_after' => 360]);
    pilotPublishedDefaultPolicy($context);
    Bus::fake();
    $this->actingAs($context['reviewer']);
    $page = visit('/finance/policy-evaluations?source_type=budget_snapshot&source_id=1')
        ->assertSee('Policy evaluations')->assertSee('Advice, not payment authority')
        ->press('Re-evaluate')->assertSee('Waiting for policy-assessments worker.');
    $run = InstitutionPolicyEvaluation::query()->sole();
    $run->checkpoint('classifying', 'Checking approved invoice against saved policy clauses.', ['state' => 'running', 'total_targets' => 3]);
    $page->assertSee('Checking approved invoice against saved policy clauses.')->assertSee('0 of 3 targets checked');
    $run->update(['state' => 'queued']);
    app()->call([(new EvaluateInstitutionPolicy($run->id)), 'handle']);
    $page->assertSee('Advisory result')->assertSee('Nothing was paid.')->assertSee('supplier-invoices')
        ->assertNoJavaScriptErrors()->screenshot(fullPage: false, filename: 'policy-evaluation-desktop');
    $page->press('Re-evaluate')->assertSee('Run #2');
    $old = $run->refresh()->result_digest;
    expect(InstitutionPolicyEvaluation::query()->count())->toBe(2)->and($run->refresh()->result_digest)->toBe($old)
        ->and(Transaction::query()->count())->toBe(0);
})->group('browser');

test('admin sees actionable empty state when no active policy or source is available', function (): void {
    $this->withVite();
    Vite::useHotFile(storage_path('framework/testing-no-vite-hot'));
    config(['eduflow.institution_id' => null]);
    Organization::factory()->create();
    $admin = User::factory()->create()->assignRole(Role::findOrCreate('admin', 'web'));
    $this->actingAs($admin);
    visit('/finance/policy-evaluations')->assertSee('No source evidence')->assertSee('No active published policy')
        ->assertDisabled('Re-evaluate')->assertSee('Institution policies')->assertNoJavaScriptErrors();
    expect(InstitutionPolicyEvaluation::query()->count())->toBe(0);
})->group('browser');

test('admin collected fee re-evaluation works on mobile dark mode without treating receipt as spend authority', function (): void {
    $this->withVite();
    Vite::useHotFile(storage_path('framework/testing-no-vite-hot'));
    $context = paymentAuthorizationContext(withFunding: false);
    config(['queue.connections.database.retry_after' => 360]);
    $published = pilotPublishedDefaultPolicy($context);
    Bus::fake();
    $batch = app(CaptureCollectionBatch::class)->handle($context['maker'], [
        'capture_key' => (string) Str::uuid(), 'source_stream' => 'cashier-fees', 'source_reference' => 'REG-TERM-DOWNPAYMENT',
        'source_document_digest' => hash('sha256', 'synthetic-fee-browser-record'), 'currency' => 'USDC',
        'received_amount' => '5', 'restricted_amount' => '0', 'collected_from' => now()->subDays(2)->toIso8601String(),
        'collected_until' => now()->subDay()->toIso8601String(), 'cash_evidence_reference' => 'Synthetic receipt check',
        'source_stream_disjoint' => true, 'received_not_forecast' => true,
    ]);
    $source = app(InstitutionPolicyEvaluations::class)->source($context['reviewer'], 'collection', $batch->id);
    $run = app(InstitutionPolicyEvaluations::class)->request($context['reviewer'], [
        'source_type' => 'collection', 'source_id' => $batch->id, 'source_digest' => $source['source_digest'],
        'expected_publication_digest' => $published['publication']->publication_digest, 'request_key' => (string) Str::uuid(),
        'reason' => 'Re-evaluate collected student fee.',
    ]);
    app()->call([(new EvaluateInstitutionPolicy($run->id)), 'handle']);
    $this->actingAs($context['reviewer']);
    $page = visit('/finance/policy-evaluations?source_type=collection&source_id='.$batch->id)->on()->mobile()->inDarkMode();
    $page->assertSee('Classification only.')->assertSee('Advisory result');
    $page->press('Pause updates');
    $page->assertNoJavaScriptErrors();
    expect($page->script('document.documentElement.classList.contains("dark")'))->toBeTrue();
    expect($page->script('getComputedStyle(document.body).backgroundColor'))->not->toBe('rgb(255, 255, 255)');
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->screenshot(fullPage: false, filename: 'policy-evaluation-mobile-dark');
    expect(Transaction::query()->count())->toBe(0);
})->group('browser');
