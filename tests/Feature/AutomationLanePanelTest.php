<?php

declare(strict_types=1);

use App\DTOs\Money;
use App\Filament\Finance\Widgets\EvidenceTimelineWidget;
use App\Filament\Pages\PaymentReviews;
use App\Filament\Resources\PaymentSubmissions\PaymentSubmissionResource;
use App\Models\Organization;
use App\Models\PaymentSubmissionOutbox;
use App\Models\User;
use App\Services\Advisory\AutomationLaneStatus;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * The admin's window onto the automated submission lane.
 *
 * What matters here is not that a widget renders — it is what the counts refuse
 * to claim. "Verified settled" must mean the verifier said so, and "in flight"
 * must mean the rail owns the transfer and nobody may resend it. Those two
 * distinctions are the whole reason the lane has an `accepted` state at all.
 */

/**
 * A durably-owed submission, moved to the state under test.
 */
function laneBillFor(string $state, array $attributes = []): PaymentSubmissionOutbox
{
    [$entry] = executorEntry();

    $entry->update(array_merge(['state' => $state], $attributes));

    return $entry->fresh();
}

test('every state is placed in the bucket its meaning supports', function (): void {
    // The mapping *is* the assertion. An entry the rail has accepted must never
    // be counted as settled, and an unresolved one must never be counted as
    // owed work a worker will blindly retry.
    $expected = [
        'queued' => 'owed',
        'running' => 'unresolved',
        'accepted' => 'in_flight',
        'submitted' => 'in_flight',
        'unknown' => 'unresolved',
        'blocked' => 'unresolved',
        'failed' => 'unresolved',
        'completed' => 'verified',
    ];

    $buckets = [
        'owed' => PaymentSubmissionOutbox::DISPATCHABLE_STATES,
        'in_flight' => PaymentSubmissionOutbox::IN_FLIGHT_STATES,
        'unresolved' => ['running', 'unknown', 'blocked', 'failed'],
        'verified' => ['completed'],
    ];

    $seen = [];
    foreach ($buckets as $bucket => $states) {
        foreach ($states as $state) {
            expect(isset($seen[$state]))->toBeFalse("{$state} is counted in two buckets.");

            $seen[$state] = true;
        }
    }

    foreach ($expected as $state => $bucket) {
        expect(in_array($state, $buckets[$bucket], true))->toBeTrue("{$state} should count as {$bucket}");
        expect(isset($seen[$state]))->toBeTrue("{$state} is counted nowhere");
    }
});

test('an accepted entry is never counted as settled', function (): void {
    // The whole point of the in-flight state: the rail has taken the payment and
    // nobody knows yet whether the money moved. Counting it as verified would
    // report a payment this system has not proven.
    laneBillFor('accepted', ['provider_handle' => '116c7ce7-8f2f-53ac-8b06-f18aac151ce3']);

    expect(app(AutomationLaneStatus::class)->counts())->toBe([
        'owed' => 0,
        'in_flight' => 1,
        'unresolved' => 0,
        'verified' => 0,
    ]);
});

test('an in-flight entry is never dispatchable, because resending would pay twice', function (): void {
    // The rail already owns the transfer. Handing the same entry to a worker
    // again is a second submission of the same money.
    expect(PaymentSubmissionOutbox::DISPATCHABLE_STATES)->not->toContain('accepted')
        ->and(PaymentSubmissionOutbox::DISPATCHABLE_STATES)->not->toContain('submitted')
        ->and(PaymentSubmissionOutbox::IN_FLIGHT_STATES)->toEqual(['accepted', 'submitted'])
        ->and(PaymentSubmissionOutbox::OPEN_STATES)->toContain('accepted');
});

test('the automation resource exposes only list and view, never create or edit', function (): void {
    // A durable submission is written by the authorization workflow inside its
    // own transaction. An editor beside this table would be a path to the rail
    // one click from a dashboard.
    expect(array_keys(PaymentSubmissionResource::getPages()))->toBe(['index', 'view']);
});

test("an unresolved entry keeps the rail's own words rather than a shrug", function (): void {
    // "The rail refused this" and "we could not reach the rail" are different
    // investigations; a generic message once erased the difference between them.
    $entry = laneBillFor('unknown', ['last_error' => 'Circle CLI failed: 400 Invalid request body.']);

    expect($entry->last_error)->toContain('Invalid request body');
});

test('a completed entry names the reference that proved it', function (): void {
    $hash = '0x'.str_repeat('f', 64);
    $entry = laneBillFor('completed', ['result' => ['provider_reference' => $hash]]);

    expect($entry->result['provider_reference'])->toBe($hash);
});

