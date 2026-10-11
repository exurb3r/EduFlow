<?php

declare(strict_types=1);

use App\Actions\CaptureCollectionBatch;
use App\Models\CollectionBatch;
use App\Models\CollectionBatchReview;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Spatie\Permission\Models\Role;

/** @return array{cashier: User, reviewer: User} */
function collectionBrowserStaff(): array
{
    config(['eduflow.institution_id' => null, 'eduflow.background_finance.enabled' => false]);
    Storage::fake('local');
    Organization::factory()->create(['currency' => 'PHP']);
    $cashier = User::factory()->create(['name' => 'Synthetic cashier'])->assignRole(Role::findOrCreate('finance_officer', 'web'));
    $reviewer = User::factory()->create(['name' => 'Synthetic reviewer'])->assignRole(Role::findOrCreate('super_admin', 'web'));

    return ['cashier' => $cashier, 'reviewer' => $reviewer];
}

test('cashier can finish three-step collection entry after a completed upload in a browser', function (): void {
    $this->withVite();
    $staff = collectionBrowserStaff();
    $this->actingAs($staff['cashier']);
    $this->travelTo(now()->setDate(2026, 10, 11)->setTime(12, 0));
    /** Pest's embedded HTTP server does not parse multipart files. Livewire upload/storage is covered in CollectionsPageTest; start here with its signed completion reference. */
    Storage::fake(FileUploadConfiguration::disk());
    $file = UploadedFile::fake()->createWithContent('cashier-report.csv', file_get_contents(base_path('tests/Fixtures/collections/cashier-report.csv')));
    $path = FileUploadConfiguration::storeTemporaryFile($file, FileUploadConfiguration::disk());
    $reference = TemporaryUploadedFile::signPath(str_replace(FileUploadConfiguration::path('/'), '', $path));
    $page = visit('/finance/collections')->assertSee('Record fees received')->click('Record collection')
        ->assertSee('Receipt / report number')->assertPresent('.filepond--root');
    $page->select('[id="mountedActionSchema0.source_stream"]', 'cashier-fees')
        ->type('[id="mountedActionSchema0.source_reference"]', 'TEST-OR-2026-10-10');
    $page->script('async () => { await Livewire.find(document.querySelector(".fi-page").getAttribute("wire:id")).call("_finishUpload", "mountedActions.0.data.source_document", ['.json_encode($reference, JSON_THROW_ON_ERROR).'], false, false); }');
    $page->type('[id="mountedActionSchema0.source_reference"]', 'TEST-OR-2026-10-10');
    $page->press('Next')->assertSee('Total received')
        ->type('[id="mountedActionSchema0.received_amount"]', '100.01')
        ->type('[id="mountedActionSchema0.restricted_amount"]', '20.01');

    $page->click('[id="mountedActionSchema0.collected_from"]')->click('[role="option"]:visible:not(.fi-disabled):text-is("9")');
    $page->click('[id="mountedActionSchema0.collected_until"]')->click('[role="option"]:visible:not(.fi-disabled):text-is("10")');
    $page->press('Next')->assertSee('100.01 PHP')->assertSee('20.01 PHP')
        ->check('[id="mountedActionSchema0.source_stream_disjoint"]')
        ->check('[id="mountedActionSchema0.received_not_forecast"]')
        ->press('Save for review')->assertSee('Collection recorded')->assertSee('test-or-2026-10-10')->assertNoJavaScriptErrors();

    $batch = CollectionBatch::query()->sole();
    expect($batch->hasValidSnapshot())->toBeTrue()->and($batch->received_minor_units)->toBe(10001)->and($batch->restricted_minor_units)->toBe(2001)
        ->and($batch->evidence()['accounts_changed'])->toBeFalse()->and(CollectionBatchReview::query()->count())->toBe(0)
        ->and(Transaction::query()->count())->toBe(0);
    expect(Storage::disk('local')->exists($batch->snapshot['source_document']['path']))->toBeTrue();
})->group('browser');

test('collection entry stays usable on mobile with no page overflow', function (): void {
    $this->withVite();
    $staff = collectionBrowserStaff();
    $this->actingAs($staff['reviewer']);
    $page = visit('/admin/collections')->on()->mobile()->assertSee('Record fees received')->assertNoJavaScriptErrors();
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->click('Record collection')->assertSee('Receipt / report number')->assertNoJavaScriptErrors();
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
})->group('browser');

test('independent reviewer can inspect a retained collection report in a browser', function (): void {
    $this->withVite();
    $staff = collectionBrowserStaff();
    $file = UploadedFile::fake()->createWithContent('cashier-report.csv', file_get_contents(base_path('tests/Fixtures/collections/cashier-report.csv')));
    app(CaptureCollectionBatch::class)->handle($staff['cashier'], ['capture_key' => (string) Str::uuid(),
        'source_stream' => 'cashier-fees', 'source_reference' => 'TEST-OR-2026-10-10', 'cash_evidence_reference' => 'Synthetic closing register',
        'currency' => 'PHP', 'received_amount' => '100.01', 'restricted_amount' => '20.01',
        'collected_from' => now()->subDays(2)->toIso8601String(), 'collected_until' => now()->subDay()->toIso8601String(),
        'source_stream_disjoint' => true, 'received_not_forecast' => true], $file);
    $this->actingAs($staff['reviewer']);
    $page = visit('/admin/collections')->assertSee('test-or-2026-10-10')->click('Details')
        ->assertSee('Download receipt / report')->assertSee('cashier-report.csv')->assertSee('100.01 PHP')->assertNoJavaScriptErrors();
    $page->click('.fi-modal-close-btn:visible')->click('Review')->assertSee('What did you check?')
        ->assertSee('Your review')->assertNoJavaScriptErrors();
    expect(CollectionBatchReview::query()->count())->toBe(0)->and(Transaction::query()->count())->toBe(0);
})->group('browser');
