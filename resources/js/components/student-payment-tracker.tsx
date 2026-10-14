import {
    ArrowUpRight,
    Check,
    Copy,
    ExternalLink,
    Loader2,
    Radio,
    RefreshCw,
    ShieldCheck,
    TriangleAlert,
    XCircle,
} from 'lucide-react';
import React, { useState } from 'react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatUsdc } from '@/lib/financial-aid';
import { cn } from '@/lib/utils';
import type { TuitionAttempt } from '@/types/financial-aid';

type StepStatus =
    | 'completed'
    | 'in_progress'
    | 'warning'
    | 'failed'
    | 'pending';

interface ProgressStep {
    id: string;
    title: string;
    description: string;
    status: StepStatus;
    meta?: string | null;
}

interface StudentPaymentTrackerProps {
    attempts?: TuitionAttempt[];
    tuitionAccount: {
        id: number;
        term: string;
        total_amount: string;
        paid_amount: string;
        remaining_amount: string;
        held_amount?: string | null;
    } | null;
    walletBalance?: string | null;
    onOpenPay?: () => void;
    onDone?: () => void;
    className?: string;
}

function shortKey(key: string): string {
    return `${key.slice(0, 8)}…${key.slice(-4)}`;
}

function shortHash(hash: string): string {
    return `${hash.slice(0, 10)}…${hash.slice(-6)}`;
}

