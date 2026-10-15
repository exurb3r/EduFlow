import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowDownLeft,
    BookOpen,
    Calendar,
    ExternalLink,
    Eye,
    EyeOff,
    HandCoins,
    HelpCircle,
    Link2,
    MessageSquare,
    ReceiptText,
    Wallet,
} from 'lucide-react';
import { useState } from 'react';

import { dashboard } from '@/routes';
import { index as financialAssistanceIndex } from '@/routes/financial-assistance';
import { index as paymentsIndex } from '@/routes/payments';
import { formatUsdc } from '@/lib/financial-aid';

import type {
    AssistanceRequestItem,
    Auth,
    DashboardStats,
    OptionItem,
    Paginated,
    QuickResource,
} from '@/types';

const resourceIcons: Record<string, typeof BookOpen> = {
    'book-open': BookOpen,
    calendar: Calendar,
    'help-circle': HelpCircle,
    'message-square': MessageSquare,
};

interface DashboardProps {
    requests: Paginated<AssistanceRequestItem>;
    filters: {
        status: string;
        search: string;
    };
    stats: DashboardStats;
    categories: OptionItem[];
    priorities: OptionItem[];
    quickResources: QuickResource[];
    finance?: FinanceSummary | null;
}

interface FinanceTuitionAccount {
    term: string;
    total_amount: string;
    paid_amount: string;
    remaining_amount: string;
    remaining_amount_fiat?: string;
    total_amount_fiat?: string;
    paid_amount_fiat?: string;
}

interface FinanceTransaction {
    id: number;
    type: string;
    type_label: string;
    amount: number;
    currency: string;
    status: string;
    status_label: string;
    tx_hash: string | null;
    network: string;
    executed_at: string | null;
}

interface FinanceSummary {
    tuitionAccount: FinanceTuitionAccount | null;
    wallet: {
        address: string | null;
    };
    totals: {
        confirmed: number;
        currency: string;
    };
    recentTransactions: FinanceTransaction[];
}

