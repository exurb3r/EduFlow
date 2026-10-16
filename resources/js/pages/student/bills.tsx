import { Head, Link, router, usePoll } from '@inertiajs/react';
import {
    BrainCircuit,
    Check,
    CircleAlert,
    Copy,
    ExternalLink,
    Loader2,
    ReceiptText,
    RefreshCw,
    ShieldCheck,
    TriangleAlert,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { OfficialPaymentReceiptModal } from '@/components/official-payment-receipt-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { pay as studentPay } from '@/routes/student';
import { show as walletShow } from '@/routes/student/wallet';
import type { OfficialPaymentReceipt } from '@/types/financial-aid';

type Bill = {
    id: number;
    category: string;
    category_label: string;
    description: string;
    amount_base_units: string;
    paid_base_units: string;
    remaining_base_units: string;
    due_date: string | null;
    status: string;
    is_pilot: boolean;
};

type Payment = {
    id: number;
    request_key: string;
    student_bill_id: number;
    amount_base_units: string;
    applied_base_units: string;
    overpayment_base_units: string;
    quoted_network_fee: string | null;
    fee_paid_by: string | null;
    tx_hash: string | null;
    provider_handle: string | null;
    submission_state: string;
    submission_label: string;
    policy_decision: string;
    policy_code: string;
    requires_human_approval: boolean;
    authorizes_outgoing_spend: boolean;
    is_rehearsal: boolean;
    is_allocated: boolean;
    verified: boolean;
    last_error: string | null;
    receipt?: OfficialPaymentReceipt | null;
};

type Props = {
    bills: Bill[];
    payments: Payment[];
    pilot_enabled: boolean;
    pilot_bill_ids: number[];
};

const EXPLORER = 'https://testnet.arcscan.app';

function usdc(baseUnits: string | null | undefined): string {
    if (!baseUnits) return '—';
    if (baseUnits.includes('.')) {
        return parseFloat(baseUnits).toFixed(6);
    }
    try {
        const units = BigInt(baseUnits);

        return `${(units / 1_000_000n).toLocaleString('en-US')}.${(units % 1_000_000n).toString().padStart(6, '0')}`;
    } catch {
        return baseUnits;
    }
}

function statusTone(status: string): 'default' | 'secondary' | 'destructive' {
    if (status === 'paid') return 'default';
    if (status === 'void') return 'destructive';

    return 'secondary';
}

function BillPaymentCard({
    payment,
    onOpenReceipt,
}: {
    payment: Payment;
    onOpenReceipt?: (receipt: OfficialPaymentReceipt) => void;
}) {
    const [copied, setCopied] = useState<string | null>(null);

    const copyText = (text: string, label: string) => {
        navigator.clipboard.writeText(text);
        setCopied(text);
        toast.success(`${label} copied to clipboard`);
        setTimeout(() => setCopied(null), 2000);
    };

    const isInFlight =
        !payment.is_allocated &&
        !payment.verified &&
        [
            'consented',
            'prepared',
            'submitting',
            'accepted',
            'awaiting_verification',
        ].includes(payment.submission_state);

    const isFailed = payment.submission_state === 'failed';
    const isNeedsReview = payment.submission_state === 'needs_operator_review';
    const isCompleted = payment.is_allocated || payment.verified;

    const steps = [
        {
            title: '1. Consent & Fee Quoted',
            desc: '120s quote locked with student consent',
            status: 'completed' as const,
        },
        {
            title: '2. Relayer Dispatch',
            desc:
                ['accepted', 'awaiting_verification', 'verified'].includes(
                    payment.submission_state,
                ) || isCompleted
                    ? 'Relayer accepted and assigned transfer handle'
                    : payment.submission_state === 'submitting'
                      ? 'Transmitting payment to Arc relayer…'
                      : isFailed
                        ? 'Submission failed'
                        : isNeedsReview
                          ? 'Operator review required before send'
                          : 'Queued for transmission',
            status: (['accepted', 'awaiting_verification', 'verified'].includes(
                payment.submission_state,
            ) || isCompleted
                ? 'completed'
                : payment.submission_state === 'submitting' ||
                    payment.submission_state === 'prepared'
                  ? 'in_progress'
                  : isFailed
                    ? 'failed'
                    : isNeedsReview
                      ? 'warning'
                      : 'pending') as
                | 'completed'
                | 'in_progress'
                | 'failed'
                | 'warning'
                | 'pending',
        },
        {
            title: '3. Arc Testnet Settlement',
            desc: payment.tx_hash
                ? 'Committed on-chain with deterministic finality'
                : ['accepted', 'awaiting_verification'].includes(
                        payment.submission_state,
                    )
                  ? 'Awaiting block confirmation and RPC receipt…'
                  : 'Pending block inclusion',
            status: (payment.verified || payment.tx_hash
                ? 'completed'
                : ['accepted', 'awaiting_verification'].includes(
                        payment.submission_state,
                    )
                  ? 'in_progress'
                  : isFailed
                    ? 'failed'
                    : 'pending') as
                | 'completed'
                | 'in_progress'
                | 'failed'
                | 'warning'
                | 'pending',
        },
        {
            title: '4. Bill Balance Credited',
            desc: payment.is_allocated
                ? 'Deducted from student outstanding bill'
                : payment.tx_hash
                  ? 'Movement verified, posting ledger allocation…'
                  : 'Pending on-chain verification',
            status: (payment.is_allocated
                ? 'completed'
                : payment.verified || payment.tx_hash
                  ? 'in_progress'
                  : 'pending') as
                | 'completed'
                | 'in_progress'
                | 'failed'
                | 'warning'
                | 'pending',
        },
    ];

    return (
        <article className="clay-card flex flex-col gap-4 border-[var(--clay-border)] p-4 sm:p-5 text-sm shadow-xs">
            <div className="flex flex-wrap items-start justify-between gap-3 border-b border-[var(--clay-border)] pb-3.5">
                <div>
                    <div className="flex items-center gap-2">
                        <span className="text-base font-bold text-[var(--clay-text)]">
                            Bill #{payment.student_bill_id}
                        </span>
                        <span className="text-xs text-[var(--clay-text-muted)]">
                            ·
                        </span>
                        <span className="font-mono text-sm font-semibold text-[var(--clay-primary-bright)]">
                            {usdc(payment.amount_base_units)} USDC
                        </span>
                    </div>
                    <p className="clay-meta mt-0.5 text-xs">
                        {payment.submission_label}
                    </p>
                </div>

                <Badge
                    variant="outline"
                    className={`text-xs font-semibold ${
                        isCompleted
                            ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-400'
                            : isInFlight
                              ? 'border-amber-500/30 bg-amber-500/10 text-amber-300 animate-pulse'
                              : isNeedsReview
                                ? 'border-amber-500/40 bg-amber-500/15 text-amber-300'
                                : 'border-rose-500/30 bg-rose-500/10 text-rose-300'
                    }`}
                >
                    {isCompleted
                        ? '✓ Verified & Credited'
                        : isInFlight
                          ? '⏳ In Flight · Arc Testnet'
                          : isNeedsReview
                            ? '⚠️ Needs Review'
                            : '✕ Failed'}
                </Badge>
            </div>

            {/* Stepper Progress */}
            <ol className="relative grid gap-3 sm:grid-cols-4 sm:gap-2 pt-1">
                {steps.map((step, idx) => (
                    <li
                        key={idx}
                        className="flex items-start gap-3 sm:flex-col sm:gap-2 text-xs"
                    >
                        <div className="flex items-center gap-2">
                            <span
                                className={`flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-bold ${
                                    step.status === 'completed'
                                        ? 'bg-[var(--status-resolved)] text-black'
                                        : step.status === 'in_progress'
                                          ? 'border-2 border-amber-400 bg-amber-400/20 text-amber-300'
                                          : step.status === 'warning'
                                            ? 'bg-amber-500 text-black'
                                            : step.status === 'failed'
                                              ? 'bg-rose-500 text-white'
                                              : 'border border-[var(--clay-border)] bg-[var(--clay-surface)] text-[var(--clay-text-muted)]'
                                }`}
                            >
                                {step.status === 'completed' ? (
                                    <Check className="size-3.5 stroke-[2.5]" />
                                ) : step.status === 'in_progress' ? (
                                    <Loader2 className="size-3 animate-spin text-amber-400" />
                                ) : step.status === 'warning' ? (
                                    <TriangleAlert className="size-3 stroke-[2.5]" />
                                ) : step.status === 'failed' ? (
                                    <XCircle className="size-3 stroke-[2.5]" />
                                ) : (
                                    idx + 1
                                )}
                            </span>
                            <span
                                className={`font-semibold sm:hidden ${
                                    step.status === 'completed'
                                        ? 'text-[var(--clay-text)]'
                                        : step.status === 'in_progress'
                                          ? 'text-amber-400'
                                          : 'text-[var(--clay-text-muted)]'
                                }`}
                            >
                                {step.title}
                            </span>
                        </div>
                        <div className="min-w-0 flex-1 sm:pt-1">
                            <p
                                className={`hidden font-semibold sm:block ${
                                    step.status === 'completed'
                                        ? 'text-[var(--clay-text)]'
                                        : step.status === 'in_progress'
                                          ? 'text-amber-400'
                                          : 'text-[var(--clay-text-muted)]'
                                }`}
                            >
                                {step.title}
                            </p>
                            <p className="mt-0.5 text-[11px] leading-tight text-[var(--clay-text-muted)]">
                                {step.desc}
                            </p>
                        </div>
                    </li>
                ))}
            </ol>

            {/* Evidence & Transaction Links */}
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-[var(--clay-border)]/60 bg-[var(--clay-surface-soft)] p-3 text-xs">
                <div className="flex flex-wrap items-center gap-3">
                    <span className="font-mono text-[11px] text-[var(--clay-text-muted)]">
                        Req: {payment.request_key.slice(0, 8)}…
                        {payment.request_key.slice(-4)}
                    </span>
                    <button
                        type="button"
                        onClick={() =>
                            copyText(payment.request_key, 'Request key')
                        }
                        className="clay-focus inline-flex items-center gap-1 text-[11px] text-[var(--clay-text-muted)] hover:text-[var(--clay-text)]"
                    >
                        <Copy className="size-3" />
                        {copied === payment.request_key ? 'Copied' : 'Copy'}
                    </button>
                    {payment.tx_hash && (
                        <a
                            href={`${EXPLORER}/tx/${payment.tx_hash}`}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1 font-mono text-[11px] text-[var(--clay-primary-bright)] hover:underline"
                        >
                            Tx: {payment.tx_hash.slice(0, 10)}…
                            {payment.tx_hash.slice(-6)}
                            <ExternalLink className="size-3" />
                        </a>
                    )}
                    {payment.receipt && (
                        <button
                            type="button"
                            onClick={() => onOpenReceipt?.(payment.receipt!)}
                            className="clay-focus inline-flex items-center gap-1 font-semibold text-[11px] text-emerald-400 hover:text-emerald-300 hover:underline"
                        >
                            <ReceiptText className="size-3" />
                            Official e-Receipt
                        </button>
                    )}
                </div>

                <div className="text-[11px] text-[var(--clay-text-muted)]">
                    {payment.fee_paid_by === 'relayer_sponsored' ? (
                        <span className="inline-flex items-center gap-1 text-emerald-400 font-medium">
                            <ShieldCheck className="size-3.5" />
                            Relayer sponsored gas
                        </span>
                    ) : (
                        payment.quoted_network_fee &&
                        `Fee: ${usdc(payment.quoted_network_fee)} USDC`
                    )}
                </div>
            </div>

            {payment.last_error && (
                <div className="rounded-lg border border-amber-500/20 bg-amber-500/10 p-2.5 text-xs text-amber-300">
                    {payment.last_error}
                </div>
            )}
        </article>
    );
}

export default function StudentBills({
    bills,
    payments,
    pilot_enabled,
}: Props) {
    const [receiptModal, setReceiptModal] =
        useState<OfficialPaymentReceipt | null>(null);

    const hasInFlight = payments.some(
        (p) =>
            !p.is_allocated &&
            !p.verified &&
            [
                'consented',
                'prepared',
                'submitting',
                'accepted',
                'awaiting_verification',
            ].includes(p.submission_state),
    );

    usePoll(hasInFlight ? 2_500 : 8_000, { only: ['payments', 'bills'] });

    function open(bill: Bill) {
        router.visit(
            studentPay.url({ query: { mode: 'bill', bill: bill.id } }),
        );
    }

    return (
        <>
            <Head title="My bills" />

            <div className="bulletin space-y-6 p-4 sm:p-6">
                <header className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="clay-h1">My bills</h1>
                        <p className="clay-body">
                            Non-tuition charges the college has levied. Paying
                            one moves USDC from your wallet into the college
                            treasury.
                        </p>
                    </div>
                    <Button variant="outline" asChild>
                        <Link href={walletShow()}>My wallet</Link>
                    </Button>
                </header>

                {!pilot_enabled && (
                    <p className="clay-card flex items-start gap-3 border-[var(--clay-border)] p-4 text-sm">
                        <CircleAlert className="mt-0.5 size-4 shrink-0" />
                        <span>
                            Bill payment submission is switched off on this
                            installation, so no transfer can be sent from here.
                            The bill and its history remain readable.
                        </span>
                    </p>
                )}

                <section className="grid gap-4">
                    {bills.length === 0 && (
                        <p className="clay-card border-[var(--clay-border)] p-5">
                            No bill has been issued to you yet.
                        </p>
                    )}

                    {bills.map((bill) => (
                        <article
                            key={bill.id}
                            className="clay-card grid gap-3 border-[var(--clay-border)] p-5"
                        >
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h2 className="clay-h2">
                                        {bill.category_label}
                                    </h2>
                                    <p className="clay-body">
                                        {bill.description}
                                    </p>
                                </div>
                                <Badge variant={statusTone(bill.status)}>
                                    {bill.status.replace('_', ' ')}
                                </Badge>
                            </div>

                            <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                                <div>
                                    <dt className="clay-meta">Billed</dt>
                                    <dd className="font-semibold">
                                        {usdc(bill.amount_base_units)} USDC
                                    </dd>
                                </div>
                                <div>
                                    <dt className="clay-meta">Paid</dt>
                                    <dd className="font-semibold">
                                        {usdc(bill.paid_base_units)} USDC
                                    </dd>
                                </div>
                                <div>
                                    <dt className="clay-meta">Still owed</dt>
                                    <dd className="font-semibold">
                                        {usdc(bill.remaining_base_units)} USDC
                                    </dd>
                                </div>
                                <div>
                                    <dt className="clay-meta">Due</dt>
                                    <dd className="font-semibold">
                                        {bill.due_date ?? '—'}
                                    </dd>
                                </div>
                            </dl>

                            <div className="flex flex-wrap items-center gap-2">
                                <Button
                                    disabled={
                                        BigInt(bill.remaining_base_units) ===
                                            0n || bill.status === 'void'
                                    }
                                    onClick={() => open(bill)}
                                >
                                    Pay this bill
                                </Button>
                                {!bill.is_pilot && pilot_enabled && (
                                    <span className="clay-meta">
                                        Not designated for the current testnet
                                        pilot, so paying it is not available
                                        yet.
                                    </span>
                                )}
                            </div>
                        </article>
                    ))}
                </section>

                <section aria-labelledby="bill-payments" className="grid gap-3">
                    <h2 id="bill-payments" className="clay-h2">
                        Payment history
                    </h2>

                    {payments.length === 0 && (
                        <p className="clay-body">
                            No payment attempt recorded.
                        </p>
                    )}

                    {payments.map((payment) => (
                        <BillPaymentCard
                            key={payment.id}
                            payment={payment}
                            onOpenReceipt={setReceiptModal}
                        />
                    ))}
                </section>

                <Button
                    variant="ghost"
                    onClick={() =>
                        router.reload({ only: ['bills', 'payments'] })
                    }
                >
                    <RefreshCw className="mr-2 size-4" />
                    Refresh
                </Button>
            </div>

            <OfficialPaymentReceiptModal
                receipt={receiptModal}
                open={receiptModal !== null}
                onOpenChange={(openState) => {
                    if (!openState) setReceiptModal(null);
                }}
            />
        </>
    );
}
