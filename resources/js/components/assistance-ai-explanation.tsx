import {
    CheckCircle2,
    Clock,
    ExternalLink,
    HelpCircle,
    Lock,
    Shield,
    Sparkles,
    UserCheck,
    XCircle,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { AiExplanation } from '@/types/financial-aid';

interface AssistanceAiExplanationProps {
    explanation: AiExplanation;
    className?: string;
}

export function AssistanceAiExplanation({
    explanation,
    className,
}: AssistanceAiExplanationProps) {
    const isPartial = explanation.decision === 'partial_approval';
    const isApproved = explanation.decision === 'auto_approve';
    const isHeld = explanation.decision === 'hold';

    return (
        <Card
            className={`overflow-hidden border-2 shadow-sm ${
                isApproved
                    ? 'border-emerald-500/30 bg-emerald-50/15 dark:border-emerald-800/40 dark:bg-emerald-950/10'
                    : isPartial
                      ? 'border-amber-500/30 bg-amber-50/15 dark:border-amber-800/40 dark:bg-amber-950/10'
                      : isHeld
                        ? 'border-orange-500/30 bg-orange-50/15 dark:border-orange-800/40 dark:bg-orange-950/10'
                        : 'border-muted bg-card'
            } ${className ?? ''}`}
        >
            <CardHeader className="gap-2 border-b bg-muted/20 pb-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex items-center gap-2">
                        <div
                            className={`flex size-8 items-center justify-center rounded-lg ${
                                isApproved
                                    ? 'bg-emerald-500/20 text-emerald-700 dark:text-emerald-300'
                                    : 'bg-amber-500/20 text-amber-700 dark:text-amber-300'
                            }`}
                        >
                            <Sparkles className="size-4" />
                        </div>
                        <div>
                            <CardTitle className="text-base font-semibold">
                                EduFlow AI Reasoning & Policy Evaluation
                            </CardTitle>
                            <CardDescription className="text-xs">
                                Deterministic evaluation under Policy{' '}
                                {explanation.policy_code}
                            </CardDescription>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge
                            className={`font-semibold uppercase tracking-wider text-[11px] ${
                                isApproved
                                    ? 'bg-emerald-600 text-white dark:bg-emerald-500'
                                    : isPartial
                                      ? 'bg-amber-600 text-white dark:bg-amber-500'
                                      : 'bg-muted text-muted-foreground'
                            }`}
                        >
                            {explanation.decision_label}
                        </Badge>
                        <Badge variant="outline" className="text-xs">
                            <Lock className="mr-1 size-3" />
                            Locked FX Quote
                        </Badge>
                    </div>
                </div>
            </CardHeader>

            <CardContent className="space-y-6 pt-6">
                {/* Plain language dual-currency explanation banner */}
                <div className="rounded-xl border border-amber-300/60 bg-amber-100/40 p-4 text-xs leading-relaxed text-amber-950 sm:text-sm dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-100">
                    <p className="font-serif font-medium text-base text-foreground mb-1">
                        Executive AI Explanation
                    </p>
                    <p>{explanation.explanation}</p>
                </div>

                {/* Hardship context synthesis */}
                {explanation.hardship_synthesis && (
                    <div className="rounded-lg border bg-background/80 p-3.5 text-xs text-muted-foreground">
                        <div className="flex items-center gap-1.5 font-medium text-foreground mb-1">
                            <HelpCircle className="size-3.5 text-amber-600" />
                            <span>Hardship Context Analysis</span>
                            <Badge
                                variant="secondary"
                                className="ml-2 text-[10px]"
                            >
                                {explanation.hardship_category}
                            </Badge>
                            <Badge
                                variant="outline"
                                className="text-[10px] uppercase font-mono"
                            >
                                Urgency: {explanation.hardship_urgency}
                            </Badge>
                        </div>
                        <p className="leading-relaxed">
                            {explanation.hardship_synthesis}
                        </p>
                    </div>
                )}

                {/* Dual-currency split breakdown cards */}
                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="rounded-xl border bg-card p-4">
                        <p className="text-xs font-medium text-muted-foreground">
                            Requested Total
                        </p>
                        <p className="mt-1 font-serif text-xl font-bold tracking-tight text-foreground tabular-nums">
                            {explanation.split.requested_usdc}
                        </p>
                        <p className="text-xs text-muted-foreground tabular-nums">
                            ≈ {explanation.split.requested_fiat}
                        </p>
                    </div>

                    <div className="rounded-xl border border-emerald-500/30 bg-emerald-50/30 p-4 dark:bg-emerald-950/20">
                        <div className="flex items-center justify-between">
                            <p className="text-xs font-medium text-emerald-900 dark:text-emerald-200">
                                Auto-Approved (Disbursed)
                            </p>
                            <Shield className="size-3.5 text-emerald-600" />
                        </div>
                        <p className="mt-1 font-serif text-xl font-bold tracking-tight text-emerald-950 tabular-nums dark:text-emerald-100">
                            {explanation.split.auto_approved_usdc}
                        </p>
                        <p className="text-xs text-emerald-900/80 tabular-nums dark:text-emerald-200/80">
                            ≈ {explanation.split.auto_approved_fiat}
                        </p>
                        <Badge
                            variant="secondary"
                            className="mt-2 bg-emerald-200/80 text-[10px] text-emerald-900 dark:bg-emerald-900/80 dark:text-emerald-100"
                        >
                            Autonomous Execution
                        </Badge>
                    </div>

                    <div
                        className={`rounded-xl border p-4 ${
                            isPartial
                                ? 'border-amber-500/40 bg-amber-50/40 dark:bg-amber-950/20'
                                : 'bg-card'
                        }`}
                    >
                        <div className="flex items-center justify-between">
                            <p className="text-xs font-medium text-amber-900 dark:text-amber-200">
                                Escalated (Pending Review)
                            </p>
                            <UserCheck className="size-3.5 text-amber-600" />
                        </div>
                        <p className="mt-1 font-serif text-xl font-bold tracking-tight text-amber-950 tabular-nums dark:text-amber-100">
                            {explanation.split.pending_usdc}
                        </p>
                        <p className="text-xs text-amber-900/80 tabular-nums dark:text-amber-200/80">
                            ≈ {explanation.split.pending_fiat}
                        </p>
                        <Badge
                            variant="outline"
                            className="mt-2 border-amber-400 text-[10px] text-amber-800 dark:border-amber-700 dark:text-amber-300"
                        >
                            Human Authority Required
                        </Badge>
                    </div>
                </div>

                {/* Deterministic Policy Checklist */}
                <div className="space-y-2">
                    <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                        Deterministic Policy Verification Matrix
                    </p>
                    <div className="grid gap-2 sm:grid-cols-2">
                        {explanation.checks.map((check) => (
                            <div
                                key={check.name}
                                className="flex items-center justify-between rounded-lg border bg-background/60 px-3 py-2 text-xs"
                            >
                                <span className="capitalize text-foreground/90">
                                    {check.name}
                                </span>
                                {check.passed ? (
                                    <span className="flex items-center gap-1 font-medium text-emerald-600 dark:text-emerald-400">
                                        <CheckCircle2 className="size-3.5" />
                                        <span>Passed</span>
                                    </span>
                                ) : (
                                    <span className="flex items-center gap-1 font-medium text-amber-600 dark:text-amber-400">
                                        <XCircle className="size-3.5" />
                                        <span>Escalate / Cap</span>
                                    </span>
                                )}
                            </div>
                        ))}
                    </div>
                </div>

                {/* Locked exchange rate snapshot info */}
                <div className="flex flex-wrap items-center justify-between gap-2 border-t pt-4 text-xs text-muted-foreground">
                    <div className="flex items-center gap-1.5">
                        <Clock className="size-3.5 text-amber-600" />
                        <span>
                            Locked Rate:{' '}
                            {explanation.locked_quote.rate_description}
                        </span>
                        <span className="text-muted-foreground/60">
                            (Provider: {explanation.locked_quote.provider})
                        </span>
                    </div>
                    {explanation.locked_quote.expires_at && (
                        <span>
                            Expires:{' '}
                            {new Date(
                                explanation.locked_quote.expires_at,
                            ).toLocaleTimeString([], {
                                hour: '2-digit',
                                minute: '2-digit',
                            })}
                        </span>
                    )}
                </div>

                {/* On-Chain Arc Transactions */}
                {explanation.transactions.length > 0 && (
                    <div className="space-y-2 border-t pt-4">
                        <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                            On-Chain Settlement Receipts (Arc Testnet)
                        </p>
                        <div className="divide-y rounded-lg border bg-card">
                            {explanation.transactions.map((tx) => (
                                <div
                                    key={tx.id}
                                    className="flex flex-wrap items-center justify-between gap-2 p-3 text-xs"
                                >
                                    <div className="space-y-0.5">
                                        <div className="flex items-center gap-2">
                                            <span className="font-semibold text-foreground">
                                                {tx.amount}
                                            </span>
                                            <Badge
                                                variant="outline"
                                                className="text-[10px] text-emerald-600 border-emerald-400"
                                            >
                                                {tx.status}
                                            </Badge>
                                        </div>
                                        <p className="font-mono text-[11px] text-muted-foreground break-all">
                                            {tx.tx_hash ||
                                                'Simulated On-Chain Batch'}
                                        </p>
                                    </div>

                                    {tx.explorer_url && (
                                        <a
                                            href={tx.explorer_url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="inline-flex items-center gap-1 font-medium text-primary hover:underline"
                                        >
                                            <span>View on ArcScan</span>
                                            <ExternalLink className="size-3" />
                                        </a>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
