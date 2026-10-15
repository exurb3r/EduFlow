import { Head, Link, router, useHttp, usePoll } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    ArrowRight,
    CheckCircle2,
    ExternalLink,
    HelpCircle,
    Info,
    RefreshCw,
    ShieldCheck,
    Sparkles,
} from 'lucide-react';
import { useState } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/reui/alert';
import { Badge } from '@/components/reui/badge';
import {
    Frame,
    FrameDescription,
    FrameHeader,
    FramePanel,
    FrameTitle,
} from '@/components/reui/frame';
import {
    Timeline,
    TimelineContent,
    TimelineDate,
    TimelineHeader,
    TimelineIndicator,
    TimelineItem,
    TimelineSeparator,
    TimelineTitle,
} from '@/components/reui/timeline';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import { formatUsdc } from '@/lib/financial-aid';
import { store } from '@/routes/finance/policy-evaluations';

type Source = {
    source_type: 'collection' | 'transaction' | 'budget_snapshot';
    source_id: number;
    source_digest: string;
    label: string;
    evidence: {
        currency: string;
        description?: string;
        reference?: string;
        source_stream?: string;
        received_minor_units?: string;
        restricted_minor_units?: string;
        realized_receipts_minor_units?: string;
        opening_funds_minor_units?: string;
        protected_reserve_minor_units?: string;
        captured_cash_headroom_minor_units?: string;
        recorded_amount?: string;
        reviewed_receipt?: boolean;
        movement_sourced?: boolean;
    };
};
type Citation = { clause_id: string; quote: string };
type Result = {
    category_id?: string | null;
    explanation?: string;
    citations?: Citation[];
    questions?: string[];
    missing_evidence?: string[];
    next_step?: string;
    proposed_minor_units?: string;
    unallocated_minor_units?: string;
    needs_review?: boolean;
    jev_called?: boolean;
    lines?: {
        target_type: string;
        target_id: number;
        target_name: string;
        category_id: string | null;
        proposed_minor_units: string;
        decision: string;
        explanation: string | null;
        citations: Citation[];
    }[];
};
type Evaluation = {
    id: number;
    source_type: Source['source_type'];
    source_id: number;
    source_digest: string;
    label: string;
    reason: string;
    state: string;
    stage: string;
    completed_targets: number;
    total_targets: number;
    timeline: { stage: string; message: string; at: string }[];
    institution_policy_version_id: number;
    publication_digest: string;
    provider: string | null;
    model: string | null;
    last_error: string | null;
    result: Result | null;
    result_valid: boolean;
};
type Props = {
    institution: { name: string };
    sources: Source[];
    current: {
        title: string;
        policy_version_id: number;
        publication_digest: string;
    } | null;
    evaluations: Evaluation[];
    queueReady: boolean;
    aiEnabled: boolean;
    adminUrl: string;
    policyUrl: string;
    financeUrl?: string;
    collectionsUrl?: string;
    supervisorUrl?: string;
};

function statusLabel(state: string): string {
    return state === 'needs_review'
        ? 'Needs review'
        : state === 'completed'
          ? 'Ready for review'
          : state === 'running'
            ? 'Running'
            : 'Queued';
}

function Citations({ items }: { items: Citation[] }) {
    if (!items || items.length === 0) return null;

    return (
        <div className="flex flex-col gap-2 rounded-lg border border-border/60 bg-muted/30 p-3.5">
            <span className="text-xs font-semibold tracking-wide uppercase text-muted-foreground">
                Cited Policy Clauses
            </span>
            <div className="flex flex-col gap-3">
                {items.map((item, index) => (
                    <blockquote
                        key={index}
                        className="flex min-w-0 flex-col gap-1 border-l-2 border-primary/50 pl-3 text-sm"
                    >
                        <span className="text-xs font-mono font-medium text-primary">
                            Clause #{item.clause_id}
                        </span>
                        <p className="text-sm text-foreground/90 leading-relaxed italic">
                            “{item.quote}”
                        </p>
                    </blockquote>
                ))}
            </div>
        </div>
    );
}

