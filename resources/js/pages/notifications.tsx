import { Head, Link, router } from '@inertiajs/react';
import { Bell, Check, ChevronLeft, ChevronRight, Inbox } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import {
    index as notificationsIndex,
    markAllRead,
    markAsRead,
} from '@/routes/notifications';
import type { NotificationRecord, Paginated } from '@/types';

interface NotificationsProps {
    notifications: Paginated<NotificationRecord>;
    unread_count: number;
}

export default function Notifications({
    notifications,
    unread_count,
}: NotificationsProps) {
    const { data, current_page, from, to, total, last_page } = notifications;

    const goToPage = (page: number) => {
        router.get(
            notificationsIndex.url({ query: { page } }),
            {},
            { preserveScroll: true, replace: true },
        );
    };

    const handleMarkAsRead = (id: string) => {
        router.post(
            markAsRead.url(id),
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    router.reload({
                        only: ['notifications', 'unread_count'],
                    });
                },
                onError: () => {
                    toast.error('Could not mark that notification as read.');
                },
            },
        );
    };

    const handleMarkAllRead = () => {
        router.post(
            markAllRead.url(),
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('All notifications marked as read.');
                    router.reload({ only: ['notifications', 'unread_count'] });
                },
                onError: () => {
                    toast.error('Could not mark notifications as read.');
                },
            },
        );
    };

    const title = (notification: NotificationRecord) =>
        typeof notification.data.title === 'string'
            ? notification.data.title
            : notification.type;

    const body = (notification: NotificationRecord) =>
        typeof notification.data.message === 'string'
            ? notification.data.message
            : null;

    return (
        <>
            <Head title="Notifications" />

            <div className="bulletin clay-ambient min-h-full w-full">
                <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-6 sm:px-6 sm:py-10 lg:px-8 lg:py-12">
                    <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p className="bulletin-eyebrow flex items-center gap-2">
                                <Bell className="size-3.5" />
                                EduFlow
                            </p>
                            <h1 className="clay-h1 mt-2">Notifications</h1>
                            {unread_count > 0 && (
                                <p className="clay-body mt-2">
                                    <span className="clay-meta font-semibold text-[var(--clay-primary-bright)]">
                                        {unread_count}
                                    </span>{' '}
                                    unread of{' '}
                                    <span className="clay-meta">{total}</span>
                                </p>
                            )}
                        </div>

                        {unread_count > 0 && (
                            <Button
                                onClick={handleMarkAllRead}
                                className="clay-focus h-11 gap-2 rounded-full bg-[var(--clay-primary)] px-5 text-xs font-semibold text-[var(--clay-primary-foreground)] shadow-[var(--shadow-cta)] hover:bg-[var(--clay-primary-bright)] sm:h-10"
                            >
                                <Check className="size-3.5" />
                                Mark all as read
                            </Button>
                        )}
                    </header>

                    {data.length === 0 ? (
                        <div
                            className="clay-card clay-rise flex flex-col items-center gap-4 px-6 py-12 text-center sm:items-start sm:text-left"
                            style={{ '--stagger': 0 } as React.CSSProperties}
                        >
                            <span className="clay-inset flex size-12 items-center justify-center rounded-full">
                                <Inbox className="size-5 text-[var(--clay-text-muted)]" />
                            </span>
                            <div>
                                {' '}
                                <p className="clay-h2">Nothing here yet.</p>
                                <p className="clay-body mx-auto mt-1.5 max-w-sm sm:mx-0">
                                    When staff reply to one of your tickets you
                                    will find it on this page.
                                </p>
                            </div>
                            <Button
                                variant="outline"
                                className="clay-focus h-10 rounded-full border-[var(--clay-border)] bg-transparent px-5 text-xs font-semibold shadow-none"
                                asChild
                            >
                                <Link href={dashboard()}>
                                    Back to dashboard
                                </Link>
                            </Button>
                        </div>
                    ) : (
                        <ul className="flex flex-col gap-3">
                            {data.map((notification, index) => (
                                <li
                                    key={notification.id}
                                    className="clay-card clay-rise p-4 sm:p-5"
                                    style={
                                        {
                                            '--stagger': Math.min(index, 8),
                                        } as React.CSSProperties
                                    }
                                >
                                    <div className="flex items-start gap-3">
                                        <span
                                            className={`mt-2 size-2 shrink-0 rounded-full ${
                                                notification.read_at
                                                    ? 'bg-[var(--clay-border)]'
                                                    : 'clay-ping bg-[var(--clay-primary)] shadow-[0_0_0_3px_var(--clay-primary-soft)]'
                                            }`}
                                        />

                                        <div className="min-w-0 flex-1">
                                            <p
                                                className={`text-sm ${
                                                    notification.read_at
                                                        ? 'text-[var(--clay-text-muted)]'
                                                        : 'font-semibold text-[var(--clay-ink)]'
                                                }`}
                                            >
                                                {title(notification)}
                                            </p>
                                            {body(notification) && (
                                                <p className="mt-0.5 text-sm leading-relaxed text-[var(--clay-text-muted)]">
                                                    {body(notification)}
                                                </p>
                                            )}
                                            <time className="clay-meta mt-1.5 block">
                                                {new Date(
                                                    notification.created_at,
                                                ).toLocaleString()}
                                            </time>
                                        </div>

                                        {!notification.read_at && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="clay-focus h-8 shrink-0 rounded-full px-3 text-xs font-semibold"
                                                onClick={() =>
                                                    handleMarkAsRead(
                                                        notification.id,
                                                    )
                                                }
                                            >
                                                Mark read
                                            </Button>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}

                    {last_page > 1 && (
                        <div className="flex flex-wrap items-center justify-between gap-3 text-xs text-[var(--clay-text-muted)]">
                            <span className="clay-meta">
                                {from ?? 0}–{to ?? 0} / {total}
                            </span>
                            <div className="flex items-center gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="clay-focus h-9 rounded-full border-[var(--clay-border)] bg-transparent text-xs font-semibold shadow-none"
                                    disabled={current_page <= 1}
                                    onClick={() => goToPage(current_page - 1)}
                                >
                                    <ChevronLeft className="size-3.5" />
                                    Previous
                                </Button>
                                <span className="clay-meta">
                                    {current_page}/{last_page}
                                </span>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="clay-focus h-9 rounded-full border-[var(--clay-border)] bg-transparent text-xs font-semibold shadow-none"
                                    disabled={current_page >= last_page}
                                    onClick={() => goToPage(current_page + 1)}
                                >
                                    Next
                                    <ChevronRight className="size-3.5" />
                                </Button>
                            </div>
                        </div>
                    )}

                    {data.length > 0 && (
                        <div>
                            <Button variant="ghost" size="sm" asChild>
                                <Link
                                    href={dashboard()}
                                    className="clay-focus rounded-full text-xs font-semibold text-[var(--clay-primary-bright)]"
                                >
                                    Back to dashboard
                                </Link>
                            </Button>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

Notifications.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
        {
            title: 'Notifications',
            href: notificationsIndex(),
        },
    ],
};
