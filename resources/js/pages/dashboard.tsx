import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowDownLeft,
    BookOpen,
    Calendar,
    ExternalLink,
    HelpCircle,
    Link2,
    MessageSquare,
    Search,
    Wallet,
} from 'lucide-react';
import { useState } from 'react';

import { formatUsdc } from '@/lib/financial-aid';
import { show as assistanceShow } from '@/routes/assistance';
import { index as financialAssistanceIndex } from '@/routes/financial-assistance';
import { index as paymentsIndex } from '@/routes/payments';
import { dashboard } from '@/routes';
import type {
    AssistancePriorityType,
    AssistanceRequestItem,
    AssistanceStatusFilter,
    AssistanceStatusType,
    Auth,
    DashboardNotificationSummary,
    DashboardStats,
    OptionItem,
    Paginated,
    QuickResource,
    StandingTone,
} from '@/types';

const resourceIcons: Record<string, typeof BookOpen> = {
    'book-open': BookOpen,
    calendar: Calendar,
    'help-circle': HelpCircle,
    'message-square': MessageSquare,
};

const STATUS_FILTERS: Array<{ value: AssistanceStatusFilter; label: string }> =
    [
        { value: 'all', label: 'All' },
        { value: 'active', label: 'Active' },
        { value: 'resolved', label: 'Resolved' },
    ];

/* -- Tone vocabulary -----------------------------------------------------
   Status and priority are the only two places colour is allowed to speak.
   Everything else in this page is neutral, so a red row always means one
   thing and never decoration. */

const statusTone: Record<AssistanceStatusType, StandingTone> = {
    pending: 'watch',
    in_progress: 'watch',
    resolved: 'good',
    closed: 'neutral',
};

const priorityTone: Record<AssistancePriorityType, StandingTone> = {
    urgent: 'action',
    high: 'action',
    medium: 'watch',
    low: 'neutral',
};

const tonePill: Record<StandingTone, string> = {
    good: 'bg-[var(--status-resolved)]/10 text-[var(--status-resolved)]',
    watch: 'bg-[var(--status-pending)]/10 text-[var(--status-pending)]',
    action: 'bg-[var(--status-urgent)]/10 text-[var(--status-urgent)]',
    neutral: 'bg-[var(--clay-surface-soft)] text-[var(--clay-text-muted)]',
};

const toneText: Record<StandingTone, string> = {
    good: 'text-[var(--status-resolved)]',
    watch: 'text-[var(--status-pending)]',
    action: 'text-[var(--status-urgent)]',
    neutral: 'text-[var(--clay-text-muted)]',
};

const toneDot: Record<StandingTone, string> = {
    good: 'bg-[var(--status-resolved)]',
    watch: 'bg-[var(--status-pending)]',
    action: 'bg-[var(--status-urgent)]',
    neutral: 'bg-[var(--clay-border)]',
};

const txTone: Record<string, StandingTone> = {
    confirmed: 'good',
    pending: 'watch',
    failed: 'action',
};

interface TuitionAccount {
    term: string;
    total_amount: string;
    paid_amount: string;
    remaining_amount: string;
    remaining_amount_fiat?: string;
}

interface FinanceTransaction {
    id: number;
    amount: number;
    currency: string;
    status: string;
    status_label: string;
    network: string;
    executed_at: string | null;
}

interface FinanceSummary {
    tuitionAccount: TuitionAccount | null;
    wallet: { address: string | null };
    totals: { confirmed: number; currency: string };
    recentTransactions: FinanceTransaction[];
}

interface DashboardProps {
    requests: Paginated<AssistanceRequestItem>;
    filters: { status: string; search: string };
    stats?: DashboardStats;
    categories?: OptionItem[];
    priorities?: OptionItem[];
    quickResources?: QuickResource[];
    notifications?: DashboardNotificationSummary | null;
    finance?: FinanceSummary | null;
}

/* -- Primitives --------------------------------------------------------- */

