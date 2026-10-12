<?php

declare(strict_types=1);

use App\Models\Student;
use App\Models\User;
use App\Services\StudentWalletService;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;

beforeEach(function (): void {
    config(['lepton.default' => 'fake']);
    Cache::flush();

    foreach (['student', 'finance_officer', 'admin', 'super_admin', 'user'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

/**
 * The dashboard is the student's own surface, so every check signs in as the
 * owner of the record rather than as a staff member.
 */
function dashboardStudent(Student $student): User
{
    return $student->user->assignRole('student');
}

test('the dashboard hero carries the live wallet balance and payout address', function (): void {
    $address = '0x'.str_repeat('a', 40);
    $student = Student::factory()->create(['payout_address' => $address]);
    app(FakeLeptonGateway::class)->seedBalance($address, 25000000);

    $this->actingAs(dashboardStudent($student));

    $this->get(route('student.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('student/dashboard')
            ->where('wallet.address', $address)
            ->where('wallet.snapshot.balance', '25.0')
            ->where('wallet.snapshot.state', 'ok')
            ->where('wallet.snapshot.is_available', true)
            ->where('wallet.snapshot.chain', 'ARC-TESTNET')
            ->where('wallet.balance_fiat', '₱1,437.50 PHP'));
});

test('the wallet balance is quoted in the display currency', function (): void {
    config(['eduflow.display_currency' => 'PHP']);
    $address = '0x'.str_repeat('b', 40);
    $student = Student::factory()->create(['payout_address' => $address]);
    app(FakeLeptonGateway::class)->seedBalance($address, 1000000);

    $this->actingAs(dashboardStudent($student));

    $this->get(route('student.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('wallet.balance_fiat', fn ($fiat): bool => is_string($fiat)
                && str_contains($fiat, 'PHP')
                && str_starts_with($fiat, '₱')));
});

test('a student with no wallet gets an empty state rather than a fabricated one', function (): void {
    $student = Student::factory()->withoutPayoutAddress()->create();

    $this->actingAs(dashboardStudent($student));

    $this->get(route('student.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('wallet.address', null)
            ->where('wallet.snapshot.state', 'missing')
            ->where('wallet.snapshot.balance', null)
            ->where('wallet.balance_fiat', null));
});

test('an unreadable chain is reported instead of shown as zero', function (): void {
    $address = '0x'.str_repeat('c', 40);
    $student = Student::factory()->create(['payout_address' => $address]);

    // A chain that raises on every read leaves the wallet without a number.
    app()->instance(
        ArcNetworkGateway::class,
        new class implements ArcNetworkGateway
        {
            public function rpcUrl(): string
            {
                return 'broken://arc';
            }

            public function chainCode(): string
            {
                return 'ARC-TESTNET';
            }

            public function chainId(): int
            {
                return 5042002;
            }

            public function blockNumber(): string
            {
                return '0x0';
            }

            public function treasuryAddress(): ?string
            {
                return null;
            }

            public function explorerUrl(string $txHash): string
            {
                return 'https://testnet.arcscan.app/tx/'.$txHash;
            }

            public function addressExplorerUrl(string $address): string
            {
                return 'https://testnet.arcscan.app/address/'.$address;
            }

            public function rpc(string $method, array $params = []): mixed
            {
                throw new RuntimeException('Arc is unreachable.');
            }
        },
    );

    $this->actingAs(dashboardStudent($student));

    $this->get(route('student.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('wallet.snapshot.state', 'unavailable')
            ->where('wallet.snapshot.balance', null)
            ->where('wallet.snapshot.is_available', false)
            ->where('wallet.snapshot.reason', fn (?string $reason): bool => is_string($reason)
                && str_contains($reason, 'Live balance unavailable')));
});

test('the dashboard reuses the cached chain reading rather than rereading per render', function (): void {
    $address = '0x'.str_repeat('d', 40);
    $student = Student::factory()->create(['payout_address' => $address]);

    app(FakeLeptonGateway::class)->seedBalance($address, 1000000);

    $this->actingAs(dashboardStudent($student));

    $this->get(route('student.dashboard'))->assertOk();

    app(FakeLeptonGateway::class)->seedBalance($address, 9000000);

    $this->get(route('student.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('wallet.snapshot.balance', '1.0'));

    app(StudentWalletService::class)->forget($student);

    $this->get(route('student.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('wallet.snapshot.balance', '9.0'));
});

test('the serialised dashboard page carries the wallet the React tree renders from', function (): void {
    $address = '0x'.str_repeat('e', 40);
    $student = Student::factory()->create(['payout_address' => $address]);
    app(FakeLeptonGateway::class)->seedBalance($address, 12345678);

    $this->actingAs(dashboardStudent($student));

    // Inertia serialises the props the component reads, so the address and the
    // exact balance string must both survive the round trip to the browser.
    $html = $this->get(route('student.dashboard'))->assertOk()->getContent();

    expect($html)
        ->toContain($address)
        ->toContain('12.345678')
        ->toContain('"component":"student\/dashboard"');
});
