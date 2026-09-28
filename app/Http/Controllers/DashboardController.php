<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AssistanceCategory;
use App\Enums\AssistancePriority;
use App\Models\AssistanceRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Status buckets offered by the dashboard filter.
     *
     * @var list<string>
     */
    private const STATUS_FILTERS = ['all', 'active', 'resolved'];

    private const PER_PAGE = 10;

    private const RECENT_NOTIFICATIONS = 5;

    public function index(Request $request): Response
    {
        $user = $request->user();

        $statusFilter = $this->resolveStatusFilter($request->query('status'));
        $search = $this->resolveSearch($request->query('search'));

        $requests = $user->assistanceRequests()
            ->with('assignee:id,name')
            ->when($search !== '', fn (Builder $query): Builder => $query->where(
                fn (Builder $query): Builder => $query
                    ->where('subject', 'like', "%{$search}%")
                    ->orWhere('ticket_number', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
            ))
            ->when($statusFilter === 'active', fn (Builder $query): Builder => $query->active())
            ->when($statusFilter === 'resolved', fn (Builder $query): Builder => $query->resolved())
            ->latest()
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (AssistanceRequest $assistanceRequest): array => $this->presentRequest($assistanceRequest));

        return Inertia::render('dashboard', [
            'requests' => $requests,
            'filters' => [
                'status' => $statusFilter,
                'search' => $search,
            ],
            'stats' => $this->buildStats($user),
            'categories' => collect(AssistanceCategory::cases())->map(fn (AssistanceCategory $category): array => [
                'value' => $category->value,
                'label' => $category->getLabel(),
            ])->values()->all(),
            'priorities' => collect(AssistancePriority::cases())->map(fn (AssistancePriority $priority): array => [
                'value' => $priority->value,
                'label' => $priority->getLabel(),
            ])->values()->all(),
            'quickResources' => $this->buildQuickResources(),
            'notifications' => $this->buildNotificationSummary($user),
        ]);
    }

    /**
     * Aggregate counts are always global so the filter tab labels stay
     * truthful regardless of the active search or status filter.
     *
     * @return array{activeRequests: int, resolvedRequests: int, totalRequests: int, medianResolutionMinutes: int|null}
     */
    private function buildStats(User $user): array
    {
        return [
            'activeRequests' => $user->assistanceRequests()->active()->count(),
            'resolvedRequests' => $user->assistanceRequests()->resolved()->count(),
            'totalRequests' => $user->assistanceRequests()->count(),
            'medianResolutionMinutes' => $this->medianResolutionMinutes($user),
        ];
    }

    /**
     * Median wall-clock minutes between submission and resolution.
     *
     * Only tickets that actually reached a terminal state contribute, so
     * pending tickets never dilute the figure toward zero.
     */
    private function medianResolutionMinutes(User $user): ?int
    {
        $durations = $user->assistanceRequests()
            ->whereNotNull('resolved_at')
            ->get(['created_at', 'resolved_at'])
            ->map(fn (AssistanceRequest $assistanceRequest): int => (int) $assistanceRequest->created_at
                ->diffInMinutes($assistanceRequest->resolved_at))
            ->sort()
            ->values();

        if ($durations->isEmpty()) {
            return null;
        }

        $count = $durations->count();
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return (int) $durations->get($middle);
        }

        return (int) round(($durations->get($middle - 1) + $durations->get($middle)) / 2);
    }

    /**
     * @return list<array{title: string, description: string, url: string|null, icon: string}>
     */
    private function buildQuickResources(): array
    {
        return collect(config('eduflow.resources', []))
            ->filter(fn (mixed $resource): bool => is_array($resource) && filled($resource['title'] ?? null))
            ->map(fn (array $resource): array => [
                'title' => (string) $resource['title'],
                'description' => (string) ($resource['description'] ?? ''),
                'url' => filled($resource['url'] ?? null) ? (string) $resource['url'] : null,
                'icon' => (string) ($resource['icon'] ?? 'link'),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{unreadCount: int, recent: list<array{id: string, title: string, body: string|null, readAt: string|null, createdAt: string}>}
     */
    private function buildNotificationSummary(User $user): array
    {
        $recent = $user->notifications()
            ->latest()
            ->limit(self::RECENT_NOTIFICATIONS)
            ->get()
            ->map(fn (DatabaseNotification $notification): array => [
                'id' => $notification->id,
                'title' => (string) ($notification->data['title'] ?? Str::headline(class_basename($notification->type))),
                'body' => isset($notification->data['message']) ? (string) $notification->data['message'] : null,
                'readAt' => $notification->read_at?->format('M d, Y h:i A'),
                'createdAt' => $notification->created_at->format('M d, Y h:i A'),
            ])
            ->values()
            ->all();

        return [
            'unreadCount' => $user->unreadNotifications()->count(),
            'recent' => $recent,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRequest(AssistanceRequest $assistanceRequest): array
    {
        return [
            'id' => $assistanceRequest->id,
            'ticket_number' => $assistanceRequest->ticket_number,
            'category' => $assistanceRequest->category->value,
            'category_label' => $assistanceRequest->category->getLabel(),
            'priority' => $assistanceRequest->priority->value,
            'priority_label' => $assistanceRequest->priority->getLabel(),
            'status' => $assistanceRequest->status->value,
            'status_label' => $assistanceRequest->status->getLabel(),
            'subject' => $assistanceRequest->subject,
            'description' => $assistanceRequest->description,
            'admin_notes' => $assistanceRequest->admin_notes,
            'assigned_to_name' => $assistanceRequest->assignee?->name,
            'created_at' => $assistanceRequest->created_at?->format('M d, Y h:i A'),
            'resolved_at' => $assistanceRequest->resolved_at?->format('M d, Y h:i A'),
        ];
    }

    private function resolveStatusFilter(mixed $status): string
    {
        return is_string($status) && in_array($status, self::STATUS_FILTERS, true)
            ? $status
            : 'all';
    }

    private function resolveSearch(mixed $search): string
    {
        if (! is_string($search)) {
            return '';
        }

        return Str::limit(trim($search), 100, '');
    }
}
