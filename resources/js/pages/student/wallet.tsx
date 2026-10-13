import { Head, router, useForm } from '@inertiajs/react';
import {
    Check,
    Copy,
    ExternalLink,
    RefreshCw,
    TriangleAlert,
    Wallet,
} from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import {
    refresh as walletRefresh,
    show as walletShow,
} from '@/routes/student/wallet';
import { index as paymentsIndex } from '@/routes/payments';
import type { TuitionAttempt } from '@/types/financial-aid';

type WalletState = 'ok' | 'missing' | 'invalid' | 'unavailable';

interface WalletSnapshot {
    address: string | null;
    balance: string | null;
    chain: string;
    explorer_url: string | null;
    is_fake: boolean;
    is_available: boolean;
    state: WalletState;
    reason: string | null;
    cached_at: string | null;
}

interface WalletProps {
    snapshot: WalletSnapshot;
    tuitionAttempts?: TuitionAttempt[];
}

function formatBaseUnits(baseUnits: string | number): string {
    const units = BigInt(baseUnits);
    return `${(units / 1_000_000n).toLocaleString('en-US')}.${(units % 1_000_000n).toString().padStart(6, '0')}`;
}

function shortAddress(address: string): string {
    return `${address.slice(0, 6)}…${address.slice(-4)}`;
}

function formatBalance(snapshot: WalletSnapshot): string {
    if (!snapshot.is_available || snapshot.balance === null) {
        return '—';
    }

    const [whole, fraction = ''] = snapshot.balance.split('.');
    const trimmed = fraction.replace(/0+$/, '').slice(0, 2).padEnd(2, '0');

    return `${Number(whole).toLocaleString('en-US')}.${trimmed}`;
}