function txStatusPillClass(status: string): string {
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

export default function Dashboard({
    quickResources = [],
    finance = null,
}: DashboardProps) {
    const { auth } = usePage<{ auth: Auth }>().props;

    const [balanceVisible, setBalanceVisible] = useState(true);

    const firstName = (auth.user?.name || 'Student').split(' ')[0];

    return (
        <>
            <Head title="Dashboard" />

            <div className="bulletin clay-ambient min-h-full w-full">
                {/* 8pt grid: 4 / 8 / 12 / 16 / 24 / 32 / 48 / 64 */}
                <div className="mx-auto flex w-full max-w-6xl flex-col gap-8 px-4 py-6 sm:gap-10 sm:px-6 sm:py-10 lg:px-8 lg:py-12">
                    {/* -- Header: greeting -- */}
                    <header className="flex flex-col gap-6">
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
                                Your tuition balance and payouts — in one place.
                            </p>
                        </div>

                        {/* -- Balance hero: hierarchy level 1 -- */}
                        <div className="grid gap-3 sm:gap-4 lg:grid-cols-3">
                            <section
                                aria-labelledby="balance-heading"
                                className="clay-card p-5 sm:p-6 lg:col-span-2"
                            >
                                <div className="flex items-start justify-between gap-4">
                                    <div>
                                        <p
                                            id="balance-heading"
                                            className="bulletin-eyebrow"
                                        >
                                            Outstanding tuition balance
                                        </p>
                                        <p className="clay-meta mt-1">
                                            {finance?.tuitionAccount?.term ??
                                                'No active term'}
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setBalanceVisible((value) => !value)
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
                                </div>

                                {finance?.tuitionAccount ? (
                                    <>
                                        <p className="bulletin-figure mt-4 text-4xl sm:text-5xl">
                                            {balanceVisible
                                                ? formatUsdc(
                                                      finance.tuitionAccount
                                                          .remaining_amount,
                                                  )
                                                : '••••••••'}
                                        </p>
                                        {finance.tuitionAccount
                                            .remaining_amount_fiat && (
                                            <p className="clay-meta mt-2 text-[var(--clay-text-muted)]">
                                                ≈{' '}
                                                {
                                                    finance.tuitionAccount
                                                        .remaining_amount_fiat
                                                }
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
                                                              finance
                                                                  .tuitionAccount
                                                                  .total_amount,
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
                                                              finance
                                                                  .tuitionAccount
                                                                  .paid_amount,
                                                          )
                                                        : '••••'}
                                                </dd>
                                            </div>
                                        </dl>
                                    </>
                                ) : (
                                    <div className="mt-4 space-y-1.5">
                                        <p className="clay-h2">
                                            No student balance on file.
                                        </p>
                                        <p className="clay-body">
                                            This account has no linked student
                                            record or active tuition account.
                                            Contact the administration to link
                                            one.
                                        </p>
                                    </div>
                                )}
                            </section>

                            <section
                                aria-labelledby="received-heading"
                                className="clay-card flex flex-col p-5 sm:p-6"
                            >
                                <p
                                    id="received-heading"
                                    className="bulletin-eyebrow"
                                >
                                    Total received
                                </p>
                                <p className="bulletin-figure mt-3 text-3xl">
                                    {finance?.totals.confirmed ?? 0}{' '}
                                    <span className="text-sm font-semibold text-[var(--clay-text-muted)]">
                                        {finance?.totals.currency ?? 'USDC'}
                                    </span>
                                </p>

                                <div className="mt-auto pt-6">
                                    {finance?.wallet.address ? (
                                        <Link
                                            href={paymentsIndex()}
                                            className="clay-focus flex items-center gap-2.5 rounded-full border border-[var(--clay-border)] px-4 py-2.5 text-xs transition-colors hover:bg-[var(--clay-surface-soft)]"
                                        >
                                            <Wallet className="size-4 text-[var(--clay-text-muted)]" />
                                            <span className="clay-meta truncate">
                                                {finance.wallet.address.slice(
                                                    0,
                                                    6,
                                                )}
                                                …
                                                {finance.wallet.address.slice(
                                                    -4,
                                                )}
                                            </span>
                                        </Link>
                                    ) : (
                                        <p className="clay-meta">
                                            No payout wallet linked yet
                                        </p>
                                    )}
                                </div>
                            </section>
                        </div>

                        {/* -- Quick actions -- */}
                        <nav
                            aria-label="Quick actions"
                            className="grid grid-cols-2 gap-3"
                        >
                            <Link
                                href={paymentsIndex()}
                                className="clay-card clay-press flex flex-col items-start gap-3 p-4 text-sm font-medium transition-transform hover:-translate-y-0.5"
                            >
                                <span className="clay-icon-chip size-9">
                                    <ReceiptText className="size-4" />
                                </span>
                                Transaction history
                            </Link>
                            <Link
                                href={financialAssistanceIndex()}
                                className="clay-card clay-press flex flex-col items-start gap-3 p-4 text-sm font-medium transition-transform hover:-translate-y-0.5"
                            >
                                <span className="clay-icon-chip size-9">
                                    <HandCoins className="size-4" />
                                </span>
                                Financial aid
                            </Link>
                        </nav>
                    </header>

                    {/* -- Recent activity -- */}
                    <section
                        aria-labelledby="activity-heading"
                        className="flex flex-col gap-4"
                    >
                        <div className="flex items-baseline justify-between gap-4">
                            <div>
                                <h2
                                    id="activity-heading"
                                    className="bulletin-eyebrow"
                                >
                                    Recent activity
                                </h2>
                                <p className="clay-body mt-1">
                                    Latest disbursements to your wallet, newest
                                    first.
                                </p>
                            </div>
                            <Link
                                href={paymentsIndex()}
                                className="clay-meta shrink-0 transition-colors hover:text-[var(--clay-text)]"
                            >
                                View all
                            </Link>
                        </div>

                        {!finance || finance.recentTransactions.length === 0 ? (
                            <div className="clay-card p-8 text-center">
                                <p className="clay-title">No payouts yet.</p>
                                <p className="clay-body mx-auto mt-1.5 max-w-md">
                                    Approved assistance lands here as confirmed{' '}
                                    {finance?.totals.currency ?? 'USDC'}{' '}
                                    transactions with its on-chain trail.
                                </p>
                            </div>
                        ) : (
                            <ul className="flex flex-col gap-3">
                                {finance.recentTransactions.map((tx, index) => (
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
                                                        className={`rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider ${txStatusPillClass(tx.status)}`}
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

                    {/* -- Quick resources -- */}
                    {quickResources.length > 0 && (
                        <section className="flex flex-col gap-4">
                            <h2 className="bulletin-eyebrow">
                                Campus resources
                            </h2>
                            <ul className="grid gap-3 sm:grid-cols-2 sm:gap-4">
                                {quickResources.map((resource, index) => {
                                    const Icon =
                                        resourceIcons[resource.icon] ?? Link2;

                                    return (
                                        <li
                                            key={resource.title}
                                            className="clay-card clay-rise p-4 sm:p-5"
                                            style={
                                                {
                                                    '--stagger': Math.min(
                                                        index,
                                                        8,
                                                    ),
                                                } as React.CSSProperties
                                            }
                                        >
                                            <div className="flex items-start gap-3">
                                                <span className="clay-icon-chip size-9 shrink-0">
                                                    <Icon className="size-4" />
                                                </span>
                                                <div className="min-w-0 flex-1">
                                                    {resource.url ? (
                                                        <a
                                                            href={resource.url}
                                                            target="_blank"
                                                            rel="noreferrer noopener"
                                                            className="clay-focus group inline-flex items-center gap-1.5 rounded-sm text-sm font-semibold text-[var(--clay-ink)] transition-colors hover:text-[var(--clay-primary-bright)]"
                                                        >
                                                            {resource.title}
                                                            <ExternalLink className="size-3 text-[var(--clay-text-faint)] transition-all duration-200 group-hover:-translate-y-0.5 group-hover:translate-x-0.5 group-hover:text-[var(--clay-primary-bright)]" />
                                                        </a>
                                                    ) : (
                                                        <span className="text-sm font-semibold text-[var(--clay-ink)]">
                                                            {resource.title}
                                                        </span>
                                                    )}
                                                    <p className="mt-0.5 text-xs leading-relaxed text-[var(--clay-text-muted)]">
                                                        {resource.description}
                                                    </p>
                                                </div>
                                            </div>
                                        </li>
                                    );
                                })}
                            </ul>
                        </section>
                    )}
                </div>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
