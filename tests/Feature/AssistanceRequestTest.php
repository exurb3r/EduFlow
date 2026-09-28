<?php

declare(strict_types=1);

use App\Enums\AssistanceCategory;
use App\Enums\AssistancePriority;
use App\Enums\AssistanceStatus;
use App\Models\AssistanceRequest;
use App\Models\User;

test('authenticated student can submit an assistance request', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('assistance-requests.store'), [
        'category' => AssistanceCategory::ACADEMIC->value,
        'priority' => AssistancePriority::HIGH->value,
        'subject' => 'Need help with mid-term project requirements',
        'description' => 'I am unable to access the course repository for the assignment.',
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    $this->assertDatabaseHas('assistance_requests', [
        'user_id' => $user->id,
        'category' => AssistanceCategory::ACADEMIC->value,
        'priority' => AssistancePriority::HIGH->value,
        'status' => AssistanceStatus::PENDING->value,
        'subject' => 'Need help with mid-term project requirements',
        'description' => 'I am unable to access the course repository for the assignment.',
    ]);

    $assistanceRequest = AssistanceRequest::first();
    expect($assistanceRequest)->not->toBeNull();
    expect($assistanceRequest->ticket_number)->toStartWith('AST-');
});

test('submitting assistance request requires valid fields', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('assistance-requests.store'), [
        'category' => '',
        'priority' => '',
        'subject' => '',
        'description' => '',
    ]);

    $response->assertSessionHasErrors(['category', 'priority', 'subject', 'description']);
    $this->assertDatabaseCount('assistance_requests', 0);
});

test('student dashboard displays assistance requests and stats', function (): void {
    $user = User::factory()->create();

    // Create 1 pending and 1 resolved request for this user
    AssistanceRequest::factory()->create([
        'user_id' => $user->id,
        'status' => AssistanceStatus::PENDING,
        'subject' => 'Active inquiry',
    ]);

    AssistanceRequest::factory()->resolved()->create([
        'user_id' => $user->id,
        'subject' => 'Solved inquiry',
    ]);

    // Create a request for another user (should not appear in this user's list)
    $otherUser = User::factory()->create();
    AssistanceRequest::factory()->create([
        'user_id' => $otherUser->id,
        'subject' => 'Other student inquiry',
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('dashboard')
        ->has('requests.data', 2)
        ->has('categories')
        ->has('priorities')
        ->where('stats.activeRequests', 1)
        ->where('stats.resolvedRequests', 1)
        ->where('stats.totalRequests', 2)
    );
});
