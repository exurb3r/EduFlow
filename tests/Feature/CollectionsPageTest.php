<?php

declare(strict_types=1);

use App\Actions\CaptureCollectionBatch;
use App\Actions\ReviewCollectionBatch;
use App\Filament\Pages\Collections;
use App\Models\CollectionBatch;
use App\Models\CollectionBatchReview;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\Transaction;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\GenerateSignedUploadUrl;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * Narrow a Livewire test handle to the page it booted.
 *
 * Livewire types the booted component as the base `Component`, so page state
 * and computed accessors are invisible until the instance is named.
 */
function collectionsPage(Testable $page): Collections
{
    $instance = $page->instance();

    if (! $instance instanceof Collections) {
        throw new RuntimeException('The Livewire test did not boot the collections page.');
    }

    return $instance;
}

/**
 * The label a cashier sees for a field inside the mounted action schema.
 *
 * The capture wizard holds form fields and the detail drawers hold infolist
 * entries, and both are what the cashier reads; the schema itself reports them
 * only as the base component type.
 */
function collectionSchemaLabel(Schema $schema, string $statePath): ?string
{
    $component = $schema->getComponentByStatePath($statePath);

    if ($component instanceof TextInput || $component instanceof FileUpload || $component instanceof TextEntry) {
        return $component->getLabel();
    }

    throw new RuntimeException('Expected a labelled field at '.$statePath.'.');
}

/** The single collection batch under test, named rather than inferred. */
function soleCollectionBatch(): CollectionBatch
{
    return CollectionBatch::query()->sole();
}

/** The single review recorded against a collection. */
function soleCollectionReview(): CollectionBatchReview
{
    return CollectionBatchReview::query()->sole();
}

/** The private local disk the captured receipt is stored on. */
function collectionEvidenceDisk(): FilesystemAdapter
{
    $disk = Storage::disk('local');

    if (! $disk instanceof FilesystemAdapter) {
        throw new RuntimeException('Collection evidence must live on a local filesystem disk.');
    }

    return $disk;
}

/** @return array{institution: Organization, cashier: User, reviewer: User} */
function cashierCollectionContext(string $panel = 'finance'): array
{
    config(['eduflow.institution_id' => null, 'eduflow.background_finance.enabled' => false]);
    Storage::fake('local');
    app()->instance(ArcNetworkGateway::class, Mockery::mock(ArcNetworkGateway::class));
    app()->instance(WalletGateway::class, Mockery::mock(WalletGateway::class));
    $institution = Organization::factory()->create(['currency' => 'PHP']);

    if (! $institution instanceof Organization) {
        throw new RuntimeException('The organization factory returned something else.');
    }

    $cashier = User::factory()->create()->assignRole(Role::findOrCreate('finance_officer', 'web'));
    $reviewer = User::factory()->create()->assignRole(Role::findOrCreate('super_admin', 'web'));
    Filament::setCurrentPanel(Filament::getPanel($panel));

    return ['institution' => $institution, 'cashier' => $cashier, 'reviewer' => $reviewer];
}

/** @return array<string, mixed> */
function cashierCollectionInput(): array
{
    return ['source_stream' => 'cashier-fees', 'source_reference' => 'OR-001234',
        'source_document' => UploadedFile::fake()->createWithContent('cashier-report.csv', "receipt,received\nOR-001234,100.01\n"),
        'cash_evidence_source' => 'uploaded_document', 'currency' => 'PHP', 'received_amount' => '100.01', 'restricted_amount' => '20.01',
        'collected_from' => now()->subDays(2)->toIso8601String(), 'collected_until' => now()->subDay()->toIso8601String(),
        'source_stream_disjoint' => true, 'received_not_forecast' => true];
}

