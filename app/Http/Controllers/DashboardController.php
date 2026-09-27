<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AssistanceCategory;
use App\Enums\AssistancePriority;
use App\Enums\AssistanceStatus;
use App\Models\AssistanceRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $requests = $user->assistanceRequests()
            ->with('assignee:id,name')
            ->latest()
            ->get()
            ->map(fn (AssistanceRequest $req): array => [
                'id' => $req->id,
                'ticket_number' => $req->ticket_number,
                'category' => $req->category->value,
                'category_label' => $req->category->getLabel(),
                'priority' => $req->priority->value,
                'priority_label' => $req->priority->getLabel(),
                'status' => $req->status->value,
                'status_label' => $req->status->getLabel(),
                'subject' => $req->subject,
                'description' => $req->description,
                'admin_notes' => $req->admin_notes,
                'assigned_to_name' => $req->assignee?->name,
                'created_at' => $req->created_at?->format('M d, Y h:i A'),
                'resolved_at' => $req->resolved_at?->format('M d, Y h:i A'),
            ]);

        $activeCount = $requests->filter(fn (array $item): bool => in_array($item['status'], [
            AssistanceStatus::PENDING->value,
            AssistanceStatus::IN_PROGRESS->value,
        ], true))->count();

        $resolvedCount = $requests->filter(fn (array $item): bool => in_array($item['status'], [
            AssistanceStatus::RESOLVED->value,
            AssistanceStatus::CLOSED->value,
        ], true))->count();

        $categories = collect(AssistanceCategory::cases())->map(fn (AssistanceCategory $category): array => [
            'value' => $category->value,
            'label' => $category->getLabel(),
        ])->values()->all();

        $priorities = collect(AssistancePriority::cases())->map(fn (AssistancePriority $priority): array => [
            'value' => $priority->value,
            'label' => $priority->getLabel(),
        ])->values()->all();

        return Inertia::render('dashboard', [
            'requests' => $requests,
            'stats' => [
                'activeRequests' => $activeCount,
                'resolvedRequests' => $resolvedCount,
                'totalRequests' => $requests->count(),
            ],
            'categories' => $categories,
            'priorities' => $priorities,
        ]);
    }
}
