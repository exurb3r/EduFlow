export type AssistanceRequestSummary = {
    id: number;
    type: string;
    requested_amount: string;
    status: string;
    submitted_at: string;
    admin_notes?: string | null;
    has_decision?: boolean;
    decision?: string | null;
    is_split?: boolean;
    // Always sent by StudentDashboardController, so the UI can render the real
    // split rather than a hardcoded figure that could contradict the decision.
    auto_approved_amount: string;
    pending_amount: string;
};

export type AiExplanation = {
    has_decision: boolean;
    decision: string;
    decision_label: string;
    decision_color: string;
    policy_code: string;
    explanation: string;
    hardship_synthesis: string;
    hardship_category: string;
    hardship_urgency: string;
    split: {
        requested_usdc: string;
        requested_fiat: string;
        auto_approved_usdc: string;
        auto_approved_fiat: string;
        pending_usdc: string;
        pending_fiat: string;
    };
    checks: Array<{
        name: string;
        passed: boolean;
    }>;
    locked_quote: {
        rate_description: string;
        provider: string;
        quoted_at: string;
        expires_at: string | null;
    };
    transactions: Array<{
        id: number;
        tx_hash: string | null;
        amount: string;
        status: string;
        network: string;
        explorer_url: string | null;
        executed_at: string | null;
    }>;
};

export type AssistanceRequest = AssistanceRequestSummary & {
    reason: string;
    term: string;
    admin_notes?: string | null;
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
        remaining_amount_fiat?: string;
        total_amount_fiat?: string;
        paid_amount_fiat?: string;
    } | null;
    requests: AssistanceRequestSummary[];
    canRequest: boolean;
    currency?: {
        display: string;
        symbol: string;
        rate_description: string;
    };
    suggestedQuestions?: string[];
};

export type AskEduFlowQueryResponse = {
    question: string;
    answer: string;
    topic: string;
    suggestedFollowups: string[];
    context: {
        display_currency: string;
        currency_symbol: string;
        units_per_usdc: number;
    };
    answered_at: string;
};
