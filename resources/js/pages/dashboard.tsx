import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    ArrowUpRight,
    Bell,
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
import { dashboard } from '@/routes';
import {
    index as notificationsIndex,
    markAllRead,
} from '@/routes/notifications';
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
            return 'bulletin-tab-pending';
        case 'in_progress':
            return 'bulletin-tab-progress';
        case 'resolved':
            return 'bulletin-tab-resolved';
        case 'closed':
        default:
            return 'bulletin-tab-closed';
    }
}

function priorityClass(priority: AssistancePriorityType): string {
    if (priority === 'urgent') {
        return 'text-[var(--status-urgent)] border-[var(--status-urgent)]/30';
    }

    if (priority === 'high') {
        return 'text-[var(--status-pending)] border-[var(--status-pending)]/35';
    }

    return 'text-muted-foreground border-border';
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
    notifications: DashboardNotificationSummary;
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
    notifications,
}: DashboardProps) {
    const { auth } = usePage<{ auth: Auth }>().props;

    const [isAskModalOpen, setIsAskModalOpen] = useState(false);
    const [selectedRequest, setSelectedRequest] =
        useState<AssistanceRequestItem | null>(null);
    const [search, setSearch] = useState(filters.search);
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

    const handleMarkAllRead = () => {
        router.post(
            markAllRead.url(),
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('All notifications marked as read.');
                    router.reload({ only: ['notifications'] });
                },
                onError: () => {
                    toast.error('Could not mark notifications as read.');
                },
            },
        );
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

            <div className="bulletin bulletin-grain min-h-full w-full">
                <div className="mx-auto flex w-full max-w-6xl flex-col px-5 py-10 sm:px-8 sm:py-14">
                    {/* Masthead */}
                    <header className="border-b border-[var(--rule-strong)] pb-8">
                        <div className="flex flex-col gap-8 lg:flex-row lg:items-end lg:justify-between">
                            <div className="min-w-0">
                                <p className="bulletin-eyebrow">
                                    EduFlow &middot; Northstar Learning Center
                                </p>
                                <h1 className="mt-4 font-serif text-4xl leading-[1.05] font-normal tracking-tight text-foreground sm:text-6xl">
                                    Welcome back,
                                    <br />
                                    <span className="text-[var(--ink-teal)] italic">
                                        {firstName}.
                                    </span>
                                </h1>
                                <p className="mt-5 max-w-md text-sm leading-relaxed text-muted-foreground">
                                    Your desk for coursework, campus records,
                                    and anything the administration can answer.
                                </p>
                            </div>

                            <div className="flex shrink-0 flex-col items-start gap-5 lg:items-end">
                                <Button
                                    onClick={() => setIsAskModalOpen(true)}
                                    className="h-11 gap-2 rounded-[var(--radius)] bg-[var(--terracotta)] px-6 font-medium text-white shadow-[var(--shadow-md)] transition-transform hover:bg-[var(--terracotta-bright)] active:scale-[0.98]"
                                >
                                    <LifeBuoy className="size-4" />
                                    Ask Assistance
                                </Button>
                                <p className="font-mono text-[11px] text-[var(--ink-faint)]">
                                    {stats.totalRequests} ticket
                                    {stats.totalRequests === 1 ? '' : 's'} on
                                    file
                                </p>
                            </div>
                        </div>
                    </header>

                    {/* Ledger of figures */}
                    <dl className="grid divide-y divide-[var(--rule)] border-b border-[var(--rule-strong)] sm:grid-cols-3 sm:divide-y-0 sm:divide-x">
                        <div className="py-6 sm:px-6 sm:py-7 sm:first:pl-0">
                            <dt className="bulletin-eyebrow">Active</dt>
                            <dd className="mt-2 flex items-baseline gap-3">
                                <span className="bulletin-figure text-5xl">
                                    {stats.activeRequests}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    awaiting staff
                                </span>
                            </dd>
                        </div>
                        <div className="py-6 sm:px-6 sm:py-7">
                            <dt className="bulletin-eyebrow">Resolved</dt>
                            <dd className="mt-2 flex items-baseline gap-3">
                                <span className="bulletin-figure text-5xl text-[var(--status-resolved)]">
                                    {stats.resolvedRequests}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    all time
                                </span>
                            </dd>
                        </div>
                        <div className="py-6 sm:px-6 sm:py-7 sm:last:pr-0">
                            <dt className="bulletin-eyebrow">
                                Median turnaround
                            </dt>
                            <dd className="mt-2 flex items-baseline gap-3">
                                <span className="bulletin-figure text-5xl text-[var(--terracotta)]">
                                    {stats.medianResolutionMinutes === null
                                        ? '—'
                                        : formatDuration(
                                              stats.medianResolutionMinutes,
                                          )}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {stats.medianResolutionMinutes === null
                                        ? 'no data yet'
                                        : 'to answer'}
                                </span>
                            </dd>
                        </div>
                    </dl>

                    {/* Notifications */}
                    <section className="py-8">
                        <div className="flex items-baseline justify-between gap-4">
                            <h2 className="bulletin-eyebrow flex items-center gap-2">
                                <Bell className="size-3.5" />
                                Notifications
                                {notifications.unreadCount > 0 && (
                                    <span className="rounded-full bg-[var(--terracotta)] px-1.5 py-0.5 text-[10px] font-semibold text-white">
                                        {notifications.unreadCount}
                                    </span>
                                )}
                            </h2>

                            <div className="flex items-center gap-4">
                                {notifications.unreadCount > 0 && (
                                    <button
                                        type="button"
                                        onClick={handleMarkAllRead}
                                        className="text-xs text-muted-foreground underline-offset-4 transition-colors hover:text-foreground hover:underline"
                                    >
                                        Mark all read
                                    </button>
                                )}
                                <Link
                                    href={notificationsIndex()}
                                    className="text-xs text-[var(--ink-teal)] underline-offset-4 hover:underline"
                                >
                                    All notifications
                                </Link>
                            </div>
                        </div>

                        {notifications.recent.length === 0 ? (
                            <p className="mt-4 border-t border-[var(--rule)] py-4 text-sm text-muted-foreground italic">
                                Nothing new. We will tell you here when a member
                                of staff replies.
                            </p>
                        ) : (
                            <ul className="mt-4">
                                {notifications.recent.map((notification) => (
                                    <li
                                        key={notification.id}
                                        className="bulletin-row flex items-baseline gap-4 py-3"
                                    >
                                        <span
                                            className={`mt-1.5 size-1.5 shrink-0 rounded-full ${
                                                notification.readAt
                                                    ? 'bg-[var(--rule-strong)]'
                                                    : 'bg-[var(--terracotta)]'
                                            }`}
                                        />
                                        <p className="min-w-0 flex-1 truncate text-sm text-foreground">
                                            {notification.title}
                                        </p>
                                        <time className="shrink-0 font-mono text-[11px] text-muted-foreground">
                                            {notification.createdAt}
                                        </time>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    {/* Assistance requests */}
                    <section className="pb-10">
                        <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                            <div>
                                <h2 className="bulletin-eyebrow">
                                    Assistance requests
                                </h2>
                                <p className="mt-1.5 text-sm text-muted-foreground">
                                    {isFiltering
                                        ? 'Filtered view of your tickets.'
                                        : 'Every ticket you have raised, newest first.'}
                                </p>
                            </div>

                            <div className="flex flex-wrap items-center gap-3">
                                <div className="relative">
                                    <Search className="pointer-events-none absolute left-3 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                                    <Input
                                        placeholder="Subject or ticket no."
                                        value={search}
                                        onChange={(e) =>
                                            setSearch(e.target.value)
                                        }
                                        className="bulletin-field h-9 w-56 pl-8 text-xs"
                                    />
                                </div>

                                <div className="flex items-center gap-1">
                                    {statusTabs.map((tab) => {
                                        const count =
                                            tab.value === 'all'
                                                ? stats.totalRequests
                                                : tab.value === 'active'
                                                  ? stats.activeRequests
                                                  : stats.resolvedRequests;

                                        return (
                                            <button
                                                key={tab.value}
                                                type="button"
                                                onClick={() =>
                                                    handleStatusChange(
                                                        tab.value,
                                                    )
                                                }
                                                className={`rounded-[var(--radius)] px-2.5 py-1.5 font-mono text-[11px] uppercase tracking-wider transition-colors ${
                                                    filters.status === tab.value
                                                        ? 'bg-[var(--ink-teal)] text-white'
                                                        : 'text-muted-foreground hover:bg-accent hover:text-foreground'
                                                }`}
                                            >
                                                {tab.label}
                                                <span className="ml-1.5 opacity-60">
                                                    {count}
                                                </span>
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                        </div>

                        {requests.data.length === 0 ? (
                            <div className="mt-6 flex flex-col items-start gap-4 border-t border-[var(--rule-strong)] py-12">
                                <Inbox className="size-6 text-[var(--rule-strong)]" />
                                <div>
                                    <p className="font-serif text-2xl text-foreground">
                                        {isFiltering
                                            ? 'Nothing matches.'
                                            : 'No requests yet.'}
                                    </p>
                                    <p className="mt-1.5 max-w-sm text-sm text-muted-foreground">
                                        {isFiltering
                                            ? 'Try a different search, or clear the filter to see everything.'
                                            : 'When you need guidance with coursework, records, or campus systems, raise a ticket and staff will pick it up.'}
                                    </p>
                                </div>
                                {isFiltering ? (
                                    <Button
                                        variant="outline"
                                        className="h-9 rounded-[var(--radius)] px-4 text-xs"
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
                                        onClick={() => setIsAskModalOpen(true)}
                                        className="h-9 gap-2 rounded-[var(--radius)] bg-[var(--terracotta)] px-4 text-xs text-white hover:bg-[var(--terracotta-bright)]"
                                    >
                                        <Plus className="size-3.5" />
                                        Raise a ticket
                                    </Button>
                                )}
                            </div>
                        ) : (
                            <>
                                <ul className="mt-6 border-t border-[var(--rule-strong)]">
                                    {requests.data.map((req) => (
                                        <li key={req.id}>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setSelectedRequest(req)
                                                }
                                                className="bulletin-row group flex w-full items-center gap-4 py-4 text-left"
                                            >
                                                <span
                                                    className={`bulletin-tab ${statusTabClass(req.status)} hidden sm:block`}
                                                />

                                                <div className="min-w-0 flex-1">
                                                    <div className="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                                                        <span className="font-mono text-[11px] text-muted-foreground">
                                                            #{req.ticket_number}
                                                        </span>
                                                        <span className="text-[11px] text-[var(--rule-strong)]">
                                                            /
                                                        </span>
                                                        <span className="text-[11px] text-muted-foreground">
                                                            {req.category_label}
                                                        </span>
                                                        <Badge
                                                            variant="outline"
                                                            className={`h-4 rounded-[2px] px-1.5 font-mono text-[10px] font-medium uppercase tracking-wider ${priorityClass(req.priority)}`}
                                                        >
                                                            {req.priority_label}
                                                        </Badge>
                                                    </div>

                                                    <p className="mt-1 truncate font-serif text-lg leading-snug text-foreground">
                                                        {req.subject}
                                                    </p>

                                                    {req.admin_notes && (
                                                        <p className="mt-1 truncate text-xs text-[var(--status-resolved)]">
                                                            <span className="font-medium">
                                                                Reply:
                                                            </span>{' '}
                                                            {req.admin_notes}
                                                        </p>
                                                    )}
                                                </div>

                                                <div className="hidden shrink-0 text-right sm:block">
                                                    <p className="font-mono text-[11px] text-muted-foreground">
                                                        {req.created_at}
                                                    </p>
                                                    <p
                                                        className={`mt-1 text-[11px] ${
                                                            req.status ===
                                                            'resolved'
                                                                ? 'text-[var(--status-resolved)]'
                                                                : req.status ===
                                                                    'in_progress'
                                                                  ? 'text-[var(--status-progress)]'
                                                                  : 'text-[var(--status-pending)]'
                                                        }`}
                                                    >
                                                        {req.status_label}
                                                    </p>
                                                </div>

                                                <ArrowUpRight className="size-4 shrink-0 text-[var(--rule-strong)] transition-all group-hover:-translate-y-0.5 group-hover:translate-x-0.5 group-hover:text-[var(--terracotta)]" />
                                            </button>
                                        </li>
                                    ))}
                                </ul>

                                {hasPages && (
                                    <div className="flex items-center justify-between pt-5 text-xs text-muted-foreground">
                                        <span className="font-mono">
                                            {from ?? 0}–{to ?? 0} / {total}
                                        </span>
                                        <div className="flex items-center gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                className="h-8 rounded-[var(--radius)] text-xs"
                                                disabled={current_page <= 1}
                                                onClick={() =>
                                                    reloadRequests({
                                                        page: current_page - 1,
                                                    })
                                                }
                                            >
                                                Previous
                                            </Button>
                                            <span className="font-mono">
                                                {current_page}/{last_page}
                                            </span>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                className="h-8 rounded-[var(--radius)] text-xs"
                                                disabled={
                                                    current_page >= last_page
                                                }
                                                onClick={() =>
                                                    reloadRequests({
                                                        page: current_page + 1,
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

                    {/* Quick resources */}
                    {quickResources.length > 0 && (
                        <section className="border-t border-[var(--rule-strong)] py-8">
                            <h2 className="bulletin-eyebrow">
                                Campus resources
                            </h2>
                            <ul className="mt-4 grid gap-x-8 sm:grid-cols-2">
                                {quickResources.map((resource) => {
                                    const Icon =
                                        resourceIcons[resource.icon] ?? Link2;

                                    return (
                                        <li
                                            key={resource.title}
                                            className="bulletin-row flex items-start gap-3 py-3.5"
                                        >
                                            <Icon className="mt-0.5 size-4 shrink-0 text-[var(--ink-teal)]" />
                                            <div className="min-w-0 flex-1">
                                                {resource.url ? (
                                                    <a
                                                        href={resource.url}
                                                        target="_blank"
                                                        rel="noreferrer noopener"
                                                        className="group inline-flex items-center gap-1.5 text-sm font-medium text-foreground"
                                                    >
                                                        {resource.title}
                                                        <ExternalLink className="size-3 text-muted-foreground transition-colors group-hover:text-[var(--terracotta)]" />
                                                    </a>
                                                ) : (
                                                    <span className="text-sm text-muted-foreground">
                                                        {resource.title}
                                                    </span>
                                                )}
                                                <p className="mt-0.5 text-xs text-muted-foreground">
                                                    {resource.description}
                                                </p>
                                            </div>
                                        </li>
                                    );
                                })}
                            </ul>
                        </section>
                    )}
                </div>
            </div>

            {/* Ask Assistance */}
            <Dialog open={isAskModalOpen} onOpenChange={setIsAskModalOpen}>
                <DialogContent className="bulletin sm:max-w-lg">
                    <form onSubmit={handleSubmitAssistance}>
                        <DialogHeader>
                            <DialogTitle className="flex items-center gap-2 font-serif text-2xl font-normal">
                                <LifeBuoy className="size-5 text-[var(--ink-teal)]" />
                                Ask Assistance
                            </DialogTitle>
                            <DialogDescription className="text-xs">
                                Goes straight to the administration and academic
                                advisors. You will get a ticket number back
                                immediately.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="grid gap-4 py-4">
                            <div className="grid grid-cols-2 gap-3">
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
                                            className="bulletin-field w-full"
                                        >
                                            <SelectValue placeholder="Select topic" />
                                        </SelectTrigger>
                                        <SelectContent className="bulletin">
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
                                            className="bulletin-field w-full"
                                        >
                                            <SelectValue placeholder="Select priority" />
                                        </SelectTrigger>
                                        <SelectContent className="bulletin">
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
                                    className="bulletin-field"
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
                                    className="bulletin-field"
                                    required
                                />
                                <p className="text-[11px] text-muted-foreground">
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
                                className="rounded-[var(--radius)]"
                                onClick={() => setIsAskModalOpen(false)}
                                disabled={form.processing}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={form.processing}
                                className="gap-2 rounded-[var(--radius)] bg-[var(--terracotta)] text-white hover:bg-[var(--terracotta-bright)]"
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

            {/* Ticket detail */}
            <Dialog
                open={!!selectedRequest}
                onOpenChange={(open) => !open && setSelectedRequest(null)}
            >
                {selectedRequest && (
                    <DialogContent className="bulletin sm:max-w-lg">
                        <DialogHeader>
                            <div className="flex items-center justify-between gap-3 pr-4">
                                <span className="font-mono text-xs text-muted-foreground">
                                    #{selectedRequest.ticket_number}
                                </span>
                                <button
                                    type="button"
                                    onClick={() =>
                                        copyTicketNumber(
                                            selectedRequest.ticket_number,
                                        )
                                    }
                                    className="text-muted-foreground transition-colors hover:text-foreground"
                                    title="Copy ticket number"
                                >
                                    <Copy className="size-3.5" />
                                </button>
                            </div>
                            <DialogTitle className="font-serif text-2xl font-normal leading-snug">
                                {selectedRequest.subject}
                            </DialogTitle>
                            <DialogDescription className="flex flex-wrap items-center gap-2 text-xs">
                                <span>{selectedRequest.category_label}</span>
                                <span className="text-[var(--rule-strong)]">
                                    /
                                </span>
                                <span>{selectedRequest.status_label}</span>
                                <span className="text-[var(--rule-strong)]">
                                    /
                                </span>
                                <span className="font-mono">
                                    {selectedRequest.created_at}
                                </span>
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-4 py-2 text-sm">
                            <div className="border-l-2 border-[var(--rule-strong)] pl-3.5">
                                <p className="bulletin-eyebrow mb-1.5">
                                    Your message
                                </p>
                                <p className="whitespace-pre-wrap text-sm leading-relaxed text-foreground">
                                    {selectedRequest.description}
                                </p>
                            </div>

                            {selectedRequest.admin_notes ? (
                                <div className="border-l-2 border-[var(--status-resolved)] pl-3.5">
                                    <p className="bulletin-eyebrow mb-1.5 text-[var(--status-resolved)]">
                                        Official reply
                                        {selectedRequest.assigned_to_name && (
                                            <span className="ml-2 normal-case tracking-normal">
                                                {
                                                    selectedRequest.assigned_to_name
                                                }
                                            </span>
                                        )}
                                    </p>
                                    <p className="whitespace-pre-wrap text-sm leading-relaxed text-foreground">
                                        {selectedRequest.admin_notes}
                                    </p>
                                    {selectedRequest.resolved_at && (
                                        <p className="mt-2 font-mono text-[11px] text-muted-foreground">
                                            resolved{' '}
                                            {selectedRequest.resolved_at}
                                        </p>
                                    )}
                                </div>
                            ) : (
                                <p className="flex items-start gap-2 border-l-2 border-[var(--status-pending)] pl-3.5 text-sm text-muted-foreground">
                                    <Clock className="mt-0.5 size-3.5 shrink-0 text-[var(--status-pending)]" />
                                    In the queue. A staff member will post a
                                    reply here.
                                </p>
                            )}
                        </div>

                        <DialogFooter>
                            <Button
                                variant="outline"
                                className="rounded-[var(--radius)]"
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
