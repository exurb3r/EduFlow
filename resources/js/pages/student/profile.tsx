import { Head, Link } from '@inertiajs/react';
import {
    ArrowUpRight,
    Check,
    ChevronRight,
    CircleAlert,
    ClipboardCopy,
    GraduationCap,
    ShieldCheck,
    Sparkles,
    Wallet,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { toast } from 'sonner';

import { formatSubmittedAt, formatUsdc } from '@/lib/financial-aid';
import { index as financialAssistanceIndex } from '@/routes/financial-assistance';
import { index as paymentsIndex } from '@/routes/payments';
import { dashboard, profile as profileRoute } from '@/routes/student';
import type {
    ProfileCheck,
    ProfileTimelineEntry,
    StandingTone,
    StudentProfileProps,
} from '@/types/student-profile';

/*
 | Student profile — "Midnight Clay".
 |
 | Built as a record sheet rather than a card grid. The dashboard is a set of
 | floating cards because it is a set of loosely related widgets; a record has
 | an order, so this page is ruled with hairlines and dense label/value rows
 | instead. Far fewer surfaces, far more information per screen.
 |
 | The standing check is the reason the page exists, so it keeps the card
 | treatment — it is the one thing here that deserves to be looked at.
 */

/* -- Tone vocabulary: colour is information, never decoration ----------- */

const toneText: Record<StandingTone, string> = {
    good: 'text-[var(--status-resolved)]',
    watch: 'text-[var(--status-pending)]',
    action: 'text-[var(--status-urgent)]',
    neutral: 'text-[var(--clay-text-muted)]',
};

const toneStroke: Record<StandingTone, string> = {
    good: 'bg-[var(--status-resolved)]',
    watch: 'bg-[var(--status-pending)]',
    action: 'bg-[var(--status-urgent)]',
    neutral: 'bg-[var(--clay-text-faint)]',
};

const toneWash: Record<StandingTone, string> = {
    good: 'bg-[var(--status-resolved)]/10 text-[var(--status-resolved)]',
    watch: 'bg-[var(--status-pending)]/10 text-[var(--status-pending)]',
    action: 'bg-[var(--status-urgent)]/10 text-[var(--status-urgent)]',
    neutral: 'bg-[var(--clay-surface-soft)] text-[var(--clay-text-muted)]',
};

/* -- Standing derivations ------------------------------------------------ */

function enrollmentTone(status: string): StandingTone {
    if (status === 'enrolled') {
        return 'good';
    }

    return status === 'on_leave' ? 'watch' : 'action';
}

function academicTone(status: string): StandingTone {
    switch (status) {
        case 'qualified':
            return 'good';
        case 'probation':
            return 'watch';
        case 'at_risk':
        case 'suspended':
            return 'action';
        default:
            return 'neutral';
    }
}

/** Attendance only reads as "good" against the policy minimum, not in isolation. */
function attendanceTone(rate: number, minimum: number): StandingTone {
    if (rate >= minimum) {
        return 'good';
    }

    return rate >= minimum - 5 ? 'watch' : 'action';
}

function toPercent(value: string): number {
    const parsed = Number.parseFloat(value);

    return Number.isFinite(parsed) ? parsed : 0;
}

function clampPercent(value: number): number {
    return Math.min(100, Math.max(0, value));
}

/* -- Primitives ---------------------------------------------------------- */

/** One ruled line of a record: label in the margin, value on the line. */
function RecordRow({
    label,
    value,
    detail,
    tone = 'neutral',
    children,
}: {
    label: string;
    value: ReactNode;
    detail?: ReactNode;
    tone?: StandingTone;
    children?: ReactNode;
}) {
    return (
        <div className="flex flex-col gap-1.5 py-4 sm:flex-row sm:items-baseline sm:gap-6">
            <dt className="bulletin-eyebrow sm:w-40 sm:shrink-0">{label}</dt>
            <dd className="flex min-w-0 flex-1 flex-col gap-2 sm:flex-row sm:items-baseline sm:justify-between sm:gap-4">
                <span
                    className={`clay-title tabular-nums ${tone === 'neutral' ? '' : toneText[tone]}`}
                >
                    {value}
                </span>
                {detail && <span className="clay-meta">{detail}</span>}
            </dd>
            {children}
        </div>
    );
}

/** Track plus fill. `marker` draws the policy threshold as a notch. */
function Meter({
    value,
    tone,
    marker,
}: {
    value: number;
    tone: StandingTone;
    marker?: number;
}) {
    const filled = clampPercent(value);

    return (
        <div
            role="img"
            aria-label={`${Math.round(filled)} percent`}
            className="clay-inset relative h-1 w-full overflow-hidden rounded-full"
        >
            <div
                className={`h-full rounded-full ${toneStroke[tone]}`}
                style={{ width: `${filled}%` }}
            />
            {marker !== undefined && (
                <span
                    aria-hidden="true"
                    className="absolute top-0 h-full w-px bg-[var(--clay-text-muted)]"
                    style={{ left: `${clampPercent(marker)}%` }}
                />
            )}
        </div>
    );
}

function GateRow({ check, index }: { check: ProfileCheck; index: number }) {
    return (
        <li className="flex items-start gap-3 border-t border-[var(--clay-border)] py-3.5 first:border-t-0 first:pt-0">
            <span
                aria-hidden="true"
                className={`clay-meta mt-0.5 w-4 shrink-0 ${check.passed ? '' : 'text-[var(--status-urgent)]'}`}
            >
                {String(index + 1).padStart(2, '0')}
            </span>

            <span
                aria-hidden="true"
                className={`mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full ${
                    check.passed
                        ? 'bg-[var(--status-resolved)]/12 text-[var(--status-resolved)]'
                        : 'bg-[var(--status-urgent)]/12 text-[var(--status-urgent)]'
                }`}
            >
                {check.passed ? (
                    <Check className="size-2.5" />
                ) : (
                    <CircleAlert className="size-2.5" />
                )}
            </span>

            <div className="min-w-0 flex-1">
                <p className="text-sm font-semibold text-[var(--clay-ink)]">
                    {check.label}
                </p>
                {check.hint && (
                    <p className="clay-body mt-0.5 text-[var(--clay-text-faint)]">
                        {check.hint}
                    </p>
                )}
            </div>
        </li>
    );
}

const timelineToneIcon = {
    good: Check,
    watch: Sparkles,
    action: CircleAlert,
    neutral: GraduationCap,
} as const;

export default function StudentProfile({
    student,
    organization,
    term,
    standing,
    tuition,
    wallet,
    eligibility,
    assistance,
    timeline,
    currency,
}: StudentProfileProps) {
    const [gatesVisible, setGatesVisible] = useState(true);

    const attendance = toPercent(standing.attendance_rate);
    const attendanceMinimum = toPercent(standing.min_attendance_rate);
    const attendanceState = attendanceTone(attendance, attendanceMinimum);
    const enrollmentState = enrollmentTone(standing.enrollment_status);
    const academicState = academicTone(standing.academic_status);

    const passedGates = eligibility.checks.filter(
        (check) => check.passed,
    ).length;
    const blockedGates = eligibility.checks.length - passedGates;
    const firstBlocker = eligibility.checks.find((check) => !check.passed);

    const tuitionBilled = toPercent(tuition.total_amount);
    const tuitionSettled =
        tuitionBilled > 0
            ? clampPercent(
                  (toPercent(tuition.paid_amount) / tuitionBilled) * 100,
              )
            : 0;
    const hasWallet = Boolean(wallet.address);

    const copyWallet = () => {
        if (!wallet.address) {
            return;
        }

        navigator.clipboard.writeText(wallet.address);
        toast.info('Wallet address copied');
    };

    return (
        <>
            <Head title="Student profile" />

            <div className="bulletin clay-ambient min-h-full w-full">
                <div className="mx-auto flex w-full max-w-5xl flex-col px-4 py-6 sm:px-6 sm:py-10 lg:px-8 lg:py-14">
                    {/* -- Masthead: the name is the page title, so it gets the
                            display cut rather than an avatar disc ---------- */}
                    <header>
                        <div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-2">
                            <p className="bulletin-eyebrow flex items-center gap-2">
                                <GraduationCap className="size-3.5" />
                                {organization.name}
                            </p>
                            <p className="clay-meta">
                                Record updated{' '}
                                {formatSubmittedAt(eligibility.evaluated_at)}
                            </p>
                        </div>

                        <div className="mt-4 flex flex-col gap-x-8 gap-y-5 border-t border-[var(--clay-border)] pt-5 lg:flex-row lg:items-end lg:justify-between">
                            <div className="min-w-0">
                                <h1 className="clay-h1 break-words">
                                    {student.name}
                                </h1>
                                <p className="clay-body mt-2 max-w-md">
                                    {student.program}
                                    <span className="text-[var(--clay-text-faint)]">
                                        {' · '}
                                    </span>
                                    Year {student.year_level}
                                    <span className="text-[var(--clay-text-faint)]">
                                        {' · '}
                                    </span>
                                    {term.name}
                                </p>
                            </div>

                            <div className="flex shrink-0 flex-wrap items-center gap-2">
                                <span
                                    className={`inline-flex items-center gap-2 rounded-sm px-2.5 py-1.5 clay-label ${toneWash[enrollmentState]}`}
                                >
                                    <span
                                        aria-hidden="true"
                                        className={`size-1.5 rounded-full ${toneStroke[enrollmentState]}`}
                                    />
                                    {standing.enrollment_label}
                                </span>
                                <span
                                    className={`inline-flex items-center gap-2 rounded-sm px-2.5 py-1.5 clay-label ${toneWash[academicState]}`}
                                >
                                    <span
                                        aria-hidden="true"
                                        className={`size-1.5 rounded-full ${toneStroke[academicState]}`}
                                    />
                                    {standing.academic_label}
                                </span>
                            </div>
                        </div>

                        {/* Identification strip — mono, ruled, scannable */}
                        <dl className="clay-inset mt-6 grid grid-cols-2 divide-x divide-y divide-[var(--clay-border)] sm:grid-cols-4 sm:divide-y-0">
                            {[
                                {
                                    label: 'Student no.',
                                    value: student.student_number,
                                },
                                {
                                    label: 'Credits',
                                    value: `${standing.credits_registered}/${standing.credits_required}`,
                                },
                                { label: 'GPA', value: standing.gpa ?? '—' },
                                { label: 'Email', value: student.email ?? '—' },
                            ].map((cell) => (
                                <div
                                    key={cell.label}
                                    className="min-w-0 px-3.5 py-3"
                                >
                                    <dt className="bulletin-eyebrow">
                                        {cell.label}
                                    </dt>
                                    <dd className="clay-meta mt-1 truncate text-[var(--clay-text)]">
                                        {cell.value}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </header>

                    {/* -- Academic record ------------------------------------ */}
                    <section
                        aria-labelledby="record-heading"
                        className="mt-12 sm:mt-16"
                    >
                        <div className="flex items-baseline justify-between gap-4 border-b border-[var(--clay-border)] pb-3">
                            <h2 id="record-heading" className="clay-h2">
                                Academic record
                            </h2>
                            <p className="clay-meta">
                                {term.name} · {term.progress}% elapsed
                            </p>
                        </div>

                        <dl className="divide-y divide-[var(--clay-border)]">
                            <RecordRow
                                label="Enrollment"
                                value={standing.enrollment_label}
                                tone={enrollmentState}
                                detail={standing.academic_note}
                            />
                            <RecordRow
                                label="Standing"
                                value={standing.academic_label}
                                tone={academicState}
                                detail={standing.academic_note}
                            />
                            <RecordRow
                                label="Attendance"
                                value={`${attendance.toFixed(2)}%`}
                                tone={attendanceState}
                                detail={`min ${attendanceMinimum.toFixed(2)}%`}
                            >
                                <div className="sm:basis-full sm:pt-2">
                                    <Meter
                                        value={attendance}
                                        tone={attendanceState}
                                        marker={attendanceMinimum}
                                    />
                                </div>
                            </RecordRow>
                            <RecordRow
                                label="Term progress"
                                value={`${term.progress}%`}
                                detail={`${formatSubmittedAt(term.starts_on)} → ${formatSubmittedAt(term.ends_on)}`}
                            >
                                <div className="sm:basis-full sm:pt-2">
                                    <Meter
                                        value={term.progress}
                                        tone="neutral"
                                    />
                                </div>
                            </RecordRow>
                        </dl>
                    </section>

                    {/* -- Standing check: the one surface worth a card ------- */}
                    <section
                        aria-labelledby="eligibility-heading"
                        className="mt-12 sm:mt-16"
                    >
                        <div className="flex items-baseline justify-between gap-4 border-b border-[var(--clay-border)] pb-3">
                            <h2 id="eligibility-heading" className="clay-h2">
                                Standing check
                            </h2>
                            <button
                                type="button"
                                onClick={() =>
                                    setGatesVisible((value) => !value)
                                }
                                aria-expanded={gatesVisible}
                                className="clay-meta clay-focus -my-2 py-2 transition-colors hover:text-[var(--clay-text)]"
                            >
                                {gatesVisible ? 'Hide' : 'Show'}{' '}
                                {eligibility.checks.length} gates
                            </button>
                        </div>

                        <div className="clay-card mt-5 overflow-hidden">
                            <div className="grid gap-0 lg:grid-cols-5">
                                {/* Verdict */}
                                <div className="flex flex-col p-5 sm:p-7 lg:col-span-2 lg:border-r lg:border-[var(--clay-border)]">
                                    <p className="bulletin-eyebrow">
                                        Autonomous assistance
                                    </p>

                                    {eligibility.eligible ? (
                                        <>
                                            <p className="bulletin-figure mt-4">
                                                {formatUsdc(
                                                    eligibility.eligible_amount_base_units,
                                                )}
                                            </p>
                                            <p className="clay-meta mt-1.5">
                                                payable without human review
                                            </p>
                                        </>
                                    ) : (
                                        <>
                                            <p className="bulletin-figure mt-4 text-[var(--status-urgent)]">
                                                {blockedGates}
                                                <span className="text-base text-[var(--clay-text-faint)]">
                                                    {' '}
                                                    gate
                                                    {blockedGates === 1
                                                        ? ''
                                                        : 's'}{' '}
                                                    blocking
                                                </span>
                                            </p>
                                            <p className="clay-body mt-3">
                                                {firstBlocker?.label ? (
                                                    <>
                                                        Blocked by{' '}
                                                        <span className="text-[var(--clay-text)]">
                                                            {firstBlocker.label}
                                                        </span>
                                                        . These are re-checked
                                                        automatically — there is
                                                        nothing to file.
                                                    </>
                                                ) : (
                                                    'These are re-checked automatically — there is nothing to file.'
                                                )}
                                            </p>
                                        </>
                                    )}

                                    <div className="mt-auto flex flex-col items-start gap-3 pt-7">
                                        <Link
                                            href={
                                                eligibility.eligible
                                                    ? financialAssistanceIndex()
                                                    : dashboard()
                                            }
                                            className="clay-focus inline-flex h-11 items-center gap-2 rounded-full bg-[var(--clay-primary)] px-5 text-sm font-semibold text-[var(--clay-primary-foreground)] transition-colors hover:bg-[var(--clay-primary-bright)]"
                                        >
                                            {eligibility.eligible
                                                ? 'Start a request'
                                                : 'Back to dashboard'}
                                            <ChevronRight className="size-4" />
                                        </Link>
                                        <p className="clay-meta">
                                            {passedGates}/
                                            {eligibility.checks.length} gates
                                            pass
                                            {eligibility.policy_version &&
                                                ` · ${eligibility.policy_version}`}
                                        </p>
                                    </div>
                                </div>

                                {/* Gates */}
                                <div className="p-5 sm:p-7 lg:col-span-3">
                                    <p className="bulletin-eyebrow">
                                        Deterministic gates
                                    </p>

                                    {gatesVisible ? (
                                        <ul className="mt-4">
                                            {eligibility.checks.map(
                                                (check, index) => (
                                                    <GateRow
                                                        key={check.key}
                                                        check={check}
                                                        index={index}
                                                    />
                                                ),
                                            )}
                                        </ul>
                                    ) : (
                                        <p className="clay-body mt-4">
                                            {passedGates} of{' '}
                                            {eligibility.checks.length} gates
                                            pass. Reveal them to see which rule
                                            decided the verdict.
                                        </p>
                                    )}

                                    <p className="clay-meta mt-5 border-t border-[var(--clay-border)] pt-4">
                                        A preview, not an approval — any
                                        disbursement still follows school
                                        review.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </section>

                    {/* -- Financial position -------------------------------- */}
                    <section
                        aria-labelledby="financial-heading"
                        className="mt-12 sm:mt-16"
                    >
                        <div className="flex items-baseline justify-between gap-4 border-b border-[var(--clay-border)] pb-3">
                            <h2 id="financial-heading" className="clay-h2">
                                Financial position
                            </h2>
                            {currency && (
                                <p className="clay-meta truncate">
                                    {currency.rate_description}
                                </p>
                            )}
                        </div>

                        <dl className="divide-y divide-[var(--clay-border)]">
                            <RecordRow
                                label="Tuition outstanding"
                                value={formatUsdc(tuition.remaining_amount)}
                                detail={tuition.remaining_amount_fiat}
                                tone={
                                    tuitionSettled >= 100 ? 'good' : 'neutral'
                                }
                            >
                                <div className="sm:basis-full sm:pt-2">
                                    <Meter
                                        value={tuitionSettled}
                                        tone={
                                            tuitionSettled >= 100
                                                ? 'good'
                                                : 'neutral'
                                        }
                                    />
                                    <p className="clay-meta mt-1.5">
                                        {formatUsdc(tuition.paid_amount)} of{' '}
                                        {formatUsdc(tuition.total_amount)}{' '}
                                        settled
                                        {tuition.due_on &&
                                            ` · next due ${formatSubmittedAt(tuition.due_on)}`}
                                    </p>
                                </div>
                            </RecordRow>
                            <RecordRow
                                label="Assistance received"
                                value={formatUsdc(
                                    assistance.received_base_units,
                                )}
                                detail={`cap ${formatUsdc(assistance.semester_cap_base_units)}`}
                            />
                            <RecordRow
                                label="Automatic limit"
                                value={formatUsdc(
                                    assistance.auto_limit_base_units,
                                )}
                                detail={`per request · ${assistance.request_count} filed`}
                            />
                            <RecordRow
                                label="Assistance fund"
                                value={formatUsdc(
                                    assistance.fund_balance_base_units,
                                )}
                                detail="school-wide"
                            />
                        </dl>

                        <div className="mt-6 flex flex-wrap gap-x-6 gap-y-3">
                            <Link
                                href={paymentsIndex()}
                                className="clay-focus inline-flex -my-1.5 items-center gap-1.5 py-1.5 text-sm text-[var(--clay-text-muted)] transition-colors hover:text-[var(--clay-text)]"
                            >
                                Payout ledger
                                <ArrowUpRight className="size-3.5" />
                            </Link>
                            <Link
                                href={financialAssistanceIndex()}
                                className="clay-focus inline-flex -my-1.5 items-center gap-1.5 py-1.5 text-sm text-[var(--clay-text-muted)] transition-colors hover:text-[var(--clay-text)]"
                            >
                                Assistance history
                                <ArrowUpRight className="size-3.5" />
                            </Link>
                        </div>
                    </section>

                    {/* -- Payout wallet -------------------------------------- */}
                    <section
                        aria-labelledby="wallet-heading"
                        className="mt-12 sm:mt-16"
                    >
                        <div className="border-b border-[var(--clay-border)] pb-3">
                            <h2 id="wallet-heading" className="clay-h2">
                                Payout wallet
                            </h2>
                        </div>

                        <div className="flex flex-col gap-4 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex min-w-0 items-center gap-3.5">
                                <span className="clay-icon-chip size-10 shrink-0">
                                    <Wallet className="size-4" />
                                </span>
                                <div className="min-w-0">
                                    <p className="clay-title">
                                        {hasWallet
                                            ? 'Arc payout address'
                                            : 'No wallet linked'}
                                    </p>
                                    <p className="clay-meta mt-0.5 truncate">
                                        {hasWallet
                                            ? wallet.address
                                            : 'Approved assistance needs a destination address.'}
                                    </p>
                                </div>
                            </div>

                            {hasWallet ? (
                                <button
                                    type="button"
                                    onClick={copyWallet}
                                    className="clay-focus inline-flex shrink-0 items-center gap-2 rounded-full border border-[var(--clay-border)] px-4 py-2.5 text-xs font-semibold text-[var(--clay-text-muted)] transition-colors hover:bg-[var(--clay-surface-soft)] hover:text-[var(--clay-text)]"
                                >
                                    <ClipboardCopy className="size-3.5" />
                                    Copy address
                                </button>
                            ) : (
                                <Link
                                    href={financialAssistanceIndex()}
                                    className="clay-focus inline-flex shrink-0 items-center gap-2 rounded-full bg-[var(--clay-primary)] px-4 py-2.5 text-xs font-semibold text-[var(--clay-primary-foreground)] transition-colors hover:bg-[var(--clay-primary-bright)]"
                                >
                                    Link a wallet
                                    <ChevronRight className="size-3.5" />
                                </Link>
                            )}
                        </div>
                    </section>

                    {/* -- History: a ruled ledger, not a stack of cards ------ */}
                    <section
                        aria-labelledby="timeline-heading"
                        className="mt-12 sm:mt-16"
                    >
                        <div className="flex items-baseline justify-between gap-4 border-b border-[var(--clay-border)] pb-3">
                            <h2 id="timeline-heading" className="clay-h2">
                                History
                            </h2>
                            <p className="clay-meta">
                                {timeline.length} entries
                            </p>
                        </div>

                        {timeline.length === 0 ? (
                            <p className="clay-body py-6">
                                Nothing recorded yet. Standing changes, term
                                events and payouts land here as EduFlow writes
                                them.
                            </p>
                        ) : (
                            <ol className="relative mt-6">
                                {timeline.map(
                                    (entry: ProfileTimelineEntry, index) => {
                                        const isLast =
                                            index === timeline.length - 1;
                                        const Icon =
                                            timelineToneIcon[entry.tone];

                                        return (
                                            <li
                                                key={entry.id}
                                                className="clay-rise relative pb-7 pl-7 last:pb-0 sm:pl-9"
                                                style={
                                                    {
                                                        '--stagger': Math.min(
                                                            index,
                                                            8,
                                                        ),
                                                    } as React.CSSProperties
                                                }
                                            >
                                                {/* Spine */}
                                                {!isLast && (
                                                    <span
                                                        aria-hidden="true"
                                                        className="absolute left-[0.3125rem] top-4 bottom-0 w-px bg-[var(--clay-border)] sm:left-[0.4375rem]"
                                                    />
                                                )}
                                                <span
                                                    aria-hidden="true"
                                                    className={`absolute left-0 top-1 flex size-2.5 items-center justify-center rounded-full ring-4 ring-[var(--clay-bg)] sm:size-3 ${toneStroke[entry.tone]}`}
                                                />

                                                <div className="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:gap-4">
                                                    <time
                                                        dateTime={entry.at}
                                                        className="clay-meta shrink-0 sm:w-28"
                                                    >
                                                        {formatSubmittedAt(
                                                            entry.at,
                                                        )}
                                                    </time>

                                                    <div className="min-w-0 flex-1">
                                                        <p className="clay-title flex items-start gap-2">
                                                            <Icon
                                                                aria-hidden="true"
                                                                className={`mt-0.5 size-3.5 shrink-0 ${toneText[entry.tone]}`}
                                                            />
                                                            <span>
                                                                {entry.title}
                                                            </span>
                                                        </p>
                                                        <p className="clay-body mt-1">
                                                            {entry.detail}
                                                        </p>
                                                    </div>
                                                </div>
                                            </li>
                                        );
                                    },
                                )}
                            </ol>
                        )}
                    </section>

                    {/* -- Footnote ------------------------------------------- */}
                    <p className="clay-meta mt-12 flex flex-wrap items-center gap-2 border-t border-[var(--clay-border)] pt-6 sm:mt-16">
                        <ShieldCheck className="size-3.5" />
                        {student.enrolled_at &&
                            `Enrolled ${formatSubmittedAt(student.enrolled_at)} · `}
                        Figures are USDC base units as recorded by EduFlow.
                    </p>
                </div>
            </div>
        </>
    );
}

StudentProfile.layout = {
    breadcrumbs: [{ title: 'Student profile', href: profileRoute() }],
};