/** @param array<string, mixed>|null $input */
function captureCashierDocument(User $cashier, ?array $input = null): CollectionBatch
{
    $input ??= cashierCollectionInput();

    return app(CaptureCollectionBatch::class)->handle($cashier, $input + ['capture_key' => (string) Str::uuid(),
        'cash_evidence_reference' => 'Uploaded cashier closing report'], $input['source_document']);
}

test('cashier collections screen is available in both panels with plain entry labels', function (string $panel): void {
    $c = cashierCollectionContext($panel);
    actingAs($panel === 'admin' ? $c['reviewer'] : $c['cashier']);
    expect(Filament::getPanel($panel)->getPages())->toContain(Collections::class);
    get(Collections::getUrl(panel: $panel))->assertSuccessful()->assertSee('Record fees received')->assertSee('Collection history');
    $page = Livewire::test(Collections::class);
    $page->__call('mountAction', ['captureCollectionBatch']);
    $page->assertSee('Payment channel')->assertDontSee('Capture key (UUID)')->assertDontSee('Source document SHA-256')->assertDontSee('ISO 8601');
    $instance = collectionsPage($page);
    $schema = $instance->getSchema($instance->getMountedActionSchemaName());
    expect(collectionSchemaLabel($schema, 'source_reference'))->toBe('Receipt / report number')
        ->and(collectionSchemaLabel($schema, 'source_document'))->toBe('Receipt or collection report')
        ->and($schema->getComponentByStatePath('source_document_digest'))->toBeNull()
        ->and($schema->getComponentByStatePath('capture_key'))->toBeNull();
    expect(Str::isUuid($instance->captureKey))->toBeTrue()
        ->and(CollectionBatch::query()->count())->toBe(0)->and(Transaction::query()->count())->toBe(0);
})->with(['finance', 'admin']);

test('cashier entry derives identity and document fingerprint and retains private proof', function (): void {
    $c = cashierCollectionContext();
    actingAs($c['cashier']);
    $input = cashierCollectionInput();
    $expectedDigest = hash('sha256', $input['source_document']->getContent());
    $page = Livewire::test(Collections::class);
    $page->__call('callAction', ['captureCollectionBatch', $input]);
    $page->__call('assertHasNoFormErrors', []);
    $batch = soleCollectionBatch();
    $document = $batch->snapshot['source_document'];
    expect($batch->hasValidSnapshot())->toBeTrue()->and($batch->capture_key)->toBe(collectionsPage($page)->captureKey)
        ->and($batch->source_document_digest)->toBe($expectedDigest)->and($batch->source_reference)->toBe('or-001234')
        ->and($batch->received_minor_units)->toBe(10001)->and($batch->restricted_minor_units)->toBe(2001)
        ->and($batch->snapshot['cash_evidence_reference'])->toBe('Uploaded receipt/report: or-001234')
        ->and($document['name'])->toBe('cashier-report.csv')->and(collectionEvidenceDisk()->visibility($document['path']))->toBe('private')
        ->and($batch->evidence()['bank_balance_verified'])->toBeFalse()->and($batch->evidence()['can_execute'])->toBeFalse()
        ->and(CollectionBatchReview::query()->count())->toBe(0)->and(Transaction::query()->count())->toBe(0);
    collectionEvidenceDisk()->assertExists($document['path']);
});

test('signed collection upload HTTP request reaches private immutable capture without a client digest', function (): void {
    $c = cashierCollectionContext();
    actingAs($c['cashier']);
    Storage::fake(FileUploadConfiguration::disk());
    $input = cashierCollectionInput();
    $upload = post(app(GenerateSignedUploadUrl::class)->forLocal(), ['files' => [$input['source_document']]])
        ->assertSuccessful();
    $page = Livewire::test(Collections::class);
    $page->__call('mountAction', ['captureCollectionBatch']);
    $page->__call('fillForm', [array_diff_key($input, ['source_document' => null])]);
    $page->call('_finishUpload', 'mountedActions.0.data.source_document', $upload->json('paths'), false, false);
    $page->__call('callMountedAction', []);
    $page->__call('assertHasNoFormErrors', []);
    $batch = soleCollectionBatch();
    expect($batch->hasValidSnapshot())->toBeTrue()
        ->and($batch->source_document_digest)->toBe(hash('sha256', $input['source_document']->getContent()));
    collectionEvidenceDisk()->assertExists($batch->snapshot['source_document']['path']);
});

