import { Link, router, usePage } from '@inertiajs/react';
import { Bell, Check, Inbox } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import {
    index as notificationsIndex,
    markAllRead,
    markAsRead,
} from '@/routes/notifications';

const HOVER_CLOSE_DELAY_MS = 160;

function relativeTime(raw: string): string {
    const parsed = new Date(raw).getTime();

    if (Number.isNaN(parsed)) {
        return raw;
    }

    const minutes = Math.round((Date.now() - parsed) / 60000);

    if (minutes < 1) {
        return 'just now';
    }

    if (minutes < 60) {
        return `${minutes}m ago`;
    }

    if (minutes < 60 * 24) {
        return `${Math.round(minutes / 60)}h ago`;
    }

    return `${Math.round(minutes / (60 * 24))}d ago`;
}

export function AppNotificationBell() {
    const { props } = usePage();
    const summary = props.notificationSummary;

    const [open, setOpen] = useState(false);
    const rootRef = useRef<HTMLDivElement>(null);
    const closeTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const cancelClose = () => {
        if (closeTimer.current) {
            clearTimeout(closeTimer.current);
            closeTimer.current = null;
        }
    };

    const scheduleClose = () => {
        cancelClose();
        closeTimer.current = setTimeout(() => {
            setOpen(false);
        }, HOVER_CLOSE_DELAY_MS);
    };

    const openNow = () => {
        cancelClose();
        setOpen(true);
    };

    const toggle = () => {
        if (open) {
            cancelClose();
            setOpen(false);
        } else {
            openNow();
        }
    };

    // Close on outside pointer press and on Escape. The panel is rendered
    // inline (not portaled), so hover-leave works and nothing steals focus.
    useEffect(() => {
        if (!open) {
            return;
        }

        const onPointerDown = (event: PointerEvent) => {
            if (
                rootRef.current &&
                !rootRef.current.contains(event.target as Node)
            ) {
                setOpen(false);
            }
        };

        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };

        document.addEventListener('pointerdown', onPointerDown);
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('pointerdown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [open]);

    if (!summary) {
        return null;
    }

    const reload = () => {
        router.reload({ only: ['notificationSummary'] });
    };

    const markAll = () => {
        router.post(
            markAllRead.url(),
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('All notifications marked as read.');
                    reload();
                },
                onError: () => {
                    toast.error('Could not mark notifications as read.');
                    reload();
                },
            },
        );
    };

    const markOne = (id: string) => {
        router.post(
            markAsRead.url(id),
            {},
            {
                preserveScroll: true,
                onSuccess: reload,
                onError: reload,
            },
        );
    };

    const dismiss = () => {
        cancelClose();
        setOpen(false);
    };

    return (
        <div
            ref={rootRef}
            className="relative flex items-center"
            onMouseEnter={openNow}
            onMouseLeave={scheduleClose}
        >
            <button
                type="button"
                onClick={toggle}
                aria-haspopup="dialog"
                aria-expanded={open}
                aria-label={
                    summary.unreadCount > 0
                        ? `Notifications, ${summary.unreadCount} unread`
                        : 'Notifications'
                }
                className="clay-icon-chip clay-focus relative size-9 bg-[var(--clay-surface-soft)] text-[var(--clay-text-muted)] shadow-[inset_0_1.5px_3px_rgb(var(--clay-shadow-color)/0.14),inset_0_-1px_0_rgb(255_255_255/0.05)] hover:bg-[var(--clay-surface-hi)] hover:text-[var(--clay-text)] aria-expanded:bg-[var(--clay-primary-soft)] aria-expanded:text-[var(--clay-primary-bright)]"
            >
                <Bell className="size-4" />
                {summary.unreadCount > 0 && (
                    <span className="clay-ping absolute -top-0.5 -right-0.5 flex size-4 items-center justify-center rounded-full bg-[var(--clay-primary)] text-[9px] leading-none font-bold text-[var(--clay-primary-foreground)]">
                        {summary.unreadCount > 9 ? '9+' : summary.unreadCount}
                    </span>
                )}
            </button>

            {open && (
                <div
                    role="dialog"
                    aria-label="Notifications"
                    className="absolute top-full right-0 z-50 mt-2 w-[22rem] max-w-[calc(100vw-2rem)] origin-top-right animate-in fade-in-0 slide-in-from-top-1 rounded-[var(--radius)] border border-[var(--clay-border)] bg-[var(--clay-surface)] p-2 shadow-[var(--shadow-lg)] duration-150"
                >
                    <div className="flex items-center justify-between px-2 pt-1 pb-2">
                        <p className="bulletin-eyebrow">Notifications</p>
                        {summary.unreadCount > 0 && (
                            <button
                                type="button"
                                className="clay-ghost clay-focus h-7 px-2"
                                onClick={markAll}
                            >
                                <Check className="size-3" />
                                Mark all read
                            </button>
                        )}
                    </div>

                    {summary.recent.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 px-4 py-8 text-center">
                            <span className="clay-inset flex size-10 items-center justify-center rounded-full">
                                <Inbox className="size-4 text-[var(--clay-text-muted)]" />
                            </span>
                            <p className="clay-body">
                                Nothing yet. Staff replies will land here.
                            </p>
                        </div>
                    ) : (
                        <ul className="flex flex-col gap-0.5">
                            {summary.recent.map((notification) => {
                                const isUnread = notification.readAt === null;

                                return (
                                    <li key={notification.id}>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                if (isUnread) {
                                                    markOne(notification.id);
                                                }
                                                dismiss();
                                            }}
                                            className="clay-focus flex w-full items-start gap-2.5 rounded-[var(--radius-sm)] px-2 py-2.5 text-left transition-colors hover:bg-[var(--clay-surface-soft)]"
                                        >
                                            <span
                                                className={`mt-1.5 size-2 shrink-0 rounded-full ${
                                                    isUnread
                                                        ? 'bg-[var(--clay-primary)] shadow-[0_0_0_3px_var(--clay-primary-soft)]'
                                                        : 'bg-[var(--clay-border)]'
                                                }`}
                                            />
                                            <span className="min-w-0 flex-1">
                                                <span
                                                    className={`block truncate text-sm ${
                                                        isUnread
                                                            ? 'font-semibold text-[var(--clay-ink)]'
                                                            : 'text-[var(--clay-text-muted)]'
                                                    }`}
                                                >
                                                    {notification.title}
                                                </span>
                                                {notification.body && (
                                                    <span className="clay-body mt-0.5 block truncate">
                                                        {notification.body}
                                                    </span>
                                                )}
                                            </span>
                                            <span className="clay-meta shrink-0 pt-0.5">
                                                {relativeTime(
                                                    notification.createdAt,
                                                )}
                                            </span>
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}

                    <div className="mt-1 border-t border-[var(--clay-border)] px-2 pt-2 pb-1">
                        <Link
                            href={notificationsIndex()}
                            onClick={dismiss}
                            className="clay-focus block rounded-full text-xs font-semibold text-[var(--clay-primary-bright)] underline-offset-4 hover:underline"
                        >
                            View all notifications
                        </Link>
                    </div>
                </div>
            )}
        </div>
    );
}