export function StudentPaymentTracker({
    attempts = [],
    tuitionAccount,
    walletBalance: _walletBalance,
    onOpenPay,
    onDone,
    className,
}: StudentPaymentTrackerProps) {
    const [copied, setCopied] = useState<string | null>(null);
    const [selectedAttemptId, setSelectedAttemptId] = useState<number | null>(
        null,
    );
    const [dismissedAttemptId, setDismissedAttemptId] = useState<number | null>(
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

    // Pick genuinely in-flight attempt first (exclude unsubmitted held/blocked attempts)
    const inFlightAttempt =
        attempts.find(
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
        ) ?? null;

    // Show in-flight attempt, or an attempt explicitly chosen for inspection, or fallback to latest attempt
    const candidateAttempt =
        inFlightAttempt ??
        (selectedAttemptId !== null
            ? (attempts.find((a) => a.id === selectedAttemptId) ?? null)
            : (attempts[0] ?? null));

    const activeAttempt =
        inFlightAttempt ??
        (candidateAttempt && candidateAttempt.id !== dismissedAttemptId
            ? candidateAttempt
            : null);

    const isInFlight = Boolean(inFlightAttempt);

    const isFailedOrExpired =
        activeAttempt &&
        (activeAttempt.state === 'failed' ||
            activeAttempt.stage === 'quote_expired_unsubmitted' ||
            activeAttempt.stage === 'preflight_blocked');

    const isNeedsReview =
        activeAttempt &&
        (activeAttempt.stage === 'needs_operator_review' ||
            activeAttempt.state === 'unknown');

    const isSettled =
        Boolean(activeAttempt) &&
        !isInFlight &&
        !isNeedsReview &&
        !isFailedOrExpired &&
        Boolean(activeAttempt?.posted || activeAttempt?.state === 'verified');

    const handleDismiss = (attemptId?: number) => {
        const idToDismiss = attemptId ?? candidateAttempt?.id;
        if (idToDismiss) {
            setDismissedAttemptId(idToDismiss);
            try {
                sessionStorage.setItem(
                    'eduflow_dismissed_tuition_attempt',
                    String(idToDismiss),
                );
            } catch {
                // Ignore storage errors
            }
        }
        setSelectedAttemptId(null);
    };

    const handleDone = () => {
        handleDismiss();
        onDone?.();
    };

    const copyText = (text: string, label: string) => {
        navigator.clipboard.writeText(text);
        setCopied(text);
        toast.success(`${label} copied to clipboard`);
        setTimeout(() => setCopied(null), 2000);
    };

    // Build the 5 vertical milestone steps
    const getSteps = (a: TuitionAttempt): ProgressStep[] => {
        // Step 1: Consent & Lock
        const step1: ProgressStep = {
            id: 'consent',
            title: '1. Consent & Fee Locked',
            description: '120s quote locked with student cryptographic consent',
            status: 'completed',
            meta: `Ceiling: ${formatUsdc(a.fee_ceiling_base_units)}`,
        };

        // Step 2: Relayer Preflight
        let step2Status: StepStatus = 'pending';
        let step2Desc = 'Queue worker verifying balance and Arc session';
        if (a.state === 'prepared') {
            if (a.stage === 'needs_operator_review') {
                step2Status = 'warning';
                step2Desc =
                    'Held: Preflight blocked or held before submission. Hold returned to balance.';
            } else {
                step2Status = 'in_progress';
                step2Desc =
                    'Job queued to student-tuition queue; verifying rail';
            }
        } else if (
            a.state === 'failed' ||
            a.stage === 'quote_expired_unsubmitted'
        ) {
            step2Status = 'failed';
            step2Desc =
                a.last_error ?? 'Quote expired before signer was reached';
        } else if (
            [
                'submitting',
                'accepted',
                'awaiting_verification',
                'verified',
            ].includes(a.state) ||
            a.posted
        ) {
            step2Status = 'completed';
            step2Desc =
                'Session, wallet balance, and relayer sponsored bound verified';
        }
        const step2: ProgressStep = {
            id: 'preflight',
            title: '2. Relayer Preflight',
            description: step2Desc,
            status: step2Status,
            meta:
                step2Status === 'in_progress'
                    ? 'Backend queue worker processing'
                    : null,
        };

        // Step 3: Arc Relayer Dispatch
        let step3Status: StepStatus = 'pending';
        let step3Desc = 'Dispatched to Circle Agent Wallet on Arc Testnet';
        if (a.state === 'submitting') {
            step3Status = 'in_progress';
            step3Desc = 'Sending transfer request to Arc relayer…';
        } else if (a.state === 'unknown') {
            step3Status = 'warning';
            step3Desc =
                'Submission outcome unconfirmed; checking existing state';
        } else if (
            ['accepted', 'awaiting_verification', 'verified'].includes(
                a.state,
            ) ||
            a.posted
        ) {
            step3Status = 'completed';
            step3Desc = 'Relayer accepted and assigned transfer handle';
        } else if (step2Status === 'failed') {
            step3Status = 'pending';
            step3Desc = 'Skipped — no signer called';
        }
        const step3: ProgressStep = {
            id: 'dispatch',
            title: '3. Arc Relayer Dispatch',
            description: step3Desc,
            status: step3Status,
            meta: a.provider_handle
                ? `Handle: ${a.provider_handle.slice(0, 8)}…`
                : step3Status === 'in_progress'
                  ? 'Circle relayer dispatching'
                  : null,
        };

        // Step 4: Arc Settlement Proof
        let step4Status: StepStatus = 'pending';
        let step4Desc =
            'Cryptographic receipt confirmed on Arc testnet consensus';
        if (['accepted', 'awaiting_verification'].includes(a.state)) {
            step4Status = 'in_progress';
            step4Desc =
                'Awaiting block confirmation and RPC receipt verification…';
        } else if (a.state === 'verified' || a.posted) {
            step4Status = 'completed';
            step4Desc = 'On-chain receipt verified by exact value & block hash';
        }
        const step4: ProgressStep = {
            id: 'settlement',
            title: '4. Arc On-Chain Proof',
            description: step4Desc,
            status: step4Status,
            meta: a.tx_hash
                ? shortHash(a.tx_hash)
                : step4Status === 'in_progress'
                  ? 'Awaiting block RPC verification'
                  : null,
        };

        // Step 5: Tuition Allocation
        let step5Status: StepStatus = 'pending';
        let step5Desc = 'Deducted from student tuition account balance';
        if (a.posted) {
            step5Status = 'completed';
            step5Desc = 'Posted to student tuition account ledger';
        } else if (a.state === 'verified') {
            step5Status = 'in_progress';
            step5Desc = 'Committed on-chain, posting local allocation…';
        }
        const step5: ProgressStep = {
            id: 'allocation',
            title: '5. Tuition Allocation',
            description: step5Desc,
            status: step5Status,
            meta:
                BigInt(a.applied_base_units || 0) > 0n
                    ? `${formatUsdc(a.applied_base_units)} credited`
                    : step5Status === 'in_progress'
                      ? 'Ledger allocation updating'
                      : null,
        };

        return [step1, step2, step3, step4, step5];
    };

    return (
        <div
            className={cn(
                'clay-card flex flex-col overflow-hidden border border-[var(--clay-border)] p-5 sm:p-6 shadow-sm',
                className,
            )}
        >
            {/* Header: Title + Live Sync Pulse */}
            <div className="flex items-start justify-between gap-3 border-b border-[var(--clay-border)] pb-4">
                <div className="min-w-0">
                    <div className="flex items-center gap-2">
                        <span className="bulletin-eyebrow flex items-center gap-1.5">
                            <Radio className="size-3 text-[var(--clay-primary-bright)] animate-pulse" />
                            Live Rail Monitor
                        </span>
                        <span className="flex items-center gap-1 rounded-full bg-[var(--clay-surface-soft)] px-2 py-0.5 text-[10px] font-semibold text-[var(--clay-text-muted)]">
                            <RefreshCw className="size-2.5 animate-spin text-[var(--clay-primary-bright)]" />
                            {isInFlight ? '2.5s live rail sync' : '8s sync'}
                        </span>
                    </div>
                    <h3 className="clay-h2 mt-1 text-base sm:text-lg">
                        Tuition payment rail
                    </h3>
                    <p className="clay-meta text-xs">
                        Circle Agent Wallet &middot; Arc Testnet
                    </p>
                </div>

                {/* State Badge */}
                <div className="flex items-center gap-1.5 shrink-0">
                    {activeAttempt ? (
                        <Badge
                            variant="outline"
                            className={`text-xs font-semibold ${
                                isInFlight
                                    ? 'border-amber-500/30 bg-amber-500/10 text-amber-400 animate-pulse'
                                    : isNeedsReview
                                      ? 'border-amber-500/40 bg-amber-500/15 text-amber-300'
                                      : isFailedOrExpired
                                        ? activeAttempt.stage ===
                                          'preflight_blocked'
                                            ? 'Preflight Blocked'
                                            : 'Quote Expired'
                                        : 'Settled'
                            }
                            `}
                        >
                            {isInFlight
                                ? 'In Flight'
                                : isNeedsReview
                                  ? 'Needs Review'
                                  : isFailedOrExpired
                                    ? activeAttempt.stage ===
                                      'preflight_blocked'
                                        ? 'Preflight Blocked'
                                        : 'Quote Expired'
                                    : 'Settled'}
                        </Badge>
                    ) : (
                        <Badge
                            variant="outline"
                            className="border-emerald-500/30 bg-emerald-500/10 text-xs text-emerald-400 font-semibold"
                        >
                            Standing By
                        </Badge>
                    )}
                </div>
            </div>

            {/* Content: Active Attempt vs Idle State */}
            {activeAttempt ? (
                <div className="mt-4 flex flex-col gap-4">
                    {/* Amount & Identity Card */}
                    <div className="clay-inset rounded-xl p-3.5">
                        <div className="flex items-baseline justify-between gap-2">
                            <span className="clay-meta text-xs">
                                Target amount
                            </span>
                            <span className="clay-title tabular-nums text-lg font-bold">
                                {formatUsdc(activeAttempt.amount_base_units)}
                            </span>
                        </div>

                        <div className="mt-2.5 flex items-center justify-between border-t border-[var(--clay-border)] pt-2 text-[11px] text-[var(--clay-text-muted)]">
                            <span className="font-mono">
                                Req: {shortKey(activeAttempt.request_key)}
                            </span>
                            <button
                                type="button"
                                onClick={() =>
                                    copyText(
                                        activeAttempt.request_key,
                                        'Request key',
                                    )
                                }
                                className="clay-focus inline-flex items-center gap-1 rounded hover:text-[var(--clay-text)]"
                                title="Copy request key"
                            >
                                <Copy className="size-3" />
                                {copied === activeAttempt.request_key
                                    ? 'Copied'
                                    : 'Copy'}
                            </button>
                        </div>

                        {activeAttempt.tx_hash && (
                            <div className="mt-1.5 flex items-center justify-between text-[11px] text-[var(--clay-text-muted)]">
                                <span className="font-mono">
                                    Hash: {shortHash(activeAttempt.tx_hash)}
                                </span>
                                {activeAttempt.explorer_url && (
                                    <a
                                        href={activeAttempt.explorer_url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="clay-focus inline-flex items-center gap-1 text-[var(--clay-primary-bright)] hover:underline"
                                    >
                                        Explorer
                                        <ExternalLink className="size-2.5" />
                                    </a>
                                )}
                            </div>
                        )}
                    </div>

                    {/* Stalled or Review Notice */}
                    {isNeedsReview && (
                        <div className="flex items-start gap-2.5 rounded-xl border border-amber-500/20 bg-amber-500/10 p-3 text-xs text-amber-300">
                            <TriangleAlert className="size-4 shrink-0 mt-0.5 text-amber-400" />
                            <div className="min-w-0 space-y-1">
                                <p className="font-semibold text-amber-200">
                                    Operator Review Active
                                </p>
                                <p className="text-[11px] leading-relaxed text-amber-300/90">
                                    {activeAttempt.last_error ??
                                        activeAttempt.guidance}
                                </p>
                                <p className="text-[10px] text-amber-400/80">
                                    No money moved from your wallet. Unsubmitted
                                    hold returned to your account balance.
                                </p>
                                {onOpenPay && (
                                    <div className="mt-2 flex items-center gap-2">
                                        <Button
                                            type="button"
                                            size="sm"
                                            onClick={() => {
                                                setSelectedAttemptId(null);
                                                onOpenPay();
                                            }}
                                            className="h-7 rounded-full bg-amber-500/20 px-3 text-[11px] font-semibold text-amber-200 hover:bg-amber-500/30"
                                        >
                                            Try Again
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => handleDismiss()}
                                            className="h-7 rounded-full px-2.5 text-[11px] text-[var(--clay-text-muted)] hover:text-[var(--clay-text)]"
                                        >
                                            Dismiss
                                        </Button>
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    {isFailedOrExpired && (
                        <div className="flex items-start gap-2.5 rounded-xl border border-rose-500/20 bg-rose-500/10 p-3 text-xs text-rose-300">
                            <XCircle className="size-4 shrink-0 mt-0.5 text-rose-400" />
                            <div className="min-w-0 space-y-1">
                                <p className="font-semibold text-rose-200">
                                    {activeAttempt.stage === 'preflight_blocked'
                                        ? 'Preflight Verification Blocked'
                                        : 'Quote Expired Unsubmitted'}
                                </p>
                                <p className="text-[11px] leading-relaxed text-rose-300/90">
                                    {activeAttempt.last_error ??
                                        (activeAttempt.stage ===
                                        'preflight_blocked'
                                            ? 'The network quote exceeded accepted fee ceiling before the signer was called. No funds were moved.'
                                            : 'The 120s quote expired before submission. No signer was called and no money moved.')}
                                </p>
                                <div className="mt-2 flex items-center gap-2">
                                    {onOpenPay && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            onClick={() => {
                                                setSelectedAttemptId(null);
                                                onOpenPay();
                                            }}
                                            className="h-7 rounded-full bg-rose-500/20 px-3 text-[11px] font-semibold text-rose-200 hover:bg-rose-500/30"
                                        >
                                            Start fresh quote
                                        </Button>
                                    )}
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => handleDismiss()}
                                        className="h-7 rounded-full px-2.5 text-[11px] text-[var(--clay-text-muted)] hover:text-[var(--clay-text)]"
                                    >
                                        Dismiss
                                    </Button>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Vertical Progress Line (Stepper) */}
                    <div className="relative mt-1 pl-1">
                        <ol className="relative space-y-5">
                            {getSteps(activeAttempt).map((step, idx, arr) => {
                                const isLast = idx === arr.length - 1;

                                return (
                                    <li
                                        key={step.id}
                                        className="relative flex items-start gap-3.5 text-xs"
                                    >
                                        {/* Vertical connecting line */}
                                        {!isLast && (
                                            <span
                                                aria-hidden="true"
                                                className={`absolute left-[11px] top-[22px] -bottom-[16px] w-[2px] transition-colors ${
                                                    step.status === 'completed'
                                                        ? 'bg-[var(--status-resolved)]'
                                                        : step.status ===
                                                            'in_progress'
                                                          ? 'bg-gradient-to-b from-[var(--status-resolved)] via-amber-400 to-[var(--clay-border)]'
                                                          : 'bg-[var(--clay-border)]'
                                                }`}
                                            />
                                        )}

                                        {/* Step Node Icon */}
                                        <div className="relative z-10 flex size-6 shrink-0 items-center justify-center rounded-full transition-transform">
                                            {step.status === 'completed' && (
                                                <span className="flex size-6 items-center justify-center rounded-full bg-[var(--status-resolved)] text-black shadow-xs">
                                                    <Check className="size-3.5 stroke-[2.5]" />
                                                </span>
                                            )}

                                            {step.status === 'in_progress' && (
                                                <span className="relative flex size-6 items-center justify-center rounded-full border-2 border-amber-400 bg-amber-400/20 text-amber-300">
                                                    <span className="absolute -inset-1 animate-ping rounded-full bg-amber-400/30" />
                                                    <Loader2 className="size-3 animate-spin text-amber-400" />
                                                </span>
                                            )}

                                            {step.status === 'warning' && (
                                                <span className="flex size-6 items-center justify-center rounded-full bg-amber-500 text-black">
                                                    <TriangleAlert className="size-3 stroke-[2.5]" />
                                                </span>
                                            )}

                                            {step.status === 'failed' && (
                                                <span className="flex size-6 items-center justify-center rounded-full bg-rose-500 text-white">
                                                    <XCircle className="size-3.5 stroke-[2.5]" />
                                                </span>
                                            )}

                                            {step.status === 'pending' && (
                                                <span className="flex size-6 items-center justify-center rounded-full border border-[var(--clay-border)] bg-[var(--clay-surface)] text-[var(--clay-text-muted)]">
                                                    <span className="size-1.5 rounded-full bg-[var(--clay-text-muted)] opacity-50" />
                                                </span>
                                            )}
                                        </div>

                                        {/* Step Text Info */}
                                        <div className="min-w-0 flex-1 pt-0.5">
                                            <div className="flex flex-wrap items-baseline justify-between gap-2">
                                                <p
                                                    className={`font-semibold ${
                                                        step.status ===
                                                        'completed'
                                                            ? 'text-[var(--clay-text)]'
                                                            : step.status ===
                                                                'in_progress'
                                                              ? 'text-amber-400'
                                                              : step.status ===
                                                                  'failed'
                                                                ? 'text-rose-400'
                                                                : 'text-[var(--clay-text-muted)]'
                                                    }`}
                                                >
                                                    {step.title}
                                                </p>
                                                {step.meta && (
                                                    <span className="font-mono text-[10px] text-[var(--clay-text-muted)]">
                                                        {step.meta}
                                                    </span>
                                                )}
                                            </div>
                                            <p className="mt-0.5 text-[11px] leading-relaxed text-[var(--clay-text-muted)]">
                                                {step.description}
                                            </p>
                                        </div>
                                    </li>
                                );
                            })}
                        </ol>
                    </div>

                    {/* Footer Guidance */}
                    <div className="mt-1 border-t border-[var(--clay-border)] pt-3 text-[11px] text-[var(--clay-text-muted)]">
                        <div className="flex items-center gap-1.5 font-medium text-[var(--clay-text)]">
                            <ShieldCheck className="size-3.5 text-emerald-400" />
                            Relayed sponsorship guarantee
                        </div>
                        <p className="mt-1 leading-normal">
                            All gas fees are relayer-sponsored. Payer balance is
                            only charged the exact tuition principal once
                            receipt is proven.
                        </p>
                    </div>

                    {/* Settled Action: Done & Pay More */}
                    {isSettled && (
                        <div className="mt-2 flex flex-col gap-2 rounded-xl border border-emerald-500/20 bg-emerald-500/10 p-3.5">
                            <div className="flex items-center gap-2">
                                <Button
                                    type="button"
                                    onClick={handleDone}
                                    className="h-9 flex-1 rounded-full bg-[var(--status-resolved)] text-black font-semibold text-xs hover:opacity-90 shadow-xs"
                                >
                                    <Check className="mr-1.5 size-3.5 stroke-[2.5]" />
                                    Done
                                </Button>
                                {tuitionAccount &&
                                    BigInt(tuitionAccount.remaining_amount) >
                                        0n &&
                                    onOpenPay && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => {
                                                handleDone();
                                                onOpenPay();
                                            }}
                                            className="h-9 rounded-full border border-[var(--clay-border)] bg-[var(--clay-surface)] px-4 text-xs font-semibold text-[var(--clay-text)] hover:bg-[var(--clay-surface-soft)]"
                                        >
                                            <ArrowUpRight className="mr-1.5 size-3.5" />
                                            Pay more
                                        </Button>
                                    )}
                            </div>
                            <p className="text-center text-[11px] text-emerald-300/80">
                                Payment verified and credited to tuition ledger.
                                Click Done to return to monitor standby.
                            </p>
                        </div>
                    )}
                </div>
            ) : (
                /* Idle State when no payment is currently in flight */
                <div className="mt-4 flex flex-col gap-4">
                    {tuitionAccount &&
                        BigInt(tuitionAccount.remaining_amount) > 0n && (
                            <div className="flex items-center justify-between rounded-xl border border-[var(--clay-border)] p-3 text-xs">
                                <div>
                                    <span className="clay-meta text-[11px]">
                                        Remaining balance
                                    </span>
                                    <p className="clay-title font-bold">
                                        {formatUsdc(
                                            tuitionAccount.remaining_amount,
                                        )}
                                    </p>
                                </div>
                                {onOpenPay && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        onClick={onOpenPay}
                                        className="h-8 rounded-full bg-[var(--clay-primary)] px-3 text-xs font-semibold text-[var(--clay-primary-foreground)] hover:bg-[var(--clay-primary-bright)]"
                                    >
                                        <ArrowUpRight className="mr-1 size-3.5" />
                                        Pay now
                                    </Button>
                                )}
                            </div>
                        )}

                    {attempts.length > 0 && (
                        <div className="border-t border-[var(--clay-border)] pt-3 text-xs">
                            <div className="flex items-center justify-between text-[11px] text-[var(--clay-text-muted)]">
                                <span>Recent attempt</span>
                                <button
                                    type="button"
                                    onClick={() => {
                                        try {
                                            sessionStorage.removeItem(
                                                'eduflow_dismissed_tuition_attempt',
                                            );
                                        } catch {
                                            // Ignore storage errors
                                        }
                                        setDismissedAttemptId(null);
                                        setSelectedAttemptId(attempts[0].id);
                                    }}
                                    className="clay-focus inline-flex items-center gap-1 font-semibold text-[var(--clay-primary-bright)] hover:underline"
                                >
                                    #{attempts[0].id} ·{' '}
                                    {formatUsdc(attempts[0].amount_base_units)}{' '}
                                    ({attempts[0].state_label})
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
