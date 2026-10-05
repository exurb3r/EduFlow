import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, ArrowRight } from 'lucide-react';
import {
    AidHeading,
    AidPage,
    PaymentsUnavailable,
} from '@/components/financial-aid';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { requestedAmountError } from '@/lib/financial-aid';
import { create, store } from '@/routes/assistance';
import { dashboard } from '@/routes/student';

export default function CreateAssistance({
    submissionKey,
    term,
}: {
    submissionKey: string;
    term: string;
}) {
    return (
        <>
            <Head title="Request assistance" />
            <AidPage>
                <Link
                    href={dashboard()}
                    className="inline-flex w-fit items-center gap-2 rounded-sm text-sm text-muted-foreground hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
                >
                    <ArrowLeft aria-hidden="true" className="size-4" />
                    Student dashboard
                </Link>
                <AidHeading
                    title="A little support. A way forward."
                    description="Tell us about the emergency affecting your studies. Your request will be submitted for evaluation, not an automatic decision."
                />
                <div className="grid items-start gap-8 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <Form
                        action={store()}
                        className="rounded-2xl border bg-card p-6 sm:p-8"
                        disableWhileProcessing
                    >
                        {({ errors, processing, hasErrors }) => (
                            <div className="space-y-7">
                                <input
                                    type="hidden"
                                    name="submission_key"
                                    value={submissionKey}
                                />
                                <input
                                    type="hidden"
                                    name="type"
                                    value="emergency"
                                />
                                {hasErrors && (
                                    <div
                                        role="alert"
                                        className="space-y-2 rounded-lg border border-destructive/30 bg-destructive/5 p-4 text-sm"
                                    >
                                        <p className="font-medium">
                                            Your request could not be submitted.
                                        </p>
                                        <ul className="list-inside list-disc">
                                            {Object.entries(errors).map(
                                                ([field, error]) => (
                                                    <li key={field}>
                                                        {field ===
                                                            'requested_amount' ||
                                                        field === 'reason' ? (
                                                            <a
                                                                className="underline underline-offset-4"
                                                                href={`#${field}`}
                                                            >
                                                                {error}
                                                            </a>
                                                        ) : (
                                                            error
                                                        )}
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    </div>
                                )}
                                <div className="space-y-2">
                                    <Label htmlFor="requested_amount">
                                        Requested amount{' '}
                                        <span className="text-muted-foreground">
                                            (USDC)
                                        </span>
                                    </Label>
                                    <Input
                                        id="requested_amount"
                                        name="requested_amount"
                                        type="text"
                                        inputMode="decimal"
                                        placeholder="0.00"
                                        required
                                        maxLength={32}
                                        aria-invalid={Boolean(
                                            errors.requested_amount,
                                        )}
                                        aria-describedby="amount-help amount-error"
                                        className="h-12 text-lg tabular-nums"
                                        onChange={(event) =>
                                            event.currentTarget.setCustomValidity(
                                                requestedAmountError(
                                                    event.currentTarget.value,
                                                ),
                                            )
                                        }
                                    />
                                    <p
                                        id="amount-help"
                                        className="text-xs leading-relaxed text-muted-foreground"
                                    >
                                        Up to 1,000,000 USDC. Use a decimal
                                        point and up to 6 decimal places; do not
                                        use commas.
                                    </p>
                                    <InputError
                                        id="amount-error"
                                        message={errors.requested_amount}
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="reason">
                                        How would this assistance help?
                                    </Label>
                                    <textarea
                                        id="reason"
                                        name="reason"
                                        required
                                        minLength={10}
                                        maxLength={2000}
                                        rows={7}
                                        aria-invalid={Boolean(errors.reason)}
                                        aria-describedby="reason-help reason-error"
                                        placeholder="Describe your situation and the expenses you need help with…"
                                        className="w-full resize-y rounded-md border border-input bg-transparent px-3 py-3 text-sm leading-relaxed shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/50 aria-invalid:border-destructive"
                                    />
                                    <p
                                        id="reason-help"
                                        className="text-xs text-muted-foreground"
                                    >
                                        10–2,000 characters. Do not include
                                        passwords, wallet recovery phrases, or
                                        private keys.
                                    </p>
                                    <InputError
                                        id="reason-error"
                                        message={errors.reason}
                                    />
                                </div>
                                <div className="flex flex-col-reverse gap-3 border-t pt-6 sm:flex-row sm:items-center sm:justify-between">
                                    <Button variant="ghost" asChild>
                                        <Link href={dashboard()}>Cancel</Link>
                                    </Button>
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        aria-busy={processing}
                                    >
                                        {processing
                                            ? 'Submitting request…'
                                            : 'Submit for evaluation'}
                                        <ArrowRight
                                            aria-hidden="true"
                                            className="size-4"
                                        />
                                    </Button>
                                </div>
                            </div>
                        )}
                    </Form>
                    <aside
                        className="space-y-6 border-l-2 border-emerald-700/30 pl-6 dark:border-emerald-300/30"
                        aria-label="Request details"
                    >
                        <div className="space-y-2">
                            <p className="text-xs font-semibold tracking-widest text-muted-foreground uppercase">
                                This request
                            </p>
                            <h2 className="font-serif text-2xl">
                                Emergency assistance
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {term}
                            </p>
                        </div>
                        <div className="space-y-2">
                            <h3 className="text-sm font-medium">
                                What happens next?
                            </h3>
                            <p className="text-sm leading-relaxed text-muted-foreground">
                                After submission, you can view your request and
                                its current status from your student dashboard.
                            </p>
                        </div>
                        <p className="text-sm leading-relaxed text-muted-foreground">
                            Submitting does not guarantee approval or funding.
                            No payment is initiated by this form.
                        </p>
                    </aside>
                </div>
                <PaymentsUnavailable />
            </AidPage>
        </>
    );
}

CreateAssistance.layout = {
    breadcrumbs: [
        { title: 'Student dashboard', href: dashboard() },
        { title: 'Request assistance', href: create() },
    ],
};