test('cashier entry requires an actual upload and does not accept arbitrary storage paths', function (mixed $document): void {
    $c = cashierCollectionContext();
    actingAs($c['cashier']);
    $page = Livewire::test(Collections::class);
    $page->__call('callAction', ['captureCollectionBatch', array_replace(cashierCollectionInput(), ['source_document' => $document])]);
    $page->__call('assertHasFormErrors', [['source_document']]);
    expect(CollectionBatch::query()->count())->toBe(0);
})->with([null, [['collection-evidence/another-institution/receipt.pdf']]]);

test('cashier entry keeps received-funds confirmation and no-double-counting attestations required', function (string $field): void {
    $c = cashierCollectionContext();
    actingAs($c['cashier']);
    $page = Livewire::test(Collections::class);
    $page->__call('callAction', ['captureCollectionBatch', array_replace(cashierCollectionInput(), [$field => false])]);
    $page->__call('assertHasFormErrors', [[$field]]);
    expect(CollectionBatch::query()->count())->toBe(0);
})->with(['source_stream_disjoint', 'received_not_forecast']);

test('cashier entry requires separate received-funds reference only when selected', function (): void {
    $c = cashierCollectionContext();
    actingAs($c['cashier']);
    $page = Livewire::test(Collections::class);
    $input = array_replace(cashierCollectionInput(), ['cash_evidence_source' => 'separate_record']);
    $page->__call('callAction', ['captureCollectionBatch', $input]);
    $page->__call('assertHasFormErrors', [['cash_evidence_reference']]);
    $page->__call('fillForm', [['cash_evidence_reference' => 'Bank statement credit OR-001234']]);
    $page->__call('callMountedAction', []);
    $page->__call('assertHasNoFormErrors', []);
    expect(CollectionBatch::query()->sole()->snapshot['cash_evidence_reference'])->toBe('Bank statement credit OR-001234');
});

test('cashier duplicate upload cannot count again or store a second retained file', function (): void {
    $c = cashierCollectionContext();
    $batch = captureCashierDocument($c['cashier']);
    $input = array_replace(cashierCollectionInput(), ['source_reference' => 'OR-999999', 'source_stream' => 'digital-wallet-fees']);
    expect(fn (): CollectionBatch => captureCashierDocument($c['cashier'], $input))->toThrow(ValidationException::class)
        ->and(CollectionBatch::query()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles('collection-evidence'))->toBe([$batch->snapshot['source_document']['path']]);
});

test('cashier upload retry keeps the same immutable record and ignores a supplied fingerprint', function (): void {
    $c = cashierCollectionContext();
    $input = cashierCollectionInput() + ['capture_key' => (string) Str::uuid(), 'cash_evidence_reference' => 'Original cashier report',
        'source_document_digest' => str_repeat('0', 64)];
    $capture = app(CaptureCollectionBatch::class);
    $first = $capture->handle($c['cashier'], $input, $input['source_document']);
    $retry = $capture->handle($c['cashier'], $input, $input['source_document']);
    expect($retry->id)->toBe($first->id)->and($first->source_document_digest)->toBe(hash('sha256', $input['source_document']->getContent()))
        ->and(CollectionBatch::query()->count())->toBe(1)->and(Storage::disk('local')->allFiles('collection-evidence'))->toHaveCount(1);
});

