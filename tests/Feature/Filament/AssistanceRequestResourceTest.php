<?php

declare(strict_types=1);

use App\Enums\AssistanceStatus;
use App\Filament\Resources\AssistanceRequests\AssistanceRequestResource;
use App\Models\AssistanceRequest;
use App\Models\User;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

test('assistance request resource is registered in admin panel', function (): void {
    $panel = Filament::getPanel('admin');
    $resources = $panel->getResources();

    $hasResource = collect($resources)->contains(
        fn ($resource): bool => $resource === AssistanceRequestResource::class
    );

    expect($hasResource)->toBeTrue();
});

test('super admin can access assistance requests page in admin panel', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $student = User::factory()->create(['name' => 'Student Alex']);
    $request = AssistanceRequest::factory()->create([
        'user_id' => $student->id,
        'subject' => 'Cannot view course timetable',
        'status' => AssistanceStatus::PENDING,
    ]);

    $response = $this->actingAs($admin)->get(AssistanceRequestResource::getUrl('index'));

    $response->assertSuccessful();
    $response->assertSee($request->ticket_number);
    $response->assertSee('Cannot view course timetable');
});

test('navigation badge reflects pending requests count', function (): void {
    AssistanceRequest::query()->delete();

    expect(AssistanceRequestResource::getNavigationBadge())->toBeNull();

    AssistanceRequest::factory()->create(['status' => AssistanceStatus::PENDING]);
    AssistanceRequest::factory()->create(['status' => AssistanceStatus::PENDING]);
    AssistanceRequest::factory()->create(['status' => AssistanceStatus::RESOLVED]);

    expect(AssistanceRequestResource::getNavigationBadge())->toBe('2');
});
