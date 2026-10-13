<?php

declare(strict_types=1);

use App\Models\Student;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;

beforeEach(function (): void {
    config(['lepton.default' => 'fake']);
    $this->fake = app(FakeLeptonGateway::class);

    foreach (['student', 'finance_officer', 'admin', 'super_admin', 'user'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

function actingAsStudent(Student $student): void
{
    test()->actingAs($student->user->assignRole('student'));
}

test('a student sees their own wallet screen', function (): void {
    $student = Student::factory()->create(['payout_address' => '0x'.str_repeat('a', 40)]);
    actingAsStudent($student);

    $this->get(route('student.wallet.show'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('student/wallet')
            ->where('snapshot.address', '0x'.str_repeat('a', 40))
            ->where('snapshot.is_available', true)
            ->where('snapshot.state', 'ok')
            ->has('tuitionAttempts'));
});

test('the wallet screen shows the live balance', function (): void {
    $address = '0x'.str_repeat('b', 40);
    $student = Student::factory()->create(['payout_address' => $address]);
    $this->fake->seedBalance($address, 25000000);
    actingAsStudent($student);

    $this->get(route('student.wallet.show'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->where('snapshot.balance', '25.0'));
});

test('a student without a wallet sees the empty state, not a fabricated address', function (): void {
    $student = Student::factory()->withoutPayoutAddress()->create();
    actingAsStudent($student);

    $this->get(route('student.wallet.show'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('snapshot.address', null)
            ->where('snapshot.state', 'missing')
            ->where('snapshot.is_available', false));
});

test('a staff member cannot open the student wallet screen', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('admin'));

    $this->get(route('student.wallet.show'))->assertForbidden();
});

test('a guest cannot open the student wallet screen', function (): void {
    $this->get(route('student.wallet.show'))->assertRedirect();
});

test('another students wallet is unreachable: no student id is accepted from the request', function (): void {
    $mine = Student::factory()->withoutPayoutAddress()->create();
    $theirs = Student::factory()->create(['payout_address' => '0x'.str_repeat('c', 40)]);

    $this->actingAs($mine->user->assignRole('student'));

    $this->get(route('student.wallet.show', ['student' => $theirs->id]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->where('snapshot.address', null));

    // The same holds on the server: refreshing reads the caller's wallet only.
    $this->post(route('student.wallet.refresh'))
        ->assertSuccessful()
        ->assertJson([
            'snapshot' => [
                'address' => null,
                'state' => 'missing',
            ],
        ]);
});

test('refreshing the balance re-reads the chain past the cache', function (): void {
    $address = '0x'.str_repeat('d', 40);
    $student = Student::factory()->create(['payout_address' => $address]);
    $this->fake->seedBalance($address, 1000000);
    actingAsStudent($student);

    $this->get(route('student.wallet.show'))->assertInertia(fn ($page) => $page->where('snapshot.balance', '1.0'));

    $this->fake->seedBalance($address, 7000000);

    $this->post(route('student.wallet.refresh'))
        ->assertSuccessful()
        ->assertJsonPath('snapshot.balance', '7.0');
});

test('linking a wallet stores it on the student record', function (): void {
    $student = Student::factory()->withoutPayoutAddress()->create();
    actingAsStudent($student);

    $address = '0x'.str_repeat('e', 40);

    $this->patch(route('wallet.update'), ['wallet_address' => $address])
        ->assertRedirect()
        ->assertSessionHas('status', 'wallet-updated');

    expect($student->fresh()->payout_address)->toBe($address);
});

test('a wallet address must be a well formed arc destination', function (string $address): void {
    $student = Student::factory()->withoutPayoutAddress()->create();
    actingAsStudent($student);

    $this->patch(route('wallet.update'), ['wallet_address' => $address])
        ->assertSessionHasErrors('wallet_address');

    expect($student->fresh()->payout_address)->toBeNull();
})->with([
    'too short' => '0x1234',
    'not hexadecimal' => '0x'.str_repeat('g', 40),
    'missing prefix' => str_repeat('a', 40),
    'uppercase spaces' => '0x '.str_repeat('a', 40),
]);

test('a student cannot link an address another student already uses', function (): void {
    $address = '0x'.str_repeat('f', 40);
    Student::factory()->create(['payout_address' => $address]);

    $student = Student::factory()->withoutPayoutAddress()->create();
    actingAsStudent($student);

    $this->patch(route('wallet.update'), ['wallet_address' => $address])
        ->assertSessionHasErrors('wallet_address');
});
