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

            <div className="bulletin bulletin-grain min-h-full w-full">
                <div className="mx-auto flex w-full max-w-3xl flex-col px-5 py-10 sm:px-8 sm:py-14">
                    <header className="flex flex-col gap-5 border-b border-[var(--rule-strong)] pb-8 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p className="bulletin-eyebrow flex items-center gap-2">
                                <Bell className="size-3.5" />
                                EduFlow
                            </p>
                            <h1 className="mt-3 font-serif text-4xl leading-none font-normal tracking-tight sm:text-5xl">
                                Notifications
                            </h1>
                            {unread_count > 0 && (
                                <p className="mt-3 text-sm text-muted-foreground">
                                    <span className="font-mono text-[var(--terracotta)]">
                                        {unread_count}
                                    </span>{' '}
                                    unread of{' '}
                                    <span className="font-mono">{total}</span>
                                </p>
                            )}
                        </div>

                        {unread_count > 0 && (
                            <Button
                                onClick={handleMarkAllRead}
                                className="h-9 gap-2 rounded-[var(--radius)] bg-[var(--terracotta)] px-4 text-xs text-white hover:bg-[var(--terracotta-bright)]"
                            >
                                <Check className="size-3.5" />
                                Mark all as read
                            </Button>
                        )}
                    </header>

                    {data.length === 0 ? (
                        <div className="flex flex-col items-start gap-4 py-16">
                            <Inbox className="size-6 text-[var(--rule-strong)]" />
                            <div>
                                <p className="font-serif text-2xl text-foreground">
                                    Nothing here yet.
                                </p>
                                <p className="mt-1.5 max-w-sm text-sm text-muted-foreground">
                                    When staff reply to one of your tickets you
                                    will find it on this page.
                                </p>
                            </div>
                            <Button
                                variant="outline"
                                className="h-9 rounded-[var(--radius)] px-4 text-xs"
                                asChild
                            >
                                <Link href={dashboard()}>
                                    Back to dashboard
                                </Link>
                            </Button>
                        </div>
                    ) : (
                        <ul className="border-b border-[var(--rule)]">
                            {data.map((notification) => (
                                <li
                                    key={notification.id}
                                    className="bulletin-row flex items-start gap-4 py-4"
                                >
                                    <span
                                        className={`mt-2 size-1.5 shrink-0 rounded-full ${
                                            notification.read_at
                                                ? 'bg-[var(--rule-strong)]'
                                                : 'bg-[var(--terracotta)]'
                                        }`}
                                    />

                                    <div className="min-w-0 flex-1">
                                        <p
                                            className={`text-sm ${
                                                notification.read_at
                                                    ? 'text-muted-foreground'
                                                    : 'font-medium text-foreground'
                                            }`}
                                        >
                                            {title(notification)}
                                        </p>
                                        {body(notification) && (
                                            <p className="mt-0.5 text-sm leading-relaxed text-muted-foreground">
                                                {body(notification)}
                                            </p>
                                        )}
                                        <time className="mt-1.5 block font-mono text-[11px] text-[var(--ink-faint)]">
                                            {new Date(
                                                notification.created_at,
                                            ).toLocaleString()}
                                        </time>
                                    </div>

                                    {!notification.read_at && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="h-7 shrink-0 px-2 text-xs"
                                            onClick={() =>
                                                handleMarkAsRead(
                                                    notification.id,
                                                )
                                            }
                                        >
                                            Mark read
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}

                    {last_page > 1 && (
                        <div className="flex items-center justify-between pt-6 text-xs text-muted-foreground">
                            <span className="font-mono">
                                {from ?? 0}–{to ?? 0} / {total}
                            </span>
                            <div className="flex items-center gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="h-8 rounded-[var(--radius)] text-xs"
                                    disabled={current_page <= 1}
                                    onClick={() => goToPage(current_page - 1)}
                                >
                                    <ChevronLeft className="size-3.5" />
                                    Previous
                                </Button>
                                <span className="font-mono">
                                    {current_page}/{last_page}
                                </span>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="h-8 rounded-[var(--radius)] text-xs"
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
                        <div className="pt-8">
                            <Button variant="ghost" size="sm" asChild>
                                <Link
                                    href={dashboard()}
                                    className="text-xs text-[var(--ink-teal)]"
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
