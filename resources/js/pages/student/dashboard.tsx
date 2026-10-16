import {
    Head,
    Link,
    router,
    usePage,
    usePoll,
} from '@inertiajs/react';
import {
    ArrowDownLeft,
    ArrowRight,
    ArrowUpRight,
    BadgeCheck,
    Check,
    CheckCircle2,
    ChevronRight,
    Clock,
    Copy,
    ExternalLink,
    Eye,
    EyeOff,
    Loader2,
    Radio,
    ReceiptText,
    RefreshCw,
    TriangleAlert,
    Wallet,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

import { StudentPaymentTracker } from '@/components/student-payment-tracker';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { formatUsdc } from '@/lib/financial-aid';
import { index as financialAssistanceIndex } from '@/routes/financial-assistance';
import { index as paymentsIndex } from '@/routes/payments';
import { dashboard, pay as studentPay } from '@/routes/student';
import { show as walletShow } from '@/routes/student/wallet';
import type {
    PaymentTransaction,
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

function shortHash(hash: string): string {
    return `${hash.slice(0, 10)}…${hash.slice(-6)}`;
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
    const canPay = hasWallet && remainingBase > 0;
    const [receipt, setReceipt] = useState<TuitionAttempt | null>(null);

    const activeReceipt = receipt
        ? (tuitionAttempts.find(
              (a) =>
                  a.id === receipt.id ||
                  Boolean(
                      a.request_key && a.request_key === receipt.request_key,
                  ),
          ) ?? receipt)
        : null;

    const inFlightAttempt =
        tuitionAttempts?.find(
            (a) =>
                ([
                    'prepared',
                    'submitting',
                    'accepted',
                    'awaiting_verification',
                    'unknown',
                ].includes(a.state) &&
                    a.stage !== 'needs_operator_review' &&
                    a.stage !== 'preflight_blocked' &&
                    a.stage !== 'quote_expired_unsubmitted') ||
                (a.state === 'verified' && !a.posted),
        ) ??
        (activeReceipt &&
        (([
            'prepared',
            'submitting',
            'accepted',
            'awaiting_verification',
            'unknown',
        ].includes(activeReceipt.state) &&
            activeReceipt.stage !== 'needs_operator_review' &&
            activeReceipt.stage !== 'preflight_blocked' &&
            activeReceipt.stage !== 'quote_expired_unsubmitted') ||
            (activeReceipt.state === 'verified' && !activeReceipt.posted))
            ? activeReceipt
            : null);
    const hasInFlight = Boolean(inFlightAttempt);

    const trackerAttempts = useMemo(() => {
        if (!receipt) {
            return tuitionAttempts;
        }
        const alreadyPresent = tuitionAttempts.some(
            (a) =>
                a.id === receipt.id ||
                Boolean(a.request_key && a.request_key === receipt.request_key),
        );
        if (alreadyPresent) {
            return tuitionAttempts;
        }
        return [receipt, ...tuitionAttempts];
    }, [receipt, tuitionAttempts]);
    const [txFilter, setTxFilter] = useState<'all' | 'inbound' | 'outbound'>(
        'all',
    );
    const [selectedTx, setSelectedTx] = useState<PaymentTransaction | null>(
        null,
    );
    const [monitorDrawerOpen, setMonitorDrawerOpen] = useState(false);
    const [isRefreshingTxs, setIsRefreshingTxs] = useState(false);
    const [copiedTxHash, setCopiedTxHash] = useState<string | null>(null);

    const [inlineDismissedId, setInlineDismissedId] = useState<number | null>(
        () => {
            try {
                const stored = sessionStorage.getItem(
                    'eduflow_dismissed_tuition_attempt',
                );
                return stored ? Number(stored) : null;
            } catch {
                return null;
            }
        },
    );

    const latestTrackerAttempt = trackerAttempts[0] ?? null;
    const showInlineTracker =
        hasInFlight ||
        (latestTrackerAttempt !== null &&
            latestTrackerAttempt.id !== inlineDismissedId);

    const { props } = usePage();
    const notificationSummary = props.notificationSummary;

    // Track seen notification and transaction IDs to notify on incoming funds
    const seenNotificationIdsRef = useRef<Set<string>>(
        new Set(notificationSummary?.recent?.map((n) => n.id) ?? []),
    );
    const lastToastRef = useRef<{ text: string; time: number } | null>(null);
    const prevBalanceRef = useRef<string | null>(snapshot?.balance ?? null);
    const seenTxIdsRef = useRef<Set<string | number>>(
        new Set(transactions.map((tx) => tx.id)),
    );
    const isMountedRef = useRef(false);

    const showReceivedToast = (title: string, description?: string | null) => {
        const now = Date.now();
        if (
            lastToastRef.current &&
            lastToastRef.current.text === title &&
            now - lastToastRef.current.time < 5000
        ) {
            return;
        }
        lastToastRef.current = { text: title, time: now };
        toast.success(title, {
            description: description ?? undefined,
        });
    };

    // Watch for new notifications from background polling
    useEffect(() => {
        if (!notificationSummary?.recent) {
            return;
        }

        for (const n of notificationSummary.recent) {
            if (!seenNotificationIdsRef.current.has(n.id)) {
                seenNotificationIdsRef.current.add(n.id);
                if (n.readAt === null) {
                    showReceivedToast(n.title, n.body);
                }
            }
        }
    }, [notificationSummary]);

    // Watch for new inbound transactions and on-chain balance increases
    useEffect(() => {
        if (!isMountedRef.current) {
            isMountedRef.current = true;
            return;
        }

        for (const tx of transactions) {
            if (!seenTxIdsRef.current.has(tx.id)) {
                seenTxIdsRef.current.add(tx.id);
                const isInbound =
                    tx.direction === 'inbound' ||
                    tx.type === 'student_assistance' ||
                    tx.type === 'refund';
                if (isInbound) {
                    showReceivedToast(
                        `Received ${tx.amount} USDC`,
                        tx.description || 'Payment arrived in your wallet.',
                    );
                }
            }
        }

        if (snapshot?.balance && prevBalanceRef.current !== null) {
            try {
                const prev = parseFloat(prevBalanceRef.current);
                const current = parseFloat(snapshot.balance);
                if (current > prev) {
                    const diff = current - prev;
                    showReceivedToast(
                        `Received +${diff.toFixed(2)} USDC`,
                        'Wallet balance updated on Arc.',
                    );
                }
            } catch {
                // Ignore parse errors
            }
        }
        prevBalanceRef.current = snapshot?.balance ?? null;
    }, [snapshot?.balance, transactions]);

    const prevPostedRef = useRef(Boolean(activeReceipt?.posted));
    useEffect(() => {
        if (!prevPostedRef.current && activeReceipt?.posted) {
            toast.success('Tuition payment posted!', {
                description: `${formatBaseUnits(activeReceipt.applied_base_units)} USDC successfully credited to your tuition ledger.`,
            });
        }
        prevPostedRef.current = Boolean(activeReceipt?.posted);
    }, [activeReceipt?.posted, activeReceipt?.applied_base_units]);

    const shouldFastPoll =
        hasInFlight ||
        Boolean(
            activeReceipt &&
            !activeReceipt.posted &&
            activeReceipt.state !== 'failed' &&
            !activeReceipt.simulated,
        );
    usePoll(shouldFastPoll ? 2500 : 8000, {
        only: [
            'tuitionAttempts',
            'tuitionAccount',
            'wallet',
            'recentTransactions',
            'totals',
            'notificationSummary',
        ],
    });

    const copyTxHash = (hash: string, event?: React.MouseEvent) => {
        event?.stopPropagation();
        navigator.clipboard.writeText(hash);
        setCopiedTxHash(hash);
        toast.success('Transaction hash copied', {
            description: 'Verify it on Arcscan block explorer.',
        });
        setTimeout(() => {
            setCopiedTxHash(null);
        }, 2000);
    };

    const refreshTransactions = () => {
        setIsRefreshingTxs(true);
        router.reload({
            only: [
                'recentTransactions',
                'wallet',
                'totals',
                'notificationSummary',
            ],
            onFinish: () => {
                setIsRefreshingTxs(false);
                toast.success('Transactions refreshed from the chain.');
            },
        });
    };

    const filteredTransactions = transactions.filter((tx) => {
        const isInbound =
            tx.direction === 'inbound' ||
            tx.type === 'student_assistance' ||
            tx.type === 'refund';
        if (txFilter === 'inbound') {
            return isInbound;
        }
        if (txFilter === 'outbound') {
            return !isInbound;
        }
        return true;
    });
    const openPay = (amount?: string | React.MouseEvent) => {
        const amt = typeof amount === 'string' ? amount : undefined;
        router.visit(
            studentPay.url({
                query: {
                    mode: 'tuition',
                    account: tuitionAccount?.id,
                    amount: amt,
                },
            }),
        );
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
                <div className="mx-auto flex w-full max-w-[1440px] flex-col gap-8 px-4 py-6 sm:gap-10 sm:px-6 sm:py-10 lg:px-8 lg:py-12">
                    <div className="flex min-w-0 flex-col gap-8 sm:gap-10">
                        {/* -- Header: greeting + actions -- */}
                        <header className="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                            <div className="min-w-0">
                                <p className="bulletin-eyebrow">
                                    OpenSinop &middot; Northstar Learning Center
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

                            <div className="flex flex-wrap items-center gap-3">
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
                            </div>
                        </header>

                        {/* -- Active Tuition Payment Rail Tracker -- */}
                        {showInlineTracker && (
                            <section
                                aria-labelledby="live-tuition-rail-heading"
                                className="flex flex-col gap-2.5"
                            >
                                <div className="flex items-center justify-between px-1">
                                    <h2
                                        id="live-tuition-rail-heading"
                                        className="bulletin-eyebrow flex items-center gap-2"
                                    >
                                        <Radio className="size-3.5 text-[var(--clay-primary-bright)] animate-pulse" />
                                        <span>Tuition payment rail</span>
                                        {hasInFlight && (
                                            <span className="rounded-full border border-amber-500/20 bg-amber-500/10 px-2 py-0.5 text-[10px] font-semibold text-amber-400 animate-pulse">
                                                Live Transfer Active
                                            </span>
                                        )}
                                    </h2>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setMonitorDrawerOpen(true)
                                        }
                                        className="clay-focus inline-flex items-center gap-1 text-xs font-semibold text-[var(--clay-primary-bright)] hover:underline"
                                    >
                                        Rail Monitor Drawer
                                        <ArrowRight className="size-3" />
                                    </button>
                                </div>

                                <StudentPaymentTracker
                                    attempts={trackerAttempts}
                                    tuitionAccount={tuitionAccount}
                                    walletBalance={snapshot?.balance}
                                    onOpenPay={() => openPay()}
                                    onDone={() => {
                                        if (latestTrackerAttempt) {
                                            setInlineDismissedId(
                                                latestTrackerAttempt.id,
                                            );
                                            try {
                                                sessionStorage.setItem(
                                                    'eduflow_dismissed_tuition_attempt',
                                                    String(
                                                        latestTrackerAttempt.id,
                                                    ),
                                                );
                                            } catch {
                                                // Ignore storage errors
                                            }
                                        }
                                        setMonitorDrawerOpen(false);
                                    }}
                                />
                            </section>
                        )}

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
                                                                    0) *
                                                                    1_000_000,
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

                                        {Number(
                                            tuitionAccount.held_amount ?? 0,
                                        ) > 0 && (
                                            <p className="mt-2 text-xs font-medium text-amber-500">
                                                {balanceVisible
                                                    ? `Includes ${formatUsdc(
                                                          tuitionAccount.held_amount!,
                                                      )} held in flight`
                                                    : '••••'}
                                            </p>
                                        )}

                                        {hasInFlight && inFlightAttempt ? (
                                            <div className="mt-3 flex items-center justify-between rounded-xl border border-amber-500/30 bg-amber-500/10 p-2.5 text-xs text-amber-300">
                                                <div className="flex min-w-0 items-center gap-2">
                                                    <Loader2 className="size-3.5 shrink-0 animate-spin text-amber-400" />
                                                    <span className="truncate font-medium">
                                                        {formatBaseUnits(
                                                            inFlightAttempt.amount_base_units,
                                                        )}{' '}
                                                        USDC transfer in flight
                                                    </span>
                                                </div>
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setMonitorDrawerOpen(
                                                            true,
                                                        )
                                                    }
                                                    className="clay-focus ml-2 shrink-0 font-semibold text-amber-200 underline hover:text-amber-100"
                                                >
                                                    Track
                                                </button>
                                            </div>
                                        ) : activeReceipt?.posted ? (
                                            <div className="mt-3 flex items-center justify-between rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-2.5 text-xs text-emerald-300">
                                                <div className="flex min-w-0 items-center gap-2">
                                                    <CheckCircle2 className="size-3.5 shrink-0 text-emerald-400" />
                                                    <span className="truncate font-medium">
                                                        Last payment of{' '}
                                                        {formatBaseUnits(
                                                            activeReceipt.applied_base_units,
                                                        )}{' '}
                                                        USDC posted
                                                    </span>
                                                </div>
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setMonitorDrawerOpen(
                                                            true,
                                                        )
                                                    }
                                                    className="clay-focus ml-2 shrink-0 font-semibold text-emerald-200 underline hover:text-emerald-100"
                                                >
                                                    Receipt
                                                </button>
                                            </div>
                                        ) : null}

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
                                                    tuitionAccount.held_amount ??
                                                        0,
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
                                            Your term and balance will appear
                                            here when an account is available.
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
                                    {(eligibility?.checks ?? []).map(
                                        (check) => (
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
                                        ),
                                    )}
                                </ul>

                                <p className="clay-meta mt-5">
                                    A preview, not an approval — any
                                    disbursement still follows school review.
                                </p>
                            </section>
                        )}

                        {/* -- Quick actions -- */}
                        <nav
                            aria-label="Quick actions"
                            className="grid grid-cols-1 gap-3 sm:grid-cols-3"
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
                            ].map(({ href, label, icon: Icon }) => (
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
                            ))}
                        </nav>

                        {/* -- Recent transactions -- */}
                        <section
                            aria-labelledby="activity-heading"
                            className="space-y-4"
                        >
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                                <div>
                                    <div className="flex items-center gap-2">
                                        <p className="bulletin-eyebrow flex items-center gap-1.5">
                                            <ReceiptText className="size-3.5 text-[var(--clay-primary-bright)]" />
                                            OpenSinop &middot; Arc Testnet
                                            Ledger
                                        </p>
                                        {transactions.length > 0 && (
                                            <span className="rounded-full bg-[var(--clay-surface-soft)] px-2 py-0.5 text-[10px] font-semibold text-[var(--clay-text-muted)]">
                                                {transactions.length} record
                                                {transactions.length === 1
                                                    ? ''
                                                    : 's'}
                                            </span>
                                        )}
                                    </div>
                                    <h2
                                        id="activity-heading"
                                        className="clay-h2 mt-1"
                                    >
                                        Recent transactions
                                    </h2>
                                    <p className="clay-meta mt-0.5">
                                        Every verified financial aid
                                        disbursement, tuition payment, and
                                        campus bill on Arc.
                                    </p>
                                </div>

                                <div className="flex flex-wrap items-center gap-2">
                                    {transactions.length > 0 && (
                                        <div className="flex items-center rounded-full border border-[var(--clay-border)] bg-[var(--clay-surface-soft)] p-1 text-xs">
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setTxFilter('all')
                                                }
                                                className={`clay-focus rounded-full px-2.5 py-1 text-xs font-semibold transition-colors ${
                                                    txFilter === 'all'
                                                        ? 'bg-[var(--clay-surface)] text-[var(--clay-text)] shadow-xs'
                                                        : 'text-[var(--clay-text-muted)] hover:text-[var(--clay-text)]'
                                                }`}
                                            >
                                                All ({transactions.length})
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setTxFilter('inbound')
                                                }
                                                className={`clay-focus inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold transition-colors ${
                                                    txFilter === 'inbound'
                                                        ? 'bg-[var(--clay-surface)] text-[var(--status-resolved)] shadow-xs'
                                                        : 'text-[var(--clay-text-muted)] hover:text-[var(--clay-text)]'
                                                }`}
                                            >
                                                <span className="size-1.5 rounded-full bg-[var(--status-resolved)]" />
                                                Received
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setTxFilter('outbound')
                                                }
                                                className={`clay-focus inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold transition-colors ${
                                                    txFilter === 'outbound'
                                                        ? 'bg-[var(--clay-surface)] text-sky-400 shadow-xs'
                                                        : 'text-[var(--clay-text-muted)] hover:text-[var(--clay-text)]'
                                                }`}
                                            >
                                                <span className="size-1.5 rounded-full bg-sky-400" />
                                                Paid
                                            </button>
                                        </div>
                                    )}

                                    <button
                                        type="button"
                                        onClick={refreshTransactions}
                                        disabled={isRefreshingTxs}
                                        className="clay-focus inline-flex size-9 items-center justify-center rounded-full border border-[var(--clay-border)] text-[var(--clay-text-muted)] transition-colors hover:bg-[var(--clay-surface-soft)] hover:text-[var(--clay-text)] disabled:opacity-50"
                                        title="Refresh recent transactions from the chain"
                                        aria-label="Refresh transactions"
                                    >
                                        <RefreshCw
                                            className={`size-3.5 ${isRefreshingTxs ? 'animate-spin' : ''}`}
                                        />
                                    </button>

                                    <Link
                                        href={paymentsIndex().url}
                                        className="clay-focus inline-flex h-9 items-center gap-1.5 rounded-full border border-[var(--clay-border)] px-3 text-xs font-semibold text-[var(--clay-text-muted)] transition-colors hover:bg-[var(--clay-surface-soft)] hover:text-[var(--clay-text)]"
                                    >
                                        Full ledger
                                        <ArrowRight className="size-3" />
                                    </Link>
                                </div>
                            </div>

                            {transactions.length === 0 ? (
                                <div className="clay-card flex flex-col items-center gap-4 p-8 text-center sm:p-12">
                                    <span className="clay-inset flex size-12 items-center justify-center rounded-full text-[var(--clay-text-muted)]">
                                        <ReceiptText className="size-5" />
                                    </span>
                                    <div className="max-w-md">
                                        <p className="clay-title">
                                            {!hasWallet
                                                ? 'No wallet linked yet.'
                                                : 'No transactions recorded yet.'}
                                        </p>
                                        <p className="clay-body mt-1.5">
                                            {!hasWallet
                                                ? 'Link a payout wallet to receive assistance disbursements and make on-chain payments.'
                                                : 'Approved assistance grants and student payments will appear here with cryptographic Arc testnet verification.'}
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        {!hasWallet ? (
                                            <Link
                                                href={walletShow()}
                                                className="clay-focus inline-flex h-9 items-center gap-2 rounded-full bg-[var(--clay-primary)] px-4 text-xs font-semibold text-[var(--clay-primary-foreground)] transition-all hover:bg-[var(--clay-primary-bright)]"
                                            >
                                                <Wallet className="size-3.5" />
                                                Link wallet
                                            </Link>
                                        ) : (
                                            <>
                                                <button
                                                    type="button"
                                                    onClick={openEligibility}
                                                    className="clay-focus inline-flex h-9 items-center gap-2 rounded-full border border-[var(--clay-border)] px-4 text-xs font-semibold transition-colors hover:bg-[var(--clay-surface-soft)]"
                                                >
                                                    <BadgeCheck className="size-3.5" />
                                                    Check eligibility
                                                </button>
                                                {canPay && (
                                                    <button
                                                        type="button"
                                                        onClick={openPay}
                                                        className="clay-focus inline-flex h-9 items-center gap-2 rounded-full bg-[var(--clay-primary)] px-4 text-xs font-semibold text-[var(--clay-primary-foreground)] transition-all hover:bg-[var(--clay-primary-bright)]"
                                                    >
                                                        <ArrowUpRight className="size-3.5" />
                                                        Pay tuition
                                                    </button>
                                                )}
                                            </>
                                        )}
                                    </div>
                                </div>
                            ) : filteredTransactions.length === 0 ? (
                                <div className="clay-card p-8 text-center">
                                    <p className="clay-title">
                                        No{' '}
                                        {txFilter === 'inbound'
                                            ? 'received'
                                            : 'paid'}{' '}
                                        transactions found.
                                    </p>
                                    <p className="clay-body mx-auto mt-1 max-w-sm">
                                        No activity matches this filter.
                                    </p>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="mt-3 rounded-full text-xs font-semibold"
                                        onClick={() => setTxFilter('all')}
                                    >
                                        Show all transactions
                                    </Button>
                                </div>
                            ) : (
                                <ul className="flex flex-col gap-3">
                                    {filteredTransactions.map((tx, index) => {
                                        const isInbound =
                                            tx.direction === 'inbound' ||
                                            tx.type === 'student_assistance' ||
                                            tx.type === 'refund';
                                        return (
                                            <li
                                                key={tx.id}
                                                onClick={() =>
                                                    setSelectedTx(tx)
                                                }
                                                className="clay-card clay-rise clay-press group relative flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5 cursor-pointer transition-all hover:border-[var(--clay-primary-bright)]/40"
                                                style={
                                                    {
                                                        '--stagger': Math.min(
                                                            index,
                                                            8,
                                                        ),
                                                    } as React.CSSProperties
                                                }
                                            >
                                                <div className="flex items-start gap-3.5 min-w-0">
                                                    <span
                                                        className={`clay-icon-chip size-10 shrink-0 ${
                                                            isInbound
                                                                ? 'border border-[var(--status-resolved)]/20 bg-[var(--status-resolved)]/10 text-[var(--status-resolved)]'
                                                                : 'border border-sky-500/20 bg-sky-500/10 text-sky-400'
                                                        }`}
                                                    >
                                                        {isInbound ? (
                                                            <ArrowDownLeft className="size-4.5" />
                                                        ) : (
                                                            <ArrowUpRight className="size-4.5" />
                                                        )}
                                                    </span>

                                                    <div className="min-w-0 flex-1">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <span className="font-semibold text-sm text-[var(--clay-text)] group-hover:text-[var(--clay-primary-bright)] transition-colors">
                                                                {tx.description ??
                                                                    tx.type_label}
                                                            </span>
                                                            <span
                                                                className={`rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider ${statusPillClass(tx.status)}`}
                                                            >
                                                                {
                                                                    tx.status_label
                                                                }
                                                            </span>
                                                        </div>

                                                        <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-[var(--clay-text-muted)]">
                                                            <span className="clay-meta">
                                                                {tx.executed_at ??
                                                                    'Recent'}
                                                            </span>
                                                            <span>
                                                                &middot;
                                                            </span>
                                                            <span className="clay-meta">
                                                                {tx.network}
                                                            </span>

                                                            {tx.tx_hash && (
                                                                <>
                                                                    <span>
                                                                        &middot;
                                                                    </span>
                                                                    <button
                                                                        type="button"
                                                                        onClick={(
                                                                            e,
                                                                        ) =>
                                                                            copyTxHash(
                                                                                tx.tx_hash!,
                                                                                e,
                                                                            )
                                                                        }
                                                                        className="clay-meta clay-focus inline-flex items-center gap-1 rounded-sm font-mono text-[11px] transition-colors hover:text-[var(--clay-text)]"
                                                                        title="Copy full transaction hash"
                                                                    >
                                                                        {shortHash(
                                                                            tx.tx_hash,
                                                                        )}
                                                                        {copiedTxHash ===
                                                                        tx.tx_hash ? (
                                                                            <Check className="size-3 text-[var(--status-resolved)]" />
                                                                        ) : (
                                                                            <Copy className="size-3" />
                                                                        )}
                                                                    </button>
                                                                </>
                                                            )}
                                                        </div>
                                                    </div>
                                                </div>

                                                <div className="flex items-center justify-between gap-4 border-t border-[var(--clay-border)] pt-3 sm:border-t-0 sm:pt-0 sm:justify-end sm:text-right">
                                                    <div>
                                                        <p
                                                            className={`clay-title tabular-nums text-base font-bold ${
                                                                isInbound
                                                                    ? 'text-[var(--status-resolved)]'
                                                                    : 'text-[var(--clay-text)]'
                                                            }`}
                                                        >
                                                            {isInbound
                                                                ? '+'
                                                                : '-'}
                                                            {Number(
                                                                tx.amount,
                                                            ).toFixed(2)}{' '}
                                                            {tx.currency}
                                                        </p>
                                                        {tx.amount_fiat && (
                                                            <p className="clay-meta mt-0.5 text-xs text-[var(--clay-text-muted)]">
                                                                &asymp;{' '}
                                                                {tx.amount_fiat}
                                                            </p>
                                                        )}
                                                    </div>

                                                    <div className="flex items-center gap-1 shrink-0">
                                                        {tx.explorer_url && (
                                                            <a
                                                                href={
                                                                    tx.explorer_url
                                                                }
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                                onClick={(e) =>
                                                                    e.stopPropagation()
                                                                }
                                                                className="clay-focus inline-flex size-8 items-center justify-center rounded-full text-[var(--clay-text-muted)] transition-colors hover:bg-[var(--clay-surface-soft)] hover:text-[var(--clay-text)]"
                                                                title="View on Arcscan block explorer"
                                                                aria-label="View on block explorer"
                                                            >
                                                                <ExternalLink className="size-3.5" />
                                                            </a>
                                                        )}
                                                        <span
                                                            className="clay-focus inline-flex size-8 items-center justify-center rounded-full text-[var(--clay-text-muted)] transition-colors group-hover:text-[var(--clay-text)]"
                                                            aria-hidden="true"
                                                        >
                                                            <ChevronRight className="size-4" />
                                                        </span>
                                                    </div>
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}

                            {/* -- Transaction Receipt Dialog -- */}
                            <Dialog
                                open={!!selectedTx}
                                onOpenChange={(open) => {
                                    if (!open) {
                                        setSelectedTx(null);
                                    }
                                }}
                            >
                                <DialogContent className="bulletin clay-card border-[var(--clay-border)] sm:max-w-lg">
                                    <DialogHeader>
                                        <div className="flex items-center gap-3">
                                            <span
                                                className={`clay-icon-chip size-10 shrink-0 ${
                                                    selectedTx?.direction ===
                                                    'inbound'
                                                        ? 'border border-[var(--status-resolved)]/20 bg-[var(--status-resolved)]/10 text-[var(--status-resolved)]'
                                                        : 'border border-sky-500/20 bg-sky-500/10 text-sky-400'
                                                }`}
                                            >
                                                {selectedTx?.direction ===
                                                'inbound' ? (
                                                    <ArrowDownLeft className="size-5" />
                                                ) : (
                                                    <ArrowUpRight className="size-5" />
                                                )}
                                            </span>
                                            <div>
                                                <DialogTitle className="clay-h2">
                                                    Transaction receipt
                                                </DialogTitle>
                                                <DialogDescription className="clay-body">
                                                    {selectedTx?.direction ===
                                                    'inbound'
                                                        ? 'Incoming disbursement credited to your student wallet.'
                                                        : 'Outgoing payment settled to the college treasury.'}
                                                </DialogDescription>
                                            </div>
                                        </div>
                                    </DialogHeader>

                                    {selectedTx && (
                                        <div className="grid gap-5">
                                            <div className="clay-inset rounded-2xl p-4 text-center">
                                                <p className="bulletin-eyebrow">
                                                    Settlement amount
                                                </p>
                                                <p
                                                    className={`bulletin-figure mt-1 text-3xl sm:text-4xl ${
                                                        selectedTx.direction ===
                                                        'inbound'
                                                            ? 'text-[var(--status-resolved)]'
                                                            : 'text-[var(--clay-text)]'
                                                    }`}
                                                >
                                                    {selectedTx.direction ===
                                                    'inbound'
                                                        ? '+'
                                                        : '-'}
                                                    {Number(
                                                        selectedTx.amount,
                                                    ).toFixed(2)}{' '}
                                                    {selectedTx.currency}
                                                </p>
                                                {selectedTx.amount_fiat && (
                                                    <p className="clay-meta mt-1 text-[var(--clay-text-muted)]">
                                                        &asymp;{' '}
                                                        {selectedTx.amount_fiat}
                                                    </p>
                                                )}
                                                <div className="mt-3 flex items-center justify-center gap-2">
                                                    <span
                                                        className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ${statusPillClass(selectedTx.status)}`}
                                                    >
                                                        <CheckCircle2 className="size-3.5" />
                                                        {
                                                            selectedTx.status_label
                                                        }
                                                    </span>
                                                </div>
                                            </div>

                                            <dl className="grid gap-3 text-sm">
                                                <div className="flex items-baseline justify-between gap-4 border-b border-[var(--clay-border)] pb-2.5">
                                                    <dt className="clay-meta">
                                                        Description
                                                    </dt>
                                                    <dd className="clay-title text-right font-medium">
                                                        {selectedTx.description ??
                                                            selectedTx.type_label}
                                                    </dd>
                                                </div>

                                                <div className="flex items-baseline justify-between gap-4 border-b border-[var(--clay-border)] pb-2.5">
                                                    <dt className="clay-meta">
                                                        Type & Flow
                                                    </dt>
                                                    <dd className="clay-title text-right">
                                                        {selectedTx.type_label}{' '}
                                                        &middot;{' '}
                                                        <span className="capitalize">
                                                            {selectedTx.direction ??
                                                                'Settlement'}
                                                        </span>
                                                    </dd>
                                                </div>

                                                <div className="flex items-baseline justify-between gap-4 border-b border-[var(--clay-border)] pb-2.5">
                                                    <dt className="clay-meta">
                                                        Executed at
                                                    </dt>
                                                    <dd className="clay-title text-right tabular-nums">
                                                        {selectedTx.executed_at ??
                                                            'Recorded'}
                                                    </dd>
                                                </div>

                                                <div className="flex items-baseline justify-between gap-4 border-b border-[var(--clay-border)] pb-2.5">
                                                    <dt className="clay-meta">
                                                        Network & Chain
                                                    </dt>
                                                    <dd className="clay-title text-right">
                                                        {selectedTx.network}{' '}
                                                        (Chain ID 5042002)
                                                    </dd>
                                                </div>

                                                {selectedTx.network_fee && (
                                                    <div className="flex items-baseline justify-between gap-4 border-b border-[var(--clay-border)] pb-2.5">
                                                        <dt className="clay-meta">
                                                            Network fee
                                                        </dt>
                                                        <dd className="clay-title text-right tabular-nums">
                                                            {
                                                                selectedTx.network_fee
                                                            }
                                                        </dd>
                                                    </div>
                                                )}

                                                {selectedTx.payer_address && (
                                                    <div className="flex items-baseline justify-between gap-4 border-b border-[var(--clay-border)] pb-2.5">
                                                        <dt className="clay-meta">
                                                            Payer address
                                                        </dt>
                                                        <dd className="flex items-center gap-1.5 font-mono text-xs">
                                                            <span>
                                                                {shortAddress(
                                                                    selectedTx.payer_address,
                                                                )}
                                                            </span>
                                                            <button
                                                                type="button"
                                                                onClick={() => {
                                                                    navigator.clipboard.writeText(
                                                                        selectedTx.payer_address!,
                                                                    );
                                                                    toast.success(
                                                                        'Payer address copied',
                                                                    );
                                                                }}
                                                                className="clay-focus text-[var(--clay-text-muted)] hover:text-[var(--clay-text)]"
                                                                title="Copy address"
                                                            >
                                                                <Copy className="size-3" />
                                                            </button>
                                                        </dd>
                                                    </div>
                                                )}

                                                {selectedTx.recipient_address && (
                                                    <div className="flex items-baseline justify-between gap-4 border-b border-[var(--clay-border)] pb-2.5">
                                                        <dt className="clay-meta">
                                                            Recipient address
                                                        </dt>
                                                        <dd className="flex items-center gap-1.5 font-mono text-xs">
                                                            <span>
                                                                {shortAddress(
                                                                    selectedTx.recipient_address,
                                                                )}
                                                            </span>
                                                            <button
                                                                type="button"
                                                                onClick={() => {
                                                                    navigator.clipboard.writeText(
                                                                        selectedTx.recipient_address!,
                                                                    );
                                                                    toast.success(
                                                                        'Recipient address copied',
                                                                    );
                                                                }}
                                                                className="clay-focus text-[var(--clay-text-muted)] hover:text-[var(--clay-text)]"
                                                                title="Copy address"
                                                            >
                                                                <Copy className="size-3" />
                                                            </button>
                                                        </dd>
                                                    </div>
                                                )}

                                                {selectedTx.tx_hash && (
                                                    <div className="flex flex-col gap-1.5 pt-1">
                                                        <dt className="clay-meta">
                                                            Transaction hash
                                                        </dt>
                                                        <dd className="clay-inset flex items-center justify-between gap-2 p-2.5 rounded-lg">
                                                            <span className="font-mono text-xs break-all select-all text-[var(--clay-text)]">
                                                                {
                                                                    selectedTx.tx_hash
                                                                }
                                                            </span>
                                                            <button
                                                                type="button"
                                                                onClick={(e) =>
                                                                    copyTxHash(
                                                                        selectedTx.tx_hash!,
                                                                        e,
                                                                    )
                                                                }
                                                                className="clay-focus inline-flex size-7 shrink-0 items-center justify-center rounded-md border border-[var(--clay-border)] bg-[var(--clay-surface)] text-[var(--clay-text-muted)] hover:text-[var(--clay-text)]"
                                                                title="Copy hash"
                                                            >
                                                                {copiedTxHash ===
                                                                selectedTx.tx_hash ? (
                                                                    <Check className="size-3.5 text-[var(--status-resolved)]" />
                                                                ) : (
                                                                    <Copy className="size-3.5" />
                                                                )}
                                                            </button>
                                                        </dd>
                                                    </div>
                                                )}
                                            </dl>

                                            <p className="clay-meta text-xs text-[var(--clay-text-muted)]">
                                                This transfer was
                                                cryptographically confirmed on
                                                Arc. Finality is reached in
                                                under 1 second and cannot be
                                                reverted.
                                            </p>

                                            <div className="flex flex-wrap items-center justify-end gap-3 pt-2">
                                                {selectedTx.explorer_url && (
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        className="clay-focus rounded-full text-xs font-semibold"
                                                        onClick={() =>
                                                            window.open(
                                                                selectedTx.explorer_url!,
                                                                '_blank',
                                                                'noopener,noreferrer',
                                                            )
                                                        }
                                                    >
                                                        <ExternalLink className="size-3.5" />
                                                        View on Arcscan
                                                    </Button>
                                                )}
                                                <Button
                                                    type="button"
                                                    className="clay-focus rounded-full bg-[var(--clay-primary)] text-xs font-semibold text-[var(--clay-primary-foreground)]"
                                                    onClick={() =>
                                                        setSelectedTx(null)
                                                    }
                                                >
                                                    Close
                                                </Button>
                                            </div>
                                        </div>
                                    )}
                                </DialogContent>
                            </Dialog>
                        </section>
                    </div>

                    {/* -- Live Rail Monitor Drawer -- */}
                    <Sheet
                        open={monitorDrawerOpen}
                        onOpenChange={setMonitorDrawerOpen}
                    >
                        <SheetContent
                            side="right"
                            className="bulletin fixed inset-y-0 right-0 z-50 flex h-full w-full flex-col border-l border-[var(--clay-border)] bg-[var(--clay-surface)] text-[var(--clay-text)] p-0 shadow-2xl overflow-y-auto sm:max-w-md"
                            style={{
                                backgroundColor: 'var(--clay-surface, #141210)',
                            }}
                        >
                            <SheetHeader className="sr-only">
                                <SheetTitle>
                                    Tuition Payment Rail Monitor
                                </SheetTitle>
                                <SheetDescription>
                                    Live tracking of Arc Testnet tuition payment
                                    steps
                                </SheetDescription>
                            </SheetHeader>
                            <div className="p-5 sm:p-6 pt-10 sm:pt-10">
                                <StudentPaymentTracker
                                    className="border-0 bg-transparent shadow-none p-0 sm:p-0"
                                    attempts={trackerAttempts}
                                    tuitionAccount={tuitionAccount}
                                    walletBalance={snapshot?.balance}
                                    onOpenPay={() => {
                                        setMonitorDrawerOpen(false);
                                        openPay();
                                    }}
                                    onDone={() => setMonitorDrawerOpen(false)}
                                />
                            </div>
                        </SheetContent>
                    </Sheet>

                    {/* -- Floating Live Rail Monitor Trigger -- */}
                    <button
                        type="button"
                        onClick={() => setMonitorDrawerOpen(true)}
                        className="clay-focus fixed bottom-6 right-6 z-40 inline-flex items-center gap-2 rounded-full border border-[var(--clay-border)] bg-[var(--clay-surface)] px-4 py-2.5 text-xs font-semibold text-[var(--clay-text)] shadow-lg backdrop-blur-md transition-all hover:-translate-y-0.5 hover:bg-[var(--clay-surface-soft)] hover:shadow-xl active:translate-y-0 active:scale-95"
                        style={{
                            backgroundColor: 'var(--clay-surface, #141210)',
                        }}
                        title="Open Live Rail Monitor drawer"
                    >
                        <Radio className="size-3 text-emerald-400 animate-pulse" />
                        <span>Rail Monitor</span>
                        {hasInFlight && (
                            <span className="flex size-2 rounded-full bg-amber-400 animate-ping" />
                        )}
                    </button>
                </div>
            </div>
        </>
    );
}

StudentDashboard.layout = {
    breadcrumbs: [{ title: 'Student dashboard', href: dashboard() }],
};
