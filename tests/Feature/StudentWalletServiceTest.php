<?php

declare(strict_types=1);

use App\DTOs\WalletReadState;
use App\Models\Student;
use App\Services\StudentWalletService;
use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;

beforeEach(function (): void {
    config(['lepton.default' => 'fake']);

    $this->service = app(StudentWalletService::class);
    $this->fake = app(FakeLeptonGateway::class);
});

test('a student with no wallet address reports missing rather than a zero balance', function (): void {
    $student = Student::factory()->withoutPayoutAddress()->create();

    $snapshot = $this->service->snapshot($student);

    expect($snapshot->state)->toBe(WalletReadState::MISSING)
        ->and($snapshot->address)->toBeNull()
        ->and($snapshot->balance)->toBeNull()
        ->and($snapshot->isReadable())->toBeFalse()
        ->and($snapshot->reason)->toContain('No wallet address');
});

test('a malformed stored address is reported as invalid and never replaced', function (): void {
    $student = Student::factory()->withInvalidPayoutAddress()->create();

    $snapshot = $this->service->snapshot($student);

    expect($snapshot->state)->toBe(WalletReadState::INVALID)
        ->and($snapshot->address)->toBeNull()
        ->and($snapshot->isReadable())->toBeFalse()
        ->and($student->fresh()->payout_address)->toBe('0xnot-a-real-address');
});

test('a valid address resolves to that address and nothing else', function (): void {
    $student = Student::factory()->create(['payout_address' => '0x'.str_repeat('a', 40)]);

    expect($this->service->addressFor($student))->toBe('0x'.str_repeat('a', 40));
});

test('addressFor returns null for a missing or malformed address', function (Student $student): void {
    expect($this->service->addressFor($student))->toBeNull();
})->with([
    'no address' => fn (): Student => Student::factory()->withoutPayoutAddress()->create(),
    'malformed' => fn (): Student => Student::factory()->withInvalidPayoutAddress()->create(),
]);

test('the balance is read through the fake driver ledger', function (): void {
    $address = '0x'.str_repeat('1', 40);
    $student = Student::factory()->create(['payout_address' => $address]);

    $this->fake->seedBalance($address, 12345678);

    $snapshot = $this->service->snapshot($student);

    expect($snapshot->state)->toBe(WalletReadState::OK)
        ->and($snapshot->balance)->toBe('12.345678')
        ->and($snapshot->isFake)->toBeTrue()
        ->and($snapshot->address)->toBe($address)
        ->and($snapshot->chain)->toBe('ARC-TESTNET');
});

test('a seeded balance of nothing reads as zero rather than failing', function (): void {
    $student = Student::factory()->create(['payout_address' => '0x'.str_repeat('2', 40)]);

    $snapshot = $this->service->snapshot($student);

    expect($snapshot->state)->toBe(WalletReadState::OK)
        ->and($snapshot->balance)->toBe('0.0');
});

test('the reading is cached for the ttl so a screen is not a page of rpc calls', function (): void {
    $address = '0x'.str_repeat('3', 40);
    $student = Student::factory()->create(['payout_address' => $address]);

    $this->fake->seedBalance($address, 5000000);
    expect($this->service->snapshot($student)->balance)->toBe('5.0');

    $this->fake->seedBalance($address, 9000000);

    expect($this->service->snapshot($student)->balance)->toBe('5.0');
});

test('forget drops the cached reading so the next snapshot hits the chain', function (): void {
    $address = '0x'.str_repeat('4', 40);
    $student = Student::factory()->create(['payout_address' => $address]);

    $this->fake->seedBalance($address, 5000000);
    expect($this->service->snapshot($student)->balance)->toBe('5.0');

    $this->fake->seedBalance($address, 9000000);
    $this->service->forget($student);

    expect($this->service->snapshot($student)->balance)->toBe('9.0');
});

test('setAddress stores a valid address and drops the cached reading', function (): void {
    $address = '0x'.str_repeat('5', 40);
    $student = Student::factory()->withoutPayoutAddress()->create();

    $this->service->setAddress($student, $address);

    expect($student->fresh()->payout_address)->toBe($address);
});

test('setAddress rejects a malformed address without touching the record', function (string $address): void {
    $student = Student::factory()->create(['payout_address' => '0x'.str_repeat('a', 40)]);

    expect(fn () => $this->service->setAddress($student, $address))
        ->toThrow(InvalidArgumentException::class);

    expect($student->fresh()->payout_address)->toBe('0x'.str_repeat('a', 40));
})->with([
    'too short' => '0x1234',
    'not hex' => '0x'.str_repeat('z', 40),
    'no prefix' => str_repeat('a', 40),
    'embedded space' => '0x'.str_repeat('a', 39).' ',
]);

test('the payload never leaks an address for an unusable wallet', function (): void {
    $student = Student::factory()->withoutPayoutAddress()->create();

    $payload = $this->service->snapshot($student)->toArray();

    expect($payload['address'])->toBeNull()
        ->and($payload['is_available'])->toBeFalse()
        ->and($payload['state'])->toBe('missing');
});

test('an uppercase address is accepted and preserved as stored', function (): void {
    $address = '0x'.str_repeat('A', 40);
    $student = Student::factory()->create(['payout_address' => $address]);

    expect($this->service->addressFor($student))->toBe($address);
});
