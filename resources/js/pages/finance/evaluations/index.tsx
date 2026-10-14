import { Head, Link, router, useHttp, usePoll } from '@inertiajs/react';
import { ArrowLeft, RefreshCw, ShieldCheck, Sparkles } from 'lucide-react';
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
        received_minor_units?: string;
        restricted_minor_units?: string;
        realized_receipts_minor_units?: string;
        opening_funds_minor_units?: string;
        protected_reserve_minor_units?: string;
        captured_cash_headroom_minor_units?: string;
        recorded_amount?: string;
        reviewed_receipt?: boolean;
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
    return items.map((item, index) => (
        <blockquote key={index} className="flex min-w-0 flex-col gap-1 text-sm">
            <span className="text-xs font-medium text-muted-foreground">
                {item.clause_id}
            </span>
            <p className="break-words">“{item.quote}”</p>
        </blockquote>
    ));
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

    return (
        <>
            <Head title="Policy evaluations" />
            <main className="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 md:p-8">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div className="flex min-w-0 flex-col gap-1">
                        <p className="text-xs text-muted-foreground">
                            {institution.name}
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Policy evaluations
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Fresh advice. Visible progress. Human review.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            asChild
                        >
                            <a href={adminUrl}>
                                <ArrowLeft aria-hidden="true" />
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
                    </div>
                </header>
                <Alert role="status">
                    <ShieldCheck aria-hidden="true" />
                    <AlertTitle>Advice, not payment authority</AlertTitle>
                    <AlertDescription>
                        Re-evaluation preserves prior results. It never changes
                        collected fees, student balances, approvals or
                        transfers. Progress shows processing stages and cited
                        conclusions, not private model reasoning.
                    </AlertDescription>
                </Alert>
                <div className="grid min-w-0 gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
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
                                    <Label htmlFor="evaluation-source">
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
                                                className="w-full"
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
                                    {source?.source_type ===
                                    'budget_snapshot' ? (
                                        <dl className="grid grid-cols-1 gap-3 text-sm">
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
                                                    <dd className="tabular-nums">
                                                        {formatUsdc(
                                                            amount ?? '0',
                                                        )}
                                                    </dd>
                                                </div>
                                            ))}
                                            <div className="text-xs text-muted-foreground">
                                                Captured evidence, not a live
                                                wallet balance. Active policy
                                                may protect additional reserve.
                                            </div>
                                        </dl>
                                    ) : (
                                        source && (
                                            <p className="break-words text-sm">
                                                {source.evidence.description}
                                            </p>
                                        )
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
                                    <Label htmlFor="evaluation-reason">
                                        Why re-evaluate?
                                    </Label>
                                    <Textarea
                                        id="evaluation-reason"
                                        value={reason}
                                        onChange={(event) =>
                                            setReason(event.target.value)
                                        }
                                        maxLength={1000}
                                        rows={3}
                                    />
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
                                    >
                                        <Sparkles aria-hidden="true" />
                                        {http.processing
                                            ? 'Queueing…'
                                            : 'Re-evaluate'}
                                    </Button>
                                    {pending && (
                                        <p
                                            role="status"
                                            className="text-xs text-muted-foreground"
                                        >
                                            Run in progress. Wait before
                                            requesting another evaluation.
                                        </p>
                                    )}
                                </div>
                            </FramePanel>
                        </Frame>
                        <Frame spacing="default" stacked>
                            <FrameHeader>
                                <FrameTitle>Policy in force</FrameTitle>
                            </FrameHeader>
                            <FramePanel>
                                <div className="flex flex-col gap-3">
                                    {current ? (
                                        <>
                                            <p className="text-sm">
                                                {current.title}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                Revision #
                                                {current.policy_version_id}
                                            </p>
                                        </>
                                    ) : (
                                        <Alert variant="warning">
                                            <AlertTitle>
                                                No active published policy
                                            </AlertTitle>
                                            <AlertDescription>
                                                Test a draft and have a
                                                different authorized reviewer
                                                publish it before re-evaluating.
                                            </AlertDescription>
                                        </Alert>
                                    )}
                                    {!queueReady && (
                                        <Alert variant="warning">
                                            <AlertTitle>
                                                Queue not ready
                                            </AlertTitle>
                                            <AlertDescription>
                                                Use a durable queue with
                                                retry_after greater than 180
                                                seconds.
                                            </AlertDescription>
                                        </Alert>
                                    )}
                                    {!aiEnabled && (
                                        <Alert variant="warning">
                                            <AlertTitle>
                                                AI advisory disabled
                                            </AlertTitle>
                                            <AlertDescription>
                                                Enable advisory and accept
                                                provider disclosure in AI
                                                settings before running.
                                            </AlertDescription>
                                        </Alert>
                                    )}
                                </div>
                            </FramePanel>
                        </Frame>
                    </aside>
                    <section
                        className="flex min-w-0 flex-col gap-6"
                        aria-label="Evaluation progress"
                    >
                        <Frame spacing="default" stacked>
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
                                                <RefreshCw aria-hidden="true" />
                                                Retry
                                            </Button>
                                        </Alert>
                                    )}
                                    {!displayed ? (
                                        <p className="text-sm text-muted-foreground">
                                            No evaluation yet. Choose evidence
                                            and request a fresh run.
                                        </p>
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
                                                {displayed.total_targets >
                                                    0 && (
                                                    <span className="text-xs tabular-nums">
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
                                                        >
                                                            Refresh sources
                                                        </Button>
                                                    </Alert>
                                                )}
                                            <div
                                                className="flex min-h-0 flex-col gap-3"
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
                                                    <details className="text-sm">
                                                        <summary className="cursor-pointer text-muted-foreground">
                                                            Earlier stages (
                                                            {displayed.timeline
                                                                .length - 4}
                                                            )
                                                        </summary>
                                                        <ul className="flex flex-col gap-3 py-3">
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
                                                                            className="flex flex-col gap-1"
                                                                        >
                                                                            <span className="text-xs text-muted-foreground">
                                                                                {new Date(
                                                                                    event.at,
                                                                                ).toLocaleTimeString()}
                                                                            </span>
                                                                            <span>
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
                                                    className="flex flex-col gap-2"
                                                    aria-label="Result pending"
                                                >
                                                    <Skeleton className="h-4 w-2/3" />
                                                    <Skeleton className="h-16 w-full" />
                                                    <p className="text-xs text-muted-foreground">
                                                        {displayed.state ===
                                                        'queued'
                                                            ? 'Waiting for policy-assessments worker. No agent processing has started.'
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
                                                        Retry as new run
                                                    </Button>
                                                </Alert>
                                            )}
                                        </>
                                    )}
                                </div>
                            </FramePanel>
                        </Frame>
                        {displayed?.result && (
                            <Frame spacing="default" stacked>
                                <FrameHeader>
                                    <FrameTitle>Advisory result</FrameTitle>
                                    <FrameDescription>
                                        {displayed.label}
                                    </FrameDescription>
                                </FrameHeader>
                                <FramePanel>
                                    <div className="flex min-w-0 flex-col gap-4">
                                        {displayed.result.category_id && (
                                            <p className="text-sm font-medium">
                                                Category:{' '}
                                                {displayed.result.category_id}
                                            </p>
                                        )}
                                        {displayed.result.explanation && (
                                            <p className="break-words text-sm">
                                                {displayed.result.explanation}
                                            </p>
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
                                            <p key={index} className="text-sm">
                                                Needs review: {message}
                                            </p>
                                        ))}
                                        {displayed.result.next_step && (
                                            <p className="text-sm text-muted-foreground">
                                                {displayed.result.next_step}
                                            </p>
                                        )}
                                        {displayed.result
                                            .proposed_minor_units !==
                                            undefined && (
                                            <div className="flex flex-wrap gap-6 text-sm">
                                                <p>
                                                    Proposed:{' '}
                                                    <strong className="tabular-nums">
                                                        {formatUsdc(
                                                            displayed.result
                                                                .proposed_minor_units,
                                                        )}
                                                    </strong>
                                                </p>
                                                <p>
                                                    Unallocated:{' '}
                                                    <strong className="tabular-nums">
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
                                                <p className="break-words text-sm">
                                                    {line.explanation}
                                                </p>
                                            )}
                                            <Citations items={line.citations} />
                                        </div>
                                    </FramePanel>
                                ))}
                            </Frame>
                        )}
                        {matching.length > 0 && (
                            <Frame spacing="default" stacked>
                                <FrameHeader>
                                    <FrameTitle>Run history</FrameTitle>
                                    <FrameDescription>
                                        Previous advice stays unchanged.
                                    </FrameDescription>
                                </FrameHeader>
                                {matching.map((item) => (
                                    <FramePanel key={item.id}>
                                        <div className="flex flex-wrap items-center justify-between gap-3">
                                            <div className="flex min-w-0 flex-col gap-1">
                                                <p className="text-sm">
                                                    Run #{item.id} ·{' '}
                                                    {statusLabel(item.state)}
                                                </p>
                                                <p className="break-words text-xs text-muted-foreground">
                                                    {item.reason}
                                                </p>
                                            </div>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    setSelectedId(item.id)
                                                }
                                                aria-current={
                                                    displayed?.id === item.id
                                                        ? 'true'
                                                        : undefined
                                                }
                                            >
                                                View run #{item.id}
                                            </Button>
                                        </div>
                                    </FramePanel>
                                ))}
                            </Frame>
                        )}
                    </section>
                </div>
            </main>
        </>
    );
}
