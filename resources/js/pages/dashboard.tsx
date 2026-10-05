import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    ArrowUpRight,
    BookOpen,
    Calendar,
    Clock,
    Copy,
    ExternalLink,
    HelpCircle,
    Inbox,
    LifeBuoy,
    Link2,
    MessageSquare,
    Plus,
    Search,
    Send,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import AssistanceRequestController from '@/actions/App/Http/Controllers/AssistanceRequestController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { dashboard } from '@/routes';

import type {
    AssistancePriorityType,
    AssistanceRequestItem,
    AssistanceStatusFilter,
    AssistanceStatusType,
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

const statusTabs: { value: AssistanceStatusFilter; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'active', label: 'Active' },
    { value: 'resolved', label: 'Resolved' },
];

function formatDuration(minutes: number): string {
    if (minutes < 60) {
        return `${minutes}m`;
    }

    if (minutes < 60 * 24) {
        const hours = minutes / 60;

        return `${Number.isInteger(hours) ? hours : hours.toFixed(1)}h`;
    }

    const days = minutes / (60 * 24);

    return `${Number.isInteger(days) ? days : days.toFixed(1)}d`;
}

function statusTabClass(status: AssistanceStatusType): string {
    switch (status) {
        case 'pending':
            return 'clay-tab-pending';
        case 'in_progress':
            return 'clay-tab-progress';
        case 'resolved':
            return 'clay-tab-resolved';
        case 'closed':
        default:
            return 'clay-tab-closed';
    }
}

function priorityClass(priority: AssistancePriorityType): string {
    if (priority === 'urgent') {
        return 'bg-[var(--status-urgent)]/10 text-[var(--status-urgent)]';
    }

    if (priority === 'high') {
        return 'bg-[var(--status-pending)]/10 text-[var(--status-pending)]';
    }

    return 'bg-[var(--clay-surface-soft)] text-[var(--clay-text-muted)]';
}

function statusPillClass(status: AssistanceStatusType): string {
    switch (status) {
        case 'resolved':
            return 'bg-[var(--status-resolved)]/10 text-[var(--status-resolved)]';
        case 'in_progress':
            return 'bg-[var(--status-progress)]/10 text-[var(--status-progress)]';
        case 'closed':
            return 'bg-[var(--clay-surface-soft)] text-[var(--clay-text-muted)]';
        case 'pending':
        default:
            return 'bg-[var(--status-pending)]/10 text-[var(--status-pending)]';
    }
}

interface DashboardProps {
    requests: Paginated<AssistanceRequestItem>;
    filters: {
        status: AssistanceStatusFilter;
        search: string;
    };
    stats: DashboardStats;
    categories: OptionItem[];
    priorities: OptionItem[];
    quickResources: QuickResource[];
}

