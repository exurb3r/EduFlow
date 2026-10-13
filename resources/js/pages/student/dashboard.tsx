import { Head, Link, router, useRemember, usePoll } from '@inertiajs/react';
import {
    ArrowDownLeft,
    ArrowRight,
    ArrowUpRight,
    BadgeCheck,
    Copy,
    Eye,
    EyeOff,
    Loader2,
    ReceiptText,
    Sparkles,
    TriangleAlert,
    Wallet,
} from 'lucide-react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';

import { AskEduFlow } from '@/components/ask-eduflow';
import { RequestStatus } from '@/components/financial-aid';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import {
    formatAidLabel,
    formatSubmittedAt,
    formatUsdc,
} from '@/lib/financial-aid';
import { show } from '@/routes/assistance';
import { index as financialAssistanceIndex } from '@/routes/financial-assistance';
import { index as paymentsIndex } from '@/routes/payments';
import { dashboard } from '@/routes/student';
import {
    quote as tuitionPayQuote,
    pay as tuitionPayStore,
} from '@/routes/student/tuition';
import { show as walletShow } from '@/routes/student/wallet';
import type {
    StudentDashboardProps,
    TuitionAttempt,
} from '@/types/financial-aid';

function statusPillClass(status: string): string {
    switch (status) {
        case 'confirmed':
            return 'bg-[var(--status-resolved)]/10 text-[var(--status-resolved)]';
        case 'pending':
            return 'bg-[var(--status-pending)]/10 text-[var(--status-pending)]';
        case 'failed':
            return 'bg-[var(--status-urgent)]/10 text-[var(--status-urgent)]';
        default:
            return 'bg-[var(--clay-surface-soft)] text-[var(--clay-text-muted)]';
    }
}

function shortAddress(address: string): string {
    return `${address.slice(0, 6)}…${address.slice(-4)}`;
}

/**
 * USDC's 6 decimals, formatted without a float. The amounts reach the browser as
 * exact base units from the server, so a value this large cannot be rounded
 * into the wrong receipt.
 */
function formatBaseUnits(baseUnits: string | number): string {
    const units = BigInt(baseUnits);
    return `${(units / 1_000_000n).toLocaleString('en-US')}.${(units % 1_000_000n).toString().padStart(6, '0')}`;
}

/**
 * An on-chain balance arrives as an exact decimal string at Arc's 18
 * decimals, so it is grouped with BigInt and never passes through a float.
 */
function formatBalance(balance: string): string {
    const [whole = '0', fraction = ''] = balance.split('.');

    if (!/^\d+$/.test(whole)) {
        return `${balance} USDC`;
    }

    const grouped = BigInt(whole).toLocaleString('en-US');
    const cents = fraction.replace(/0+$/, '').slice(0, 2).padEnd(2, '0');

    return `${grouped}.${cents} USDC`;
}