test('cashier entry reports invalid exact amounts at their input fields', function (string $field, string $amount): void {
    $c = cashierCollectionContext();
    actingAs($c['cashier']);
    $page = Livewire::test(Collections::class);
    $page->__call('callAction', ['captureCollectionBatch', array_replace(cashierCollectionInput(), [$field => $amount])]);
    $page->__call('assertHasFormErrors', [[$field]]);
    expect(CollectionBatch::query()->count())->toBe(0);
})->with([['received_amount', '1,500.00'], ['received_amount', '1.001'], ['restricted_amount', '-1']]);

test('cashier date picker stores UTC identity from local time without losing an offset', function (): void {
    $originalTimezone = date_default_timezone_get();
    $originalConfig = config('app.timezone');
    try {
        config(['app.timezone' => 'Asia/Manila']);
        date_default_timezone_set('Asia/Manila');
        $c = cashierCollectionContext();
        actingAs($c['cashier']);
        $input = array_replace(cashierCollectionInput(), ['collected_from' => '2026-10-08T08:00:00+08:00', 'collected_until' => '2026-10-08T17:00:00+08:00']);
        $page = Livewire::test(Collections::class);
        $page->__call('callAction', ['captureCollectionBatch', $input]);
        $page->__call('assertHasNoFormErrors', []);
        $batch = CollectionBatch::query()->sole();
        expect($batch->fresh()->hasValidSnapshot())->toBeTrue()->and($batch->snapshot['collected_from'])->toBe('2026-10-08T00:00:00+00:00')
            ->and($batch->snapshot['collected_until'])->toBe('2026-10-08T09:00:00+00:00');
    } finally {
        config(['app.timezone' => $originalConfig]);
        date_default_timezone_set($originalTimezone);
    }
});

test('cashier upload validation refuses unsafe types empty files and oversized proof', function (string $case): void {
    $c = cashierCollectionContext();
    $input = cashierCollectionInput();
    $input['source_document'] = match ($case) {
        'type' => UploadedFile::fake()->createWithContent('receipt.html', '<script>alert(1)</script>'),
        'empty' => UploadedFile::fake()->createWithContent('receipt.csv', ''),
        'size' => UploadedFile::fake()->create('receipt.pdf', 10241, 'application/pdf'),
        default => throw new LogicException('Unknown collection upload case.'),
    };
    expect(fn (): CollectionBatch => captureCashierDocument($c['cashier'], $input))->toThrow(ValidationException::class)
        ->and(CollectionBatch::query()->count())->toBe(0)->and(Storage::disk('local')->allFiles('collection-evidence'))->toBe([]);
})->with(['type', 'empty', 'size']);

test('retained collection proof requires staff authorization and intact bytes', function (): void {
    $c = cashierCollectionContext();
    $batch = captureCashierDocument($c['cashier']);
    $url = route('finance.collection-batches.document', $batch);
    actingAs(User::factory()->create());
    get($url)->assertForbidden();
    actingAs($c['reviewer']);
    get($url)->assertSuccessful()->assertDownload('cashier-report.csv')->assertHeader('X-Content-Type-Options', 'nosniff');
    Storage::disk('local')->put($batch->snapshot['source_document']['path'], 'changed document');
    get($url)->assertStatus(409);
    expect(Transaction::query()->count())->toBe(0);
});

test('collection document download refuses metadata path injection even with a recomputed digest', function (): void {
    $c = cashierCollectionContext();
    $batch = captureCashierDocument($c['cashier']);
    $snapshot = $batch->snapshot;
    $snapshot['source_document']['path'] = '../unrelated-private-file';
    DB::table('collection_batches')->where('id', $batch->id)->update(['snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
        'snapshot_digest' => PaymentIntent::digest($snapshot)]);
    actingAs($c['reviewer']);
    get(route('finance.collection-batches.document', $batch))->assertStatus(409);
});