export default function Dashboard({
    requests,
    filters,
    stats,
    categories = [
        { value: 'academic', label: 'Academic & Coursework' },
        { value: 'technical', label: 'Technical & IT Support' },
        { value: 'enrollment', label: 'Enrollment & Records' },
        { value: 'financial', label: 'Tuition & Billing' },
        { value: 'general', label: 'General Inquiry' },
    ],
    priorities = [
        { value: 'low', label: 'Low' },
        { value: 'medium', label: 'Medium' },
        { value: 'high', label: 'High' },
        { value: 'urgent', label: 'Urgent' },
    ],
    quickResources = [],
}: DashboardProps) {
    const { auth } = usePage<{ auth: Auth }>().props;

    const [isAskModalOpen, setIsAskModalOpen] = useState(false);
    const [selectedRequest, setSelectedRequest] =
        useState<AssistanceRequestItem | null>(null);
    const [search, setSearch] = useState(filters.search);
    const [isFilteringRequests, setIsFilteringRequests] = useState(false);
    const searchTimeout = useRef<ReturnType<typeof setTimeout> | null>(null);

    const form = useForm({
        category: 'academic',
        priority: 'medium',
        subject: '',
        description: '',
    });

    const reloadRequests = (overrides: {
        status?: AssistanceStatusFilter;
        search?: string;
        page?: number;
    }) => {
        const nextStatus = overrides.status ?? filters.status;
        const nextSearch = overrides.search ?? filters.search;
        const query: Record<string, string | number> = {};

        if (nextStatus !== 'all') {
            query.status = nextStatus;
        }

        if (nextSearch !== '') {
            query.search = nextSearch;
        }

        if (overrides.page && overrides.page > 1) {
            query.page = overrides.page;
        }

        router.get(
            dashboard.url({ query }),
            {},
            {
                only: ['requests', 'filters'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => setIsFilteringRequests(true),
                onFinish: () => setIsFilteringRequests(false),
            },
        );
    };

    useEffect(() => {
        if (search === filters.search) {
            return;
        }

        if (searchTimeout.current) {
            clearTimeout(searchTimeout.current);
        }

        searchTimeout.current = setTimeout(() => {
            reloadRequests({ search });
        }, 350);

        return () => {
            if (searchTimeout.current) {
                clearTimeout(searchTimeout.current);
            }
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const handleStatusChange = (status: AssistanceStatusFilter) => {
        reloadRequests({ status });
    };

    const handleSubmitAssistance = (e: React.FormEvent) => {
        e.preventDefault();

        form.post(AssistanceRequestController.store.url(), {
            preserveScroll: true,
            onSuccess: () => {
                setIsAskModalOpen(false);
                form.reset();
                toast.success('Assistance request submitted successfully!', {
                    description:
                        'Your ticket has been forwarded to the administration team.',
                });
            },
            onError: () => {
                toast.error(
                    'Failed to submit assistance request. Please check the fields.',
                );
            },
        });
    };

    const copyTicketNumber = (ticket: string) => {
        navigator.clipboard.writeText(ticket);
        toast.info(`Copied #${ticket} to clipboard`);
    };

    const { current_page, from, to, total, last_page } = requests;
    const hasPages = last_page > 1;
    const isFiltering = filters.status !== 'all' || filters.search !== '';
    const firstName = (auth.user?.name || 'Student').split(' ')[0];

    return (
        <>
            <Head title="Student Dashboard" />

            <TooltipProvider delayDuration={200}>
                <div className="bulletin clay-ambient min-h-full w-full">
                    {/* 8pt grid: 4 / 8 / 12 / 16 / 24 / 32 / 48 / 64 */}
                    <div className="mx-auto flex w-full max-w-6xl flex-col gap-8 px-4 py-6 sm:gap-10 sm:px-6 sm:py-10 lg:px-8 lg:py-12">
                        {/* -- Header: identity + primary action -- */}
                        <header className="flex flex-col gap-6">
                            <div className="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                                <div className="min-w-0">
                                    <p className="bulletin-eyebrow">
                                        EduFlow &middot; Northstar Learning
                                        Center
                                    </p>
                                    <h1 className="clay-h1 mt-2">
                                        Welcome back,{' '}
                                        <span className="text-[var(--clay-primary-bright)]">
                                            {firstName}
                                        </span>
                                    </h1>
                                    <p className="clay-body mt-1.5 max-w-md">
                                        Your desk for coursework, campus
                                        records, and anything the administration
                                        can answer.
                                    </p>
                                </div>

                                <div className="flex shrink-0 flex-col gap-2 lg:items-end">
                                    <Button
                                        onClick={() => setIsAskModalOpen(true)}
                                        className="clay-focus h-12 w-full gap-2 rounded-full bg-[var(--clay-primary)] px-7 text-sm font-semibold text-[var(--clay-primary-foreground)] shadow-[var(--shadow-cta)] transition-all hover:-translate-y-0.5 hover:bg-[var(--clay-primary-bright)] hover:shadow-[var(--shadow-lg)] active:translate-y-0 active:scale-[0.98] sm:h-11 sm:w-auto"
                                    >
                                        <LifeBuoy className="size-4" />
                                        Ask Assistance
                                    </Button>
                                    <p className="clay-meta text-center lg:text-right">
                                        {stats.totalRequests} ticket
                                        {stats.totalRequests === 1
                                            ? ''
                                            : 's'}{' '}
                                        on file
                                    </p>
                                </div>
                            </div>

                            {/* -- Stat tiles: hierarchy level 1 -- */}
                            <dl className="grid grid-cols-3 gap-3 sm:gap-4">
                                <div className="clay-card p-4 sm:p-6">
                                    <dt className="bulletin-eyebrow">Active</dt>
                                    <dd className="mt-2 sm:mt-3">
                                        <span className="bulletin-figure block text-[28px] sm:text-4xl lg:text-5xl">
                                            {stats.activeRequests}
                                        </span>
                                        <span className="mt-1 hidden text-xs text-[var(--clay-text-muted)] sm:block">
                                            awaiting staff
                                        </span>
                                    </dd>
                                </div>
                                <div className="clay-card p-4 sm:p-6">
                                    <dt className="bulletin-eyebrow">
                                        Resolved
                                    </dt>
                                    <dd className="mt-2 sm:mt-3">
                                        <span className="bulletin-figure block text-[28px] text-[var(--status-resolved)] sm:text-4xl lg:text-5xl">
                                            {stats.resolvedRequests}
                                        </span>
                                        <span className="mt-1 hidden text-xs text-[var(--clay-text-muted)] sm:block">
                                            all time
                                        </span>
                                    </dd>
                                </div>
                                <div className="clay-card p-4 sm:p-6">
                                    <dt className="bulletin-eyebrow">
                                        Median time
                                    </dt>
                                    <dd className="mt-2 sm:mt-3">
                                        <span className="bulletin-figure block text-[28px] text-[var(--clay-accent)] sm:text-4xl lg:text-5xl">
                                            {stats.medianResolutionMinutes ===
                                            null
                                                ? '—'
                                                : formatDuration(
                                                      stats.medianResolutionMinutes,
                                                  )}
                                        </span>
                                        <span className="mt-1 hidden text-xs text-[var(--clay-text-muted)] sm:block">
                                            {stats.medianResolutionMinutes ===
                                            null
                                                ? 'no data yet'
                                                : 'to answer'}
                                        </span>
                                    </dd>
                                </div>
                            </dl>
                        </header>

                        {/* -- Assistance requests -- */}
                        <section className="flex flex-col gap-4">
                            <div className="flex flex-col gap-4">
                                <div className="flex flex-wrap items-end justify-between gap-x-4 gap-y-2">
                                    <div>
                                        <h2 className="bulletin-eyebrow">
                                            Assistance requests
                                        </h2>
                                        <p className="clay-body mt-1">
                                            {isFiltering
                                                ? 'Filtered view of your tickets.'
                                                : 'Every ticket you have raised, newest first.'}
                                        </p>
                                    </div>

                                    {isFiltering && (
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setSearch('');
                                                router.get(
                                                    dashboard.url(),
                                                    {},
                                                    {
                                                        only: [
                                                            'requests',
                                                            'filters',
                                                        ],
                                                        preserveState: true,
                                                        preserveScroll: true,
                                                        replace: true,
                                                    },
                                                );
                                            }}
                                            className="clay-ghost clay-focus"
                                        >
                                            <X className="size-3" />
                                            Clear filters
                                        </button>
                                    )}
                                </div>

                                <div className="relative">
                                    <Search
                                        className={`pointer-events-none absolute left-4 top-1/2 size-4 -translate-y-1/2 transition-colors ${
                                            search
                                                ? 'text-[var(--clay-primary-bright)]'
                                                : 'text-[var(--clay-text-faint)]'
                                        }`}
                                    />
                                    <Input
                                        placeholder="Search subject or ticket no."
                                        value={search}
                                        onChange={(e) =>
                                            setSearch(e.target.value)
                                        }
                                        className="clay-field clay-focus h-12 w-full rounded-full pl-11 text-sm shadow-none sm:h-10"
                                    />
                                    {search && (
                                        <button
                                            type="button"
                                            aria-label="Clear search"
                                            onClick={() => setSearch('')}
                                            className="clay-focus absolute right-3 top-1/2 flex size-6 -translate-y-1/2 items-center justify-center rounded-full bg-[var(--clay-surface-hi)] text-[var(--clay-text-muted)] transition-colors hover:text-[var(--clay-text)]"
                                        >
                                            <X className="size-3.5" />
                                        </button>
                                    )}
                                </div>

                                <div
                                    className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1"
                                    role="tablist"
                                    aria-label="Filter tickets by status"
                                >
                                    {statusTabs.map((tab) => {
                                        const count =
                                            tab.value === 'all'
                                                ? stats.totalRequests
                                                : tab.value === 'active'
                                                  ? stats.activeRequests
                                                  : stats.resolvedRequests;
                                        const isActive =
                                            filters.status === tab.value;

                                        return (
                                            <button
                                                key={tab.value}
                                                type="button"
                                                role="tab"
                                                aria-selected={isActive}
                                                data-active={isActive}
                                                onClick={() =>
                                                    handleStatusChange(
                                                        tab.value,
                                                    )
                                                }
                                                className="clay-chip clay-focus shrink-0"
                                            >
                                                {tab.label}
                                                <span
                                                    className={
                                                        isActive
                                                            ? 'opacity-70'
                                                            : 'opacity-60'
                                                    }
                                                >
                                                    {count}
                                                </span>
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>

                            {requests.data.length === 0 ? (
                                <div
                                    className="clay-card clay-rise flex flex-col items-center gap-4 px-6 py-12 text-center sm:items-start sm:text-left"
                                    style={
                                        {
                                            '--stagger': 1,
                                        } as React.CSSProperties
                                    }
                                >
                                    <span className="clay-inset flex size-12 items-center justify-center rounded-full">
                                        <Inbox className="size-5 text-[var(--clay-text-muted)]" />
                                    </span>
                                    <div>
                                        <p className="text-lg font-semibold text-[var(--clay-ink)]">
                                            {isFiltering
                                                ? 'Nothing matches.'
                                                : 'No requests yet.'}
                                        </p>
                                        <p className="clay-body mx-auto mt-1.5 max-w-sm sm:mx-0">
                                            {isFiltering
                                                ? 'Try a different search, or clear the filter to see everything.'
                                                : 'When you need guidance with coursework, records, or campus systems, raise a ticket and staff will pick it up.'}
                                        </p>
                                    </div>
                                    {isFiltering ? (
                                        <Button
                                            variant="outline"
                                            className="clay-focus h-10 rounded-full border-[var(--clay-border)] bg-transparent px-5 text-xs font-semibold shadow-none"
                                            onClick={() => {
                                                setSearch('');
                                                router.get(
                                                    dashboard.url(),
                                                    {},
                                                    {
                                                        only: [
                                                            'requests',
                                                            'filters',
                                                        ],
                                                        preserveState: true,
                                                        preserveScroll: true,
                                                        replace: true,
                                                    },
                                                );
                                            }}
                                        >
                                            Clear filters
                                        </Button>
                                    ) : (
                                        <Button
                                            onClick={() =>
                                                setIsAskModalOpen(true)
                                            }
                                            className="clay-focus h-10 gap-2 rounded-full bg-[var(--clay-primary)] px-5 text-xs font-semibold text-[var(--clay-primary-foreground)] shadow-[var(--shadow-cta)] hover:bg-[var(--clay-primary-bright)]"
                                        >
                                            <Plus className="size-3.5" />
                                            Raise a ticket
                                        </Button>
                                    )}
                                </div>
                            ) : (
                                <>
                                    <ul
                                        className="flex flex-col gap-3"
                                        data-loading={isFilteringRequests}
                                        aria-busy={isFilteringRequests}
                                    >
                                        {requests.data.map((req, index) => (
                                            <li key={req.id}>
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setSelectedRequest(req)
                                                    }
                                                    style={
                                                        {
                                                            '--stagger':
                                                                Math.min(
                                                                    index,
                                                                    8,
                                                                ),
                                                        } as React.CSSProperties
                                                    }
                                                    className="clay-card clay-press clay-rise flex w-full items-center gap-3 p-4 text-left sm:gap-4 sm:p-5"
                                                >
                                                    <span
                                                        className={`clay-tab ${statusTabClass(req.status)}`}
                                                    />

                                                    <div className="min-w-0 flex-1">
                                                        <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                            <span className="clay-meta">
                                                                #
                                                                {
                                                                    req.ticket_number
                                                                }
                                                            </span>
                                                            <span className="clay-meta hidden sm:inline">
                                                                ·
                                                            </span>
                                                            <span className="clay-meta hidden text-[var(--clay-text-muted)] sm:inline">
                                                                {
                                                                    req.category_label
                                                                }
                                                            </span>
                                                            <Badge
                                                                variant="outline"
                                                                className={`rounded-full border-0 px-2 py-0 text-[10px] font-semibold uppercase tracking-wider ${priorityClass(req.priority)}`}
                                                            >
                                                                {
                                                                    req.priority_label
                                                                }
                                                            </Badge>
                                                        </div>

                                                        <p className="clay-title mt-1 truncate text-base sm:text-lg">
                                                            {req.subject}
                                                        </p>

                                                        {req.admin_notes && (
                                                            <p className="mt-1 truncate text-xs text-[var(--status-resolved)]">
                                                                <span className="font-semibold">
                                                                    Reply:
                                                                </span>{' '}
                                                                {
                                                                    req.admin_notes
                                                                }
                                                            </p>
                                                        )}

                                                        <div className="mt-2 flex items-center gap-2 sm:hidden">
                                                            <span
                                                                className={`rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider ${statusPillClass(req.status)}`}
                                                            >
                                                                {
                                                                    req.status_label
                                                                }
                                                            </span>
                                                            <span className="clay-meta">
                                                                {req.created_at}
                                                            </span>
                                                        </div>
                                                    </div>

                                                    <Tooltip>
                                                        <TooltipTrigger asChild>
                                                            <span className="hidden shrink-0 text-right sm:block">
                                                                <p className="clay-meta">
                                                                    {
                                                                        req.created_at
                                                                    }
                                                                </p>
                                                                <p
                                                                    className={`clay-meta mt-1 font-semibold ${statusPillClass(req.status)}`}
                                                                >
                                                                    {
                                                                        req.status_label
                                                                    }
                                                                </p>
                                                            </span>
                                                        </TooltipTrigger>
                                                        <TooltipContent className="clay-tooltip">
                                                            Raised{' '}
                                                            {req.created_at}
                                                        </TooltipContent>
                                                    </Tooltip>

                                                    <ArrowUpRight className="size-4 shrink-0 text-[var(--clay-border)] transition-all duration-200 group-hover:text-[var(--clay-primary-bright)]" />
                                                </button>
                                            </li>
                                        ))}
                                    </ul>

                                    {hasPages && (
                                        <div className="flex flex-wrap items-center justify-between gap-3 pt-1 text-xs text-[var(--clay-text-muted)]">
                                            <span className="clay-meta">
                                                {from ?? 0}–{to ?? 0} / {total}
                                            </span>
                                            <div className="flex items-center gap-2">
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    className="clay-focus h-9 rounded-full border-[var(--clay-border)] bg-transparent text-xs font-semibold shadow-none"
                                                    disabled={current_page <= 1}
                                                    onClick={() =>
                                                        reloadRequests({
                                                            page:
                                                                current_page -
                                                                1,
                                                        })
                                                    }
                                                >
                                                    Previous
                                                </Button>
                                                <span className="clay-meta">
                                                    {current_page}/{last_page}
                                                </span>
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    className="clay-focus h-9 rounded-full border-[var(--clay-border)] bg-transparent text-xs font-semibold shadow-none"
                                                    disabled={
                                                        current_page >=
                                                        last_page
                                                    }
                                                    onClick={() =>
                                                        reloadRequests({
                                                            page:
                                                                current_page +
                                                                1,
                                                        })
                                                    }
                                                >
                                                    Next
                                                </Button>
                                            </div>
                                        </div>
                                    )}
                                </>
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
                                            resourceIcons[resource.icon] ??
                                            Link2;

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
                                                                href={
                                                                    resource.url
                                                                }
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
                                                            {
                                                                resource.description
                                                            }
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
            </TooltipProvider>

            {/* -- Ask Assistance dialog -- */}
            <Dialog open={isAskModalOpen} onOpenChange={setIsAskModalOpen}>
                <DialogContent className="bulletin clay-card border-[var(--clay-border)] sm:max-w-lg">
                    <form onSubmit={handleSubmitAssistance}>
                        <DialogHeader>
                            <DialogTitle className="flex items-center gap-3 text-xl font-bold text-[var(--clay-ink)]">
                                <span className="clay-icon-chip size-9 shrink-0">
                                    <LifeBuoy className="size-4" />
                                </span>
                                Ask Assistance
                            </DialogTitle>
                            <DialogDescription className="text-xs leading-relaxed">
                                Goes straight to the administration and academic
                                advisors. You will get a ticket number back
                                immediately.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="grid gap-4 py-4">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label
                                        htmlFor="category"
                                        className="bulletin-eyebrow"
                                    >
                                        Category
                                    </Label>
                                    <Select
                                        value={form.data.category}
                                        onValueChange={(val) =>
                                            form.setData('category', val)
                                        }
                                    >
                                        <SelectTrigger
                                            id="category"
                                            className="clay-field clay-focus w-full rounded-xl shadow-none"
                                        >
                                            <SelectValue placeholder="Select topic" />
                                        </SelectTrigger>
                                        <SelectContent className="bulletin rounded-xl border-[var(--clay-border)] shadow-[var(--shadow-lg)]">
                                            {categories.map((c) => (
                                                <SelectItem
                                                    key={c.value}
                                                    value={c.value}
                                                >
                                                    {c.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        message={form.errors.category}
                                    />
                                </div>

                                <div className="space-y-1.5">
                                    <Label
                                        htmlFor="priority"
                                        className="bulletin-eyebrow"
                                    >
                                        Urgency
                                    </Label>
                                    <Select
                                        value={form.data.priority}
                                        onValueChange={(val) =>
                                            form.setData('priority', val)
                                        }
                                    >
                                        <SelectTrigger
                                            id="priority"
                                            className="clay-field clay-focus w-full rounded-xl shadow-none"
                                        >
                                            <SelectValue placeholder="Select priority" />
                                        </SelectTrigger>
                                        <SelectContent className="bulletin rounded-xl border-[var(--clay-border)] shadow-[var(--shadow-lg)]">
                                            {priorities.map((p) => (
                                                <SelectItem
                                                    key={p.value}
                                                    value={p.value}
                                                >
                                                    {p.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        message={form.errors.priority}
                                    />
                                </div>
                            </div>

                            <div className="space-y-1.5">
                                <Label
                                    htmlFor="subject"
                                    className="bulletin-eyebrow"
                                >
                                    Subject
                                </Label>
                                <Input
                                    id="subject"
                                    placeholder="e.g. Cannot submit assignment on the course portal"
                                    value={form.data.subject}
                                    onChange={(e) =>
                                        form.setData('subject', e.target.value)
                                    }
                                    className="clay-field clay-focus shadow-none"
                                    required
                                    maxLength={255}
                                />
                                <InputError message={form.errors.subject} />
                            </div>

                            <div className="space-y-1.5">
                                <Label
                                    htmlFor="description"
                                    className="bulletin-eyebrow"
                                >
                                    Detail
                                </Label>
                                <Textarea
                                    id="description"
                                    placeholder="What happened, any error text, and what you have already tried."
                                    rows={5}
                                    value={form.data.description}
                                    onChange={(e) =>
                                        form.setData(
                                            'description',
                                            e.target.value,
                                        )
                                    }
                                    className="clay-field clay-focus shadow-none"
                                    required
                                />
                                <p className="clay-meta mt-3">
                                    Include course codes and steps to reproduce
                                    the problem.
                                </p>
                                <InputError message={form.errors.description} />
                            </div>
                        </div>

                        <DialogFooter className="gap-2 sm:gap-0">
                            <Button
                                type="button"
                                variant="outline"
                                className="clay-focus rounded-full border-[var(--clay-border)] bg-transparent font-semibold shadow-none"
                                onClick={() => setIsAskModalOpen(false)}
                                disabled={form.processing}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={form.processing}
                                data-loading={form.processing}
                                className="clay-focus gap-2 rounded-full bg-[var(--clay-primary)] font-semibold text-[var(--clay-primary-foreground)] shadow-[var(--shadow-cta)] hover:bg-[var(--clay-primary-bright)]"
                            >
                                <Send className="size-4" />
                                {form.processing
                                    ? 'Submitting...'
                                    : 'Submit Request'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* -- Ticket detail dialog -- */}
            <Dialog
                open={!!selectedRequest}
                onOpenChange={(open) => !open && setSelectedRequest(null)}
            >
                {selectedRequest && (
                    <DialogContent className="bulletin clay-card border-[var(--clay-border)] sm:max-w-lg">
                        <DialogHeader>
                            <div className="flex items-center justify-between gap-3 pr-4">
                                <span className="clay-meta">
                                    #{selectedRequest.ticket_number}
                                </span>
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                copyTicketNumber(
                                                    selectedRequest.ticket_number,
                                                )
                                            }
                                            className="clay-icon-chip clay-focus size-8 text-[var(--clay-text-muted)]"
                                        >
                                            <Copy className="size-3.5" />
                                        </button>
                                    </TooltipTrigger>
                                    <TooltipContent className="clay-tooltip">
                                        Copy ticket number
                                    </TooltipContent>
                                </Tooltip>
                            </div>
                            <DialogTitle className="text-xl font-bold leading-snug text-[var(--clay-ink)]">
                                {selectedRequest.subject}
                            </DialogTitle>
                            <DialogDescription className="flex flex-wrap items-center gap-2 text-xs">
                                <span>{selectedRequest.category_label}</span>
                                <span className="text-[var(--clay-border)]">
                                    /
                                </span>
                                <span>{selectedRequest.status_label}</span>
                                <span className="text-[var(--clay-border)]">
                                    /
                                </span>
                                <span className="clay-meta">
                                    {selectedRequest.created_at}
                                </span>
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-4 py-2 text-sm">
                            <div className="clay-inset p-4">
                                <p className="bulletin-eyebrow mb-1.5">
                                    Your message
                                </p>
                                <p className="whitespace-pre-wrap text-sm leading-relaxed text-[var(--clay-text)]">
                                    {selectedRequest.description}
                                </p>
                            </div>

                            {selectedRequest.admin_notes ? (
                                <div className="rounded-[var(--radius-sm)] bg-[var(--clay-primary-soft)] p-4">
                                    <p className="bulletin-eyebrow mb-1.5 text-[var(--clay-primary-bright)]">
                                        Official reply
                                        {selectedRequest.assigned_to_name && (
                                            <span className="ml-2 normal-case tracking-normal">
                                                {
                                                    selectedRequest.assigned_to_name
                                                }
                                            </span>
                                        )}
                                    </p>
                                    <p className="whitespace-pre-wrap text-sm leading-relaxed text-[var(--clay-text)]">
                                        {selectedRequest.admin_notes}
                                    </p>
                                    {selectedRequest.resolved_at && (
                                        <p className="clay-meta mt-2">
                                            resolved{' '}
                                            {selectedRequest.resolved_at}
                                        </p>
                                    )}
                                </div>
                            ) : (
                                <p className="clay-inset flex items-start gap-2 p-4 text-sm text-[var(--clay-text-muted)]">
                                    <Clock className="mt-0.5 size-3.5 shrink-0 text-[var(--status-pending)]" />
                                    In the queue. A staff member will post a
                                    reply here.
                                </p>
                            )}
                        </div>

                        <DialogFooter>
                            <Button
                                variant="outline"
                                className="clay-focus rounded-full border-[var(--clay-border)] bg-transparent font-semibold shadow-none"
                                onClick={() => setSelectedRequest(null)}
                            >
                                Close
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                )}
            </Dialog>
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