export default function PolicyEvaluations({
    institution,
    sources,
    current,
    evaluations,
    queueReady,
    aiEnabled,
    adminUrl,
    policyUrl,
    financeUrl: _financeUrl = '/finance',
    collectionsUrl = '/finance/collections',
    supervisorUrl = '/finance/finance-supervisor',
}: Props) {
    const [sourceKey, setSourceKey] = useState(() => {
        const query = new URLSearchParams(
            typeof window === 'undefined' ? '' : window.location.search,
        );
        const requested = `${query.get('source_type')}:${query.get('source_id')}`;
        return sources.some(
            (source) =>
                `${source.source_type}:${source.source_id}` === requested,
        )
            ? requested
            : sources[0]
              ? `${sources[0].source_type}:${sources[0].source_id}`
              : '';
    });
    const [reason, setReason] = useState(
        'Review allocation against current institution policy.',
    );
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [local, setLocal] = useState<Evaluation | null>(null);
    const [pollError, setPollError] = useState(false);
    const http = useHttp<Record<string, never>, { data: Evaluation }>({});
    const source = sources.find(
        (item) => `${item.source_type}:${item.source_id}` === sourceKey,
    );
    const matching = evaluations.filter(
        (item) =>
            item.source_type === source?.source_type &&
            item.source_id === source?.source_id,
    );
    const displayed =
        evaluations.find((item) => item.id === selectedId) ??
        (local?.id === selectedId ? local : null) ??
        matching[0] ??
        null;
    const pending =
        matching.some((item) => ['queued', 'running'].includes(item.state)) ||
        (local !== null &&
            local.source_id === source?.source_id &&
            local.source_type === source?.source_type &&
            !evaluations.some((item) => item.id === local.id) &&
            ['queued', 'running'].includes(local.state));
    const { polling, start, stop } = usePoll(2000, {
        only: ['evaluations', 'current', 'queueReady', 'aiEnabled'],
        onSuccess: () => setPollError(false),
        onError: () => setPollError(true),
        onNetworkError: () => {
            setPollError(true);
            return false;
        },
        onHttpException: () => {
            setPollError(true);
            return false;
        },
    });
    const canRun =
        !!source &&
        !!current &&
        queueReady &&
        aiEnabled &&
        !pending &&
        reason.trim().length > 0;

    async function reevaluate() {
        if (!source || !current || !canRun) return;
        http.transform(() => ({
            source_type: source.source_type,
            source_id: source.source_id,
            source_digest: source.source_digest,
            expected_publication_digest: current.publication_digest,
            request_key: crypto.randomUUID(),
            reason,
        }));
        try {
            const response = await http.post(store.url());
            setLocal(response.data);
            setSelectedId(response.data.id);
            start();
            router.reload({ only: ['evaluations'] });
        } catch {
            /* Server validation remains inline. */
        }
    }

    // Determine specific review guidance for human staff
    const isNeedsReview =
        displayed?.state === 'needs_review' ||
        displayed?.result?.needs_review === true;
    const isUnreviewedCollection =
        source?.source_type === 'collection' &&
        source.evidence.reviewed_receipt === false;

    return (
        <>
            <Head title="Policy evaluations" />
            <main className="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 md:p-8">
                {/* Header */}
                <header className="flex flex-wrap items-center justify-between gap-4 border-b border-border/50 pb-5">
                    <div className="flex min-w-0 flex-col gap-1">
                        <div className="flex items-center gap-2 text-xs font-medium text-muted-foreground">
                            <span>{institution.name}</span>
                            <span>·</span>
                            <span>Financial Operations</span>
                        </div>
                        <h1 className="text-2xl font-semibold tracking-tight text-foreground">
                            Policy evaluations
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Fresh advice. Visible progress. Human review.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            asChild
                        >
                            <a href={adminUrl}>
                                <ArrowLeft
                                    aria-hidden="true"
                                    className="mr-1 h-3.5 w-3.5"
                                />
                                Back to panel
                            </a>
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            asChild
                        >
                            <Link href={policyUrl}>Institution policies</Link>
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            asChild
                        >
                            <a href={collectionsUrl}>Collections ledger</a>
                        </Button>
                    </div>
                </header>

                {/* Top Safety Banner */}
                <Alert
                    role="status"
                    className="bg-primary-50/40 border-primary/20 dark:bg-primary-950/20"
                >
                    <ShieldCheck
                        aria-hidden="true"
                        className="h-5 w-5 text-primary"
                    />
                    <AlertTitle className="text-sm font-semibold">
                        Advice, not payment authority
                    </AlertTitle>
                    <AlertDescription className="text-xs text-muted-foreground leading-relaxed">
                        Re-evaluation preserves prior results. It never changes
                        collected fees, student balances, approvals or
                        transfers. Progress shows processing stages and cited
                        conclusions, not private model reasoning.
                    </AlertDescription>
                </Alert>

                <div className="grid min-w-0 gap-6 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,2fr)]">
                    {/* Left Panel: Source Selection & Breakdown */}
                    <aside className="flex min-w-0 flex-col gap-6">
                        <Frame spacing="default" stacked>
                            <FrameHeader>
                                <FrameTitle>Source evidence</FrameTitle>
                                <FrameDescription>
                                    Choose recorded funds or planning context.
                                </FrameDescription>
                            </FrameHeader>
                            <FramePanel>
                                <div className="flex min-w-0 flex-col gap-4">
                                    <Label
                                        htmlFor="evaluation-source"
                                        className="text-xs font-medium"
                                    >
                                        Collection, transaction or budget
                                    </Label>
                                    {sources.length === 0 ? (
                                        <Alert role="status">
                                            <AlertTitle>
                                                No source evidence
                                            </AlertTitle>
                                            <AlertDescription>
                                                Record and review a collection
                                                first, then capture budget
                                                context for allocation.
                                            </AlertDescription>
                                        </Alert>
                                    ) : (
                                        <Select
                                            value={sourceKey}
                                            onValueChange={(value) => {
                                                setSourceKey(value);
                                                setSelectedId(null);
                                                setLocal(null);
                                            }}
                                        >
                                            <SelectTrigger
                                                id="evaluation-source"
                                                className="w-full text-left"
                                            >
                                                <SelectValue placeholder="Choose evidence" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {sources.map((item) => (
                                                    <SelectItem
                                                        key={`${item.source_type}:${item.source_id}`}
                                                        value={`${item.source_type}:${item.source_id}`}
                                                    >
                                                        {item.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    )}

                                    {/* Structured Evidence Card */}
                                    {source && (
                                        <div className="flex flex-col gap-3 rounded-lg border border-border/80 bg-muted/20 p-3.5">
                                            <div className="flex items-center justify-between gap-2">
                                                <Badge
                                                    variant="outline"
                                                    className="text-xs font-semibold capitalize"
                                                >
                                                    {source.source_type ===
                                                    'collection'
                                                        ? 'Collection Receipt'
                                                        : source.source_type ===
                                                            'budget_snapshot'
                                                          ? 'Budget Snapshot'
                                                          : 'Recorded Transaction'}
                                                </Badge>
                                                {source.evidence
                                                    .reviewed_receipt !==
                                                    undefined && (
                                                    <Badge
                                                        variant={
                                                            source.evidence
                                                                .reviewed_receipt
                                                                ? 'success-light'
                                                                : 'warning-light'
                                                        }
                                                        className="text-xs"
                                                    >
                                                        {source.evidence
                                                            .reviewed_receipt
                                                            ? '✓ Staff Approved'
                                                            : '⏳ Awaiting Review'}
                                                    </Badge>
                                                )}
                                            </div>

                                            {source.source_type ===
                                            'budget_snapshot' ? (
                                                <dl className="grid grid-cols-1 gap-2 text-xs">
                                                    {[
                                                        [
                                                            'Opening funds',
                                                            source.evidence
                                                                .opening_funds_minor_units,
                                                        ],
                                                        [
                                                            'Reviewed receipts',
                                                            source.evidence
                                                                .realized_receipts_minor_units,
                                                        ],
                                                        [
                                                            'Restricted cash',
                                                            source.evidence
                                                                .restricted_minor_units,
                                                        ],
                                                        [
                                                            'Captured reserve',
                                                            source.evidence
                                                                .protected_reserve_minor_units,
                                                        ],
                                                        [
                                                            'Captured cash available',
                                                            source.evidence
                                                                .captured_cash_headroom_minor_units,
                                                        ],
                                                    ].map(([label, amount]) => (
                                                        <div
                                                            key={label}
                                                            className="flex flex-wrap items-center justify-between gap-2"
                                                        >
                                                            <dt className="text-muted-foreground">
                                                                {label}
                                                            </dt>
                                                            <dd className="font-mono font-medium">
                                                                {formatUsdc(
                                                                    amount ??
                                                                        '0',
                                                                )}
                                                            </dd>
                                                        </div>
                                                    ))}
                                                    <div className="pt-1 text-[11px] text-muted-foreground">
                                                        Captured evidence, not a
                                                        live wallet balance.
                                                        Active policy may
                                                        protect additional
                                                        reserve.
                                                    </div>
                                                </dl>
                                            ) : (
                                                <div className="flex flex-col gap-2 text-xs">
                                                    {source.evidence
                                                        .received_minor_units && (
                                                        <div className="flex items-center justify-between">
                                                            <span className="text-muted-foreground">
                                                                Received Amount:
                                                            </span>
                                                            <span className="font-mono text-sm font-bold text-foreground">
                                                                {formatUsdc(
                                                                    source
                                                                        .evidence
                                                                        .received_minor_units,
                                                                )}
                                                            </span>
                                                        </div>
                                                    )}
                                                    {source.evidence
                                                        .source_stream && (
                                                        <div className="flex items-center justify-between">
                                                            <span className="text-muted-foreground">
                                                                Channel:
                                                            </span>
                                                            <span className="font-medium text-foreground">
                                                                {
                                                                    source
                                                                        .evidence
                                                                        .source_stream
                                                                }
                                                            </span>
                                                        </div>
                                                    )}
                                                    {source.evidence
                                                        .reference && (
                                                        <div className="flex flex-col gap-1 pt-1">
                                                            <span className="text-muted-foreground">
                                                                Reference Proof:
                                                            </span>
                                                            <span className="font-mono text-[11px] break-all bg-background/80 p-1.5 rounded border border-border/50">
                                                                {
                                                                    source
                                                                        .evidence
                                                                        .reference
                                                                }
                                                            </span>
                                                        </div>
                                                    )}
                                                </div>
                                            )}

                                            {/* Action to view source in ledger */}
                                            {source.source_type ===
                                                'collection' && (
                                                <div className="pt-2 border-t border-border/50 flex justify-end">
                                                    <a
                                                        href={collectionsUrl}
                                                        className="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline"
                                                    >
                                                        Open in Collections
                                                        ledger
                                                        <ExternalLink
                                                            aria-hidden="true"
                                                            className="h-3 w-3"
                                                        />
                                                    </a>
                                                </div>
                                            )}
                                            {source.source_type ===
                                                'budget_snapshot' && (
                                                <div className="pt-2 border-t border-border/50 flex justify-end">
                                                    <a
                                                        href={supervisorUrl}
                                                        className="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline"
                                                    >
                                                        Open in Finance
                                                        Supervisor
                                                        <ExternalLink
                                                            aria-hidden="true"
                                                            className="h-3 w-3"
                                                        />
                                                    </a>
                                                </div>
                                            )}
                                        </div>
                                    )}

                                    {source &&
                                        source.source_type !==
                                            'budget_snapshot' && (
                                            <p className="text-xs text-muted-foreground">
                                                Classification only. A recorded
                                                receipt or transaction cannot
                                                supply allocation amounts
                                                without reviewed budget context.
                                            </p>
                                        )}

                                    {/* Why re-evaluate */}
                                    <div className="flex flex-col gap-1.5 pt-1">
                                        <Label
                                            htmlFor="evaluation-reason"
                                            className="text-xs font-medium"
                                        >
                                            Why re-evaluate?
                                        </Label>
                                        <Textarea
                                            id="evaluation-reason"
                                            value={reason}
                                            onChange={(event) =>
                                                setReason(event.target.value)
                                            }
                                            maxLength={1000}
                                            rows={2}
                                            className="text-xs"
                                        />
                                    </div>

                                    {Object.values(http.errors)
                                        .flat()
                                        .map((error, index) => (
                                            <Alert
                                                key={index}
                                                variant="destructive"
                                            >
                                                <AlertTitle>
                                                    Request refused
                                                </AlertTitle>
                                                <AlertDescription>
                                                    {error}
                                                </AlertDescription>
                                            </Alert>
                                        ))}

                                    <Button
                                        type="button"
                                        size="sm"
                                        disabled={!canRun || http.processing}
                                        onClick={reevaluate}
                                        className="w-full"
                                    >
                                        <Sparkles
                                            aria-hidden="true"
                                            className="mr-1.5 h-4 w-4"
                                        />
                                        {http.processing
                                            ? 'Queueing…'
                                            : 'Re-evaluate'}
                                    </Button>

                                    {pending && (
                                        <p
                                            role="status"
                                            className="text-xs text-muted-foreground text-center animate-pulse"
                                        >
                                            Run in progress. Wait before
                                            requesting another evaluation.
                                        </p>
                                    )}
                                </div>
                            </FramePanel>
                        </Frame>

                        {/* Policy in force summary */}
                        <Frame spacing="default" stacked>
                            <FrameHeader>
                                <FrameTitle>Policy in force</FrameTitle>
                            </FrameHeader>
                            <FramePanel>
                                <div className="flex flex-col gap-2 text-xs">
                                    <p className="font-semibold text-foreground">
                                        {current?.title ??
                                            'No active published policy'}
                                    </p>
                                    {current && (
                                        <div className="flex items-center gap-2 text-muted-foreground">
                                            <Badge
                                                variant="outline"
                                                className="text-[11px]"
                                            >
                                                Revision #
                                                {current.policy_version_id}
                                            </Badge>
                                            <span
                                                className="font-mono text-[11px] truncate max-w-[140px]"
                                                title={
                                                    current.publication_digest
                                                }
                                            >
                                                {current.publication_digest.slice(
                                                    0,
                                                    10,
                                                )}
                                                …
                                            </span>
                                        </div>
                                    )}
                                    <div className="pt-2">
                                        <Link
                                            href={policyUrl}
                                            className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                                        >
                                            Inspect policy rules & clauses
                                            <ArrowRight
                                                aria-hidden="true"
                                                className="h-3 w-3"
                                            />
                                        </Link>
                                    </div>
                                </div>
                            </FramePanel>
                        </Frame>
                    </aside>

                    {/* Right Panel: Human Decision Guide, Timeline & Results */}
                    <div className="flex min-w-0 flex-col gap-6">
                        {/* 1. Clear Human Decision Box when review is needed */}
                        {isNeedsReview && (
                            <div className="rounded-xl border border-amber-500/40 bg-amber-50/50 p-5 shadow-sm dark:bg-amber-950/20">
                                <div className="flex items-start gap-3.5">
                                    <div className="rounded-lg bg-amber-100 p-2 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400">
                                        <AlertTriangle
                                            aria-hidden="true"
                                            className="h-5 w-5"
                                        />
                                    </div>
                                    <div className="flex min-w-0 flex-col gap-2">
                                        <div>
                                            <h3 className="text-base font-semibold text-amber-950 dark:text-amber-200">
                                                Human Review & Action Required
                                            </h3>
                                            <p className="text-xs text-amber-900/80 dark:text-amber-300/80 leading-relaxed">
                                                The AI agent did not find an
                                                unambiguous policy match above
                                                the required 80% confidence
                                                threshold (fail-closed safety).
                                                Zero funds were moved. Follow
                                                the guidance below to take
                                                action.
                                            </p>
                                        </div>

                                        <div className="grid grid-cols-1 gap-3 pt-2 sm:grid-cols-2">
                                            {isUnreviewedCollection ? (
                                                <div className="rounded-lg border border-amber-200 bg-white/70 p-3.5 dark:border-amber-800/40 dark:bg-white/5 flex flex-col justify-between gap-2">
                                                    <div>
                                                        <span className="text-xs font-semibold text-foreground">
                                                            Step 1: Review
                                                            Receipt in
                                                            Collections
                                                        </span>
                                                        <p className="text-xs text-muted-foreground mt-0.5">
                                                            This receipt has not
                                                            been approved by
                                                            staff yet. A
                                                            separate officer
                                                            must check the proof
                                                            of funds.
                                                        </p>
                                                    </div>
                                                    <Button
                                                        asChild
                                                        size="sm"
                                                        variant="default"
                                                        className="w-full"
                                                    >
                                                        <a
                                                            href={
                                                                collectionsUrl
                                                            }
                                                        >
                                                            Open Collections
                                                            Ledger
                                                            <ExternalLink
                                                                aria-hidden="true"
                                                                className="ml-1.5 h-3.5 w-3.5"
                                                            />
                                                        </a>
                                                    </Button>
                                                </div>
                                            ) : (
                                                <div className="rounded-lg border border-amber-200 bg-white/70 p-3.5 dark:border-amber-800/40 dark:bg-white/5 flex flex-col justify-between gap-2">
                                                    <div>
                                                        <span className="text-xs font-semibold text-foreground">
                                                            Step 1: Plan in
                                                            Finance Supervisor
                                                        </span>
                                                        <p className="text-xs text-muted-foreground mt-0.5">
                                                            You can manually
                                                            bind this receipt to
                                                            a department budget
                                                            snapshot to allocate
                                                            it toward
                                                            institution bills.
                                                        </p>
                                                    </div>
                                                    <Button
                                                        asChild
                                                        size="sm"
                                                        variant="default"
                                                        className="w-full"
                                                    >
                                                        <a href={supervisorUrl}>
                                                            Open Finance
                                                            Supervisor
                                                            <ExternalLink
                                                                aria-hidden="true"
                                                                className="ml-1.5 h-3.5 w-3.5"
                                                            />
                                                        </a>
                                                    </Button>
                                                </div>
                                            )}

                                            <div className="rounded-lg border border-amber-200 bg-white/70 p-3.5 dark:border-amber-800/40 dark:bg-white/5 flex flex-col justify-between gap-2">
                                                <div>
                                                    <span className="text-xs font-semibold text-foreground">
                                                        Step 2: Refine
                                                        Institution Policy
                                                    </span>
                                                    <p className="text-xs text-muted-foreground mt-0.5">
                                                        If this fee category
                                                        should be recognized
                                                        automatically by the AI
                                                        agent, add or refine its
                                                        category clause.
                                                    </p>
                                                </div>
                                                <Button
                                                    asChild
                                                    size="sm"
                                                    variant="outline"
                                                    className="w-full"
                                                >
                                                    <Link href={policyUrl}>
                                                        Edit Institution Policy
                                                        <ArrowRight
                                                            aria-hidden="true"
                                                            className="ml-1.5 h-3.5 w-3.5"
                                                        />
                                                    </Link>
                                                </Button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* 2. Success Banner when Category Matched Confidently */}
                        {displayed?.state === 'completed' &&
                            displayed?.result?.category_id && (
                                <div className="rounded-xl border border-emerald-500/30 bg-emerald-50/50 p-5 shadow-sm dark:bg-emerald-950/20">
                                    <div className="flex items-start gap-3.5">
                                        <div className="rounded-lg bg-emerald-100 p-2 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400">
                                            <CheckCircle2
                                                aria-hidden="true"
                                                className="h-5 w-5"
                                            />
                                        </div>
                                        <div className="flex min-w-0 flex-col gap-2">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <h3 className="text-base font-semibold text-emerald-950 dark:text-emerald-200">
                                                    AI Matched Policy Category:{' '}
                                                    {
                                                        displayed.result
                                                            .category_id
                                                    }
                                                </h3>
                                                <Badge
                                                    variant="success-light"
                                                    className="text-xs"
                                                >
                                                    Confidence &gt; 80%
                                                </Badge>
                                            </div>
                                            <p className="text-xs text-emerald-900/80 dark:text-emerald-300/80 leading-relaxed">
                                                {displayed.result.explanation}
                                            </p>
                                            <div className="pt-2 flex flex-wrap gap-2">
                                                <Button
                                                    asChild
                                                    size="sm"
                                                    variant="default"
                                                >
                                                    <a href={supervisorUrl}>
                                                        Allocate in Finance
                                                        Supervisor
                                                        <ArrowRight
                                                            aria-hidden="true"
                                                            className="ml-1.5 h-3.5 w-3.5"
                                                        />
                                                    </a>
                                                </Button>
                                                <Button
                                                    asChild
                                                    size="sm"
                                                    variant="outline"
                                                >
                                                    <a href={collectionsUrl}>
                                                        View in Collections
                                                        <ExternalLink
                                                            aria-hidden="true"
                                                            className="ml-1.5 h-3.5 w-3.5"
                                                        />
                                                    </a>
                                                </Button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            )}

                        {/* Agent Progress Frame */}
                        <Frame
                            spacing="default"
                            stacked
                            aria-label="Evaluation progress"
                        >
                            <FrameHeader>
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div className="flex flex-col gap-1">
                                        <FrameTitle>Agent progress</FrameTitle>
                                        <FrameDescription>
                                            Persisted stages refresh every two
                                            seconds.
                                        </FrameDescription>
                                    </div>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={polling ? stop : start}
                                    >
                                        {polling
                                            ? 'Pause updates'
                                            : 'Resume updates'}
                                    </Button>
                                </div>
                            </FrameHeader>
                            <FramePanel>
                                <div className="flex min-w-0 flex-col gap-4">
                                    {pollError && (
                                        <Alert variant="warning">
                                            <AlertTitle>
                                                Progress refresh failed
                                            </AlertTitle>
                                            <AlertDescription>
                                                Last saved progress remains
                                                visible.
                                            </AlertDescription>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() => router.reload()}
                                            >
                                                <RefreshCw
                                                    aria-hidden="true"
                                                    className="mr-1 h-3.5 w-3.5"
                                                />
                                                Retry
                                            </Button>
                                        </Alert>
                                    )}

                                    {!displayed ? (
                                        <div className="py-8 text-center text-sm text-muted-foreground flex flex-col items-center gap-2">
                                            <HelpCircle
                                                aria-hidden="true"
                                                className="h-8 w-8 text-muted-foreground/50"
                                            />
                                            <p>
                                                No evaluation yet for this
                                                record. Choose evidence on the
                                                left and click Re-evaluate.
                                            </p>
                                        </div>
                                    ) : (
                                        <>
                                            <div
                                                className="flex flex-wrap items-center gap-2"
                                                role="status"
                                                aria-live="polite"
                                            >
                                                <Badge
                                                    variant={
                                                        displayed.state ===
                                                        'needs_review'
                                                            ? 'warning-light'
                                                            : displayed.state ===
                                                                'completed'
                                                              ? 'success-light'
                                                              : 'primary-light'
                                                    }
                                                >
                                                    {statusLabel(
                                                        displayed.state,
                                                    )}
                                                </Badge>
                                                <span className="text-xs text-muted-foreground">
                                                    Run #{displayed.id} · Policy
                                                    #
                                                    {
                                                        displayed.institution_policy_version_id
                                                    }
                                                </span>
                                                {displayed.provider && (
                                                    <span className="text-xs text-muted-foreground font-mono">
                                                        via {displayed.provider}{' '}
                                                        (
                                                        {displayed.model ??
                                                            'default'}
                                                        )
                                                    </span>
                                                )}
                                                {displayed.total_targets >
                                                    0 && (
                                                    <span className="text-xs tabular-nums text-muted-foreground">
                                                        {
                                                            displayed.completed_targets
                                                        }{' '}
                                                        of{' '}
                                                        {
                                                            displayed.total_targets
                                                        }{' '}
                                                        targets checked
                                                    </span>
                                                )}
                                            </div>

                                            {current &&
                                                current.publication_digest !==
                                                    displayed.publication_digest && (
                                                    <Alert variant="warning">
                                                        <AlertTitle>
                                                            Older policy result
                                                        </AlertTitle>
                                                        <AlertDescription>
                                                            Policy changed.
                                                            Request a new run
                                                            against current
                                                            evidence.
                                                        </AlertDescription>
                                                    </Alert>
                                                )}

                                            {source &&
                                                source.source_digest !==
                                                    displayed.source_digest && (
                                                    <Alert variant="warning">
                                                        <AlertTitle>
                                                            Source changed
                                                        </AlertTitle>
                                                        <AlertDescription>
                                                            This run used
                                                            earlier evidence.
                                                            Refresh sources
                                                            before requesting
                                                            new advice.
                                                        </AlertDescription>
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                router.reload()
                                                            }
                                                            className="mt-2"
                                                        >
                                                            Refresh sources
                                                        </Button>
                                                    </Alert>
                                                )}

                                            {/* Timeline */}
                                            <div
                                                className="flex min-h-0 flex-col gap-3 pt-1"
                                                tabIndex={0}
                                                aria-label="Processing timeline"
                                            >
                                                <Timeline
                                                    value={
                                                        displayed.timeline
                                                            .length
                                                    }
                                                >
                                                    {displayed.timeline
                                                        .slice(-4)
                                                        .map((event, index) => (
                                                            <TimelineItem
                                                                key={index}
                                                                step={index + 1}
                                                            >
                                                                <TimelineHeader>
                                                                    <TimelineDate
                                                                        dateTime={
                                                                            event.at
                                                                        }
                                                                    >
                                                                        {new Date(
                                                                            event.at,
                                                                        ).toLocaleTimeString()}
                                                                    </TimelineDate>
                                                                    <TimelineTitle>
                                                                        {event.stage.replaceAll(
                                                                            '_',
                                                                            ' ',
                                                                        )}
                                                                    </TimelineTitle>
                                                                </TimelineHeader>
                                                                <TimelineIndicator />
                                                                <TimelineSeparator />
                                                                <TimelineContent>
                                                                    {
                                                                        event.message
                                                                    }
                                                                </TimelineContent>
                                                            </TimelineItem>
                                                        ))}
                                                </Timeline>

                                                {displayed.timeline.length >
                                                    4 && (
                                                    <details className="text-xs">
                                                        <summary className="cursor-pointer text-muted-foreground hover:underline">
                                                            Earlier stages (
                                                            {displayed.timeline
                                                                .length - 4}
                                                            )
                                                        </summary>
                                                        <ul className="flex flex-col gap-2 py-2.5">
                                                            {displayed.timeline
                                                                .slice(0, -4)
                                                                .map(
                                                                    (
                                                                        event,
                                                                        index,
                                                                    ) => (
                                                                        <li
                                                                            key={
                                                                                index
                                                                            }
                                                                            className="flex flex-col gap-0.5 text-muted-foreground"
                                                                        >
                                                                            <span className="text-[10px] font-mono">
                                                                                {new Date(
                                                                                    event.at,
                                                                                ).toLocaleTimeString()}
                                                                            </span>
                                                                            <span className="text-foreground/80">
                                                                                {
                                                                                    event.message
                                                                                }
                                                                            </span>
                                                                        </li>
                                                                    ),
                                                                )}
                                                        </ul>
                                                    </details>
                                                )}
                                            </div>

                                            {['queued', 'running'].includes(
                                                displayed.state,
                                            ) && (
                                                <div
                                                    className="flex flex-col gap-2 pt-2"
                                                    aria-label="Result pending"
                                                >
                                                    <Skeleton className="h-4 w-2/3" />
                                                    <Skeleton className="h-16 w-full" />
                                                    <p className="text-xs text-muted-foreground">
                                                        {displayed.state ===
                                                        'queued'
                                                            ? 'Waiting for default queue worker. No agent processing has started.'
                                                            : 'Background processing underway. Result appears after safety checks.'}
                                                    </p>
                                                </div>
                                            )}

                                            {displayed.last_error && (
                                                <Alert variant="warning">
                                                    <AlertTitle>
                                                        Needs review
                                                    </AlertTitle>
                                                    <AlertDescription>
                                                        {displayed.last_error}
                                                    </AlertDescription>
                                                    <div className="pt-2">
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="outline"
                                                            disabled={
                                                                !canRun ||
                                                                http.processing
                                                            }
                                                            onClick={reevaluate}
                                                        >
                                                            <RefreshCw
                                                                aria-hidden="true"
                                                                className="mr-1.5 h-3.5 w-3.5"
                                                            />
                                                            Retry as new run
                                                        </Button>
                                                    </div>
                                                </Alert>
                                            )}
                                        </>
                                    )}
                                </div>
                            </FramePanel>
                        </Frame>

                        {/* Advisory Result Frame */}
                        {displayed?.result && (
                            <Frame spacing="default" stacked>
                                <FrameHeader>
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div>
                                            <FrameTitle>
                                                Advisory result
                                            </FrameTitle>
                                            <FrameDescription>
                                                {displayed.label}
                                            </FrameDescription>
                                        </div>
                                        <Badge
                                            variant={
                                                displayed.result.category_id
                                                    ? 'success-light'
                                                    : 'warning-light'
                                            }
                                        >
                                            {displayed.result.category_id
                                                ? `Category: ${displayed.result.category_id}`
                                                : 'Requires human clarification'}
                                        </Badge>
                                    </div>
                                </FrameHeader>
                                <FramePanel>
                                    <div className="flex min-w-0 flex-col gap-4">
                                        {displayed.result.explanation && (
                                            <p className="break-words text-sm leading-relaxed text-foreground/90">
                                                {displayed.result.explanation}
                                            </p>
                                        )}

                                        {/* JEV Probability Scoring Breakdown */}
                                        {displayed.result.probabilities &&
                                            Object.keys(
                                                displayed.result.probabilities,
                                            ).length > 0 && (
                                                <div className="flex flex-col gap-2.5 rounded-lg border border-border/70 bg-muted/20 p-3.5">
                                                    <div className="flex items-center justify-between">
                                                        <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                                            JEV Category
                                                            Confidence Scoring
                                                        </span>
                                                        <span className="rounded bg-background px-2 py-0.5 font-mono text-[11px] text-muted-foreground border border-border/50">
                                                            Safety Threshold:
                                                            80%
                                                        </span>
                                                    </div>
                                                    <div className="flex flex-col gap-2 pt-1">
                                                        {Object.entries(
                                                            displayed.result
                                                                .probabilities,
                                                        )
                                                            .filter(
                                                                ([_, score]) =>
                                                                    (score as number) >
                                                                    0.005,
                                                            )
                                                            .map(
                                                                ([
                                                                    cat,
                                                                    score,
                                                                ]) => {
                                                                    const pct =
                                                                        Math.round(
                                                                            (score as number) *
                                                                                100,
                                                                        );
                                                                    const isSelected =
                                                                        cat ===
                                                                        displayed
                                                                            .result
                                                                            ?.category_id;
                                                                    const isHigh =
                                                                        pct >=
                                                                        80;
                                                                    return (
                                                                        <div
                                                                            key={
                                                                                cat
                                                                            }
                                                                            className="flex flex-col gap-1"
                                                                        >
                                                                            <div className="flex items-center justify-between text-xs">
                                                                                <span
                                                                                    className={`font-mono ${
                                                                                        isSelected
                                                                                            ? 'font-bold text-foreground'
                                                                                            : 'text-muted-foreground'
                                                                                    }`}
                                                                                >
                                                                                    {
                                                                                        cat
                                                                                    }
                                                                                </span>
                                                                                <span className="font-mono text-xs tabular-nums font-semibold">
                                                                                    {
                                                                                        pct
                                                                                    }
                                                                                    %
                                                                                </span>
                                                                            </div>
                                                                            <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                                                                                <div
                                                                                    className={`h-full rounded-full transition-all duration-500 ${
                                                                                        isHigh
                                                                                            ? 'bg-emerald-500'
                                                                                            : isSelected
                                                                                              ? 'bg-primary'
                                                                                              : 'bg-amber-400'
                                                                                    }`}
                                                                                    style={{
                                                                                        width: `${Math.max(pct, 2)}%`,
                                                                                    }}
                                                                                />
                                                                            </div>
                                                                        </div>
                                                                    );
                                                                },
                                                            )}
                                                    </div>
                                                </div>
                                            )}

                                        {/* JEV Decision Process Steps */}
                                        {displayed.result.decision_process &&
                                            displayed.result.decision_process
                                                .length > 0 && (
                                                <div className="flex flex-col gap-2 rounded-lg border border-border/60 bg-muted/20 p-3.5">
                                                    <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                                        Decision Audit Process
                                                    </span>
                                                    <ol className="relative flex flex-col gap-2 pt-1">
                                                        {displayed.result.decision_process.map(
                                                            (step, idx) => (
                                                                <li
                                                                    key={idx}
                                                                    className="flex items-start gap-2.5 text-xs"
                                                                >
                                                                    <span className="flex size-4 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[10px] font-bold text-primary mt-0.5">
                                                                        {idx +
                                                                            1}
                                                                    </span>
                                                                    <span className="text-foreground/90 leading-relaxed">
                                                                        {step}
                                                                    </span>
                                                                </li>
                                                            ),
                                                        )}
                                                    </ol>
                                                </div>
                                            )}

                                        <Citations
                                            items={
                                                displayed.result.citations ?? []
                                            }
                                        />

                                        {[
                                            ...(displayed.result.questions ??
                                                []),
                                            ...(displayed.result
                                                .missing_evidence ?? []),
                                        ].map((message, index) => (
                                            <p
                                                key={index}
                                                className="text-sm font-medium text-amber-700 dark:text-amber-400"
                                            >
                                                Needs review: {message}
                                            </p>
                                        ))}

                                        {displayed.result.next_step && (
                                            <div className="rounded-lg bg-muted/40 p-3 text-xs text-muted-foreground flex items-start gap-2">
                                                <Info
                                                    aria-hidden="true"
                                                    className="h-4 w-4 shrink-0 text-primary mt-0.5"
                                                />
                                                <span>
                                                    {displayed.result.next_step}
                                                </span>
                                            </div>
                                        )}

                                        {displayed.result
                                            .proposed_minor_units !==
                                            undefined && (
                                            <div className="flex flex-wrap gap-6 text-sm border-t border-border/50 pt-3">
                                                <p>
                                                    Proposed:{' '}
                                                    <strong className="tabular-nums font-mono">
                                                        {formatUsdc(
                                                            displayed.result
                                                                .proposed_minor_units,
                                                        )}
                                                    </strong>
                                                </p>
                                                <p>
                                                    Unallocated:{' '}
                                                    <strong className="tabular-nums font-mono">
                                                        {formatUsdc(
                                                            displayed.result
                                                                .unallocated_minor_units ??
                                                                '0',
                                                        )}
                                                    </strong>
                                                </p>
                                            </div>
                                        )}
                                    </div>
                                </FramePanel>

                                {/* Proposed allocation lines if budget plan */}
                                {displayed.result.lines?.map((line) => (
                                    <FramePanel
                                        key={`${line.target_type}:${line.target_id}`}
                                    >
                                        <div className="flex min-w-0 flex-col gap-3">
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <h3 className="text-sm font-medium">
                                                    {line.target_name}
                                                </h3>
                                                <Badge
                                                    variant={
                                                        line.decision === 'hold'
                                                            ? 'warning-light'
                                                            : 'success-light'
                                                    }
                                                >
                                                    {line.decision === 'hold'
                                                        ? 'Hold'
                                                        : 'Proposed for review'}
                                                </Badge>
                                            </div>
                                            <p className="text-sm tabular-nums">
                                                {formatUsdc(
                                                    line.proposed_minor_units,
                                                )}{' '}
                                                ·{' '}
                                                {line.category_id ??
                                                    'Needs review'}
                                            </p>
                                            {line.explanation && (
                                                <p className="text-xs text-muted-foreground">
                                                    {line.explanation}
                                                </p>
                                            )}
                                            <Citations items={line.citations} />
                                        </div>
                                    </FramePanel>
                                ))}
                            </Frame>
                        )}

                        {/* Run History Frame */}
                        {matching.length > 0 && (
                            <Frame spacing="default" stacked>
                                <FrameHeader>
                                    <FrameTitle>Run history</FrameTitle>
                                    <FrameDescription>
                                        Previous advice stays unchanged.
                                    </FrameDescription>
                                </FrameHeader>
                                <FramePanel>
                                    <ul className="flex flex-col divide-y divide-border/50">
                                        {matching.map((item) => (
                                            <li
                                                key={item.id}
                                                className={`flex flex-col gap-1 py-3 transition hover:bg-muted/10 ${
                                                    item.id === displayed?.id
                                                        ? 'bg-muted/20 font-medium'
                                                        : ''
                                                }`}
                                            >
                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                    <span className="text-xs">
                                                        Run #{item.id} ·{' '}
                                                        {statusLabel(
                                                            item.state,
                                                        )}
                                                    </span>
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="ghost"
                                                        className="h-7 text-xs"
                                                        onClick={() => {
                                                            setSelectedId(
                                                                item.id,
                                                            );
                                                            setLocal(item);
                                                        }}
                                                    >
                                                        View run #{item.id}
                                                    </Button>
                                                </div>
                                                <p className="text-xs text-muted-foreground">
                                                    {item.reason}
                                                </p>
                                            </li>
                                        ))}
                                    </ul>
                                </FramePanel>
                            </Frame>
                        )}
                    </div>
                </div>
            </main>
        </>
    );
}
