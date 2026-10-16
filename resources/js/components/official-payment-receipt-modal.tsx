import {
    Check,
    CheckCircle2,
    Copy,
    Download,
    ExternalLink,
    Fingerprint,
    Printer,
    ReceiptText,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatUsdc } from '@/lib/financial-aid';
import type { OfficialPaymentReceipt } from '@/types/financial-aid';

interface OfficialPaymentReceiptModalProps {
    receipt: OfficialPaymentReceipt | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

function shortAddress(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }
    if (value.length <= 16) {
        return value;
    }

    return `${value.slice(0, 10)}…${value.slice(-8)}`;
}

export function OfficialPaymentReceiptModal({
    receipt,
    open,
    onOpenChange,
}: OfficialPaymentReceiptModalProps) {
    const [copiedField, setCopiedField] = useState<string | null>(null);

    if (!receipt) {
        return null;
    }

    const copyValue = (text: string, label: string) => {
        navigator.clipboard.writeText(text);
        setCopiedField(label);
        toast.success(`${label} copied to clipboard`);
        setTimeout(() => setCopiedField(null), 2000);
    };

    const handlePrint = () => {
        window.print();
    };

    const handleDownload = () => {
        const text = [
            '=========================================',
            '           OFFICIAL E-RECEIPT            ',
            '=========================================',
            `Receipt Number:    ${receipt.receipt_number}`,
            `Payment Type:      ${receipt.payment_type ?? 'tuition'}`,
            `Issued At:         ${receipt.issued_at}`,
            `Status:            ${receipt.verified_status ?? 'Verified on Arc Testnet'}`,
            '-----------------------------------------',
            `Student Name:      ${receipt.student_name}`,
            `Student Number:    ${receipt.student_number}`,
            `Program:           ${receipt.program}`,
            `Term/Category:     ${receipt.term}`,
            `Description:       ${receipt.description ?? 'Payment Settlement'}`,
            '-----------------------------------------',
            `Principal (USDC):  ${formatUsdc(receipt.amount_base_units)}`,
            `Applied to Ledger: ${formatUsdc(receipt.applied_base_units)}`,
            `Overpayment Held:  ${formatUsdc(receipt.overpayment_base_units || '0')}`,
            `Remaining Balance: ${formatUsdc(receipt.remaining_balance_base_units)}`,
            `Network Fee:       ${receipt.network_fee} (${receipt.fee_guarantee})`,
            '-----------------------------------------',
            `Chain:             ${receipt.chain} (Chain ID: ${receipt.chain_id})`,
            `Block Number:      ${receipt.block_number ? '#' + receipt.block_number : 'Confirmed'}`,
            `Tx Hash:           ${receipt.tx_hash ?? 'N/A'}`,
            `Payer:             ${receipt.payer_address}`,
            `Recipient:         ${receipt.recipient_address}`,
            `Settlement Digest: ${receipt.settlement_digest ?? 'N/A'}`,
            '=========================================',
            'EduFlow OpenSinop On-Chain Settlement',
        ].join('\n');

        const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `${receipt.receipt_number}.txt`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        toast.success('e-Receipt downloaded');
    };

    const isPartial =
        BigInt(receipt.remaining_balance_base_units || '0') > 0n;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[92vh] max-w-xl overflow-y-auto border border-[var(--clay-border)] bg-[var(--clay-surface)] p-0 text-[var(--clay-text)] shadow-2xl sm:rounded-3xl">
                {/* Top Official Header Banner */}
                <div className="border-b border-[var(--clay-border)] bg-gradient-to-br from-emerald-500/15 via-[var(--clay-surface-soft)] to-[var(--clay-surface)] px-6 py-5">
                    <div className="flex items-start justify-between gap-3">
                        <div className="flex items-center gap-3">
                            <div className="flex size-11 shrink-0 items-center justify-center rounded-2xl border border-emerald-500/30 bg-emerald-500/15 text-emerald-400">
                                <ReceiptText className="size-5" />
                            </div>
                            <div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-mono text-[11px] font-bold tracking-wider text-emerald-400 uppercase">
                                        Official e-Receipt
                                    </span>
                                    <Badge
                                        variant="outline"
                                        className="border-emerald-500/40 bg-emerald-500/15 text-[10px] font-semibold text-emerald-300"
                                    >
                                        <CheckCircle2 className="mr-1 size-3" />
                                        {receipt.verified_status ?? 'On-Chain Verified'}
                                    </Badge>
                                </div>
                                <DialogHeader className="mt-1 p-0 text-left">
                                    <DialogTitle className="font-mono text-base font-bold tracking-tight text-[var(--clay-text)] sm:text-lg">
                                        {receipt.receipt_number}
                                    </DialogTitle>
                                    <DialogDescription className="text-xs text-[var(--clay-text-muted)]">
                                        Issued {receipt.issued_at} &middot;{' '}
                                        {receipt.chain} (Chain ID{' '}
                                        {receipt.chain_id})
                                    </DialogDescription>
                                </DialogHeader>
                            </div>
                        </div>

                        <div className="flex items-center gap-1.5 print:hidden">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={handleDownload}
                                className="h-8 gap-1.5 rounded-full border-[var(--clay-border)] bg-[var(--clay-surface)] px-3 text-xs font-semibold"
                                title="Download text summary"
                            >
                                <Download className="size-3.5" />
                                <span className="hidden sm:inline">Download</span>
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={handlePrint}
                                className="h-8 gap-1.5 rounded-full border-[var(--clay-border)] bg-[var(--clay-surface)] px-3 text-xs font-semibold"
                            >
                                <Printer className="size-3.5" />
                                <span className="hidden sm:inline">Print / Save PDF</span>
                            </Button>
                        </div>
                    </div>
                </div>

                <div className="space-y-5 px-6 py-5">
                    {/* Hero Amount Credited */}
                    <div className="rounded-2xl border border-emerald-500/25 bg-emerald-500/10 p-4 text-center">
                        <p className="text-[11px] font-semibold tracking-wider text-emerald-300/90 uppercase">
                            Total Settled &amp; Verified on Arc
                        </p>
                        <p className="mt-1 font-mono text-2xl font-extrabold tracking-tight text-emerald-300 sm:text-3xl">
                            {formatUsdc(receipt.amount_base_units)}
                        </p>
                        <div className="mt-2 flex flex-wrap items-center justify-center gap-2 text-xs text-emerald-200/90">
                            <span>
                                Applied to{' '}
                                {receipt.payment_type === 'bill'
                                    ? 'Campus Bill'
                                    : 'Tuition Ledger'}
                                :{' '}
                                <strong className="font-mono">
                                    {formatUsdc(receipt.applied_base_units)}
                                </strong>
                            </span>
                            <span className="opacity-40">&bull;</span>
                            <span>
                                {isPartial ? (
                                    <span className="rounded-full bg-amber-500/20 px-2 py-0.5 text-[11px] font-semibold text-amber-300">
                                        Partial Settlement &middot; Remaining:{' '}
                                        {formatUsdc(
                                            receipt.remaining_balance_base_units,
                                        )}
                                    </span>
                                ) : (
                                    <span className="rounded-full bg-emerald-500/20 px-2 py-0.5 text-[11px] font-semibold text-emerald-300">
                                        Paid in Full (0.00 USDC Remaining)
                                    </span>
                                )}
                            </span>
                        </div>
                    </div>

                    {/* Student & Account Information */}
                    <div className="rounded-2xl border border-[var(--clay-border)] bg-[var(--clay-surface-soft)]/50 p-4">
                        <p className="text-[11px] font-bold tracking-wider text-[var(--clay-text-muted)] uppercase">
                            Student &amp; Ledger Details
                        </p>
                        <dl className="mt-3 grid grid-cols-1 gap-2.5 text-xs sm:grid-cols-2">
                            <div>
                                <dt className="text-[var(--clay-text-muted)]">
                                    Student Name
                                </dt>
                                <dd className="mt-0.5 font-semibold text-[var(--clay-text)]">
                                    {receipt.student_name}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-[var(--clay-text-muted)]">
                                    Student Number
                                </dt>
                                <dd className="mt-0.5 font-mono font-semibold text-[var(--clay-text)]">
                                    {receipt.student_number}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-[var(--clay-text-muted)]">
                                    Program
                                </dt>
                                <dd className="mt-0.5 font-medium text-[var(--clay-text)]">
                                    {receipt.program || 'Undergraduate'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-[var(--clay-text-muted)]">
                                    Academic Term / Category
                                </dt>
                                <dd className="mt-0.5 font-semibold text-[var(--clay-text)]">
                                    {receipt.term}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    {/* Financial Breakdown */}
                    <div className="rounded-2xl border border-[var(--clay-border)] bg-[var(--clay-surface-soft)]/50 p-4">
                        <p className="text-[11px] font-bold tracking-wider text-[var(--clay-text-muted)] uppercase">
                            Settlement &amp; Allocation Breakdown
                        </p>
                        <div className="mt-3 space-y-2 text-xs">
                            <div className="flex items-center justify-between">
                                <span className="text-[var(--clay-text-muted)]">
                                    Principal Transferred
                                </span>
                                <span className="font-mono font-semibold text-[var(--clay-text)]">
                                    {formatUsdc(receipt.amount_base_units)}
                                </span>
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-[var(--clay-text-muted)]">
                                    Credited to Student Account
                                </span>
                                <span className="font-mono font-semibold text-emerald-400">
                                    {formatUsdc(receipt.applied_base_units)}
                                </span>
                            </div>
                            {BigInt(receipt.overpayment_base_units || '0') >
                                0n && (
                                <div className="flex items-center justify-between">
                                    <span className="text-[var(--clay-text-muted)]">
                                        Overpayment Credit Held
                                    </span>
                                    <span className="font-mono font-semibold text-amber-300">
                                        {formatUsdc(
                                            receipt.overpayment_base_units,
                                        )}
                                    </span>
                                </div>
                            )}
                            {receipt.total_tuition_base_units &&
                                BigInt(receipt.total_tuition_base_units) >
                                    0n && (
                                    <div className="flex items-center justify-between border-t border-[var(--clay-border)] pt-2">
                                        <span className="text-[var(--clay-text-muted)]">
                                            Cumulative Term Paid / Total
                                        </span>
                                        <span className="font-mono text-[var(--clay-text)]">
                                            {formatUsdc(
                                                receipt.paid_tuition_base_units ??
                                                    receipt.applied_base_units,
                                            )}{' '}
                                            /{' '}
                                            {formatUsdc(
                                                receipt.total_tuition_base_units,
                                            )}
                                        </span>
                                    </div>
                                )}
                            <div className="flex items-center justify-between">
                                <span className="text-[var(--clay-text-muted)]">
                                    Remaining Account Balance
                                </span>
                                <span className="font-mono font-bold text-[var(--clay-text)]">
                                    {formatUsdc(
                                        receipt.remaining_balance_base_units,
                                    )}
                                </span>
                            </div>
                            <div className="flex items-center justify-between border-t border-[var(--clay-border)] pt-2">
                                <span className="text-[var(--clay-text-muted)]">
                                    Arc Network Gas Fee
                                </span>
                                <span className="inline-flex items-center gap-1.5 font-mono text-emerald-400">
                                    <ShieldCheck className="size-3.5" />
                                    0.00 USDC Paid by Student (Relayer
                                    Sponsored)
                                </span>
                            </div>
                        </div>
                    </div>

                    {/* Cryptographic & On-Chain Proof */}
                    <div className="rounded-2xl border border-[var(--clay-border)] bg-[var(--clay-surface-soft)]/50 p-4">
                        <div className="flex items-center justify-between">
                            <p className="flex items-center gap-1.5 text-[11px] font-bold tracking-wider text-[var(--clay-text-muted)] uppercase">
                                <Fingerprint className="size-3.5 text-[var(--clay-primary-bright)]" />
                                Cryptographic On-Chain Proof
                            </p>
                            {receipt.explorer_url && (
                                <a
                                    href={receipt.explorer_url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="inline-flex items-center gap-1 text-xs font-semibold text-[var(--clay-primary-bright)] hover:underline"
                                >
                                    Verify on Arcscan
                                    <ExternalLink className="size-3" />
                                </a>
                            )}
                        </div>

                        <div className="mt-3 space-y-2.5 text-xs">
                            <div className="flex items-center justify-between gap-2">
                                <span className="text-[var(--clay-text-muted)]">
                                    Payer Wallet (Student)
                                </span>
                                <button
                                    type="button"
                                    onClick={() =>
                                        copyValue(
                                            receipt.payer_address,
                                            'Payer wallet address',
                                        )
                                    }
                                    className="inline-flex items-center gap-1.5 font-mono text-[11px] text-[var(--clay-text)] hover:text-[var(--clay-primary-bright)]"
                                >
                                    {shortAddress(receipt.payer_address)}
                                    {copiedField === 'Payer wallet address' ? (
                                        <Check className="size-3 text-emerald-400" />
                                    ) : (
                                        <Copy className="size-3 opacity-70" />
                                    )}
                                </button>
                            </div>

                            <div className="flex items-center justify-between gap-2">
                                <span className="text-[var(--clay-text-muted)]">
                                    Recipient (Institution Treasury)
                                </span>
                                <button
                                    type="button"
                                    onClick={() =>
                                        copyValue(
                                            receipt.recipient_address,
                                            'Treasury wallet address',
                                        )
                                    }
                                    className="inline-flex items-center gap-1.5 font-mono text-[11px] text-[var(--clay-text)] hover:text-[var(--clay-primary-bright)]"
                                >
                                    {shortAddress(receipt.recipient_address)}
                                    {copiedField ===
                                    'Treasury wallet address' ? (
                                        <Check className="size-3 text-emerald-400" />
                                    ) : (
                                        <Copy className="size-3 opacity-70" />
                                    )}
                                </button>
                            </div>

                            {receipt.block_number && (
                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-[var(--clay-text-muted)]">
                                        Arc Block Number
                                    </span>
                                    <span className="font-mono text-[11px] font-semibold text-emerald-400">
                                        #{receipt.block_number}
                                    </span>
                                </div>
                            )}

                            {receipt.tx_hash && (
                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-[var(--clay-text-muted)]">
                                        Arc Transaction Hash
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            copyValue(
                                                receipt.tx_hash as string,
                                                'Transaction hash',
                                            )
                                        }
                                        className="inline-flex items-center gap-1.5 font-mono text-[11px] text-emerald-400 hover:underline"
                                    >
                                        {shortAddress(receipt.tx_hash)}
                                        {copiedField === 'Transaction hash' ? (
                                            <Check className="size-3 text-emerald-400" />
                                        ) : (
                                            <Copy className="size-3 opacity-70" />
                                        )}
                                    </button>
                                </div>
                            )}

                            {receipt.settlement_digest && (
                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-[var(--clay-text-muted)]">
                                        Settlement Evidence SHA-256
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            copyValue(
                                                receipt.settlement_digest as string,
                                                'Settlement SHA-256 digest',
                                            )
                                        }
                                        className="inline-flex items-center gap-1.5 font-mono text-[11px] text-[var(--clay-text)] hover:text-[var(--clay-primary-bright)]"
                                    >
                                        {shortAddress(
                                            receipt.settlement_digest,
                                        )}
                                        {copiedField ===
                                        'Settlement SHA-256 digest' ? (
                                            <Check className="size-3 text-emerald-400" />
                                        ) : (
                                            <Copy className="size-3 opacity-70" />
                                        )}
                                    </button>
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Footer Actions */}
                    <div className="flex flex-wrap items-center justify-between gap-2 border-t border-[var(--clay-border)] pt-3 print:hidden">
                        <p className="text-[11px] text-[var(--clay-text-muted)]">
                            Immutable Arc Testnet settlement receipt &middot;
                            OpenSinop Finance
                        </p>
                        <div className="flex items-center gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={handleDownload}
                                className="h-9 gap-1.5 rounded-full border-[var(--clay-border)] bg-transparent px-4 text-xs font-semibold"
                            >
                                <Download className="size-3.5" />
                                Download .txt
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={handlePrint}
                                className="h-9 gap-1.5 rounded-full border-[var(--clay-border)] bg-transparent px-4 text-xs font-semibold"
                            >
                                <Printer className="size-3.5" />
                                Print e-Receipt
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                onClick={() => onOpenChange(false)}
                                className="h-9 rounded-full bg-[var(--clay-primary)] px-5 text-xs font-semibold text-[var(--clay-primary-foreground)] hover:bg-[var(--clay-primary-bright)]"
                            >
                                Done
                            </Button>
                        </div>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
