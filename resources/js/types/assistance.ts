export type AssistanceCategoryType =
    | 'academic'
    | 'technical'
    | 'enrollment'
    | 'financial'
    | 'general';
export type AssistancePriorityType = 'low' | 'medium' | 'high' | 'urgent';
export type AssistanceStatusType =
    | 'pending'
    | 'in_progress'
    | 'resolved'
    | 'closed';
export type AssistanceStatusFilter = 'all' | 'active' | 'resolved';

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
    medianResolutionMinutes: number | null;
}

export interface QuickResource {
    title: string;
    description: string;
    url: string | null;
    icon: string;
}

export interface DashboardNotification {
    id: string;
    title: string;
    body: string | null;
    readAt: string | null;
    createdAt: string;
}

export interface DashboardNotificationSummary {
    unreadCount: number;
    recent: DashboardNotification[];
}

export interface OptionItem {
    value: string;
    label: string;
}

export interface PaginatedLink {
    url: string | null;
    label: string;
    active: boolean;
}

/**
 * Mirrors LengthAwarePaginator::toArray(), which Inertia serialises flat —
 * there is no nested `meta`/`links` wrapper for a raw paginator.
 */
export interface Paginated<T> {
    data: T[];
    current_page: number;
    first_page_url: string | null;
    from: number | null;
    last_page: number;
    last_page_url: string | null;
    next_page_url: string | null;
    path: string | null;
    per_page: number;
    prev_page_url: string | null;
    to: number | null;
    total: number;
    links: PaginatedLink[];
}

export interface NotificationRecord {
    id: string;
    type: string;
    data: Record<string, unknown>;
    read_at: string | null;
    created_at: string;
}