export default function StudentWallet({
    snapshot,
    tuitionAttempts = [],
}: WalletProps) {
    const hasAddress = Boolean(snapshot.address);

    const { data, setData, patch, processing, errors, reset } = useForm({
        wallet_address: snapshot.address ?? '',
    });

    const isEditing = data.wallet_address !== (snapshot.address ?? '');

    const copyAddress = () => {
        if (!snapshot.address) {
            return;
        }

        navigator.clipboard.writeText(snapshot.address);
        toast.success('Wallet address copied');
    };

    const refreshBalance = () => {
        router.post(
            walletRefresh.url(),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    toast.success('Balance refreshed from the chain.');
                },
                onError: () => {
                    toast.error('Could not read the chain just now.');
                },
            },
        );
    };

    const saveWallet = (e: React.FormEvent) => {
        e.preventDefault();

        patch(walletShow.url(), {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Payout wallet updated.', {
                    description:
                        'Funds sent to this address cannot be recovered if it is wrong.',
                });
            },
            onError: () => {
                toast.error('That wallet address was not accepted.');
            },
        });
    };

    const statusTone =
        snapshot.state === 'ok'
            ? 'text-[var(--status-resolved)]'
            : snapshot.state === 'unavailable'
              ? 'text-[var(--status-pending)]'
              : 'text-[var(--clay-text-muted)]';

    return (
        <>
            <Head title="My Wallet" />

            <div className="bulletin clay-ambient min-h-full w-full">
                <div className="mx-auto flex w-full max-w-4xl flex-col gap-8 px-4 py-6 sm:px-6 sm:py-10 lg:px-8 lg:py-12">
                    <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p className="bulletin-eyebrow flex items-center gap-2">
                                <Wallet className="size-3.5" />
                                EduFlow AI &middot; Wallet
                            </p>
                            <h1 className="clay-h1 mt-2">My Wallet</h1>
                            <p className="clay-body mt-1.5">
                                Approved assistance is paid in USDC to this
                                address on the Arc network.
                            </p>
                        </div>

                        <Button
                            variant="outline"
                            size="sm"
                            className="clay-focus h-9 rounded-full border-[var(--clay-border)] bg-transparent text-xs font-semibold shadow-none"
                            disabled={!hasAddress}
                            onClick={refreshBalance}
                        >
                            <RefreshCw className="size-3.5" />
                            Refresh
                        </Button>
                    </header>

                    {/* -- Balance -- */}
                    <section className="clay-card p-5 sm:p-6">
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <p className="bulletin-eyebrow">Balance</p>
                                <p className="bulletin-figure mt-2 text-4xl tabular-nums">
                                    {formatBalance(snapshot)}{' '}
                                    <span className="text-base font-semibold text-[var(--clay-text-muted)]">
                                        USDC
                                    </span>
                                </p>
                                <p className={`clay-meta mt-2 ${statusTone}`}>
                                    {snapshot.state === 'ok'
                                        ? `Live on ${snapshot.chain}`
                                        : snapshot.state === 'unavailable'
                                          ? 'Chain unreadable'
                                          : snapshot.state === 'missing'
                                            ? 'No wallet linked'
                                            : 'Address not usable'}
                                    {snapshot.cached_at &&
                                        ` · read ${snapshot.cached_at}`}
                                </p>
                            </div>

                            {snapshot.explorer_url && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="clay-focus h-8 rounded-full px-2 text-xs font-semibold"
                                    onClick={() =>
                                        window.open(
                                            snapshot.explorer_url as string,
                                            '_blank',
                                        )
                                    }
                                >
                                    <ExternalLink className="size-3.5" />
                                </Button>
                            )}
                        </div>

                        {snapshot.reason && snapshot.state !== 'ok' && (
                            <p className="clay-body mt-4 flex items-start gap-2 text-[var(--clay-text-muted)]">
                                <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                                {snapshot.reason}
                            </p>
                        )}

                        {snapshot.is_fake && (
                            <p className="clay-meta mt-3">
                                Testnet ledger: no real USDC moves on this
                                driver.
                            </p>
                        )}
                    </section>

                    {/* -- Receive -- */}
                    <section className="clay-card p-5 sm:p-6">
                        <p className="bulletin-eyebrow">Receive</p>
                        <p className="clay-body mt-1.5">
                            Send USDC on Arc to this address to fund the wallet.
                            Anyone can pay it; only the holder of its keys can
                            spend from it.
                        </p>

                        {hasAddress ? (
                            <>
                                <div className="clay-inset mt-4 flex flex-wrap items-center gap-3 p-3 sm:p-4">
                                    <span className="min-w-0 flex-1 font-mono text-xs break-all sm:text-sm">
                                        {snapshot.address}
                                    </span>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="clay-focus h-9 shrink-0 rounded-full border-[var(--clay-border)] bg-transparent text-xs font-semibold shadow-none"
                                        onClick={copyAddress}
                                    >
                                        <Copy className="size-3.5" />
                                        Copy
                                    </Button>
                                </div>

                                <p className="clay-meta mt-2">
                                    {shortAddress(String(snapshot.address))} is
                                    your payout destination for approved
                                    assistance.
                                </p>
                            </>
                        ) : (
                            <p className="clay-body mt-4 text-[var(--clay-text-muted)]">
                                Link a payout wallet below before funds can be
                                received.
                            </p>
                        )}
                    </section>

                    {/* -- Pilot tuition attempts -- */}
                    <section
                        className="clay-card grid gap-4 p-5 sm:p-6"
                        aria-labelledby="tuition-attempts-heading"
                    >
                        <h2 id="tuition-attempts-heading" className="clay-h2">
                            Pilot tuition attempts
                        </h2>
                        <p className="clay-meta">
                            Transfer and allocation are separate. Unknown means
                            investigate the existing payment, never send again.
                        </p>
                        {tuitionAttempts.length === 0 ? (
                            <p className="clay-body">
                                No pilot tuition attempt recorded.
                            </p>
                        ) : (
                            tuitionAttempts.map((a) => (
                                <div key={a.id} className="grid gap-2 text-sm">
                                    <p className="font-semibold">
                                        #{a.id} — {a.state_label} —{' '}
                                        {formatBaseUnits(a.amount_base_units)}{' '}
                                        USDC
                                    </p>
                                    <p>
                                        {a.guidance} Allocation:{' '}
                                        {a.posted
                                            ? `${formatBaseUnits(a.applied_base_units)} USDC applied to pilot account`
                                            : 'Not posted'}
                                        .
                                    </p>
                                    <p className="break-all text-xs">
                                        Request: {a.request_key}{' '}
                                        {a.tx_hash
                                            ? `· Transaction: ${a.tx_hash}`
                                            : '· No chain hash yet'}
                                    </p>
                                </div>
                            ))
                        )}
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() =>
                                router.reload({
                                    only: ['tuitionAttempts'],
                                })
                            }
                        >
                            Refresh attempt status
                        </Button>
                    </section>

                    {/* -- Link or replace -- */}
                    <section className="clay-card p-5 sm:p-6">
                        <p className="bulletin-eyebrow">
                            {hasAddress ? 'Replace wallet' : 'Link wallet'}
                        </p>
                        <p className="clay-body mt-1.5">
                            Paste the address exactly as your wallet shows it.
                            Arc accepts either letter case, but the characters
                            must be right: funds sent to a wrong address cannot
                            be recovered.
                        </p>

                        <form onSubmit={saveWallet} className="mt-4 grid gap-4">
                            <div className="space-y-1.5">
                                <Label
                                    htmlFor="wallet_address"
                                    className="bulletin-eyebrow"
                                >
                                    Wallet address
                                </Label>
                                <Input
                                    id="wallet_address"
                                    placeholder="0x…"
                                    autoComplete="off"
                                    spellCheck={false}
                                    value={data.wallet_address}
                                    onChange={(e) =>
                                        setData(
                                            'wallet_address',
                                            e.target.value.trim(),
                                        )
                                    }
                                    className="clay-field clay-focus font-mono shadow-none"
                                    required
                                />
                                <InputError message={errors.wallet_address} />
                                <p className="clay-meta">
                                    Starts with 0x, followed by exactly 40
                                    hexadecimal characters.
                                </p>
                            </div>

                            <div className="flex flex-wrap items-center gap-3">
                                <Button
                                    type="submit"
                                    disabled={processing || !isEditing}
                                    data-loading={processing}
                                    className="clay-focus rounded-full bg-[var(--clay-primary)] font-semibold text-[var(--clay-primary-foreground)] shadow-[var(--shadow-cta)] hover:bg-[var(--clay-primary-bright)]"
                                >
                                    <Check className="size-3.5" />
                                    {hasAddress
                                        ? 'Replace wallet'
                                        : 'Link wallet'}
                                </Button>

                                {isEditing && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="clay-focus h-9 rounded-full text-xs font-semibold"
                                        onClick={() => reset('wallet_address')}
                                    >
                                        Undo
                                    </Button>
                                )}

                                <Button
                                    variant="link"
                                    size="sm"
                                    className="clay-focus h-9 rounded-full text-xs font-semibold text-[var(--clay-text-muted)]"
                                    onClick={() =>
                                        router.visit(paymentsIndex())
                                    }
                                >
                                    View payments
                                </Button>
                            </div>
                        </form>
                    </section>
                </div>
            </div>
        </>
    );
}
