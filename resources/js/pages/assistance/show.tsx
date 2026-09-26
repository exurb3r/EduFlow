import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import {
    AidHeading,
    AidPage,
    PaymentsUnavailable,
    RequestStatus,
} from '@/components/financial-aid';
import {
    formatAidLabel,
    formatSubmittedAt,
    formatUsdc,
} from '@/lib/financial-aid';
import { dashboard } from '@/routes/student';
import type { AssistanceRequest } from '@/types/financial-aid';

export default function ShowAssistance({
    assistanceRequest: request,
}: {
    assistanceRequest: AssistanceRequest;
}) {
    return (
        <>
            <Head title={`Assistance request #${request.id}`} />
            <AidPage>
                <Link
                    href={dashboard()}
                    className="inline-flex w-fit items-center gap-2 rounded-sm text-sm text-muted-foreground hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
                >
                    <ArrowLeft aria-hidden="true" className="size-4" />
                    Back to your requests
                </Link>
                <AidHeading
                    title={`Request #${request.id}`}
                    description="Your submitted request, kept in one place."
                >
                    <RequestStatus status={request.status} />
                </AidHeading>
                <section
                    aria-labelledby="request-details"
                    className="overflow-hidden rounded-2xl border bg-card"
                >
                    <div className="grid md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                        <div className="space-y-4 bg-emerald-50/70 p-6 sm:p-8 dark:bg-emerald-950/30">
                            <h2
                                id="request-details"
                                className="text-sm font-medium text-emerald-900 dark:text-emerald-200"
                            >
                                Requested assistance
                            </h2>
                            <p className="font-serif text-3xl tracking-tight break-words text-emerald-950 tabular-nums sm:text-4xl dark:text-emerald-100">
                                {formatUsdc(request.requested_amount)}
                            </p>
                            <p className="text-xs text-emerald-900/80 dark:text-emerald-200/80">
                                Requested amount, not an approved award.
                            </p>
                        </div>
                        <dl className="grid gap-5 p-6 text-sm sm:p-8">
                            <div className="space-y-1">
                                <dt className="text-muted-foreground">
                                    Assistance type
                                </dt>
                                <dd className="font-medium">
                                    {formatAidLabel(request.type)}
                                </dd>
                            </div>
                            <div className="space-y-1">
                                <dt className="text-muted-foreground">
                                    Academic term
                                </dt>
                                <dd className="font-medium">{request.term}</dd>
                            </div>
                            <div className="space-y-1">
                                <dt className="text-muted-foreground">
                                    Submitted
                                </dt>
                                <dd className="font-medium">
                                    <time dateTime={request.submitted_at}>
                                        {formatSubmittedAt(
                                            request.submitted_at,
                                        )}
                                    </time>
                                </dd>
                            </div>
                        </dl>
                    </div>
                    <div className="space-y-4 border-t p-6 sm:p-8">
                        <h2 className="font-serif text-xl">
                            Your reason for requesting support
                        </h2>
                        <p className="max-w-3xl text-sm leading-7 whitespace-pre-wrap break-words text-muted-foreground">
                            {request.reason}
                        </p>
                    </div>
                </section>
                <PaymentsUnavailable />
            </AidPage>
        </>
    );
}

ShowAssistance.layout = {
    breadcrumbs: [{ title: 'Student dashboard', href: dashboard() }],
};
