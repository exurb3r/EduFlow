import { LockKeyhole } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { formatAidLabel } from '@/lib/financial-aid';

export function AidPage({ children }: { children: ReactNode }) {
    return (
        <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-8 p-4 py-8 text-foreground sm:p-8 lg:py-12">
            {children}
        </div>
    );
}

export function AidHeading({
    title,
    description,
    children,
}: {
    title: string;
    description: string;
    children?: ReactNode;
}) {
    return (
        <header className="flex flex-col justify-between gap-6 sm:flex-row sm:items-end">
            <div className="max-w-2xl space-y-3">
                <p className="text-xs font-semibold tracking-[0.2em] text-emerald-800 uppercase dark:text-emerald-300">
                    Student financial support
                </p>
                <h1 className="font-serif text-3xl tracking-tight sm:text-4xl">
                    {title}
                </h1>
                <p className="text-sm leading-relaxed text-muted-foreground sm:text-base">
                    {description}
                </p>
            </div>
            {children}
        </header>
    );
}

export function RequestStatus({ status }: { status: string }) {
    return (
        <span className="inline-flex max-w-full items-center gap-2 rounded-full border bg-muted/50 px-3 py-1 text-xs font-medium">
            <span
                aria-hidden="true"
                className="size-1.5 shrink-0 rounded-full bg-current opacity-60"
            />
            {formatAidLabel(status)}
        </span>
    );
}

export function PaymentsUnavailable() {
    return (
        <aside
            className="flex flex-col gap-4 rounded-xl border border-dashed bg-muted/30 p-5 sm:flex-row sm:items-center sm:justify-between"
            aria-label="Payment availability"
        >
            <div className="flex items-start gap-3">
                <LockKeyhole
                    aria-hidden="true"
                    className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                />
                <div className="space-y-1">
                    <h2 className="text-sm font-medium">
                        Payments are disabled
                    </h2>
                    <p
                        id="payment-notice"
                        className="max-w-xl text-sm leading-relaxed text-muted-foreground"
                    >
                        Awaiting evaluation. Submitting a request does not
                        approve assistance or initiate a payment.
                    </p>
                </div>
            </div>
            <Button
                variant="outline"
                disabled
                aria-describedby="payment-notice"
            >
                Payments unavailable
            </Button>
        </aside>
    );
}
