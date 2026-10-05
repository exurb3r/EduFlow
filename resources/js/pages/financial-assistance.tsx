import { Head, useForm } from '@inertiajs/react';
import {
    ArrowRight,
    BadgeCheck,
    Check,
    CircleAlert,
    HandCoins,
    Landmark,
    ShieldCheck,
    Split,
    Wallet,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import InputError from '@/components/input-error';
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
import { Textarea } from '@/components/ui/textarea';
import { store as financialAssistanceStore } from '@/routes/financial-assistance';
import { update as walletUpdate } from '@/routes/wallet';
import type { Paginated } from '@/types';

interface AssistanceDecisionView {
    decision: string;
    approved_amount: number;
    requested_amount: number;
    requires_human_approval: boolean;
    reason: string;
    policy: string;
    checks: { label: string; passed: boolean }[];
    agent: string;
}

interface AssistanceRequestView {
    id: number;
    reference_number: string;
    reason: string;
    requested_amount: number;
    approved_amount: number;
    status: string;
    status_label: string;
    created_at: string;
    decision: AssistanceDecisionView | null;
}

interface FinancialAssistanceProps {
    requests: Paginated<AssistanceRequestView>;
    wallet: {
        address: string | null;
    };
    policy: {
        auto_limit: number;
        currency: string;
        budget_remaining: number;
        minimum_reserve: number;
    };
    highlightId?: number;
}

function statusPillClass(status: string): string {
    switch (status) {
        case 'paid':
        case 'auto_approved':
            return 'bg-[var(--status-resolved)]/10 text-[var(--status-resolved)]';
        case 'escalated':
            return 'bg-[var(--status-pending)]/10 text-[var(--status-pending)]';
        case 'approved_by_human':
            return 'bg-[var(--clay-accent)]/10 text-[var(--clay-accent)]';
        case 'rejected':
            return 'bg-[var(--status-urgent)]/10 text-[var(--status-urgent)]';
        default:
            return 'bg-[var(--clay-surface-soft)] text-[var(--clay-text-muted)]';
    }
}

function decisionLabel(decision: string): string {
    switch (decision) {
        case 'auto_approve':
            return 'AUTO-APPROVED';
        case 'partial_approval':
            return 'PARTIAL — AUTO + REVIEW';
        case 'escalate':
            return 'ESCALATED TO HUMAN';
        case 'hold':
            return 'HELD';
        default:
            return decision.toUpperCase();
    }
}

export default function FinancialAssistance({
    requests,
    wallet,
    policy,
    highlightId = 0,
}: FinancialAssistanceProps) {
    const [isWalletOpen, setIsWalletOpen] = useState(false);

    const form = useForm({
        amount: '',
        reason: '',
    });

    const walletForm = useForm({
        wallet_address: wallet.address ?? '',
    });

    const hasWallet = Boolean(wallet.address);

    const submitRequest = (e: React.FormEvent) => {
        e.preventDefault();

        form.post(financialAssistanceStore.url(), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                toast.success('Request submitted to EduFlow AI.', {
                    description:
                        'The agent evaluated it against the assistance policy.',
                });
            },
            onError: () => {
                toast.error(
                    'Could not submit the request. Check the fields and your wallet.',
                );
            },
        });
    };

    const submitWallet = (e: React.FormEvent) => {
        e.preventDefault();

        walletForm.patch(walletUpdate.url(), {
            preserveScroll: true,
            onSuccess: () => {
                setIsWalletOpen(false);
                toast.success('Payout wallet linked.');
            },
            onError: () => {
                toast.error('That wallet address was not accepted.');
            },
        });
    };

    return (
        <>
            <Head title="Financial Assistance" />

            <div className="bulletin clay-ambient min-h-full w-full">
                <div className="mx-auto flex w-full max-w-4xl flex-col gap-8 px-4 py-6 sm:px-6 sm:py-10 lg:px-8 lg:py-12">
                    <header className="flex flex-col gap-2">
                        <p className="bulletin-eyebrow flex items-center gap-2">
                            <HandCoins className="size-3.5" />
                            EduFlow AI &middot; Bounded Autonomy
                        </p>
                        <h1 className="clay-h1">Financial Assistance</h1>
                        <p className="clay-body max-w-xl">
                            Request emergency assistance in {policy.currency}.
                            EduFlow evaluates every request against the
                            assistance policy — amounts within the automatic
                            limit are paid instantly, the rest escalates to a
                            human reviewer.
                        </p>
                    </header>

                    {/* Policy strip */}
                    <section className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div className="clay-card p-4 sm:p-5">
                            <p className="bulletin-eyebrow flex items-center gap-2">
                                <ShieldCheck className="size-3.5" />
                                Auto limit
                            </p>
                            <p className="bulletin-figure mt-2 text-2xl sm:text-3xl">
                                {policy.auto_limit}{' '}
                                <span className="text-sm font-semibold text-[var(--clay-text-muted)]">
                                    {policy.currency}
                                </span>
                            </p>
                        </div>
                        <div className="clay-card p-4 sm:p-5">
                            <p className="bulletin-eyebrow flex items-center gap-2">
                                <Landmark className="size-3.5" />
                                Fund remaining
                            </p>
                            <p className="bulletin-figure mt-2 text-2xl text-[var(--clay-accent)] sm:text-3xl">
                                {policy.budget_remaining}{' '}
                                <span className="text-sm font-semibold text-[var(--clay-text-muted)]">
                                    {policy.currency}
                                </span>
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={() => setIsWalletOpen(true)}
                            className="clay-card clay-press p-4 text-left sm:p-5"
                        >
                            <p className="bulletin-eyebrow flex items-center gap-2">
                                <Wallet className="size-3.5" />
                                Payout wallet
                            </p>
                            <p className="mt-2 truncate text-sm font-semibold text-[var(--clay-ink)]">
                                {hasWallet
                                    ? `${wallet.address?.slice(0, 10)}…${wallet.address?.slice(-6)}`
                                    : 'Link a wallet →'}
                            </p>
                        </button>
                    </section>

                    {/* Request form */}
                    <section className="clay-card p-4 sm:p-6">
                        <h2 className="clay-h2 text-lg">New request</h2>
                        <form
                            onSubmit={submitRequest}
                            className="mt-4 grid gap-4"
                        >
                            <div className="space-y-1.5">
                                <Label
                                    htmlFor="amount"
                                    className="bulletin-eyebrow"
                                >
                                    Amount ({policy.currency})
                                </Label>
                                <Input
                                    id="amount"
                                    type="number"
                                    min={1}
                                    max={5000}
                                    step="0.01"
                                    placeholder="e.g. 150"
                                    value={form.data.amount}
                                    onChange={(e) =>
                                        form.setData('amount', e.target.value)
                                    }
                                    className="clay-field clay-focus shadow-none"
                                    required
                                />
                                <InputError message={form.errors.amount} />
                            </div>

                            <div className="space-y-1.5">
                                <Label
                                    htmlFor="reason"
                                    className="bulletin-eyebrow"
                                >
                                    Reason
                                </Label>
                                <Textarea
                                    id="reason"
                                    rows={4}
                                    placeholder="What is the emergency, and what will the funds cover?"
                                    value={form.data.reason}
                                    onChange={(e) =>
                                        form.setData('reason', e.target.value)
                                    }
                                    className="clay-field clay-focus shadow-none"
                                    required
                                />
                                <InputError message={form.errors.reason} />
                            </div>

                            <div>
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                    data-loading={form.processing}
                                    className="clay-focus gap-2 rounded-full bg-[var(--clay-primary)] px-6 font-semibold text-[var(--clay-primary-foreground)] shadow-[var(--shadow-cta)] hover:bg-[var(--clay-primary-bright)]"
                                >
                                    Submit to EduFlow AI
                                    <ArrowRight className="size-4" />
                                </Button>
                            </div>
                        </form>
                    </section>

                    {/* History */}
                    <section className="flex flex-col gap-4">
                        <h2 className="bulletin-eyebrow">Your requests</h2>

                        {requests.data.length === 0 ? (
                            <div className="clay-card clay-rise flex flex-col items-center gap-4 px-6 py-12 text-center sm:items-start sm:text-left">
                                <span className="clay-inset flex size-12 items-center justify-center rounded-full">
                                    <HandCoins className="size-5 text-[var(--clay-text-muted)]" />
                                </span>
                                <div>
                                    <p className="clay-h2">No requests yet.</p>
                                    <p className="clay-body mx-auto mt-1.5 max-w-sm sm:mx-0">
                                        Submit your first request and watch the
                                        agent's bounded decision appear here.
                                    </p>
                                </div>
                            </div>
                        ) : (
                            <ul className="flex flex-col gap-3">
                                {requests.data.map((req, index) => (
                                    <li
                                        key={req.id}
                                        className={`clay-card clay-rise p-4 sm:p-5 ${
                                            req.id === highlightId
                                                ? 'ring-2 ring-[var(--clay-accent)]'
                                                : ''
                                        }`}
                                        style={
                                            {
                                                '--stagger': Math.min(index, 8),
                                            } as React.CSSProperties
                                        }
                                    >
                                        <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                            <span className="clay-meta">
                                                #{req.reference_number}
                                            </span>
                                            <span className="clay-meta">
                                                {req.created_at}
                                            </span>
                                            <span
                                                className={`rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider ${statusPillClass(req.status)}`}
                                            >
                                                {req.status_label}
                                            </span>
                                        </div>

                                        <div className="mt-2 flex flex-wrap items-baseline gap-x-2">
                                            <span className="clay-title">
                                                {req.requested_amount}{' '}
                                                {policy.currency} requested
                                            </span>
                                            {req.approved_amount > 0 &&
                                                req.approved_amount <
                                                    req.requested_amount && (
                                                    <span className="text-xs font-semibold text-[var(--clay-accent)]">
                                                        → {req.approved_amount}{' '}
                                                        approved now
                                                    </span>
                                                )}
                                        </div>

                                        <p className="clay-body mt-1">
                                            {req.reason}
                                        </p>

                                        {req.decision && (
                                            <div className="clay-inset mt-3 p-3.5">
                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                    <p className="flex items-center gap-2 text-xs font-bold tracking-wider text-[var(--clay-ink)]">
                                                        {req.decision
                                                            .requires_human_approval ? (
                                                            <Split className="size-3.5 text-[var(--status-pending)]" />
                                                        ) : (
                                                            <BadgeCheck className="size-3.5 text-[var(--status-resolved)]" />
                                                        )}
                                                        {decisionLabel(
                                                            req.decision
                                                                .decision,
                                                        )}
                                                    </p>
                                                    <span className="clay-meta">
                                                        {req.decision.policy}
                                                    </span>
                                                </div>

                                                <ul className="mt-2 grid gap-1">
                                                    {req.decision.checks.map(
                                                        (check) => (
                                                            <li
                                                                key={
                                                                    check.label
                                                                }
                                                                className="flex items-center gap-2 text-xs"
                                                            >
                                                                {check.passed ? (
                                                                    <Check className="size-3 shrink-0 text-[var(--status-resolved)]" />
                                                                ) : (
                                                                    <CircleAlert className="size-3 shrink-0 text-[var(--status-urgent)]" />
                                                                )}
                                                                <span
                                                                    className={
                                                                        check.passed
                                                                            ? 'text-[var(--clay-text-muted)]'
                                                                            : 'text-[var(--status-urgent)]'
                                                                    }
                                                                >
                                                                    {
                                                                        check.label
                                                                    }
                                                                </span>
                                                            </li>
                                                        ),
                                                    )}
                                                </ul>

                                                <p className="mt-2.5 border-t border-[var(--clay-border)] pt-2.5 text-xs leading-relaxed text-[var(--clay-text-muted)]">
                                                    {req.decision.reason}
                                                </p>
                                                <p className="clay-meta mt-2">
                                                    {req.decision.agent}
                                                </p>
                                            </div>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            </div>

            {/* Wallet dialog */}
            <Dialog open={isWalletOpen} onOpenChange={setIsWalletOpen}>
                <DialogContent className="bulletin clay-card border-[var(--clay-border)] sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle className="clay-h2 flex items-center gap-3">
                            <span className="clay-icon-chip size-9 shrink-0">
                                <Wallet className="size-4" />
                            </span>
                            Payout wallet
                        </DialogTitle>
                        <DialogDescription className="clay-body">
                            Approved assistance is paid in {policy.currency} to
                            this address on the Arc network.
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submitWallet} className="grid gap-4">
                        <div className="space-y-1.5">
                            <Label
                                htmlFor="wallet_address"
                                className="bulletin-eyebrow"
                            >
                                Wallet address
                            </Label>
                            <Input
                                id="wallet_address"
                                placeholder="0x…"
                                value={walletForm.data.wallet_address}
                                onChange={(e) =>
                                    walletForm.setData(
                                        'wallet_address',
                                        e.target.value,
                                    )
                                }
                                className="clay-field clay-focus font-mono shadow-none"
                                required
                            />
                            <InputError
                                message={walletForm.errors.wallet_address}
                            />
                        </div>

                        <DialogFooter className="gap-2 sm:gap-0">
                            <Button
                                type="button"
                                variant="outline"
                                className="clay-focus rounded-full border-[var(--clay-border)] bg-transparent font-semibold shadow-none"
                                onClick={() => setIsWalletOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={walletForm.processing}
                                data-loading={walletForm.processing}
                                className="clay-focus rounded-full bg-[var(--clay-primary)] font-semibold text-[var(--clay-primary-foreground)] shadow-[var(--shadow-cta)] hover:bg-[var(--clay-primary-bright)]"
                            >
                                Save wallet
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
