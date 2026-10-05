import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Plus, Sparkles } from 'lucide-react';
import { AskEduFlow } from '@/components/ask-eduflow';
import {
    AidHeading,
    AidPage,
    PaymentsUnavailable,
    RequestStatus,
} from '@/components/financial-aid';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    formatAidLabel,
    formatSubmittedAt,
    formatUsdc,
} from '@/lib/financial-aid';
import { create, show } from '@/routes/assistance';
import { dashboard } from '@/routes/student';
import type { StudentDashboardProps } from '@/types/financial-aid';

export default function StudentDashboard({
    student,
    tuitionAccount,
    requests,
    canRequest,
    currency,
    suggestedQuestions,
}: StudentDashboardProps) {
    return (
        <>
            <Head title="Student dashboard" />
            <AidPage>
                <AidHeading
                    title={`Welcome, ${student.name}.`}
                    description="Your tuition, your requests, and a clear view of what comes next."
                >
                    {canRequest && (
                        <Button
                            asChild
                            className="shrink-0 bg-amber-600 text-white hover:bg-amber-700 dark:bg-amber-600"
                        >
                            <Link href={create()}>
                                <Plus aria-hidden="true" />
                                Request assistance
                            </Link>
                        </Button>
                    )}
                </AidHeading>

                <dl className="grid grid-cols-2 gap-6 border-y py-5 text-sm sm:grid-cols-3">
                    <div className="space-y-1">
                        <dt className="text-muted-foreground">
                            Student number
                        </dt>
                        <dd className="font-medium break-words">
                            {student.student_number}
                        </dd>
                    </div>
                    <div className="space-y-1">
                        <dt className="text-muted-foreground">Program</dt>
                        <dd className="font-medium break-words">
                            {student.program}
                        </dd>
                    </div>
                    <div className="space-y-1">
                        <dt className="text-muted-foreground">Year level</dt>
                        <dd className="font-medium">{student.year_level}</dd>
                    </div>
                </dl>

                {/* Tuition Overview Card with Dual-Currency Display */}
                <section
                    aria-labelledby="tuition-heading"
                    className="overflow-hidden rounded-2xl border bg-card"
                >
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b px-6 py-4">
                        <div className="flex items-center gap-2">
                            <h2 id="tuition-heading" className="font-medium">
                                Tuition overview
                            </h2>
                            {currency && (
                                <Badge
                                    variant="outline"
                                    className="text-[11px] font-normal text-muted-foreground"
                                >
                                    {currency.rate_description}
                                </Badge>
                            )}
                        </div>
                        {tuitionAccount && (
                            <p className="text-sm text-muted-foreground">
                                {tuitionAccount.term}
                            </p>
                        )}
                    </div>
                    {tuitionAccount ? (
                        <dl className="grid md:grid-cols-2">
                            <div className="flex flex-col justify-center gap-2 bg-emerald-50/70 p-6 sm:p-8 dark:bg-emerald-950/30">
                                <dt className="text-sm text-emerald-900 dark:text-emerald-200">
                                    Remaining balance
                                </dt>
                                <dd className="font-serif text-3xl tracking-tight break-words text-emerald-950 tabular-nums sm:text-4xl dark:text-emerald-100">
                                    {formatUsdc(
                                        tuitionAccount.remaining_amount,
                                    )}
                                </dd>
                                {tuitionAccount.remaining_amount_fiat && (
                                    <p className="text-xs font-medium text-emerald-900/80 dark:text-emerald-200/80">
                                        ≈ {tuitionAccount.remaining_amount_fiat}
                                    </p>
                                )}
                            </div>
                            <div className="grid content-center gap-6 p-6 sm:p-8">
                                <div className="flex flex-wrap justify-between gap-2">
                                    <dt className="text-sm text-muted-foreground">
                                        Total tuition
                                    </dt>
                                    <dd className="font-medium break-all tabular-nums">
                                        {formatUsdc(
                                            tuitionAccount.total_amount,
                                        )}
                                        {tuitionAccount.total_amount_fiat && (
                                            <span className="ml-2 text-xs font-normal text-muted-foreground">
                                                (≈{' '}
                                                {
                                                    tuitionAccount.total_amount_fiat
                                                }
                                                )
                                            </span>
                                        )}
                                    </dd>
                                </div>
                                <div className="flex flex-wrap justify-between gap-2 border-t pt-5">
                                    <dt className="text-sm text-muted-foreground">
                                        Amount paid
                                    </dt>
                                    <dd className="font-medium break-all tabular-nums">
                                        {formatUsdc(tuitionAccount.paid_amount)}
                                        {tuitionAccount.paid_amount_fiat && (
                                            <span className="ml-2 text-xs font-normal text-muted-foreground">
                                                (≈{' '}
                                                {
                                                    tuitionAccount.paid_amount_fiat
                                                }
                                                )
                                            </span>
                                        )}
                                    </dd>
                                </div>
                            </div>
                        </dl>
                    ) : (
                        <div className="space-y-2 p-8">
                            <p className="font-serif text-xl">
                                No tuition account available yet.
                            </p>
                            <p className="text-sm text-muted-foreground">
                                Your term and balance will appear here when an
                                account is available.
                            </p>
                        </div>
                    )}
                </section>

                {/* Interactive Ask EduFlow AI Component */}
                <AskEduFlow
                    suggestedQuestions={suggestedQuestions}
                    displayCurrency={currency?.display}
                    rateDescription={currency?.rate_description}
                />

                {/* Assistance Requests List */}
                <section
                    aria-labelledby="requests-heading"
                    className="space-y-4"
                >
                    <div className="flex items-baseline justify-between gap-4">
                        <h2
                            id="requests-heading"
                            className="font-serif text-2xl"
                        >
                            Your assistance requests
                        </h2>
                        <span className="text-sm text-muted-foreground">
                            {requests.length} total
                        </span>
                    </div>
                    {!canRequest && (
                        <p className="text-sm text-muted-foreground">
                            New requests are not available for your account
                            right now. You can still view your existing
                            requests.
                        </p>
                    )}
                    {requests.length === 0 ? (
                        <div className="rounded-xl border border-dashed p-8 text-center">
                            <h3 className="font-medium">
                                A place to ask for support.
                            </h3>
                            <p className="mx-auto mt-2 max-w-md text-sm leading-relaxed text-muted-foreground">
                                You have not submitted any assistance requests.
                                When you do, you can follow their status here.
                            </p>
                        </div>
                    ) : (
                        <ul className="divide-y rounded-xl border bg-card">
                            {requests.map((request) => (
                                <li
                                    key={request.id}
                                    className="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div className="min-w-0 space-y-2">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Link
                                                href={show({
                                                    assistanceRequest:
                                                        request.id,
                                                })}
                                                className="inline-flex items-center gap-2 rounded-sm font-medium underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
                                            >
                                                {formatAidLabel(request.type)}{' '}
                                                assistance{' '}
                                                <span className="text-muted-foreground">
                                                    #{request.id}
                                                </span>
                                                <ArrowRight
                                                    aria-hidden="true"
                                                    className="size-4"
                                                />
                                            </Link>

                                            {request.is_split && (
                                                <Badge
                                                    variant="secondary"
                                                    className="gap-1 border border-amber-400 bg-amber-100/60 text-[10px] text-amber-900 dark:border-amber-800 dark:bg-amber-950/60 dark:text-amber-200"
                                                >
                                                    <Sparkles className="size-2.5" />
                                                    Autonomous Split
                                                </Badge>
                                            )}
                                        </div>
                                        <p className="text-xs text-muted-foreground">
                                            Submitted{' '}
                                            <time
                                                dateTime={request.submitted_at}
                                            >
                                                {formatSubmittedAt(
                                                    request.submitted_at,
                                                )}
                                            </time>
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap items-center justify-between gap-4 sm:justify-end">
                                        <div className="text-right">
                                            <span className="text-sm font-medium break-all tabular-nums">
                                                {formatUsdc(
                                                    request.requested_amount,
                                                )}
                                            </span>
                                            {request.is_split && (
                                                <p className="text-[11px] text-muted-foreground">
                                                    {formatUsdc(
                                                        request.auto_approved_amount,
                                                    )}{' '}
                                                    auto · remainder pending
                                                </p>
                                            )}
                                        </div>
                                        <RequestStatus
                                            status={request.status}
                                        />
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
                <PaymentsUnavailable />
            </AidPage>
        </>
    );
}

StudentDashboard.layout = {
    breadcrumbs: [{ title: 'Student dashboard', href: dashboard() }],
};
