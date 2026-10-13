<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Wallet;
use App\Services\ChainQuantity;
use App\Services\LeptonTreasuryService;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

/**
 * A gateway whose stdout has been decorated exactly as `arc-canteen` decorates
 * it when colour is forced on (FORCE_COLOR=1, or a pty on the parent process).
 *
 * Reproduced on ARC-TESTNET: `arc-canteen rpc eth_blockNumber` answered
 * "\e[1;36m0x3fcb62f\e[0m", and the unwrapped value reached
 * Amounts::fromHexQuantity(), which threw
 * `Invalid hex quantity [\e[1;36m0x3fcb62f\e[0m]` and answered GET /admin with
 * a 500.
 */
final readonly class ColorisedArcGateway implements ArcNetworkGateway
{
    public function __construct(
        private string $payload,
        private ?string $balance = null,
    ) {}

    public function rpcUrl(): string
    {
        return 'https://rpc.testnet.arc-node.thecanteenapp.com/v1/key';
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
        return $this->payload;
    }

    public function treasuryAddress(): string
    {
        return '0x19bee6bef4f66d9d0cdb07f4d7fea00dc328251d';
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
        return $method === 'eth_getBalance' ? $this->balance : null;
    }
}

/**
 * Wrap a bare payload in the cyan-bold SGR pair the CLI emits.
 */
function colorise(string $payload): string
{
    return "\e[1;36m".$payload."\e[0m\n";
}

function treasuryWallet(): Wallet
{
    // Through the factories, like every other suite: a hand-rolled create() here
    // would drift from the schema the moment a column is added.
    /** @var Organization $organization */
    $organization = Organization::factory()->create([
        'name' => 'Treasury Academy',
        'currency' => 'USDC',
        'minimum_reserve' => 10000.00,
        'max_auto_payment' => 1000.00,
        'max_daily_disbursement' => 5000.00,
        'human_approval_threshold' => 1000.00,
    ]);

    /** @var Wallet $wallet */
    $wallet = Wallet::factory()->create([
        'organization_id' => $organization->id,
        'provider' => 'circle',
        'network' => 'arc',
        'address' => '0x19bee6bef4f66d9d0cdb07f4d7fea00dc328251d',
        'balance' => 20.00,
        'status' => 'active',
    ]);

    return $wallet;
}

it('parses a colourised hex quantity instead of throwing', function (): void {
    // The literal payload from the 500: Invalid hex quantity [0x3fcb62f].
    expect(ChainQuantity::normalize(colorise('0x3fcb62f')))->toBe('0x3fcb62f')
        ->and(ChainQuantity::toInt(colorise('0x3fcb62f')))->toBe(66893359);
});

it('reports treasury status instead of throwing when the CLI colourises its output', function (): void {
    // 0x1158e460913d00000 is 20 USDC at 18 decimals, and overflows PHP_INT_MAX.
    app()->instance(ArcNetworkGateway::class, new ColorisedArcGateway(
        payload: colorise('0x3fcb62f'),
        balance: colorise('0x1158e460913d00000'),
    ));

    $status = app(LeptonTreasuryService::class)->status(treasuryWallet());

    expect($status['block'])->toBe(66893359)
        ->and($status['live_available'])->toBeTrue()
        ->and($status['onchain_balance'])->toBe(20.0)
        ->and($status['in_sync'])->toBeTrue()
        ->and($status['error'])->toBeNull();
});

it('degrades to unavailable rather than throwing on unreadable chain output', function (): void {
    app()->instance(ArcNetworkGateway::class, new ColorisedArcGateway(
        payload: 'Error: no agent session is active',
    ));

    $status = app(LeptonTreasuryService::class)->status(treasuryWallet());

    expect($status['block'])->toBeNull()
        ->and($status['live_available'])->toBeFalse()
        ->and($status['onchain_balance'])->toBeNull()
        ->and($status['error'])->not->toBeNull();
});

it('blames the payload, not the session, when a quantity is present but unreadable', function (): void {
    app()->instance(ArcNetworkGateway::class, new ColorisedArcGateway(
        payload: colorise('0xnot-a-quantity'),
        balance: colorise('0xnot-a-quantity'),
    ));

    $status = app(LeptonTreasuryService::class)->status(treasuryWallet());

    expect($status['error'])->toBe('Unreadable balance quantity from the RPC endpoint.');
});

it('accepts the unadorned and CRLF and quoted forms a CLI may emit', function (string $raw): void {
    expect(ChainQuantity::toInt($raw))->toBe(66893359);
})->with([
    'bare' => ['0x3fcb62f'],
    'trailing newline' => ["0x3fcb62f\n"],
    'crlf' => ["0x3fcb62f\r\n"],
    'double quoted' => ['"0x3fcb62f"'],
    'padded' => ['   0x3fcb62f   '],
    'uppercase digits' => ['0x3FCB62F'],
]);

it('returns null instead of a wrapped negative for a quantity past PHP_INT_MAX', function (): void {
    // An (int) cast of an out-of-range string is the corruption Amounts exists
    // to prevent; a block read must never yield a negative height.
    expect(ChainQuantity::toInt('0xffffffffffffffffffffff'))->toBeNull();
});

it('still reads an 18-decimal amount above PHP_INT_MAX exactly', function (): void {
    expect(ChainQuantity::toDecimalString(colorise('0x1158e460913d00000'), 18))->toBe('20');
});