export default function StudentDashboard({
    student,
    tuitionAccount,
    requests,
    currency,
    suggestedQuestions,
    wallet,
    totals,
    recentTransactions,
    eligibility,
    tuitionAttempts = [],
}: StudentDashboardProps) {
    const [balanceVisible, setBalanceVisible] = useState(true);
    const [eligibilityVisible, setEligibilityVisible] = useState(false);
    const eligibilityRef = useRef<HTMLElement | null>(null);
    const firstName = student.name.split(' ')[0];
    const transactions = recentTransactions ?? [];
    const snapshot = wallet?.snapshot;
    // `address` is the same value in both props; narrowing it once here keeps the
    // wallet branch free of `wallet` possibly-undefined casts.
    const walletAddress = wallet?.address ?? null;
    const hasWallet = Boolean(walletAddress);

    // Pay Now needs a wallet with a balance and tuition that is still owed.
    const remainingBase = Number(tuitionAccount?.remaining_amount ?? 0);
    const remainingUsdc = remainingBase / 1_000_000;
    const canPay = hasWallet && remainingBase > 0;
    const [payOpen, setPayOpen] = useState(false);
    const [payAmount, setPayAmount] = useState('');
    const [payQuote, setPayQuote] = useState<{
        network_fee: string;
        total: string;
        max_amount_base_units: number;
        balance: string | null;
        chain: string;
        request_digest: string;
        consent_text: string;
        expires_at: string;
        fee_ceiling_base_units: number;
    } | null>(null);
    const [payError, setPayError] = useState<string | null>(null);
    const [quoting, setQuoting] = useState(false);
    const [paying, setPaying] = useState(false);
    /**
     * The identity this attempt keeps for its whole life, minted when the quote
     * is requested and echoed back by both endpoints. Holding it in state is
     * what stops a retry from becoming a second transfer.
     */
    const [requestKey, setRequestKey] = useRemember<string | null>(
        null,
        `tuition-request:${tuitionAccount?.id}`,
    );
    const [consent, setConsent] = useState(false);
    const [receipt, setReceipt] = useState<TuitionAttempt | null>(null);
    usePoll(8000, { only: ['tuitionAttempts', 'tuitionAccount', 'wallet'] });

    const defaultPayBaseUnits = Math.min(
        remainingBase,
        Number(
            snapshot?.balance
                ? Math.floor(Number(snapshot.balance) * 1_000_000)
                : 0,
        ),
    );

    const openPay = () => {
        setPayAmount((defaultPayBaseUnits / 1_000_000).toFixed(2));
        setPayQuote(null);
        setConsent(false);
        setRequestKey(null);
        setPayError(null);
        setReceipt(
            tuitionAttempts.find(
                (a) => !['simulated', 'failed'].includes(a.state) && !a.posted,
            ) ?? null,
        );
        setPayOpen(true);
    };

    const requestQuote = async (baseUnits: number) => {
        setQuoting(true);
        setPayError(null);

        try {
            const response = await fetch(tuitionPayQuote.url(), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN':
                        document.querySelector<HTMLMetaElement>(
                            'meta[name="csrf-token"]',
                        )?.content ?? '',
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    tuition_account_id: tuitionAccount?.id,
                    amount_base_units: baseUnits,
                    request_key: requestKey ?? undefined,
                }),
            });

            const payload = await response.json();

            if (!response.ok) {
                setPayError(
                    payload?.message ??
                        'Could not quote this payment on the chain.',
                );
                setPayQuote(null);

                return;
            }

            setPayQuote(payload);
            setRequestKey(payload.request_key ?? null);
        } catch {
            setPayError('Could not reach the payment network.');
        } finally {
            setQuoting(false);
        }
    };

    const submitPay = async () => {
        if (!/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,6})?$/.test(payAmount)) {
            setPayError('Enter exact USDC with at most six decimal places.');
            return;
        }
        const [whole, fraction = ''] = payAmount.split('.');
        const exact =
            BigInt(whole) * 1_000_000n + BigInt(fraction.padEnd(6, '0'));
        const baseUnits = Number(exact);

        if (!Number.isSafeInteger(baseUnits) || baseUnits <= 0) {
            setPayError('Enter an amount greater than zero.');

            return;
        }

        if (!payQuote || !requestKey) {
            setPayError('Get a fresh fee quote before sending this payment.');
            await requestQuote(baseUnits);

            return;
        }
        if (!consent) {
            setPayError('Accept this exact pilot quote before confirming.');
            return;
        }

        setPaying(true);
        setPayError(null);

        try {
            const response = await fetch(tuitionPayStore.url(), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN':
                        document.querySelector<HTMLMetaElement>(
                            'meta[name="csrf-token"]',
                        )?.content ?? '',
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    tuition_account_id: tuitionAccount?.id,
                    amount_base_units: baseUnits,
                    request_key: requestKey,
                    request_digest: payQuote.request_digest,
                    consent: true,
                }),
            });

            const payload = await response.json();

            if (!response.ok) {
                setPayError(
                    payload?.message ?? 'The transfer could not be sent.',
                );

                return;
            }

            setReceipt(payload);

            // The wallet balance and paid figures both changed on-chain.
            router.reload({
                only: ['tuitionAttempts', 'tuitionAccount', 'wallet'],
            });
        } catch {
            setPayError(
                'Response lost. Check existing attempt history before doing anything else; do not create a new payment.',
            );
            router.reload({ only: ['tuitionAttempts'] });
        } finally {
            setPaying(false);
        }
    };

    const copyAddress = () => {
        if (!walletAddress) {
            return;
        }

        navigator.clipboard.writeText(walletAddress);
        toast.success('Wallet address copied', {
            description: 'Share it to receive USDC on Arc.',
        });
    };

    const openEligibility = () => {
        setEligibilityVisible(true);
        // Let the card mount before scrolling it into view.
        requestAnimationFrame(() => {
            eligibilityRef.current?.scrollIntoView({
                behavior: 'smooth',
                block: 'start',
            });
        });
    };

    return (
        <>
            <Head title="Student dashboard" />

            <div className="bulletin clay-ambient min-h-full w-full">
                <div className="mx-auto flex w-full max-w-6xl flex-col gap-8 px-4 py-6 sm:gap-10 sm:px-6 sm:py-10 lg:px-8 lg:py-12">
                    {/* -- Header: greeting + eligibility CTA -- */}
                    <header className="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                        <div className="min-w-0">
                            <p className="bulletin-eyebrow">
                                EduFlow &middot; Northstar Learning Center
                            </p>
                            <h1 className="clay-h1 mt-2">
                                Welcome back,{' '}
                                <span className="text-[var(--clay-primary-bright)]">
                                    {firstName}
                                </span>
                            </h1>
                            <p className="clay-body mt-1.5 max-w-md">
                                {student.program} &middot; Year{' '}
                                {student.year_level} &middot;{' '}
                                {student.student_number}
                            </p>
                        </div>

                        <button
                            type="button"
                            onClick={openPay}
                            disabled={!canPay}
                            title={
                                canPay
                                    ? undefined
                                    : hasWallet
                                      ? 'Nothing is owed on this account.'
                                      : 'Link a payout wallet first.'
                            }
                            className="clay-focus inline-flex h-11 shrink-0 items-center gap-2 rounded-full bg-[var(--clay-primary)] px-6 text-sm font-semibold text-[var(--clay-primary-foreground)] transition-all hover:-translate-y-0.5 hover:bg-[var(--clay-primary-bright)] active:translate-y-0 active:scale-[0.98] disabled:pointer-events-none disabled:opacity-60"
                        >
                            <span className="inline-flex items-center gap-2">
                                <ArrowUpRight
                                    aria-hidden="true"
                                    className="size-4"
                                />
                                Pay Now
                            </span>
                        </button>
                    </header>

                    {/* -- Pay tuition -- */}
                    <Dialog
                        open={payOpen}
                        onOpenChange={(open) => {
                            if (paying) {
                                return;
                            }

                            setPayOpen(open);
                        }}
                    >
                        <DialogContent className="bulletin clay-card border-[var(--clay-border)] sm:max-w-md">
                            <DialogHeader>
                                <DialogTitle className="clay-h2 flex items-center gap-3">
                                    <span className="clay-icon-chip size-9 shrink-0">
                                        <ArrowUpRight className="size-4" />
                                    </span>
                                    Pay tuition
                                </DialogTitle>
                                <DialogDescription className="clay-body">
                                    Operator-controlled Arc testnet pilot only.
                                    This does not pay official college tuition.
                                    Confirmation binds this one amount and fee
                                    ceiling.
                                </DialogDescription>
                            </DialogHeader>

                            {receipt ? (
                                <div className="grid gap-4">
                                    <p
                                        className={
                                            receipt.simulated
                                                ? 'clay-title text-[var(--status-pending)]'
                                                : 'clay-title text-[var(--status-resolved)]'
                                        }
                                    >
                                        {receipt.simulated
                                            ? 'Rehearsal — nothing was sent.'
                                            : receipt.state_label}
                                    </p>
                                    <dl className="grid gap-3 text-sm">
                                        <div className="flex items-baseline justify-between gap-4">
                                            <dt className="clay-meta">
                                                {receipt.simulated
                                                    ? 'Would be sent to the institution'
                                                    : 'Requested principal (not a settlement claim)'}
                                            </dt>
                                            <dd className="clay-title tabular-nums">
                                                {formatBaseUnits(
                                                    receipt.amount_base_units,
                                                )}{' '}
                                                USDC
                                            </dd>
                                        </div>
                                        <div className="flex items-baseline justify-between gap-4">
                                            <dt className="clay-meta">
                                                {receipt.simulated
                                                    ? 'Network fee (not charged)'
                                                    : 'Proven network fee'}
                                            </dt>
                                            <dd className="clay-title tabular-nums">
                                                {receipt.actual_network_fee ??
                                                    'Not yet proven'}
                                            </dd>
                                        </div>
                                        <div className="flex items-baseline justify-between gap-4">
                                            <dt className="clay-meta">
                                                Applied to this account
                                            </dt>
                                            <dd className="clay-title tabular-nums">
                                                {formatBaseUnits(
                                                    receipt.applied_base_units,
                                                )}{' '}
                                                USDC
                                            </dd>
                                        </div>
                                        {BigInt(
                                            receipt.overpayment_base_units,
                                        ) > 0n && (
                                            <div className="flex items-baseline justify-between gap-4">
                                                <dt className="clay-meta">
                                                    Larger than what was owed
                                                </dt>
                                                <dd className="clay-title tabular-nums text-[var(--status-pending)]">
                                                    {formatBaseUnits(
                                                        receipt.overpayment_base_units,
                                                    )}{' '}
                                                    USDC
                                                </dd>
                                            </div>
                                        )}
                                        <div className="flex items-baseline justify-between gap-4">
                                            <dt className="clay-meta">
                                                Still owed
                                            </dt>
                                            <dd className="clay-title tabular-nums">
                                                {formatBaseUnits(
                                                    tuitionAccount?.remaining_amount ??
                                                        '0',
                                                )}{' '}
                                                USDC
                                            </dd>
                                        </div>
                                        <div className="min-w-0">
                                            <dt className="clay-meta">
                                                Transaction
                                            </dt>
                                            <dd className="clay-meta mt-1 break-all">
                                                {receipt.tx_hash}
                                            </dd>
                                        </div>
                                    </dl>
                                    <p className="clay-meta">
                                        {receipt.simulated
                                            ? 'No USDC moved on any network, and no fee was charged.'
                                            : 'Fees are shown only when proven. Receipt status, allocation and official tuition are different claims.'}
                                    </p>
                                    <p className="clay-meta">
                                        {receipt.guidance} Allocation:{' '}
                                        {receipt.posted
                                            ? 'Posted to designated pilot account'
                                            : 'Not posted'}
                                        .
                                    </p>
                                    {BigInt(receipt.overpayment_base_units) >
                                        0n && (
                                        <p className="clay-meta">
                                            You paid more than this account
                                            owed, so the excess is recorded
                                            against your account rather than
                                            absorbed into the school's budget.
                                        </p>
                                    )}
                                    <Button
                                        type="button"
                                        className="clay-focus rounded-full bg-[var(--clay-primary)] font-semibold text-[var(--clay-primary-foreground)]"
                                        onClick={() => setPayOpen(false)}
                                    >
                                        Done
                                    </Button>
                                </div>
                            ) : (
                                <form
                                    className="grid gap-4"
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        void submitPay();
                                    }}
                                >
                                    <dl className="grid gap-3 text-sm">
                                        <div className="flex items-baseline justify-between gap-4">
                                            <dt className="clay-meta">
                                                Outstanding
                                            </dt>
                                            <dd className="clay-title tabular-nums">
                                                {remainingUsdc.toFixed(2)} USDC
                                            </dd>
                                        </div>
                                        <div className="flex items-baseline justify-between gap-4">
                                            <dt className="clay-meta">
                                                Wallet balance
                                            </dt>
                                            <dd className="clay-title tabular-nums">
                                                {snapshot?.balance
                                                    ? `${snapshot.balance} USDC`
                                                    : '—'}
                                            </dd>
                                        </div>
                                    </dl>

                                    <div className="space-y-1.5">
                                        <Label
                                            htmlFor="pay_amount"
                                            className="bulletin-eyebrow"
                                        >
                                            Amount (USDC)
                                        </Label>
                                        <Input
                                            id="pay_amount"
                                            type="text"
                                            inputMode="decimal"
                                            max={remainingUsdc}
                                            value={payAmount}
                                            onChange={(event) => {
                                                setPayAmount(
                                                    event.target.value,
                                                );
                                                setPayQuote(null);
                                                setRequestKey(null);
                                                setConsent(false);
                                            }}
                                            className="clay-field clay-focus tabular-nums shadow-none"
                                            required
                                        />
                                        <InputError
                                            message={payError ?? undefined}
                                        />
                                        <p className="clay-meta">
                                            Your wallet pays the amount plus the
                                            network fee, which comes out of the
                                            same balance.
                                        </p>
                                    </div>

                                    {payQuote && (
                                        <dl className="clay-inset grid gap-2 p-3 text-sm">
                                            <div className="flex items-baseline justify-between gap-4">
                                                <dt className="clay-meta">
                                                    Network fee
                                                </dt>
                                                <dd className="clay-title tabular-nums">
                                                    {payQuote.network_fee} USDC
                                                </dd>
                                            </div>
                                            <div className="flex items-baseline justify-between gap-4">
                                                <dt className="clay-meta">
                                                    Total leaving your wallet
                                                </dt>
                                                <dd className="clay-title tabular-nums">
                                                    {payQuote.total} USDC
                                                </dd>
                                            </div>
                                        </dl>
                                    )}

                                    {payQuote && (
                                        <label className="flex items-start gap-3 text-sm">
                                            <input
                                                type="checkbox"
                                                checked={consent}
                                                onChange={(e) =>
                                                    setConsent(e.target.checked)
                                                }
                                            />
                                            <span>
                                                {payQuote.consent_text} Fee
                                                ceiling:{' '}
                                                {formatBaseUnits(
                                                    payQuote.fee_ceiling_base_units,
                                                )}{' '}
                                                USDC. Quote expires:{' '}
                                                {payQuote.expires_at}.
                                            </span>
                                        </label>
                                    )}

                                    <div className="flex flex-wrap items-center gap-3">
                                        <Button
                                            type="submit"
                                            disabled={
                                                paying ||
                                                quoting ||
                                                (!!payQuote && !consent)
                                            }
                                            data-loading={paying || quoting}
                                            className="clay-focus rounded-full bg-[var(--clay-primary)] font-semibold text-[var(--clay-primary-foreground)]"
                                        >
                                            {paying || quoting ? (
                                                <Loader2 className="size-4 animate-spin" />
                                            ) : (
                                                <ArrowUpRight className="size-4" />
                                            )}
                                            {payQuote
                                                ? 'Confirm pilot consent'
                                                : quoting
                                                  ? 'Reading the fee…'
                                                  : 'Pay Now'}
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            className="clay-focus rounded-full text-xs font-semibold"
                                            onClick={() => setPayOpen(false)}
                                            disabled={paying}
                                        >
                                            Cancel
                                        </Button>
                                    </div>

                                    <p className="clay-meta flex items-start gap-2 text-[var(--status-pending)]">
                                        <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
                                        USDC transfers are final. Confirm the
                                        amount before you send.
                                    </p>
                                </form>
                            )}
                        </DialogContent>
                    </Dialog>

                    <section
                        className="clay-card grid gap-4 p-5"
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
                                    only: ['tuitionAttempts', 'tuitionAccount'],
                                })
                            }
                        >
                            Refresh attempt status
                        </Button>
                    </section>

                    {/* -- Wallet hero -- */}
                    <section
                        aria-labelledby="wallet-heading"
                        className="grid gap-4 lg:grid-cols-3"
                    >
                        <div className="clay-card p-5 sm:p-8 lg:col-span-2">
                            <div className="flex items-start justify-between gap-4">
                                <div className="flex min-w-0 items-center gap-3">
                                    <span className="clay-icon-chip size-10 shrink-0">
                                        <Wallet className="size-4" />
                                    </span>
                                    <div className="min-w-0">
                                        <p
                                            id="wallet-heading"
                                            className="bulletin-eyebrow"
                                        >
                                            My wallet
                                        </p>
                                        <p className="clay-meta mt-0.5">
                                            {snapshot?.chain
                                                ? `Available balance on ${snapshot.chain}`
                                                : 'USDC on Arc'}
                                        </p>
                                    </div>
                                </div>

                                <div className="flex shrink-0 items-center gap-2">
                                    {snapshot?.is_fake && (
                                        <span className="rounded-full bg-[var(--clay-surface-soft)] px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wider text-[var(--clay-text-muted)]">
                                            Test ledger
                                        </span>
                                    )}
                                    {hasWallet && (
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setBalanceVisible(
                                                    (value) => !value,
                                                )
                                            }
                                            className="clay-focus inline-flex size-9 items-center justify-center rounded-full text-[var(--clay-text-muted)] transition-colors hover:bg-[var(--clay-surface-soft)] hover:text-[var(--clay-text)]"
                                            aria-label={
                                                balanceVisible
                                                    ? 'Hide balance'
                                                    : 'Show balance'
                                            }
                                        >
                                            {balanceVisible ? (
                                                <Eye className="size-4" />
                                            ) : (
                                                <EyeOff className="size-4" />
                                            )}
                                        </button>
                                    )}
                                </div>
                            </div>

                            {!hasWallet ? (
                                <div className="mt-6 space-y-3">
                                    <p className="clay-h2">
                                        {snapshot?.state === 'invalid'
                                            ? 'Wallet address not usable.'
                                            : 'No wallet linked.'}
                                    </p>
                                    <p className="clay-body max-w-sm">
                                        {snapshot?.state === 'invalid'
                                            ? 'The address on file is not a valid Arc destination, so payments are blocked. Replace it to receive assistance and hold USDC.'
                                            : 'Link a payout wallet to receive assistance and to hold USDC on Arc.'}
                                    </p>
                                    <Link
                                        href={walletShow()}
                                        className="clay-focus inline-flex h-10 items-center gap-2 rounded-full bg-[var(--clay-primary)] px-5 text-sm font-semibold text-[var(--clay-primary-foreground)] transition-all hover:-translate-y-0.5 hover:bg-[var(--clay-primary-bright)] active:translate-y-0"
                                    >
                                        <Wallet className="size-4" />
                                        {snapshot?.state === 'invalid'
                                            ? 'Replace wallet'
                                            : 'Link a wallet'}
                                    </Link>
                                </div>
                            ) : (
                                <>
                                    <p className="bulletin-figure mt-5 text-4xl sm:text-5xl">
                                        {balanceVisible
                                            ? snapshot?.is_available &&
                                              snapshot.balance
                                                ? formatBalance(
                                                      snapshot.balance,
                                                  )
                                                : 'Not available'
                                            : '••••••••'}
                                    </p>
                                    <p className="clay-meta mt-2 text-[var(--clay-text-muted)]">
                                        {balanceVisible
                                            ? wallet?.balance_fiat
                                                ? `≈ ${wallet.balance_fiat}`
                                                : snapshot?.chain
                                            : '••••'}
                                    </p>

                                    {snapshot && !snapshot.is_available && (
                                        <p className="clay-meta mt-3 flex items-start gap-2 text-[var(--status-pending)]">
                                            <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
                                            {snapshot.reason ??
                                                'Balance unavailable.'}
                                        </p>
                                    )}

                                    <dl className="mt-6 grid gap-4 border-t border-[var(--clay-border)] pt-5 sm:grid-cols-2">
                                        <div className="min-w-0">
                                            <dt className="bulletin-eyebrow">
                                                Payout address
                                            </dt>
                                            <dd className="mt-1.5 flex items-center gap-2">
                                                <span className="clay-meta min-w-0 truncate">
                                                    {shortAddress(
                                                        walletAddress ?? '',
                                                    )}
                                                </span>
                                                <button
                                                    type="button"
                                                    onClick={copyAddress}
                                                    className="clay-focus inline-flex size-7 shrink-0 items-center justify-center rounded-full text-[var(--clay-text-muted)] transition-colors hover:bg-[var(--clay-surface-soft)] hover:text-[var(--clay-text)]"
                                                    aria-label="Copy wallet address"
                                                    title="Copy wallet address"
                                                >
                                                    <Copy className="size-3.5" />
                                                </button>
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="bulletin-eyebrow">
                                                Received to date
                                            </dt>
                                            <dd className="clay-title mt-1.5 tabular-nums">
                                                {formatUsdc(
                                                    String(
                                                        Math.round(
                                                            (totals?.confirmed ??
                                                                0) * 1_000_000,
                                                        ),
                                                    ),
                                                )}
                                            </dd>
                                        </div>
                                    </dl>

                                    <div className="mt-6 flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            onClick={copyAddress}
                                            className="clay-focus inline-flex h-9 items-center gap-2 rounded-full bg-[var(--clay-primary)] px-4 text-xs font-semibold text-[var(--clay-primary-foreground)] transition-all hover:-translate-y-0.5 hover:bg-[var(--clay-primary-bright)] active:translate-y-0"
                                        >
                                            <ArrowDownLeft className="size-3.5" />
                                            Receive
                                        </button>
                                        <Link
                                            href={paymentsIndex()}
                                            className="clay-focus inline-flex h-9 items-center gap-2 rounded-full border border-[var(--clay-border)] px-4 text-xs font-semibold transition-colors hover:bg-[var(--clay-surface-soft)]"
                                        >
                                            <ReceiptText className="size-3.5" />
                                            Payments
                                        </Link>
                                        <Link
                                            href={walletShow()}
                                            className="clay-focus inline-flex h-9 items-center gap-1 rounded-full px-3 text-xs font-semibold text-[var(--clay-text-muted)] transition-colors hover:bg-[var(--clay-surface-soft)] hover:text-[var(--clay-text)]"
                                        >
                                            Manage
                                            <ArrowRight className="size-3.5" />
                                        </Link>
                                    </div>
                                </>
                            )}
                        </div>

                        {/* -- Tuition balance, now the supporting card -- */}
                        <div className="clay-card p-5 sm:p-6">
                            <p className="bulletin-eyebrow">
                                Outstanding tuition
                            </p>
                            {tuitionAccount?.term && (
                                <p className="clay-meta mt-1">
                                    {tuitionAccount.term}
                                </p>
                            )}

                            {tuitionAccount ? (
                                <>
                                    <p className="bulletin-figure mt-4 text-3xl">
                                        {balanceVisible
                                            ? formatUsdc(
                                                  tuitionAccount.remaining_amount,
                                              )
                                            : '••••••••'}
                                    </p>
                                    {tuitionAccount.remaining_amount_fiat && (
                                        <p className="clay-meta mt-2 text-[var(--clay-text-muted)]">
                                            {balanceVisible
                                                ? `≈ ${tuitionAccount.remaining_amount_fiat}`
                                                : '••••'}
                                        </p>
                                    )}

                                    {Number(tuitionAccount.held_amount ?? 0) >
                                        0 && (
                                        <p className="mt-2 text-xs font-medium text-amber-500">
                                            {balanceVisible
                                                ? `Includes ${formatUsdc(
                                                      tuitionAccount.held_amount!,
                                                  )} held in flight`
                                                : '••••'}
                                        </p>
                                    )}

                                    <dl className="mt-6 grid grid-cols-2 gap-4 border-t border-[var(--clay-border)] pt-5">
                                        <div>
                                            <dt className="bulletin-eyebrow">
                                                Total tuition
                                            </dt>
                                            <dd className="clay-title mt-1.5 tabular-nums">
                                                {balanceVisible
                                                    ? formatUsdc(
                                                          tuitionAccount.total_amount,
                                                      )
                                                    : '••••'}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="bulletin-eyebrow">
                                                Paid
                                            </dt>
                                            <dd className="clay-title mt-1.5 tabular-nums text-[var(--status-resolved)]">
                                                {balanceVisible
                                                    ? formatUsdc(
                                                          tuitionAccount.paid_amount,
                                                      )
                                                    : '••••'}
                                            </dd>
                                            {Number(
                                                tuitionAccount.held_amount ?? 0,
                                            ) > 0 && (
                                                <p className="mt-1 text-xs text-amber-500">
                                                    +
                                                    {formatUsdc(
                                                        tuitionAccount.held_amount!,
                                                    )}{' '}
                                                    held
                                                </p>
                                            )}
                                        </div>
                                    </dl>
                                </>
                            ) : (
                                <div className="mt-4 space-y-1.5">
                                    <p className="clay-h2">
                                        No tuition account yet.
                                    </p>
                                    <p className="clay-body">
                                        Your term and balance will appear here
                                        when an account is available.
                                    </p>
                                </div>
                            )}
                        </div>
                    </section>

                    {/* -- Assistance eligibility: on-demand check, no request needed -- */}
                    {eligibilityVisible && (
                        <section
                            ref={eligibilityRef}
                            aria-labelledby="eligibility-heading"
                            className="clay-card scroll-mt-6 p-5 sm:p-6"
                        >
                            <div className="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <p
                                        id="eligibility-heading"
                                        className="bulletin-eyebrow"
                                    >
                                        Assistance eligibility
                                    </p>
                                    <p className="clay-meta mt-1">
                                        {eligibility?.policy_version
                                            ? `Policy ${eligibility.policy_version} · checked automatically`
                                            : 'No active policy'}
                                    </p>
                                </div>
                                <div className="flex shrink-0 items-center gap-3">
                                    <span
                                        className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold ${
                                            eligibility?.eligible
                                                ? 'bg-[var(--status-resolved)]/10 text-[var(--status-resolved)]'
                                                : 'bg-[var(--clay-surface-soft)] text-[var(--clay-text-muted)]'
                                        }`}
                                    >
                                        <BadgeCheck className="size-3.5" />
                                        {eligibility?.eligible
                                            ? 'Eligible'
                                            : 'Not eligible'}
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setEligibilityVisible(false)
                                        }
                                        className="clay-meta clay-focus rounded-full transition-colors hover:text-[var(--clay-text)]"
                                    >
                                        Hide
                                    </button>
                                </div>
                            </div>

                            {eligibility?.eligible ? (
                                <p className="bulletin-figure mt-4 text-4xl sm:text-5xl">
                                    {formatUsdc(
                                        eligibility.eligible_amount_base_units,
                                    )}
                                </p>
                            ) : (
                                <p className="clay-body mt-4 max-w-md">
                                    You do not currently meet the assistance
                                    gates below. They are re-checked
                                    automatically — nothing to file.
                                </p>
                            )}

                            <ul className="mt-6 grid gap-x-6 gap-y-2.5 border-t border-[var(--clay-border)] pt-5 sm:grid-cols-2">
                                {(eligibility?.checks ?? []).map((check) => (
                                    <li
                                        key={check.key}
                                        className="flex items-center gap-2.5 text-sm"
                                    >
                                        <span
                                            aria-hidden="true"
                                            className={`size-2 shrink-0 rounded-full ${
                                                check.passed
                                                    ? 'bg-[var(--status-resolved)]'
                                                    : 'bg-[var(--status-urgent)]'
                                            }`}
                                        />
                                        <span
                                            className={
                                                check.passed
                                                    ? 'text-[var(--clay-text)]'
                                                    : 'text-[var(--clay-text-muted)]'
                                            }
                                        >
                                            {check.label}
                                        </span>
                                    </li>
                                ))}
                            </ul>

                            <p className="clay-meta mt-5">
                                A preview, not an approval — any disbursement
                                still follows school review.
                            </p>
                        </section>
                    )}

                    {/* -- Quick actions -- */}
                    <nav
                        aria-label="Quick actions"
                        className="grid grid-cols-2 gap-3 sm:grid-cols-3"
                    >
                        <button
                            type="button"
                            onClick={openEligibility}
                            className="clay-card clay-press flex flex-col items-start gap-3 p-4 text-left text-sm font-medium transition-transform hover:-translate-y-0.5"
                        >
                            <span className="clay-icon-chip size-9">
                                <BadgeCheck className="size-4" />
                            </span>
                            Check eligibility
                        </button>

                        {[
                            {
                                href: paymentsIndex().url,
                                label: 'Payments',
                                icon: ReceiptText,
                            },
                            {
                                href: financialAssistanceIndex().url,
                                label: 'Financial aid',
                                icon: ArrowDownLeft,
                            },
                            {
                                href: '#ask-eduflow',
                                label: 'Ask EduFlow',
                                icon: Sparkles,
                            },
                        ].map(({ href, label, icon: Icon }) =>
                            href.startsWith('#') ? (
                                <a
                                    key={label}
                                    href={href}
                                    className="clay-card clay-press flex flex-col items-start gap-3 p-4 text-sm font-medium transition-transform hover:-translate-y-0.5"
                                >
                                    <span className="clay-icon-chip size-9">
                                        <Icon className="size-4" />
                                    </span>
                                    {label}
                                </a>
                            ) : (
                                <Link
                                    key={label}
                                    href={href}
                                    className="clay-card clay-press flex flex-col items-start gap-3 p-4 text-sm font-medium transition-transform hover:-translate-y-0.5"
                                >
                                    <span className="clay-icon-chip size-9">
                                        <Icon className="size-4" />
                                    </span>
                                    {label}
                                </Link>
                            ),
                        )}
                    </nav>

                    {/* -- Recent activity -- */}
                    <section
                        aria-labelledby="activity-heading"
                        className="space-y-4"
                    >
                        <div className="flex items-baseline justify-between gap-4">
                            <h2 id="activity-heading" className="clay-h2">
                                Recent activity
                            </h2>
                            <Link
                                href={paymentsIndex()}
                                className="clay-meta transition-colors hover:text-[var(--clay-text)]"
                            >
                                View all
                            </Link>
                        </div>

                        {transactions.length === 0 ? (
                            <div className="clay-card p-8 text-center">
                                <p className="clay-title">No payouts yet.</p>
                                <p className="clay-body mx-auto mt-1.5 max-w-md">
                                    Approved assistance lands here as confirmed{' '}
                                    {totals?.currency ?? 'USDC'} transactions.
                                </p>
                            </div>
                        ) : (
                            <ul className="flex flex-col gap-3">
                                {transactions.map((tx, index) => (
                                    <li
                                        key={tx.id}
                                        className="clay-card clay-rise p-4 sm:p-5"
                                        style={
                                            {
                                                '--stagger': Math.min(index, 8),
                                            } as React.CSSProperties
                                        }
                                    >
                                        <div className="flex items-start gap-3">
                                            <span className="clay-icon-chip size-9 shrink-0">
                                                <ArrowDownLeft className="size-4" />
                                            </span>
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                    <span className="clay-title">
                                                        +{tx.amount}{' '}
                                                        {tx.currency}
                                                    </span>
                                                    <span
                                                        className={`rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider ${statusPillClass(tx.status)}`}
                                                    >
                                                        {tx.status_label}
                                                    </span>
                                                    <span className="clay-meta">
                                                        {tx.type_label}
                                                    </span>
                                                </div>
                                                <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1">
                                                    <span className="clay-meta">
                                                        {tx.network}
                                                    </span>
                                                    <span className="clay-meta">
                                                        {tx.executed_at}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    {/* -- Ask EduFlow -- */}
                    <section id="ask-eduflow">
                        <AskEduFlow
                            suggestedQuestions={suggestedQuestions}
                            displayCurrency={currency?.display}
                            rateDescription={currency?.rate_description}
                        />
                    </section>

                    {/* -- Assistance requests -- */}
                    <section
                        aria-labelledby="requests-heading"
                        className="space-y-4"
                    >
                        <div className="flex items-baseline justify-between gap-4">
                            <h2 id="requests-heading" className="clay-h2">
                                Your assistance requests
                            </h2>
                            <span className="clay-meta">
                                {requests.length} total
                            </span>
                        </div>

                        {requests.length === 0 ? (
                            <div className="clay-card p-8 text-center">
                                <p className="clay-title">
                                    No assistance cases on file.
                                </p>
                                <p className="clay-body mx-auto mt-1.5 max-w-md">
                                    When the school evaluates you for support,
                                    the decision and its explanation appear
                                    here.
                                </p>
                            </div>
                        ) : (
                            <ul className="flex flex-col gap-3">
                                {requests.map((request, index) => (
                                    <li
                                        key={request.id}
                                        className="clay-card clay-rise p-4 sm:p-5"
                                        style={
                                            {
                                                '--stagger': Math.min(index, 8),
                                            } as React.CSSProperties
                                        }
                                    >
                                        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                            <div className="min-w-0 space-y-2">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <Link
                                                        href={show({
                                                            assistanceRequest:
                                                                request.id,
                                                        })}
                                                        className="clay-focus inline-flex items-center gap-2 rounded-sm font-semibold underline-offset-4 hover:underline"
                                                    >
                                                        {formatAidLabel(
                                                            request.type,
                                                        )}{' '}
                                                        assistance{' '}
                                                        <span className="text-[var(--clay-text-muted)]">
                                                            #{request.id}
                                                        </span>
                                                        <ArrowRight
                                                            aria-hidden="true"
                                                            className="size-4"
                                                        />
                                                    </Link>

                                                    {request.is_split && (
                                                        <Badge
                                                            variant="secondary"
                                                            className="gap-1 border border-[var(--status-pending)]/40 bg-[var(--status-pending)]/10 text-[10px] text-[var(--status-pending)]"
                                                        >
                                                            <Sparkles className="size-2.5" />
                                                            Autonomous Split
                                                        </Badge>
                                                    )}
                                                </div>
                                                <p className="clay-meta">
                                                    Submitted{' '}
                                                    <time
                                                        dateTime={
                                                            request.submitted_at
                                                        }
                                                    >
                                                        {formatSubmittedAt(
                                                            request.submitted_at,
                                                        )}
                                                    </time>
                                                </p>
                                            </div>
                                            <div className="flex flex-wrap items-center justify-between gap-4 sm:justify-end">
                                                <div className="text-right">
                                                    <span className="tabular-nums font-semibold">
                                                        {formatUsdc(
                                                            request.requested_amount,
                                                        )}
                                                    </span>
                                                    {request.is_split && (
                                                        <p className="clay-meta">
                                                            {formatUsdc(
                                                                request.auto_approved_amount,
                                                            )}{' '}
                                                            auto · remainder
                                                            pending
                                                        </p>
                                                    )}
                                                </div>
                                                <RequestStatus
                                                    status={request.status}
                                                />
                                            </div>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            </div>
        </>
    );
}

StudentDashboard.layout = {
    breadcrumbs: [{ title: 'Student dashboard', href: dashboard() }],
};
