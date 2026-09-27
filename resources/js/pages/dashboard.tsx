import { Head, useForm, usePage } from '@inertiajs/react';
import {
    AlertCircle,
    ArrowUpRight,
    BookOpen,
    Calendar,
    CheckCircle2,
    Clock,
    Copy,
    ExternalLink,
    HelpCircle,
    Inbox,
    LifeBuoy,
    MessageSquare,
    Plus,
    Search,
    Send,
    Sparkles,
    UserCheck,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import AssistanceRequestController from '@/actions/App/Http/Controllers/AssistanceRequestController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
import type {
    AssistancePriorityType,
    AssistanceRequestItem,
    AssistanceStatusType,
    Auth,
    DashboardStats,
    OptionItem,
} from '@/types';

interface DashboardProps {
    requests?: AssistanceRequestItem[];
    stats?: DashboardStats;
    categories?: OptionItem[];
    priorities?: OptionItem[];
}

export default function Dashboard({
    requests = [],
    stats = { activeRequests: 0, resolvedRequests: 0, totalRequests: 0 },
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
}: DashboardProps) {
    const { auth } = usePage<{ auth: Auth }>().props;

    const [isAskModalOpen, setIsAskModalOpen] = useState(false);
    const [selectedRequest, setSelectedRequest] =
        useState<AssistanceRequestItem | null>(null);
    const [filterStatus, setFilterStatus] = useState<string>('all');
    const [searchQuery, setSearchQuery] = useState<string>('');

    const form = useForm({
        category: 'academic',
        priority: 'medium',
        subject: '',
        description: '',
    });

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
        toast.info(`Copied ticket #${ticket} to clipboard`);
    };

    const getStatusBadge = (status: AssistanceStatusType, label: string) => {
        switch (status) {
            case 'pending':
                return (
                    <Badge
                        variant="outline"
                        className="border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400"
                    >
                        <span className="mr-1.5 size-1.5 rounded-full bg-amber-500 animate-pulse" />
                        {label}
                    </Badge>
                );
            case 'in_progress':
                return (
                    <Badge
                        variant="outline"
                        className="border-blue-500/30 bg-blue-500/10 text-blue-600 dark:text-blue-400"
                    >
                        <Clock className="mr-1 size-3 text-blue-500" />
                        {label}
                    </Badge>
                );
            case 'resolved':
                return (
                    <Badge
                        variant="outline"
                        className="border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"
                    >
                        <CheckCircle2 className="mr-1 size-3 text-emerald-500" />
                        {label}
                    </Badge>
                );
            case 'closed':
            default:
                return (
                    <Badge variant="secondary" className="text-muted-foreground">
                        {label}
                    </Badge>
                );
        }
    };

    const getPriorityBadge = (priority: AssistancePriorityType, label: string) => {
        switch (priority) {
            case 'urgent':
                return (
                    <Badge
                        variant="destructive"
                        className="bg-red-500/15 text-red-600 hover:bg-red-500/25 border-red-500/20 dark:text-red-400"
                    >
                        {label}
                    </Badge>
                );
            case 'high':
                return (
                    <Badge
                        variant="outline"
                        className="border-orange-500/30 bg-orange-500/10 text-orange-600 dark:text-orange-400"
                    >
                        {label}
                    </Badge>
                );
            case 'medium':
                return (
                    <Badge
                        variant="outline"
                        className="border-sky-500/30 bg-sky-500/10 text-sky-600 dark:text-sky-400"
                    >
                        {label}
                    </Badge>
                );
            case 'low':
            default:
                return (
                    <Badge variant="outline" className="text-muted-foreground">
                        {label}
                    </Badge>
                );
        }
    };

    const filteredRequests = requests.filter((req) => {
        const matchesStatus =
            filterStatus === 'all'
                ? true
                : filterStatus === 'active'
                  ? req.status === 'pending' || req.status === 'in_progress'
                  : req.status === 'resolved' || req.status === 'closed';

        const matchesSearch =
            searchQuery.trim() === ''
                ? true
                : req.subject.toLowerCase().includes(searchQuery.toLowerCase()) ||
                  req.ticket_number
                      .toLowerCase()
                      .includes(searchQuery.toLowerCase()) ||
                  req.category_label
                      .toLowerCase()
                      .includes(searchQuery.toLowerCase());

        return matchesStatus && matchesSearch;
    });

    return (
        <>
            <Head title="Student Dashboard" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8 max-w-7xl mx-auto w-full">
                {/* Hero / Greeting Banner */}
                <div className="relative overflow-hidden rounded-2xl border border-sidebar-border/70 bg-gradient-to-r from-primary/10 via-background to-accent/20 p-6 sm:p-8 shadow-xs">
                    <div className="relative z-10 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div className="space-y-1.5">
                            <div className="inline-flex items-center gap-2 rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold text-primary">
                                <Sparkles className="size-3.5" />
                                EduFlow Student Portal
                            </div>
                            <h1 className="text-2xl font-bold tracking-tight sm:text-3xl text-foreground">
                                Welcome back, {auth.user?.name || 'Student'}! 👋
                            </h1>
                            <p className="text-sm text-muted-foreground max-w-xl">
                                Need guidance with your coursework, system access, or
                                campus records? Our instructors and administration
                                staff are ready to assist you.
                            </p>
                        </div>

                        <div className="flex items-center gap-3">
                            <Button
                                size="lg"
                                onClick={() => setIsAskModalOpen(true)}
                                className="group relative overflow-hidden bg-primary px-5 font-semibold text-primary-foreground shadow-md transition-all hover:shadow-primary/25 hover:shadow-lg active:scale-95"
                            >
                                <LifeBuoy className="mr-2 size-5 transition-transform group-hover:rotate-12" />
                                <span>Ask Assistance</span>
                            </Button>
                        </div>
                    </div>

                    <div className="pointer-events-none absolute -right-12 -bottom-12 size-64 rounded-full bg-primary/5 blur-3xl" />
                </div>

                {/* Quick Stats Grid */}
                <div className="grid gap-4 sm:grid-cols-3">
                    <Card className="border-sidebar-border/70 shadow-xs hover:border-amber-500/40 transition-colors">
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Active Inquiries
                            </CardTitle>
                            <div className="rounded-lg bg-amber-500/10 p-2 text-amber-600 dark:text-amber-400">
                                <Clock className="size-4" />
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-foreground">
                                {stats.activeRequests}
                            </div>
                            <p className="text-xs text-muted-foreground mt-1 flex items-center gap-1">
                                {stats.activeRequests > 0 ? (
                                    <span className="font-medium text-amber-500">
                                        Awaiting staff resolution
                                    </span>
                                ) : (
                                    <span>All requests caught up</span>
                                )}
                            </p>
                        </CardContent>
                    </Card>

                    <Card className="border-sidebar-border/70 shadow-xs hover:border-emerald-500/40 transition-colors">
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Resolved Tickets
                            </CardTitle>
                            <div className="rounded-lg bg-emerald-500/10 p-2 text-emerald-600 dark:text-emerald-400">
                                <CheckCircle2 className="size-4" />
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-foreground">
                                {stats.resolvedRequests}
                            </div>
                            <p className="text-xs text-muted-foreground mt-1">
                                Inquiries successfully answered
                            </p>
                        </CardContent>
                    </Card>

                    <Card className="border-sidebar-border/70 shadow-xs hover:border-primary/40 transition-colors">
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Support Helpdesk
                            </CardTitle>
                            <div className="rounded-lg bg-primary/10 p-2 text-primary">
                                <UserCheck className="size-4" />
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-foreground flex items-center gap-2">
                                <span>Online</span>
                                <span className="size-2 rounded-full bg-emerald-500 animate-pulse" />
                            </div>
                            <p className="text-xs text-muted-foreground mt-1">
                                Avg. response time: &lt; 24 business hours
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {/* My Assistance Requests Section */}
                <div className="space-y-4">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 className="text-lg font-bold tracking-tight text-foreground">
                                My Assistance Requests
                            </h2>
                            <p className="text-xs text-muted-foreground">
                                Real-time status of your submitted tickets and counselor
                                responses
                            </p>
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            <div className="relative">
                                <Search className="absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                                <Input
                                    placeholder="Search by subject or ticket #..."
                                    value={searchQuery}
                                    onChange={(e) => setSearchQuery(e.target.value)}
                                    className="h-9 w-48 sm:w-64 pl-8 text-xs bg-background"
                                />
                            </div>

                            <div className="inline-flex rounded-lg border border-sidebar-border/70 bg-muted/40 p-0.5 text-xs font-medium">
                                <button
                                    type="button"
                                    onClick={() => setFilterStatus('all')}
                                    className={`rounded-md px-2.5 py-1 transition-colors ${
                                        filterStatus === 'all'
                                            ? 'bg-background font-semibold text-foreground shadow-xs'
                                            : 'text-muted-foreground hover:text-foreground'
                                    }`}
                                >
                                    All ({requests.length})
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setFilterStatus('active')}
                                    className={`rounded-md px-2.5 py-1 transition-colors ${
                                        filterStatus === 'active'
                                            ? 'bg-background font-semibold text-foreground shadow-xs'
                                            : 'text-muted-foreground hover:text-foreground'
                                    }`}
                                >
                                    Active ({stats.activeRequests})
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setFilterStatus('resolved')}
                                    className={`rounded-md px-2.5 py-1 transition-colors ${
                                        filterStatus === 'resolved'
                                            ? 'bg-background font-semibold text-foreground shadow-xs'
                                            : 'text-muted-foreground hover:text-foreground'
                                    }`}
                                >
                                    Resolved ({stats.resolvedRequests})
                                </button>
                            </div>
                        </div>
                    </div>

                    {filteredRequests.length === 0 ? (
                        <Card className="border-sidebar-border/70 bg-card/50 p-8 text-center">
                            <div className="mx-auto flex size-12 items-center justify-center rounded-full bg-muted">
                                <Inbox className="size-6 text-muted-foreground" />
                            </div>
                            <h3 className="mt-3 text-sm font-semibold text-foreground">
                                No assistance requests found
                            </h3>
                            <p className="mt-1 text-xs text-muted-foreground max-w-sm mx-auto">
                                {searchQuery || filterStatus !== 'all'
                                    ? 'No inquiries matched your current filter criteria.'
                                    : 'You haven’t submitted any inquiries yet. Click "Ask Assistance" whenever you need guidance.'}
                            </p>
                            <div className="mt-4">
                                <Button
                                    size="sm"
                                    onClick={() => setIsAskModalOpen(true)}
                                    className="gap-1.5"
                                >
                                    <Plus className="size-4" />
                                    Submit Inquiry
                                </Button>
                            </div>
                        </Card>
                    ) : (
                        <div className="grid gap-3">
                            {filteredRequests.map((req) => (
                                <Card
                                    key={req.id}
                                    className="border-sidebar-border/70 hover:border-primary/40 transition-all shadow-xs cursor-pointer"
                                    onClick={() => setSelectedRequest(req)}
                                >
                                    <CardContent className="p-4 sm:p-5">
                                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                            <div className="space-y-1.5 min-w-0">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="font-mono text-xs font-semibold text-muted-foreground">
                                                        #{req.ticket_number}
                                                    </span>
                                                    {getStatusBadge(
                                                        req.status,
                                                        req.status_label,
                                                    )}
                                                    {getPriorityBadge(
                                                        req.priority,
                                                        req.priority_label,
                                                    )}
                                                    <Badge
                                                        variant="outline"
                                                        className="text-xs font-normal"
                                                    >
                                                        {req.category_label}
                                                    </Badge>
                                                </div>

                                                <h4 className="text-sm font-semibold text-foreground truncate">
                                                    {req.subject}
                                                </h4>

                                                <p className="text-xs text-muted-foreground line-clamp-1">
                                                    {req.description}
                                                </p>
                                            </div>

                                            <div className="flex items-center justify-between sm:justify-end gap-3 shrink-0 pt-2 sm:pt-0 border-t sm:border-t-0 border-border/50">
                                                <div className="text-right">
                                                    <p className="text-[11px] text-muted-foreground">
                                                        Submitted
                                                    </p>
                                                    <p className="text-xs font-medium text-foreground">
                                                        {req.created_at}
                                                    </p>
                                                </div>

                                                <Button
                                                    variant="secondary"
                                                    size="sm"
                                                    className="h-8 text-xs gap-1"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        setSelectedRequest(req);
                                                    }}
                                                >
                                                    <span>Details</span>
                                                    <ArrowUpRight className="size-3.5" />
                                                </Button>
                                            </div>
                                        </div>

                                        {req.admin_notes && (
                                            <div className="mt-3 rounded-lg bg-emerald-500/10 border border-emerald-500/20 p-2.5 text-xs text-emerald-800 dark:text-emerald-300 flex items-start gap-2">
                                                <CheckCircle2 className="size-4 text-emerald-600 dark:text-emerald-400 shrink-0 mt-0.5" />
                                                <div className="min-w-0">
                                                    <span className="font-semibold">
                                                        Staff Response:
                                                    </span>{' '}
                                                    <span className="line-clamp-2">
                                                        {req.admin_notes}
                                                    </span>
                                                </div>
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                    )}
                </div>

                {/* Campus Resources / FAQ Quick Grid */}
                <div className="space-y-3 pt-2">
                    <h3 className="text-sm font-semibold text-muted-foreground uppercase tracking-wider">
                        Campus Quick Resources
                    </h3>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <Card className="border-sidebar-border/70 hover:bg-accent/40 transition-colors">
                            <CardHeader className="p-4 space-y-1">
                                <div className="flex items-center justify-between">
                                    <BookOpen className="size-4 text-primary" />
                                    <ExternalLink className="size-3 text-muted-foreground" />
                                </div>
                                <CardTitle className="text-sm">
                                    Academic Library
                                </CardTitle>
                                <CardDescription className="text-xs">
                                    Access research journals, past exams, and e-books.
                                </CardDescription>
                            </CardHeader>
                        </Card>

                        <Card className="border-sidebar-border/70 hover:bg-accent/40 transition-colors">
                            <CardHeader className="p-4 space-y-1">
                                <div className="flex items-center justify-between">
                                    <Calendar className="size-4 text-primary" />
                                    <ExternalLink className="size-3 text-muted-foreground" />
                                </div>
                                <CardTitle className="text-sm">
                                    Academic Calendar
                                </CardTitle>
                                <CardDescription className="text-xs">
                                    Check term milestones, holidays, and exam schedules.
                                </CardDescription>
                            </CardHeader>
                        </Card>

                        <Card className="border-sidebar-border/70 hover:bg-accent/40 transition-colors">
                            <CardHeader className="p-4 space-y-1">
                                <div className="flex items-center justify-between">
                                    <HelpCircle className="size-4 text-primary" />
                                    <ExternalLink className="size-3 text-muted-foreground" />
                                </div>
                                <CardTitle className="text-sm">
                                    IT &amp; LMS Guides
                                </CardTitle>
                                <CardDescription className="text-xs">
                                    Self-help setup guides for Wi-Fi and student portal.
                                </CardDescription>
                            </CardHeader>
                        </Card>

                        <Card className="border-sidebar-border/70 hover:bg-accent/40 transition-colors">
                            <CardHeader className="p-4 space-y-1">
                                <div className="flex items-center justify-between">
                                    <MessageSquare className="size-4 text-primary" />
                                    <ExternalLink className="size-3 text-muted-foreground" />
                                </div>
                                <CardTitle className="text-sm">
                                    Office Hours
                                </CardTitle>
                                <CardDescription className="text-xs">
                                    Book consultation slots with advisors and faculty.
                                </CardDescription>
                            </CardHeader>
                        </Card>
                    </div>
                </div>
            </div>

            {/* "Ask Assistance" Modal Dialog */}
            <Dialog open={isAskModalOpen} onOpenChange={setIsAskModalOpen}>
                <DialogContent className="sm:max-w-lg">
                    <form onSubmit={handleSubmitAssistance}>
                        <DialogHeader>
                            <DialogTitle className="flex items-center gap-2 text-lg">
                                <LifeBuoy className="size-5 text-primary" />
                                Ask Assistance
                            </DialogTitle>
                            <DialogDescription className="text-xs">
                                Submit an inquiry directly to the administration and
                                academic advisors. Your ticket will be logged and
                                answered.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="grid gap-4 py-4">
                            <div className="grid grid-cols-2 gap-3">
                                <div className="space-y-1.5">
                                    <Label htmlFor="category" className="text-xs">
                                        Category
                                    </Label>
                                    <Select
                                        value={form.data.category}
                                        onValueChange={(val) =>
                                            form.setData('category', val)
                                        }
                                    >
                                        <SelectTrigger id="category" className="w-full">
                                            <SelectValue placeholder="Select topic" />
                                        </SelectTrigger>
                                        <SelectContent>
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
                                    <InputError message={form.errors.category} />
                                </div>

                                <div className="space-y-1.5">
                                    <Label htmlFor="priority" className="text-xs">
                                        Urgency Level
                                    </Label>
                                    <Select
                                        value={form.data.priority}
                                        onValueChange={(val) =>
                                            form.setData('priority', val)
                                        }
                                    >
                                        <SelectTrigger id="priority" className="w-full">
                                            <SelectValue placeholder="Select priority" />
                                        </SelectTrigger>
                                        <SelectContent>
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
                                    <InputError message={form.errors.priority} />
                                </div>
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="subject" className="text-xs">
                                    Subject / Summary
                                </Label>
                                <Input
                                    id="subject"
                                    placeholder="e.g., Unable to submit assignment on Course Portal"
                                    value={form.data.subject}
                                    onChange={(e) =>
                                        form.setData('subject', e.target.value)
                                    }
                                    required
                                    maxLength={255}
                                />
                                <InputError message={form.errors.subject} />
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="description" className="text-xs">
                                    Detailed Message / Problem Description
                                </Label>
                                <Textarea
                                    id="description"
                                    placeholder="Please provide details about what happened, error messages, or what you need help with..."
                                    rows={4}
                                    value={form.data.description}
                                    onChange={(e) =>
                                        form.setData('description', e.target.value)
                                    }
                                    required
                                />
                                <p className="text-[11px] text-muted-foreground">
                                    Include any relevant course codes or steps to
                                    reproduce the problem.
                                </p>
                                <InputError message={form.errors.description} />
                            </div>
                        </div>

                        <DialogFooter className="gap-2 sm:gap-0">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setIsAskModalOpen(false)}
                                disabled={form.processing}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={form.processing}
                                className="gap-1.5"
                            >
                                <Send className="size-4" />
                                {form.processing ? 'Submitting...' : 'Submit Request'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Ticket Details Modal */}
            <Dialog
                open={!!selectedRequest}
                onOpenChange={(open) => !open && setSelectedRequest(null)}
            >
                {selectedRequest && (
                    <DialogContent className="sm:max-w-lg">
                        <DialogHeader>
                            <div className="flex items-center justify-between pr-4">
                                <div className="flex items-center gap-2">
                                    <span className="font-mono text-xs font-semibold text-muted-foreground">
                                        #{selectedRequest.ticket_number}
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            copyTicketNumber(
                                                selectedRequest.ticket_number,
                                            )
                                        }
                                        className="text-muted-foreground hover:text-foreground"
                                        title="Copy Ticket ID"
                                    >
                                        <Copy className="size-3.5" />
                                    </button>
                                </div>
                                {getStatusBadge(
                                    selectedRequest.status,
                                    selectedRequest.status_label,
                                )}
                            </div>
                            <DialogTitle className="text-base font-bold mt-1 text-foreground">
                                {selectedRequest.subject}
                            </DialogTitle>
                            <DialogDescription className="text-xs text-muted-foreground flex items-center gap-2">
                                <span>{selectedRequest.category_label}</span>
                                <span>•</span>
                                <span>Submitted on {selectedRequest.created_at}</span>
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-4 py-2 text-sm">
                            <div className="rounded-lg bg-muted/40 p-3.5 border border-border/60">
                                <p className="text-xs font-medium text-muted-foreground mb-1">
                                    Your Inquiry
                                </p>
                                <p className="text-foreground whitespace-pre-wrap text-xs sm:text-sm">
                                    {selectedRequest.description}
                                </p>
                            </div>

                            {selectedRequest.admin_notes ? (
                                <div className="rounded-lg bg-emerald-500/10 border border-emerald-500/30 p-3.5">
                                    <div className="flex items-center gap-1.5 text-xs font-semibold text-emerald-700 dark:text-emerald-400 mb-1">
                                        <CheckCircle2 className="size-4" />
                                        <span>Official Response</span>
                                        {selectedRequest.assigned_to_name && (
                                            <span className="font-normal text-muted-foreground">
                                                from {selectedRequest.assigned_to_name}
                                            </span>
                                        )}
                                    </div>
                                    <p className="text-xs sm:text-sm text-foreground whitespace-pre-wrap">
                                        {selectedRequest.admin_notes}
                                    </p>
                                    {selectedRequest.resolved_at && (
                                        <p className="text-[11px] text-muted-foreground mt-2">
                                            Resolved on {selectedRequest.resolved_at}
                                        </p>
                                    )}
                                </div>
                            ) : (
                                <div className="rounded-lg bg-amber-500/10 border border-amber-500/20 p-3 text-xs text-amber-800 dark:text-amber-300 flex items-center gap-2">
                                    <AlertCircle className="size-4 text-amber-500 shrink-0" />
                                    <span>
                                        Your request is in our queue. A staff member
                                        will review and post a response here soon.
                                    </span>
                                </div>
                            )}
                        </div>

                        <DialogFooter>
                            <Button
                                variant="secondary"
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
