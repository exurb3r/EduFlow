import type { Auth } from '@/types/auth';
import type { DashboardNotificationSummary } from '@/types/assistance';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            notificationSummary: DashboardNotificationSummary | null;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
