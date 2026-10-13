<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AssistanceCategory;
use App\Enums\AssistancePriority;
use App\Enums\CurrencyCode;
use App\Models\AssistanceRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CurrencyConverter;
use App\Services\StudentWalletService;
use DateTimeInterface;
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

    public function index(Request $request, CurrencyConverter $converter): Response
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
            'notifications' => DashboardController::buildNotificationSummary($user),
            'finance' => $this->buildFinanceSummary($user, $converter),
        ]);
    }

    /**
     * Financial snapshot for the balance hero and recent-activity rail.
     *
     * Tuition figures are integer base-unit strings formatted at the display
     * edge, mirroring the student dashboard. Users without a linked student
     * record (or without a single current-term account) receive null tuition
     * data instead of an invented balance.
     *
     * @return array{tuitionAccount: array{term: string, total_amount: string, paid_amount: string, held_amount: string, remaining_amount: string, unadjusted_remaining_amount: string, remaining_amount_fiat: string, total_amount_fiat: string, paid_amount_fiat: string, held_amount_fiat: string}|null, wallet: array{address: string|null}, totals: array{confirmed: float, currency: string}, recentTransactions: list<array{id: int, type: string, type_label: string, amount: float, currency: string, status: string, status_label: string, tx_hash: string|null, network: string, executed_at: string|null}>}
     */
    private function buildFinanceSummary(User $user, CurrencyConverter $converter): array
    {
        $displayCurrency = CurrencyCode::tryFrom(strtoupper((string) config('eduflow.display_currency', 'PHP'))) ?? CurrencyCode::PHP;

        $tuitionAccountData = null;
        $student = $user->student()->first();
        if ($student !== null) {
            $accounts = $student->tuitionAccounts()->with('academicTerm')
                ->whereHas('academicTerm', fn ($query) => $query->whereDate('starts_on', '<=', today())->whereDate('ends_on', '>=', today()))->get();
            $account = $accounts->count() === 1 ? $accounts->first() : null;

            if ($account !== null) {
                $totalBase = (int) $account->total_amount;
                $paidBase = (int) $account->paid_amount;
                $heldBase = (int) $account->heldAmount();
                $effectiveRemainingBase = (int) $account->effectiveRemainingAmount();

                $tuitionAccountData = [
                    'term' => $account->academicTerm->name,
                    'total_amount' => (string) $account->total_amount,
                    'paid_amount' => (string) $account->paid_amount,
                    'held_amount' => (string) $heldBase,
                    'remaining_amount' => (string) $effectiveRemainingBase,
                    'unadjusted_remaining_amount' => (string) $account->remainingAmount(),
                    'remaining_amount_fiat' => $converter->formatDual($effectiveRemainingBase, $displayCurrency),
                    'total_amount_fiat' => $converter->formatDual($totalBase, $displayCurrency),
                    'paid_amount_fiat' => $converter->formatDual($paidBase, $displayCurrency),
                    'held_amount_fiat' => $converter->formatDual($heldBase, $displayCurrency),
                ];
            }
        }

        // The wallet is the payout address on the student record, the same one
        // the wallet screen and the disbursement path use.
        $walletAddress = $student !== null
            ? app(StudentWalletService::class)->addressFor($student)
            : null;

        $transactionQuery = Transaction::query()
            ->paidTo($walletAddress)
            ->whereIn('type', ['student_assistance', 'refund']);

        $recentTransactions = (clone $transactionQuery)
            ->latest('executed_at')
            ->limit(5)
            ->get()
            ->map(fn ($transaction): array => [
                'id' => $transaction->id,
                'type' => $transaction->type->value,
                'type_label' => $transaction->type->getLabel(),
                'amount' => (float) $transaction->amount,
                'currency' => $transaction->currency,
                'status' => $transaction->status->value,
                'status_label' => $transaction->status->getLabel(),
                'tx_hash' => $transaction->provider_tx_hash,
                'network' => $transaction->network,
                'executed_at' => $transaction->executed_at?->format('M d, Y h:i A'),
            ])
            ->values()
            ->all();

        return [
            'tuitionAccount' => $tuitionAccountData,
            'wallet' => [
                'address' => $walletAddress,
            ],
            'totals' => [
                'confirmed' => (float) (clone $transactionQuery)->where('status', 'confirmed')->sum('amount'),
                'currency' => 'USDC',
            ],
            'recentTransactions' => $recentTransactions,
        ];
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
    public static function buildNotificationSummary(User $user): array
    {
        $recent = $user->notifications()
            ->latest()
            ->limit(self::RECENT_NOTIFICATIONS)
            ->get()
            ->map(function (DatabaseNotification $notification): array {
                $readAt = $notification->read_at;
                $createdAt = $notification->created_at;

                return [
                    'id' => (string) $notification->getKey(),
                    'title' => (string) ($notification->data['title'] ?? Str::headline(class_basename((string) $notification->type))),
                    'body' => isset($notification->data['message']) ? (string) $notification->data['message'] : null,
                    'readAt' => $readAt instanceof DateTimeInterface ? $readAt->format('M d, Y h:i A') : null,
                    'createdAt' => $createdAt instanceof DateTimeInterface ? $createdAt->format('M d, Y h:i A') : 'Unknown',
                ];
            })
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
