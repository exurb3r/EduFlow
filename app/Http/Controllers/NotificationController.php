<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): Response
    {
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (DatabaseNotification $notification): array => [
                'id' => $notification->id,
                'type' => $notification->type,
                'data' => $notification->data,
                'read_at' => $notification->read_at?->toISOString(),
                'created_at' => $notification->created_at->toISOString(),
            ]);

        return Inertia::render('notifications', [
            'notifications' => $notifications,
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markAsRead(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $notification = $request->user()
            ->notifications()
            ->find($id);

        if (! $notification) {
            if ($request->hasHeader('X-Inertia')) {
                abort(404);
            }

            return response()->json(['message' => 'Notification not found.'], 404);
        }

        $notification->markAsRead();

        if ($request->hasHeader('X-Inertia')) {
            return back();
        }

        return response()->json(['message' => 'Notification marked as read.']);
    }

    public function markAllAsRead(Request $request): JsonResponse|RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        if ($request->hasHeader('X-Inertia')) {
            return back();
        }

        return response()->json(['message' => 'All notifications marked as read.']);
    }
}
