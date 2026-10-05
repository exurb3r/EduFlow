import { Head, router } from '@inertiajs/react';
import {
    ArrowDownLeft,
    Copy,
    ExternalLink,
    ReceiptText,
    Wallet,
} from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { index as paymentsIndex } from '@/routes/payments';
import type { Paginated } from '@/types';

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
    wallet: {
        address: string | null;
    };
    totals: {
        confirmed: number;
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
    wallet,
    totals,
}: PaymentsProps) {
    const { data, current_page, from, to, total, last_page } = transactions;

    const copyHash = (hash: string) => {
        navigator.clipboard.writeText(hash);
        toast.info('Transaction hash copied');
    };

    const hasWallet = Boolean(wallet.address);

    return (
        <>
            <Head title="My Payments" />

            <div className="bulletin clay-ambient min-h-full w-full">
                <div className="mx-auto flex w-full max-w-4xl flex-col gap-8 px-4 py-6 sm:px-6 sm:py-10 lg:px-8 lg:py-12">
                    <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p className="bulletin-eyebrow flex items-center gap-2">
                                <ReceiptText className="size-3.5" />
                                EduFlow AI &middot; Payout Ledger
                            </p>
                            <h1 className="clay-h1 mt-2">My Payments</h1>
                            <p className="clay-body mt-1.5">
                                Every disbursement EduFlow has sent to your
                                wallet, with its on-chain trail.
                            </p>
                        </div>

                        <div className="clay-card px-5 py-4">
                            <p className="bulletin-eyebrow">Total received</p>
                            <p className="bulletin-figure mt-1 text-3xl">
                                {totals.confirmed}{' '}
                                <span className="text-sm font-semibold text-[var(--clay-text-muted)]">
                                    {totals.currency}
                                </span>
                            </p>
                        </div>
                    </header>

                    {!hasWallet ? (
                        <div className="clay-card flex flex-col items-center gap-4 px-6 py-12 text-center sm:items-start sm:text-left">
                            <span className="clay-inset flex size-12 items-center justify-center rounded-full">
                                <Wallet className="size-5 text-[var(--clay-text-muted)]" />
                            </span>
                            <div>
                                <p className="clay-h2">No wallet linked.</p>
                                <p className="clay-body mx-auto mt-1.5 max-w-sm sm:mx-0">
                                    Link a payout wallet from the Financial
                                    Assistance page to receive disbursements.
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
                                    Approved assistance lands here as confirmed
                                    {totals.currency} transactions.
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
                                                    +{tx.amount} {tx.currency}
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
                                                        {shortHash(tx.tx_hash)}
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
                                                onClick={() =>
                                                    toast.info(
                                                        'Explorer link coming when the Circle integration is live.',
                                                    )
                                                }
                                            >
                                                <ExternalLink className="size-3.5" />
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
                </div>
            </div>
        </>
    );
}
