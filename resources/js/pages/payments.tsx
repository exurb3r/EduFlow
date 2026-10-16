import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowDownLeft,
    ArrowUpRight,
    CheckCircle2,
    Copy,
    ExternalLink,
    ReceiptText,
    Wallet,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { OfficialPaymentReceiptModal } from '@/components/official-payment-receipt-modal';
import { Button } from '@/components/ui/button';
import { formatUsdc } from '@/lib/financial-aid';
import { index as paymentsIndex } from '@/routes/payments';
import { pay as studentPayRoute } from '@/routes/student';
import type { Paginated } from '@/types';
import type { OfficialPaymentReceipt } from '@/types/financial-aid';

export interface PaymentTransactionView {
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

interface PaymentsProps {
    transactions: Paginated<PaymentTransactionView>;
    receipts?: OfficialPaymentReceipt[];
    wallet: {
        address: string | null;
    };
    totals: {
        confirmed: number;
        tuition_paid?: number;
        currency: string;
    };
}

function statusPillClass(status: string): string {
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

function shortHash(hash: string): string {
    return `${hash.slice(0, 10)}…${hash.slice(-6)}`;
}

export default function Payments({
    transactions,
    receipts = [],
    wallet,
    totals,
}: PaymentsProps) {
    const { data, current_page, from, to, total, last_page } = transactions;
    const [activeReceipt, setActiveReceipt] =
        useState<OfficialPaymentReceipt | null>(null);

    const copyHash = (hash: string) => {
        navigator.clipboard.writeText(hash);
        toast.info('Transaction hash copied');
    };

    const hasWallet = Boolean(wallet.address);

    return (
        <>
            <Head title="My Payments & Official e-Receipts" />

            <div className="bulletin clay-ambient min-h-full w-full">
                <div className="mx-auto flex w-full max-w-4xl flex-col gap-8 px-4 py-6 sm:px-6 sm:py-10 lg:px-8 lg:py-12">
                    <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p className="bulletin-eyebrow flex items-center gap-2">
                                <ReceiptText className="size-3.5" />
                                OpenSinop AI &middot; On-Chain Ledger &amp;
                                e-Receipts
                            </p>
                            <h1 className="clay-h1 mt-2">
                                My Payments &amp; e-Receipts
                            </h1>
                            <p className="clay-body mt-1.5">
                                Official on-chain e-Receipts for your tuition
                                and bill settlements, plus incoming assistance
                                disbursements.
                            </p>
                        </div>

                        <div className="flex flex-wrap items-center gap-3">
                            {typeof totals.tuition_paid === 'number' && (
                                <div className="clay-card px-5 py-4">
                                    <p className="bulletin-eyebrow">
                                        Tuition Settled
                                    </p>
                                    <p className="bulletin-figure mt-1 text-2xl text-[var(--status-resolved)] sm:text-3xl">
                                        {totals.tuition_paid}{' '}
                                        <span className="text-sm font-semibold text-[var(--clay-text-muted)]">
                                            {totals.currency}
                                        </span>
                                    </p>
                                </div>
                            )}

                            <div className="clay-card px-5 py-4">
                                <p className="bulletin-eyebrow">
                                    Total received
                                </p>
                                <p className="bulletin-figure mt-1 text-2xl sm:text-3xl">
                                    {totals.confirmed}{' '}
                                    <span className="text-sm font-semibold text-[var(--clay-text-muted)]">
                                        {totals.currency}
                                    </span>
                                </p>
                            </div>
                        </div>
                    </header>

                    {/* Official On-Chain e-Receipts Section */}
                    <section className="space-y-4">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h2 className="clay-h2 text-lg">
                                    Official On-Chain e-Receipts
                                </h2>
                                <p className="clay-meta text-xs">
                                    Cryptographically verified tuition and
                                    campus bill payments on Arc Testnet
                                </p>
                            </div>
                            <Button
                                variant="outline"
                                size="sm"
                                className="clay-focus h-9 rounded-full border-[var(--clay-border)] bg-[var(--clay-surface)] px-4 text-xs font-semibold"
                                asChild
                            >
                                <Link href={studentPayRoute.url()}>
                                    <ArrowUpRight className="mr-1.5 size-3.5" />
                                    Make a Payment
                                </Link>
                            </Button>
                        </div>

                        {receipts.length === 0 ? (
                            <div className="clay-card flex flex-col items-center gap-3 px-6 py-8 text-center sm:items-start sm:text-left">
                                <p className="clay-title text-sm">
                                    No official payment e-receipts issued yet.
                                </p>
                                <p className="clay-body text-xs text-[var(--clay-text-muted)]">
                                    As soon as your tuition or campus bill
                                    transfer settles on Arc Testnet, your
                                    official printable e-Receipt appears here
                                    automatically.
                                </p>
                            </div>
                        ) : (
                            <ul className="flex flex-col gap-3">
                                {receipts.map((receipt, index) => {
                                    const isPartial =
                                        BigInt(
                                            receipt.remaining_balance_base_units ||
                                                '0',
                                        ) > 0n;
                                    return (
                                        <li
                                            key={receipt.receipt_number}
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
                                            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                                <div className="flex items-start gap-3">
                                                    <span className="flex size-10 shrink-0 items-center justify-center rounded-2xl border border-emerald-500/30 bg-emerald-500/15 text-emerald-400">
                                                        <ReceiptText className="size-4" />
                                                    </span>
                                                    <div className="min-w-0 flex-1">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <span className="font-mono text-xs font-bold text-emerald-400">
                                                                {
                                                                    receipt.receipt_number
                                                                }
                                                            </span>
                                                            <span className="clay-title">
                                                                {formatUsdc(
                                                                    receipt.amount_base_units,
                                                                )}
                                                            </span>
                                                            <span className="inline-flex items-center gap-1 rounded-full bg-emerald-500/15 px-2 py-0.5 text-[10px] font-semibold text-emerald-300">
                                                                <CheckCircle2 className="size-3" />
                                                                On-Chain
                                                                Verified
                                                            </span>
                                                            {isPartial && (
                                                                <span className="rounded-full bg-amber-500/15 px-2 py-0.5 text-[10px] font-semibold text-amber-300">
                                                                    Partial
                                                                    Settlement
                                                                </span>
                                                            )}
                                                        </div>
                                                        <p className="mt-1 text-xs font-medium text-[var(--clay-text)]">
                                                            {receipt.description ??
                                                                receipt.term}{' '}
                                                            &middot; Remaining
                                                            Balance:{' '}
                                                            <span className="font-mono font-semibold">
                                                                {formatUsdc(
                                                                    receipt.remaining_balance_base_units,
                                                                )}
                                                            </span>
                                                        </p>
                                                        <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-[var(--clay-text-muted)]">
                                                            {receipt.tx_hash && (
                                                                <button
                                                                    type="button"
                                                                    onClick={() =>
                                                                        copyHash(
                                                                            receipt.tx_hash as string,
                                                                        )
                                                                    }
                                                                    className="clay-meta clay-focus inline-flex items-center gap-1 rounded-full px-2 py-0.5 hover:bg-[var(--clay-surface-soft)] hover:text-[var(--clay-text)]"
                                                                >
                                                                    {shortHash(
                                                                        receipt.tx_hash,
                                                                    )}
                                                                    <Copy className="size-3" />
                                                                </button>
                                                            )}
                                                            <span className="clay-meta">
                                                                {receipt.chain}
                                                            </span>
                                                            <span className="clay-meta">
                                                                {
                                                                    receipt.issued_at
                                                                }
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div className="flex items-center gap-2 shrink-0">
                                                    {receipt.explorer_url && (
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            className="clay-focus h-9 rounded-full px-3 text-xs font-semibold"
                                                            asChild
                                                        >
                                                            <a
                                                                href={
                                                                    receipt.explorer_url
                                                                }
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                            >
                                                                Arcscan
                                                                <ExternalLink className="ml-1 size-3.5" />
                                                            </a>
                                                        </Button>
                                                    )}
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        onClick={() =>
                                                            setActiveReceipt(
                                                                receipt,
                                                            )
                                                        }
                                                        className="clay-focus h-9 rounded-full bg-emerald-500/20 px-4 text-xs font-semibold text-emerald-200 hover:bg-emerald-500/30"
                                                    >
                                                        <ReceiptText className="mr-1.5 size-3.5" />
                                                        View Official e-Receipt
                                                    </Button>
                                                </div>
                                            </div>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </section>

                    {/* Inbound Assistance & Refund Disbursements Section */}
                    <section className="space-y-4">
                        <div>
                            <h2 className="clay-h2 text-lg">
                                Inbound Disbursements (Assistance &amp; Refunds)
                            </h2>
                            <p className="clay-meta text-xs">
                                Every disbursement OpenSinop has sent to your
                                wallet, with its on-chain trail
                            </p>
                        </div>

                        {!hasWallet ? (
                            <div className="clay-card flex flex-col items-center gap-4 px-6 py-12 text-center sm:items-start sm:text-left">
                                <span className="clay-inset flex size-12 items-center justify-center rounded-full">
                                    <Wallet className="size-5 text-[var(--clay-text-muted)]" />
                                </span>
                                <div>
                                    <p className="clay-h2">No wallet linked.</p>
                                    <p className="clay-body mx-auto mt-1.5 max-w-sm sm:mx-0">
                                        Link a payout wallet from the Financial
                                        Assistance page to receive
                                        disbursements.
                                    </p>
                                </div>
                            </div>
                        ) : data.length === 0 ? (
                            <div className="clay-card flex flex-col items-center gap-4 px-6 py-12 text-center sm:items-start sm:text-left">
                                <span className="clay-inset flex size-12 items-center justify-center rounded-full">
                                    <ArrowDownLeft className="size-5 text-[var(--clay-text-muted)]" />
                                </span>
                                <div>
                                    <p className="clay-h2">No payouts yet.</p>
                                    <p className="clay-body mx-auto mt-1.5 max-w-sm sm:mx-0">
                                        Approved assistance lands here as
                                        confirmed {totals.currency}{' '}
                                        transactions.
                                    </p>
                                </div>
                            </div>
                        ) : (
                            <ul className="flex flex-col gap-3">
                                {data.map((tx, index) => (
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
                                                        className={`rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider ${statusPillClass(tx.status)}`}
                                                    >
                                                        {tx.status_label}
                                                    </span>
                                                    <span className="clay-meta">
                                                        {tx.type_label}
                                                    </span>
                                                </div>

                                                <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1">
                                                    {tx.tx_hash && (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                copyHash(
                                                                    tx.tx_hash as string,
                                                                )
                                                            }
                                                            className="clay-meta clay-focus inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 transition-colors hover:bg-[var(--clay-surface-soft)] hover:text-[var(--clay-text)]"
                                                            title="Copy transaction hash"
                                                        >
                                                            {shortHash(
                                                                tx.tx_hash,
                                                            )}
                                                            <Copy className="size-3" />
                                                        </button>
                                                    )}
                                                    <span className="clay-meta">
                                                        {tx.network}
                                                    </span>
                                                    <span className="clay-meta">
                                                        {tx.executed_at}
                                                    </span>
                                                </div>
                                            </div>

                                            {tx.tx_hash && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="clay-focus h-8 shrink-0 rounded-full px-2 text-xs font-semibold"
                                                    asChild
                                                >
                                                    <a
                                                        href={`https://testnet.arcscan.app/tx/${tx.tx_hash}`}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        title="View on Arcscan block explorer"
                                                    >
                                                        <ExternalLink className="size-3.5" />
                                                    </a>
                                                </Button>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}

                        {data.length > 0 && last_page > 1 && (
                            <div className="flex flex-wrap items-center justify-between gap-3 text-xs text-[var(--clay-text-muted)]">
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
                                            router.get(
                                                paymentsIndex.url({
                                                    query: {
                                                        page: current_page - 1,
                                                    },
                                                }),
                                                {},
                                                {
                                                    preserveScroll: true,
                                                    replace: true,
                                                },
                                            )
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
                                        disabled={current_page >= last_page}
                                        onClick={() =>
                                            router.get(
                                                paymentsIndex.url({
                                                    query: {
                                                        page: current_page + 1,
                                                    },
                                                }),
                                                {},
                                                {
                                                    preserveScroll: true,
                                                    replace: true,
                                                },
                                            )
                                        }
                                    >
                                        Next
                                    </Button>
                                </div>
                            </div>
                        )}
                    </section>
                </div>
            </div>

            <OfficialPaymentReceiptModal
                receipt={activeReceipt}
                open={Boolean(activeReceipt)}
                onOpenChange={(open) => {
                    if (!open) {
                        setActiveReceipt(null);
                    }
                }}
            />
        </>
    );
}
