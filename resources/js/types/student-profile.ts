/**
 * Shapes for the student profile page (`student/profile`).
 *
 * Amounts are USDC base units expressed as strings so they never pass through
 * `Number` — see `formatUsdc` in `@/lib/financial-aid`. The `eligibility` block
 * deliberately mirrors the `StudentDashboardProps['eligibility']` shape produced
 * by `PreviewStudentEligibility`, so the same preview can back both pages.
 */

export type StandingTone = 'good' | 'watch' | 'action' | 'neutral';

export type ProfileCheck = {
    key: string;
    label: string;
    passed: boolean;
    /** Why this gate exists, in student language rather than policy language. */
    hint?: string;
};

export type ProfileTimelineEntry = {
    id: string;
    at: string;
    title: string;
    detail: string;
    tone: StandingTone;
    kind: 'academic' | 'tuition' | 'assistance';
};

export type StudentProfileProps = {
    student: {
        name: string;
        email?: string;
        student_number: string;
        program: string;
        year_level: number;
        /** Dates are ISO-8601 so the page can format them once, client-side. */
        enrolled_at?: string;
    };
    organization: {
        name: string;
    };
    term: {
        name: string;
        starts_on: string;
        ends_on: string;
        /** Whole percent of the term already elapsed, 0-100. */
        progress: number;
    };
    standing: {
        enrollment_status: string;
        enrollment_label: string;
        academic_status: string;
        academic_label: string;
        academic_note: string;
        /** Percentage strings ("92.40") so precision is never truncated. */
        attendance_rate: string;
        min_attendance_rate: string;
        credits_registered: number;
        credits_required: number;
        gpa: string | null;
    };
    tuition: {
        term: string;
        total_amount: string;
        paid_amount: string;
        remaining_amount: string;
        remaining_amount_fiat?: string;
        due_on: string | null;
    };
    wallet: {
        address: string | null;
    };
    /** Same contract as the dashboard preview — a preview, never an approval. */
    eligibility: {
        eligible: boolean;
        eligible_amount_base_units: string;
        policy_version: string | null;
        checks: ProfileCheck[];
        evaluated_at: string;
    };
    assistance: {
        currency: string;
        auto_limit_base_units: string;
        semester_cap_base_units: string;
        received_base_units: string;
        fund_balance_base_units: string;
        request_count: number;
    };
    timeline: ProfileTimelineEntry[];
    currency?: {
        display: string;
        symbol: string;
        rate_description: string;
    };
};
