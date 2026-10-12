<?php

use App\Enums\AssistanceStatus;
use App\Models\AssistanceRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

test('guests are redirected to the login page', function (): void {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('dashboard paginates assistance requests', function (): void {
    $user = User::factory()->create();

    AssistanceRequest::factory()->count(12)->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('requests.data', 10)
            ->where('requests.total', 12)
            ->where('requests.last_page', 2)
            ->where('requests.current_page', 1)
        );
});

test('dashboard filters requests by search term across subject and ticket number', function (): void {
    $user = User::factory()->create();

    $match = AssistanceRequest::factory()->create([
        'user_id' => $user->id,
        'subject' => 'Wifi keeps dropping in the engineering lab',
        'ticket_number' => 'AST-AAAAAA',
    ]);

    AssistanceRequest::factory()->create([
        'user_id' => $user->id,
        'subject' => 'Tuition receipt question',
        'ticket_number' => 'AST-BBBBBB',
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['search' => 'engineering']))
        ->assertInertia(fn ($page) => $page
            ->has('requests.data', 1)
            ->where('requests.data.0.id', $match->id)
            ->where('filters.search', 'engineering')
        );

    $this->actingAs($user)
        ->get(route('dashboard', ['search' => 'AST-BBBBBB']))
        ->assertInertia(fn ($page) => $page
            ->has('requests.data', 1)
            ->where('requests.data.0.subject', 'Tuition receipt question')
        );
});

test('dashboard filters requests by status bucket', function (): void {
    $user = User::factory()->create();

    AssistanceRequest::factory()->create([
        'user_id' => $user->id,
        'subject' => 'Still open',
    ]);
    AssistanceRequest::factory()->inProgress()->create([
        'user_id' => $user->id,
        'subject' => 'Being worked on',
    ]);
    AssistanceRequest::factory()->resolved()->create([
        'user_id' => $user->id,
        'subject' => 'All done',
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['status' => 'active']))
        ->assertInertia(fn ($page) => $page
            ->has('requests.data', 2)
            ->where('filters.status', 'active')
        );

    $this->actingAs($user)
        ->get(route('dashboard', ['status' => 'resolved']))
        ->assertInertia(fn ($page) => $page
            ->has('requests.data', 1)
            ->where('requests.data.0.subject', 'All done')
        );
});

test('dashboard falls back to the all filter for an unrecognised status', function (): void {
    $user = User::factory()->create();

    AssistanceRequest::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('dashboard', ['status' => 'not-a-real-status']))
        ->assertInertia(fn ($page) => $page
            ->has('requests.data', 1)
            ->where('filters.status', 'all')
        );
});

test('dashboard stats stay global while a filter is applied', function (): void {
    $user = User::factory()->create();

    AssistanceRequest::factory()->count(3)->create([
        'user_id' => $user->id,
        'subject' => 'Pending one',
    ]);
    AssistanceRequest::factory()->resolved()->create([
        'user_id' => $user->id,
        'subject' => 'Resolved one',
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['status' => 'active', 'search' => 'Pending']))
        ->assertInertia(fn ($page) => $page
            ->has('requests.data', 3)
            ->where('stats.activeRequests', 3)
            ->where('stats.resolvedRequests', 1)
            ->where('stats.totalRequests', 4)
        );
});

test('dashboard reports a null median resolution time when nothing is resolved', function (): void {
    $user = User::factory()->create();

    AssistanceRequest::factory()->count(2)->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('stats.medianResolutionMinutes', null)
        );
});

test('dashboard computes the median resolution time from resolved tickets only', function (): void {
    $user = User::factory()->create();
    $now = Carbon::parse('2026-01-15 12:00:00');

    // Durations of 30, 60 and 90 minutes -> median 60.
    foreach ([30, 60, 90] as $minutes) {
        AssistanceRequest::factory()->create([
            'user_id' => $user->id,
            'status' => AssistanceStatus::RESOLVED,
            'created_at' => $now->copy(),
            'resolved_at' => $now->copy()->addMinutes($minutes),
        ]);
    }

    // An unresolved ticket must not drag the median toward zero.
    AssistanceRequest::factory()->create([
        'user_id' => $user->id,
        'status' => AssistanceStatus::PENDING,
        'created_at' => $now->copy(),
        'resolved_at' => null,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('stats.medianResolutionMinutes', 60)
            ->where('stats.resolvedRequests', 3)
        );
});

test('dashboard averages the two middle values for an even sample', function (): void {
    $user = User::factory()->create();
    $now = Carbon::parse('2026-01-15 12:00:00');

    // Durations of 10 and 30 minutes -> median 20.
    foreach ([10, 30] as $minutes) {
        AssistanceRequest::factory()->create([
            'user_id' => $user->id,
            'status' => AssistanceStatus::RESOLVED,
            'created_at' => $now->copy(),
            'resolved_at' => $now->copy()->addMinutes($minutes),
        ]);
    }

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('stats.medianResolutionMinutes', 20)
        );
});

test('dashboard exposes quick resources with a null url until one is configured', function (): void {
    $user = User::factory()->create();

    config()->set('eduflow.resources', [
        [
            'title' => 'Academic Library',
            'description' => 'Journals and past exams.',
            'url' => null,
            'icon' => 'book-open',
        ],
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('quickResources', 1)
            ->where('quickResources.0.title', 'Academic Library')
            ->where('quickResources.0.url', null)
        );
});

test('dashboard exposes a configured quick resource url', function (): void {
    $user = User::factory()->create();

    config()->set('eduflow.resources', [
        [
            'title' => 'Academic Library',
            'description' => 'Journals and past exams.',
            'url' => 'https://library.example.test',
            'icon' => 'book-open',
        ],
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('quickResources', 1)
            ->where('quickResources.0.url', 'https://library.example.test')
        );
});

test('dashboard summarises notifications for the authenticated user only', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $user->notify(makeDatabaseNotification([
        'title' => 'Ticket resolved',
        'message' => 'Your Wi-Fi request was answered.',
    ]));
    $user->notify(makeDatabaseNotification(['title' => 'Second update']));
    $otherUser->notify(makeDatabaseNotification(['title' => 'Not yours']));

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('notifications.unreadCount', 2)
            ->has('notifications.recent', 2)
            ->where('notifications.recent', function (mixed $recent): bool {
                $titles = array_column(
                    $recent instanceof Collection ? $recent->all() : $recent,
                    'title',
                );

                return in_array('Ticket resolved', $titles, true)
                    && in_array('Second update', $titles, true)
                    && ! in_array('Not yours', $titles, true);
            })
        );
});

test('dashboard surfaces the notification body and read state', function (): void {
    $user = User::factory()->create();

    $user->notify(makeDatabaseNotification([
        'title' => 'Ticket resolved',
        'message' => 'Your Wi-Fi request was answered.',
    ]));

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('notifications.recent', 1)
            ->where('notifications.recent.0.title', 'Ticket resolved')
            ->where('notifications.recent.0.body', 'Your Wi-Fi request was answered.')
            ->where('notifications.recent.0.readAt', null)
        );
});

test('dashboard excludes read notifications from the unread count', function (): void {
    $user = User::factory()->create();

    $user->notify(makeDatabaseNotification(['title' => 'Already seen']));
    $user->notifications()->firstOrFail()->markAsRead();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('notifications.unreadCount', 0)
            ->has('notifications.recent', 1)
            ->where('notifications.recent.0.readAt', fn (mixed $value): bool => $value !== null)
        );
});