test('admin can access payment submissions list and view pages', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));

    $entry = laneBillFor('accepted', [
        'provider_handle' => '116c7ce7-8f2f-53ac-8b06-f18aac151ce3',
        'last_error' => 'In flight observation.',
    ]);

    $listResponse = $this->actingAs($admin)->get(PaymentSubmissionResource::getUrl('index'));
    $listResponse->assertSuccessful();
    $listResponse->assertSee('Payment submissions');
    $listResponse->assertSee('Payment approvals');
    $listResponse->assertSee(PaymentReviews::getUrl(), false);
    $listResponse->assertSee('accepted by the rail');
    $listResponse->assertSee('Investigate unresolved attempts before any resend.');
    $listResponse->assertDontSee('none — no transfer in flight');
    $listResponse->assertDontSee('exists only once the transfer completed');
    $listResponse->assertSee(Money::formatExact($entry->snapshot['amount_base_units'], 'USDC'));

    $viewResponse = $this->actingAs($admin)->get(PaymentSubmissionResource::getUrl('view', ['record' => $entry]));
    $viewResponse->assertSuccessful();
    $viewResponse->assertSee('What the automation did, in order');
    $viewResponse->assertSee('116c7ce7-8f2f-53ac-8b06-f18aac151ce3');
    $viewResponse->assertSee(Money::formatExact($entry->snapshot['amount_base_units'], 'USDC'));
    $viewResponse->assertSee(Money::formatExact($entry->snapshot['max_fee_base_units'], 'USDC'));

    $approvalResponse = $this->actingAs($admin)->get(PaymentReviews::getUrl(panel: 'admin'));
    $approvalResponse->assertSuccessful();
    $approvalResponse->assertSee('Payment approvals');
    $approvalResponse->assertSee('Agent advice is not payment authority.');
});

test('unresolved submission detail never treats a missing provider handle as proof of no transfer', function (): void {
    $admin = User::factory()->create()->assignRole(Role::findOrCreate('super_admin', 'web'));
    $entry = laneBillFor('unknown', ['last_error' => 'Service returned error 400: Invalid request body']);
    $response = $this->actingAs($admin)->get(PaymentSubmissionResource::getUrl('view', ['record' => $entry]));
    $response->assertSuccessful()->assertSee('not recorded — outcome may be unknown')
        ->assertSee('Investigate the existing attempt before any resend; safe recovery is not yet proven.')
        ->assertDontSee('none — nothing in flight')->assertDontSee('No USDC moved.');
});

test('finance sees provider progress without opening admin or dispatching work', function (): void {
    $entry = laneBillFor('accepted', ['provider_handle' => '116c7ce7-8f2f-53ac-8b06-f18aac151ce3']);
    $reviewer = User::factory()->create()->assignRole(Role::findOrCreate('finance_officer', 'web'));
    Bus::fake();
    Filament::setCurrentPanel('finance');

    $this->actingAs($reviewer)->get(PaymentSubmissionResource::getUrl('index', panel: 'finance'))
        ->assertSuccessful()->assertSee('accepted by the rail')->assertSee('/finance/payment-reviews', false)
        ->assertSee($entry->intent->invoice->reference);
    $this->get(PaymentSubmissionResource::getUrl('view', ['record' => $entry], panel: 'finance'))
        ->assertSuccessful()->assertSee('116c7ce7-8f2f-53ac-8b06-f18aac151ce3')
        ->assertSee('This is in flight, not settled.');
    $this->get(PaymentReviews::getUrl(panel: 'finance'))
        ->assertSuccessful()->assertSee('/finance/payment-submissions', false);

    Bus::assertNothingDispatched();
    expect($entry->fresh()->state)->toBe('accepted')
        ->and(PaymentSubmissionResource::canCreate())->toBeFalse()
        ->and(PaymentSubmissionResource::canEdit($entry))->toBeFalse()
        ->and(PaymentSubmissionResource::canDelete($entry))->toBeFalse();
});

test('student and unverified finance account cannot read payment progress', function (): void {
    laneBillFor('accepted');
    Filament::setCurrentPanel('finance');
    $student = User::factory()->create()->assignRole(Role::findOrCreate('student', 'web'));
    $this->actingAs($student)->get(PaymentSubmissionResource::getUrl('index', panel: 'finance'))->assertForbidden();

    $unverified = User::factory()->unverified()->create()->assignRole(Role::findOrCreate('finance_officer', 'web'));
    $this->actingAs($unverified)->get(PaymentSubmissionResource::getUrl('index', panel: 'finance'))->assertForbidden();
});

test('finance evidence timeline shows stored submission progress instead of claiming no executor exists', function (): void {
    $entry = laneBillFor('accepted', ['stage' => 'awaiting_rail_completion']);
    $reviewer = User::factory()->create()->assignRole(Role::findOrCreate('finance_officer', 'web'));
    Filament::setCurrentPanel('finance');
    Bus::fake();

    Livewire::actingAs($reviewer)->test(EvidenceTimelineWidget::class)
        ->assertSee('accepted')->assertSee('awaiting_rail_completion')->assertSee('No verified settlement recorded.')
        ->assertDontSee('No executor is shipped');
    expect($entry->intent->submission->id)->toBe($entry->id);
    Bus::assertNothingDispatched();
});

test('finance progress never exposes submission from another installation', function (): void {
    $entry = laneBillFor('accepted');
    $other = Organization::factory()->create();
    if (! $other instanceof Organization) {
        throw new RuntimeException('Organization factory returned no organization.');
    }
    config(['eduflow.institution_id' => $other->id]);
    $reviewer = User::factory()->create()->assignRole(Role::findOrCreate('finance_officer', 'web'));
    Filament::setCurrentPanel('finance');
    $this->actingAs($reviewer);

    expect(PaymentSubmissionResource::getEloquentQuery()->whereKey($entry->id)->exists())->toBeFalse()
        ->and(PaymentSubmissionResource::canView($entry))->toBeFalse();
    $this->get(PaymentSubmissionResource::getUrl('view', ['record' => $entry], panel: 'finance'))->assertNotFound();
});
