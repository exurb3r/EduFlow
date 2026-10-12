<?php

declare(strict_types=1);

use App\Filament\Resources\Students\Pages\ViewStudent;
use App\Filament\Resources\Students\Widgets\StudentWalletWidget;
use App\Models\Student;
use App\Models\User;
use App\Services\CircleAgentWalletCli;
use App\Services\StudentWalletGenerationService;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;
use Yukazakiri\Lepton\Support\CliRunner;
use Yukazakiri\Lepton\Support\LeptonCliException;

beforeEach(function (): void {
    config(['lepton.default' => 'fake']);

    // Every widget render asks Circle how many agent-wallet slots are left. Bind a
    // stand-in for the whole file so no test silently depends on whether the real
    // `circle` binary happens to be installed on the machine running it.
    $wallets = [];
    app()->instance(CircleAgentWalletCli::class, generationCli($wallets));

    foreach (['super_admin', 'admin', 'finance_officer', 'student', 'user'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

test('the wallet widget is registered on the student view page in both panels', function (string $panel): void {
    $this->actingAs(User::factory()->create()->assignRole('super_admin'));

    $page = new ViewStudent;

    expect($page::getResource()::getPages())->toHaveKey('view')
        ->and((new ReflectionClass(ViewStudent::class))->getMethod('getFooterWidgets')->invoke(new ViewStudent))
        ->toBe([StudentWalletWidget::class]);
})->with(['admin', 'finance']);

test('staff see the wallet for the student on the page', function (string $role): void {
    $student = Student::factory()->create(['payout_address' => '0x'.str_repeat('7', 40)]);

    $this->actingAs(User::factory()->create()->assignRole($role));
    filament()->setCurrentPanel('finance');

    Livewire::test(StudentWalletWidget::class, ['record' => $student])
        ->assertOk()
        ->assertSee('Student wallet')
        ->assertSee('0x'.str_repeat('7', 40));
})->with(['super_admin', 'admin', 'finance_officer']);

test('the widget reports the live balance read through the chain', function (): void {
    $student = Student::factory()->create();
    app(FakeLeptonGateway::class)->seedBalance($student->payout_address, 42000000);

    $this->actingAs(User::factory()->create()->assignRole('admin'));

    Livewire::test(StudentWalletWidget::class, ['record' => $student])
        ->assertOk()
        ->assertSet('balance', '42.0')
        ->assertSet('isAvailable', true);
});

test('a student with no wallet shows an empty state rather than a generated address', function (): void {
    $student = Student::factory()->withoutPayoutAddress()->create();

    $this->actingAs(User::factory()->create()->assignRole('admin'));

    Livewire::test(StudentWalletWidget::class, ['record' => $student])
        ->assertOk()
        ->assertSet('state', 'missing')
        ->assertSee('No wallet yet')
        ->assertSee('Generate a Circle agent wallet')
        ->assertDontSee('not-a-real-address');
});

test('a malformed address is reported rather than read', function (): void {
    $student = Student::factory()->withInvalidPayoutAddress()->create();

    $this->actingAs(User::factory()->create()->assignRole('admin'));

    Livewire::test(StudentWalletWidget::class, ['record' => $student])
        ->assertOk()
        ->assertSet('state', 'invalid')
        ->assertSet('address', null);
});

test('a forged record cannot read another students wallet', function (): void {
    $mine = Student::factory()->create();
    $theirs = Student::factory()->create(['payout_address' => '0x'.str_repeat('9', 40)]);

    $this->actingAs(User::factory()->create()->assignRole('admin'));
    filament()->setCurrentPanel('finance');

    // The widget record is a public, client-restorable Livewire property.
    // Swapping it must not expose another student's wallet.
    Livewire::test(StudentWalletWidget::class, ['record' => $mine])
        ->assertOk()
        ->assertSet('address', $mine->payout_address)
        ->set('record', $theirs)
        ->call('refresh')
        ->assertOk()
        ->assertDontSee('0x'.str_repeat('9', 40))
        ->assertSet('address', $mine->payout_address);
});

test('a staff member without access to the resource cannot read the widget', function (): void {
    $student = Student::factory()->create();

    $this->actingAs(User::factory()->create()->assignRole('student'));

    Livewire::test(StudentWalletWidget::class, ['record' => $student])
        ->assertOk()
        ->assertSet('address', null)
        ->assertSee('This wallet could not be loaded');
});

function generationCli(array &$wallets): CircleAgentWalletCli
{
    return new class($wallets) extends CircleAgentWalletCli
    {
        public function __construct(private array &$wallets)
        {
            parent::__construct(new CliRunner('true', 5));
        }

        public function agentWalletAddresses(string $chain): array
        {
            return array_map(fn (array $w): string => strtolower($w['address']), $this->wallets);
        }

        public function createAgentWallet(string $idempotencyKey): array
        {
            $this->wallets[] = ['address' => '0x'.str_pad(dechex(crc32($idempotencyKey)), 40, '0', STR_PAD_LEFT)];

            return [];
        }

        public function fundWallet(string $address, string $chain): array
        {
            return ['data' => ['txHash' => '0x'.str_repeat('d', 64)]];
        }
    };
}

/**
 * A Circle CLI that cannot run at all, the way CI sees it: the binary is absent.
 */
function unavailableCli(): CircleAgentWalletCli
{
    return new class extends CircleAgentWalletCli
    {
        public function __construct()
        {
            parent::__construct(new CliRunner('true', 5));
        }

        public function agentWalletAddresses(string $chain): array
        {
            throw new LeptonCliException(['circle', 'wallet', 'list', '--type', 'agent', '--chain', $chain], 127, 'sh: 1: exec: circle: not found');
        }
    };
}

/**
 * The slot count only decorates the mint button, so a CLI that cannot be reached
 * must cost the admin their wallet panel -- and must never be reported as zero.
 *
 * Reporting 0 would read as "the cap is reached" and hide the real fault; the
 * mint itself still has to refuse rather than claim it worked.
 */
test('an unreachable Circle CLI degrades the panel instead of breaking the student page', function (): void {
    app()->instance(CircleAgentWalletCli::class, unavailableCli());

    $student = Student::factory()->withoutPayoutAddress()->create();

    $this->actingAs(User::factory()->create()->assignRole('admin'));
    filament()->setCurrentPanel('finance');

    Livewire::test(StudentWalletWidget::class, ['record' => $student])
        ->assertOk()
        ->assertSet('remainingSlots', null)
        ->assertSee('Circle could not report')
        ->assertDontSee('0 of 5')
        ->call('generateWallet')
        ->assertHasNoErrors()
        ->assertNotified()
        ->assertSet('hasGeneratedWallet', false);

    expect($student->fresh()->payout_address)->toBeNull();
});

test('the panel shows the generated wallet provenance and funding history', function (): void {
    $wallets = [];
    app()->instance(CircleAgentWalletCli::class, generationCli($wallets));

    $student = Student::factory()->withoutPayoutAddress()->create();
    app(StudentWalletGenerationService::class)->generate($student);
    app(StudentWalletGenerationService::class)->fund($student);

    $this->actingAs(User::factory()->create()->assignRole('admin'));
    filament()->setCurrentPanel('finance');

    Livewire::test(StudentWalletWidget::class, ['record' => $student])
        ->assertOk()
        ->assertSet('hasGeneratedWallet', true)
        ->assertSet('generatedProvider', 'circle')
        ->assertSet('fundingCount', 1)
        ->assertSet('remainingSlots', 4)
        ->assertSee('student:fund-wallet');
});

test('the generate action mints a wallet from the panel', function (): void {
    $wallets = [];
    app()->instance(CircleAgentWalletCli::class, generationCli($wallets));

    $student = Student::factory()->withoutPayoutAddress()->create();

    $this->actingAs(User::factory()->create()->assignRole('admin'));
    filament()->setCurrentPanel('finance');

    Livewire::test(StudentWalletWidget::class, ['record' => $student])
        ->assertOk()
        ->assertSet('hasGeneratedWallet', false)
        ->call('generateWallet')
        ->assertHasNoErrors()
        ->assertSet('hasGeneratedWallet', true);

    $student->refresh();

    expect($student->payout_address)->not->toBeNull()
        ->and($student->wallet)->not->toBeNull()
        ->and($wallets)->toHaveCount(1);
});

test('a staff member without access cannot mint a wallet for a student', function (): void {
    $wallets = [];
    app()->instance(CircleAgentWalletCli::class, generationCli($wallets));

    $student = Student::factory()->withoutPayoutAddress()->create();

    $this->actingAs(User::factory()->create()->assignRole('student'));

    Livewire::test(StudentWalletWidget::class, ['record' => $student])
        ->call('generateWallet')
        ->assertOk();

    expect($student->fresh()->payout_address)->toBeNull()
        ->and($wallets)->toHaveCount(0);
});

test('a swapped record cannot mint a wallet for an actor who may not view it', function (): void {
    $wallets = [];
    app()->instance(CircleAgentWalletCli::class, generationCli($wallets));

    $mine = Student::factory()->create();
    $theirs = Student::factory()->withoutPayoutAddress()->create();

    // A student-role user has no view access to any student record, so swapping
    // the client-restorable $record must not give them a mint either.
    $this->actingAs(User::factory()->create()->assignRole('student'));

    Livewire::test(StudentWalletWidget::class, ['record' => $mine])
        ->set('record', $theirs)
        ->call('generateWallet')
        ->assertOk();

    expect($theirs->fresh()->payout_address)->toBeNull()
        ->and($wallets)->toHaveCount(0);
});