test('private collection proof is unavailable outside the selected institution', function (): void {
    $c = cashierCollectionContext();
    $batch = captureCashierDocument($c['cashier']);
    $other = Organization::factory()->create();

    if (! $other instanceof Organization) {
        throw new RuntimeException('The organization factory returned something else.');
    }

    config(['eduflow.institution_id' => $other->id]);
    actingAs($c['reviewer']);
    get(route('finance.collection-batches.document', $batch))->assertForbidden();
});

test('collection details expose proof download and reviewer can save an independent record review', function (): void {
    $c = cashierCollectionContext('admin');
    $batch = captureCashierDocument($c['cashier']);
    actingAs($c['reviewer']);
    $page = Livewire::test(Collections::class);
    $page->__call('mountAction', [TestAction::make('viewCollectionBatch')->table($batch)]);
    $modalHtml = implode('', $page->effects['partials'] ?? []);
    expect($modalHtml)->toContain('Download receipt / report', '100.01 PHP', 'cashier-report.csv');
    $page->__call('unmountAction', []);
    $page->__call('callAction', [TestAction::make('reviewCollectionBatch')->table($batch), ['decision' => 'approve_receipts',
        'verification_reference' => 'Original cashier register page 12', 'reason' => 'Total and restrictions independently checked.', 'attestation' => true]]);
    $page->__call('assertHasNoFormErrors', []);
    expect(soleCollectionReview()->reviewed_by)->toBe($c['reviewer']->id)
        ->and($batch->evidence()['receipts_reviewed'])->toBeTrue()->and(Transaction::query()->count())->toBe(0);
});

test('cashier review stays independent and overview counts records not money', function (): void {
    $c = cashierCollectionContext();
    $batch = captureCashierDocument($c['cashier']);
    actingAs($c['cashier']);
    $page = Livewire::test(Collections::class);
    $page->__call('assertActionHidden', [TestAction::make('reviewCollectionBatch')->table($batch)]);
    expect(collectionsPage($page)->collectionOverview())->toBe(['awaiting' => 1, 'approved' => 0, 'held_or_rejected' => 0]);
    app(ReviewCollectionBatch::class)->handle($c['reviewer'], $batch, $batch->snapshot_digest, 'approve_receipts', 'Checked original register', 'Totals and restrictions match.');
    actingAs($c['reviewer']);
    expect(collectionsPage(Livewire::test(Collections::class))->collectionOverview())->toBe(['awaiting' => 0, 'approved' => 1, 'held_or_rejected' => 0])
        ->and(Transaction::query()->count())->toBe(0);
});

test('cashier entry preserves exact six-decimal USDC without rounded table or detail amounts', function (): void {
    $c = cashierCollectionContext();
    actingAs($c['cashier']);
    $input = array_replace(cashierCollectionInput(), ['currency' => 'USDC', 'received_amount' => '0.000003', 'restricted_amount' => '0.000001']);
    $page = Livewire::test(Collections::class);
    $page->__call('callAction', ['captureCollectionBatch', $input]);
    $page->__call('assertHasNoFormErrors', []);
    $page->assertSee('0.000003 USDC')->assertSee('0.000001 USDC');
    expect(soleCollectionBatch()->received_minor_units)->toBe(3);
});

test('legacy collection records remain readable without claiming retained source files', function (): void {
    $c = cashierCollectionContext();
    $input = cashierCollectionInput() + ['capture_key' => (string) Str::uuid(), 'cash_evidence_reference' => 'Original register page 12',
        'source_document_digest' => hash('sha256', 'original proof held outside EduFlow')];
    $batch = app(CaptureCollectionBatch::class)->handle($c['cashier'], $input);
    actingAs($c['reviewer']);
    $page = Livewire::test(Collections::class);
    $page->__call('mountAction', [TestAction::make('viewCollectionBatch')->table($batch)]);
    expect(implode('', $page->effects['partials'] ?? []))->toContain('No uploaded file')->not->toContain('Download receipt / report');
    get(route('finance.collection-batches.document', $batch))->assertNotFound();
});
