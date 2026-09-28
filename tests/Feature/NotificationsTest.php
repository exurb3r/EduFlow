<?php

use App\Models\User;

test('guests are redirected away from the notifications page', function (): void {
    $this->get(route('notifications.index'))->assertRedirect(route('login'));
});

test('notifications page renders an inertia page with the unread count', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('notifications')
            ->has('notifications.data', 0)
            ->where('unread_count', 0)
        );
});

test('notifications page only lists the authenticated user notifications', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $user->notify(makeDatabaseNotification(['title' => 'Mine']));
    $otherUser->notify(makeDatabaseNotification(['title' => 'Theirs']));

    $this->actingAs($user)
        ->get(route('notifications.index'))
        ->assertInertia(fn ($page) => $page
            ->has('notifications.data', 1)
            ->where('notifications.data.0.data', ['title' => 'Mine'])
            ->where('unread_count', 1)
        );
});

test('notifications page paginates', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 21) as $ignored) {
        $user->notify(makeDatabaseNotification(['title' => 'Update']));
    }

    $this->actingAs($user)
        ->get(route('notifications.index'))
        ->assertInertia(fn ($page) => $page
            ->has('notifications.data', 20)
            ->where('notifications.total', 21)
            ->where('notifications.last_page', 2)
        );
});

test('a user can mark one of their notifications as read', function (): void {
    $user = User::factory()->create();
    $user->notify(makeDatabaseNotification(['title' => 'Update']));
    $notification = $user->notifications()->firstOrFail();

    $this->actingAs($user)
        ->post(route('notifications.mark-as-read', $notification->id))
        ->assertOk();

    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('a user cannot mark another users notification as read', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherUser->notify(makeDatabaseNotification(['title' => 'Theirs']));
    $notification = $otherUser->notifications()->firstOrFail();

    $this->actingAs($user)
        ->post(route('notifications.mark-as-read', $notification->id))
        ->assertNotFound();

    expect($notification->fresh()->read_at)->toBeNull();
});

test('a user can mark all of their notifications as read', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $user->notify(makeDatabaseNotification(['title' => 'One']));
    $user->notify(makeDatabaseNotification(['title' => 'Two']));
    $otherUser->notify(makeDatabaseNotification(['title' => 'Theirs']));
    $otherNotification = $otherUser->notifications()->firstOrFail();

    $this->actingAs($user)
        ->post(route('notifications.mark-all-read'))
        ->assertOk();

    expect($user->unreadNotifications()->count())->toBe(0);
    expect($otherNotification->fresh()->read_at)->toBeNull();
});
