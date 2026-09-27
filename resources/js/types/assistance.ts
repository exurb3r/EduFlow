export type AssistanceCategoryType = 'academic' | 'technical' | 'enrollment' | 'financial' | 'general';
export type AssistancePriorityType = 'low' | 'medium' | 'high' | 'urgent';
export type AssistanceStatusType = 'pending' | 'in_progress' | 'resolved' | 'closed';

export interface AssistanceRequestItem {
    id: number;
    ticket_number: string;
    category: AssistanceCategoryType;
    category_label: string;
    priority: AssistancePriorityType;
    priority_label: string;
    status: AssistanceStatusType;
    status_label: string;
    subject: string;
    description: string;
    admin_notes: string | null;
    assigned_to_name: string | null;
    created_at: string;
    resolved_at: string | null;
}

export interface DashboardStats {
    activeRequests: number;
    resolvedRequests: number;
    totalRequests: number;
}

export interface OptionItem {
    value: string;
    label: string;
}
