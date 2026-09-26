export type AssistanceRequestSummary = {
    id: number;
    type: string;
    requested_amount: string;
    status: string;
    submitted_at: string;
};

export type AssistanceRequest = AssistanceRequestSummary & {
    reason: string;
    term: string;
};

export type StudentDashboardProps = {
    student: {
        name: string;
        student_number: string;
        program: string;
        year_level: string | number;
    };
    tuitionAccount: {
        term: string;
        total_amount: string;
        paid_amount: string;
        remaining_amount: string;
    } | null;
    requests: AssistanceRequestSummary[];
    canRequest: boolean;
};