/** One measured figure in the stat band. */
function Stat({
    label,
    value,
    tone = 'neutral',
    hint,
}: {
    label: string;
    value: string;
    tone?: StandingTone;
    hint?: string;
}) {
    return (
        <div className="min-w-0 px-4 py-4 sm:px-5 sm:py-5">
            <p className="bulletin-eyebrow">{label}</p>
            <p
                className={`clay-figure mt-2 ${tone === 'neutral' ? '' : toneText[tone]}`}
            >
                {value}
            </p>
            {hint && <p className="clay-meta mt-1.5 truncate">{hint}</p>}
        </div>
    );
}

/** Section header: a real heading, not an eyebrow. */
function SectionHead({
    id,
    title,
    meta,
    action,
}: {
    id: string;
    title: string;
    meta?: string;
    action?: React.ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2 border-b border-[var(--clay-border)] pb-3">
            <div className="min-w-0">
                <h2 id={id} className="clay-h2">
                    {title}
                </h2>
                {meta && <p className="clay-meta mt-1.5">{meta}</p>}
            </div>
            {action}
        </div>
    );
}

function Pill({
    tone,
    children,
}: {
    tone: StandingTone;
    children: React.ReactNode;
}) {
    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 clay-label ${tonePill[tone]}`}
        >
            <span
                aria-hidden="true"
                className={`size-1.5 rounded-full ${toneDot[tone]}`}
            />
            {children}
        </span>
    );
}

function formatDuration(minutes: number | null): string {
    if (minutes === null) {
        return '—';
    }

    if (minutes < 60) {
        return `${minutes}m`;
    }

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return rest === 0 ? `${hours}h` : `${hours}h ${rest}m`;
}

function formatAge(value: string): string {
    const created = new Date(value);

    if (Number.isNaN(created.getTime())) {
        return '—';
    }

    const hours = Math.max(
        0,
        Math.floor((Date.now() - created.getTime()) / 3_600_000),
    );

    if (hours < 1) {
        return 'just now';
    }

    if (hours < 24) {
        return `${hours}h ago`;
    }

    const days = Math.floor(hours / 24);

    return days === 1 ? 'yesterday' : `${days}d ago`;
}

export default function Dashboard({
    requests,
    filters,
    stats,
    quickResources = [],
    notifications = null,
    finance = null,
}: DashboardProps) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const [search, setSearch] = useState(filters.search);

    const firstName = (auth.user?.name || 'there').split(' ')[0];
    const activeStatus = (filters.status || 'all') as AssistanceStatusFilter;
    const unread = notifications?.unreadCount ?? 0;
    const tuition = finance?.tuitionAccount ?? null;

    const applyFilters = (next: {
        status?: AssistanceStatusFilter;
        search?: string;
    }) => {
        router.get(
            dashboard().url,
            {
                status: next.status ?? activeStatus,
                search: next.search ?? filters.search,
            },
            { preserveScroll: true, replace: true },
        );
    };

    const submitSearch = (event: React.FormEvent) => {
        event.preventDefault();
        applyFilters({ search: search.trim() });
    };

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
                                {stats?.totalRequests ?? 0} assistance{' '}
                                {stats?.totalRequests === 1 ? 'case' : 'cases'}{' '}
                                on file
                                {stats?.activeRequests
                                    ? ` · ${stats.activeRequests} still need attention`
                                    : ' · nothing waiting'}
                                .
                            </p>
                        </div>
                    </header>

                    {/* -- Stat band: the one place numbers lead --------------- */}
                    <section
                        aria-label="Summary"
                        className="clay-inset grid grid-cols-2 divide-x divide-y divide-[var(--clay-border)] lg:grid-cols-4 lg:divide-y-0"
                    >
                        <Stat
                            label="Active cases"
                            value={String(stats?.activeRequests ?? 0)}
                            tone={
                                (stats?.activeRequests ?? 0) > 0
                                    ? 'watch'
                                    : 'good'
                            }
                            hint="awaiting resolution"
                        />
                        <Stat
                            label="Resolved"
                            value={String(stats?.resolvedRequests ?? 0)}
                            tone="good"
                            hint="closed out"
                        />
                        <Stat
                            label="Median resolution"
                            value={formatDuration(
                                stats?.medianResolutionMinutes ?? null,
                            )}
                            hint="submission → closure"
                        />
                        <Stat
                            label="Unread"
                            value={String(unread)}
                            tone={unread > 0 ? 'action' : 'neutral'}
                            hint="notifications"
                        />
                    </section>

                    {/* -- Work + rail ------------------------------------- */}
                    <div className="grid gap-8 lg:grid-cols-3 lg:gap-6">
                        <div className="flex min-w-0 flex-col gap-8 lg:col-span-2">
                            {/* Assistance queue */}
                            <section aria-labelledby="queue-heading">
                                <SectionHead
                                    id="queue-heading"
                                    title="Assistance queue"
                                    meta={`${requests.total} matching ${
                                        requests.total === 1 ? 'case' : 'cases'
                                    }`}
                                    action={
                                        <Link
                                            href={financialAssistanceIndex()}
                                            className="clay-meta clay-focus -my-2.5 shrink-0 py-2.5 transition-colors hover:text-[var(--clay-text)] sm:-my-2 sm:py-2"
                                        >
                                            Open assistance
                                            <ExternalLink className="ml-1 inline size-3" />
                                        </Link>
                                    }
                                />

                                {/* Filter bar */}
                                <div className="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div
                                        role="tablist"
                                        aria-label="Filter by status"
                                        className="flex flex-wrap gap-1.5"
                                    >
                                        {STATUS_FILTERS.map((option) => (
                                            <button
                                                key={option.value}
                                                type="button"
                                                role="tab"
                                                aria-selected={
                                                    activeStatus ===
                                                    option.value
                                                }
                                                onClick={() =>
                                                    applyFilters({
                                                        status: option.value,
                                                    })
                                                }
                                                className={`clay-chip clay-focus py-2.5 sm:py-2 ${
                                                    activeStatus ===
                                                    option.value
                                                        ? 'data-[active=true]'
                                                        : ''
                                                }`}
                                                data-active={
                                                    activeStatus ===
                                                    option.value
                                                }
                                            >
                                                {option.label}
                                            </button>
                                        ))}
                                    </div>

                                    <form
                                        onSubmit={submitSearch}
                                        className="relative sm:w-56"
                                    >
                                        <label
                                            htmlFor="dash-search"
                                            className="sr-only"
                                        >
                                            Search cases
                                        </label>
                                        <Search
                                            aria-hidden="true"
                                            className="pointer-events-none absolute left-3 top-1/2 size-3.5 -translate-y-1/2 text-[var(--clay-text-faint)]"
                                        />
                                        <input
                                            id="dash-search"
                                            type="search"
                                            value={search}
                                            onChange={(event) =>
                                                setSearch(event.target.value)
                                            }
                                            placeholder="Ticket, subject…"
                                            className="clay-field clay-focus w-full rounded-full border border-[var(--clay-border)] bg-transparent py-2 pl-9 pr-3 text-sm text-[var(--clay-text)] shadow-none placeholder:text-[var(--clay-text-faint)]"
                                        />
                                    </form>
                                </div>

                                {/* Queue */}
                                {requests.data.length === 0 ? (
                                    <div className="clay-card p-8 text-center">
                                        <p className="clay-title">
                                            Nothing matches that filter.
                                        </p>
                                        <p className="clay-body mx-auto mt-1.5 max-w-md">
                                            Clear the search or switch back to
                                            All to see every case on file.
                                        </p>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setSearch('');
                                                applyFilters({
                                                    status: 'all',
                                                    search: '',
                                                });
                                            }}
                                            className="clay-focus mt-5 inline-flex h-10 items-center gap-2 rounded-full border border-[var(--clay-border)] px-5 text-xs font-semibold text-[var(--clay-text-muted)] transition-colors hover:bg-[var(--clay-surface-soft)] hover:text-[var(--clay-text)]"
                                        >
                                            Reset filters
                                        </button>
                                    </div>
                                ) : (
                                    <ul className="flex flex-col">
                                        {requests.data.map((request, index) => (
                                            <li
                                                key={request.id}
                                                className="clay-rise border-t border-[var(--clay-border)] first:border-t-0"
                                                style={
                                                    {
                                                        '--stagger': Math.min(
                                                            index,
                                                            8,
                                                        ),
                                                    } as React.CSSProperties
                                                }
                                            >
                                                <Link
                                                    href={
                                                        assistanceShow({
                                                            assistanceRequest:
                                                                request.id,
                                                        }).url
                                                    }
                                                    className="clay-focus group flex items-start gap-3 py-3.5 transition-colors hover:bg-[var(--clay-surface-soft)] sm:gap-4 sm:px-2"
                                                >
                                                    <span
                                                        aria-hidden="true"
                                                        className={`mt-2 size-1.5 shrink-0 rounded-full ${toneDot[priorityTone[request.priority]]}`}
                                                    />

                                                    <span className="min-w-0 flex-1">
                                                        <span className="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                                                            <span className="clay-title">
                                                                {
                                                                    request.subject
                                                                }
                                                            </span>
                                                            <Pill
                                                                tone={
                                                                    statusTone[
                                                                        request
                                                                            .status
                                                                    ]
                                                                }
                                                            >
                                                                {
                                                                    request.status_label
                                                                }
                                                            </Pill>
                                                        </span>

                                                        <span className="mt-1 flex flex-wrap items-center gap-x-2.5 gap-y-1">
                                                            <span className="clay-meta">
                                                                {
                                                                    request.ticket_number
                                                                }
                                                            </span>
                                                            <span className="clay-meta text-[var(--clay-text-muted)]">
                                                                {
                                                                    request.category_label
                                                                }
                                                            </span>
                                                            {request.priority !==
                                                                'low' && (
                                                                <span
                                                                    className={`clay-label ${toneText[priorityTone[request.priority]]}`}
                                                                >
                                                                    {
                                                                        request.priority_label
                                                                    }
                                                                </span>
                                                            )}
                                                            {request.assigned_to_name && (
                                                                <span className="clay-meta">
                                                                    {
                                                                        request.assigned_to_name
                                                                    }
                                                                </span>
                                                            )}
                                                        </span>
                                                    </span>

                                                    <time
                                                        dateTime={
                                                            request.created_at
                                                        }
                                                        className="clay-meta shrink-0 pt-1"
                                                    >
                                                        {formatAge(
                                                            request.created_at,
                                                        )}
                                                    </time>
                                                </Link>
                                            </li>
                                        ))}
                                    </ul>
                                )}

                                {requests.last_page > 1 && (
                                    <nav
                                        aria-label="Pagination"
                                        className="mt-5 flex items-center justify-between gap-3 border-t border-[var(--clay-border)] pt-4"
                                    >
                                        <p className="clay-meta">
                                            Page {requests.current_page} of{' '}
                                            {requests.last_page}
                                        </p>
                                        <div className="flex gap-2">
                                            <button
                                                type="button"
                                                disabled={
                                                    !requests.prev_page_url
                                                }
                                                onClick={() =>
                                                    router.get(
                                                        requests.prev_page_url as string,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                                className="clay-focus h-9 rounded-full border border-[var(--clay-border)] px-4 text-xs font-semibold text-[var(--clay-text-muted)] transition-colors hover:bg-[var(--clay-surface-soft)] hover:text-[var(--clay-text)] disabled:cursor-not-allowed disabled:opacity-40"
                                            >
                                                Previous
                                            </button>
                                            <button
                                                type="button"
                                                disabled={
                                                    !requests.next_page_url
                                                }
                                                onClick={() =>
                                                    router.get(
                                                        requests.next_page_url as string,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                                className="clay-focus h-9 rounded-full border border-[var(--clay-border)] px-4 text-xs font-semibold text-[var(--clay-text-muted)] transition-colors hover:bg-[var(--clay-surface-soft)] hover:text-[var(--clay-text)] disabled:cursor-not-allowed disabled:opacity-40"
                                            >
                                                Next
                                            </button>
                                        </div>
                                    </nav>
                                )}
                            </section>
                        </div>

                        {/* -- Right rail -- */}
                        <aside className="flex min-w-0 flex-col gap-8 lg:gap-6">
                            {/* Finance snapshot */}
                            <section aria-labelledby="finance-heading">
                                <SectionHead
                                    id="finance-heading"
                                    title="Tuition"
                                    meta={tuition?.term ?? 'No active term'}
                                />

                                {tuition ? (
                                    <dl className="divide-y divide-[var(--clay-border)]">
                                        {[
                                            {
                                                label: 'Outstanding',
                                                value: tuition.remaining_amount,
                                                tone: 'neutral' as const,
                                            },
                                            {
                                                label: 'Paid',
                                                value: tuition.paid_amount,
                                                tone: 'good' as const,
                                            },
                                            {
                                                label: 'Total billed',
                                                value: tuition.total_amount,
                                                tone: 'neutral' as const,
                                            },
                                        ].map((row) => (
                                            <div
                                                key={row.label}
                                                className="flex items-baseline justify-between gap-3 py-3"
                                            >
                                                <dt className="bulletin-eyebrow">
                                                    {row.label}
                                                </dt>
                                                <dd
                                                    className={`clay-title shrink-0 tabular-nums ${toneText[row.tone]}`}
                                                >
                                                    {formatUsdc(row.value)}
                                                </dd>
                                            </div>
                                        ))}
                                    </dl>
                                ) : (
                                    <p className="clay-body py-4">
                                        No tuition account linked to this user.
                                    </p>
                                )}

                                <div className="mt-4 flex flex-col gap-2">
                                    <Link
                                        href={paymentsIndex()}
                                        className="clay-focus inline-flex h-10 items-center justify-center gap-2 rounded-full border border-[var(--clay-border)] text-xs font-semibold text-[var(--clay-text-muted)] transition-colors hover:bg-[var(--clay-surface-soft)] hover:text-[var(--clay-text)]"
                                    >
                                        Payout ledger
                                    </Link>
                                    {finance?.wallet.address && (
                                        <p className="clay-meta flex items-center gap-1.5 truncate">
                                            <Wallet className="size-3 shrink-0" />
                                            {finance.wallet.address.slice(
                                                0,
                                                10,
                                            )}
                                            …{finance.wallet.address.slice(-6)}
                                        </p>
                                    )}
                                </div>
                            </section>

                            {/* Notifications */}
                            {notifications &&
                                notifications.recent.length > 0 && (
                                    <section aria-labelledby="notif-heading">
                                        <SectionHead
                                            id="notif-heading"
                                            title="Notifications"
                                            meta={
                                                unread > 0
                                                    ? `${unread} unread`
                                                    : 'all read'
                                            }
                                        />
                                        <ul className="divide-y divide-[var(--clay-border)]">
                                            {notifications.recent.map(
                                                (item) => (
                                                    <li
                                                        key={item.id}
                                                        className="py-3"
                                                    >
                                                        <p className="flex items-start gap-2 text-sm font-semibold text-[var(--clay-ink)]">
                                                            {!item.readAt && (
                                                                <span
                                                                    aria-hidden="true"
                                                                    className="clay-ping mt-1.5 size-1.5 shrink-0 rounded-full bg-[var(--clay-primary)]"
                                                                />
                                                            )}
                                                            <span className="min-w-0">
                                                                {item.title}
                                                            </span>
                                                        </p>
                                                        {item.body && (
                                                            <p className="clay-body mt-0.5 line-clamp-2">
                                                                {item.body}
                                                            </p>
                                                        )}
                                                        <time className="clay-meta mt-1 block">
                                                            {item.createdAt}
                                                        </time>
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    </section>
                                )}

                            {/* Payouts */}
                            {finance &&
                                finance.recentTransactions.length > 0 && (
                                    <section aria-labelledby="payout-heading">
                                        <SectionHead
                                            id="payout-heading"
                                            title="Recent payouts"
                                            meta={`${formatUsdc(
                                                String(
                                                    Math.round(
                                                        finance.totals
                                                            .confirmed *
                                                            1_000_000,
                                                    ),
                                                ),
                                            )} received all time`}
                                        />
                                        <ul className="divide-y divide-[var(--clay-border)]">
                                            {finance.recentTransactions.map(
                                                (transaction) => (
                                                    <li
                                                        key={transaction.id}
                                                        className="flex items-center justify-between gap-3 py-3"
                                                    >
                                                        <span className="flex min-w-0 items-center gap-2.5">
                                                            <ArrowDownLeft
                                                                aria-hidden="true"
                                                                className={`size-3.5 shrink-0 ${toneText[txTone[transaction.status] ?? 'neutral']}`}
                                                            />
                                                            <span className="min-w-0">
                                                                <span className="clay-meta block truncate text-[var(--clay-text)]">
                                                                    +
                                                                    {
                                                                        transaction.amount
                                                                    }{' '}
                                                                    {
                                                                        transaction.currency
                                                                    }
                                                                </span>
                                                                <span className="clay-meta block truncate">
                                                                    {
                                                                        transaction.network
                                                                    }
                                                                </span>
                                                            </span>
                                                        </span>
                                                        <Pill
                                                            tone={
                                                                txTone[
                                                                    transaction
                                                                        .status
                                                                ] ?? 'neutral'
                                                            }
                                                        >
                                                            {
                                                                transaction.status_label
                                                            }
                                                        </Pill>
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    </section>
                                )}
                        </aside>
                    </div>

                    {/* -- Resources -- */}
                    {quickResources.length > 0 && (
                        <section aria-labelledby="resources-heading">
                            <SectionHead
                                id="resources-heading"
                                title="Campus resources"
                                meta="External links and guides"
                            />
                            <ul className="grid grid-cols-1 gap-x-8 sm:grid-cols-2">
                                {quickResources.map((resource, index) => {
                                    const Icon =
                                        resourceIcons[resource.icon] ?? Link2;
                                    const inner = (
                                        <>
                                            <span className="clay-icon-chip size-8 shrink-0">
                                                <Icon className="size-3.5" />
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <span className="clay-title block text-sm">
                                                    {resource.title}
                                                </span>
                                                <span className="clay-body mt-0.5 block text-xs leading-relaxed">
                                                    {resource.description}
                                                </span>
                                            </span>
                                        </>
                                    );

                                    return (
                                        <li
                                            key={resource.title}
                                            className="clay-rise border-t border-[var(--clay-border)]"
                                            style={
                                                {
                                                    '--stagger': Math.min(
                                                        index,
                                                        8,
                                                    ),
                                                } as React.CSSProperties
                                            }
                                        >
                                            {resource.url ? (
                                                <a
                                                    href={resource.url}
                                                    target="_blank"
                                                    rel="noreferrer noopener"
                                                    className="clay-focus group flex items-start gap-3 py-3.5 transition-colors hover:bg-[var(--clay-surface-soft)] sm:px-2"
                                                >
                                                    {inner}
                                                    <ExternalLink className="mt-1 size-3 shrink-0 text-[var(--clay-text-faint)] transition-transform group-hover:-translate-y-0.5 group-hover:translate-x-0.5" />
                                                </a>
                                            ) : (
                                                <div className="flex items-start gap-3 py-3.5 sm:px-2">
                                                    {inner}
                                                </div>
                                            )}
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
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
