<?php

declare(strict_types=1);

use App\Models\AssistanceRequest;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->user->assignRole(Role::findOrCreate('user', 'web'));
});

/**
 * The dashboard is the one non-student landing page, and it is fed real
 * props (stats, queue, filters). These guard the operational surface: if the
 * queue or the stat band stops rendering, the page silently degrades to an
 * empty shell with no type or test to catch it.
 */
it('renders the stat band and the assistance queue for a user with cases', function (): void {
    AssistanceRequest::factory()->count(3)->create([
        'user_id' => $this->user->id,
        'status' => 'pending',
    ]);

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('dashboard')
            ->has('stats.activeRequests')
            ->has('stats.resolvedRequests')
            ->has('stats.medianResolutionMinutes')
            ->has('requests.data', 3)
            ->has('filters.status')
            ->has('quickResources')
            ->has('finance')
        );
});

it('filters the queue to active cases only', function (): void {
    AssistanceRequest::factory()->create([
        'user_id' => $this->user->id,
        'status' => 'pending',
    ]);
    AssistanceRequest::factory()->create([
        'user_id' => $this->user->id,
        'status' => 'resolved',
        'resolved_at' => now(),
    ]);

    $this->actingAs($this->user)
        ->get(route('dashboard', ['status' => 'active']))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('filters.status', 'active')
            ->has('requests.data', 1)
            // Counts stay global so the filter tabs never lie about the totals.
            ->where('stats.totalRequests', 2)
        );
});

it('keeps an empty queue renderable rather than crashing', function (): void {
    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('dashboard')
            ->has('requests.data', 0)
            ->where('stats.totalRequests', 0)
            ->where('stats.medianResolutionMinutes', null)
        );
});

it('requires authentication', function (): void {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});
