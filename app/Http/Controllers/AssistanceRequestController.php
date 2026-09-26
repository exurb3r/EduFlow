<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SubmitAssistanceRequest;
use App\Enums\AssistanceCategory;
use App\Enums\AssistancePriority;
use App\Enums\AssistanceStatus;
use App\Http\Requests\StoreAssistanceRequest;
use App\Http\Requests\StoreAssistanceRequestRequest;
use App\Models\AssistanceRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AssistanceRequestController extends Controller
{
    /**
     * Store a newly created assistance ticket from the user dashboard.
     */
    public function store(StoreAssistanceRequestRequest $request): RedirectResponse
    {
        $assistanceRequest = $request->user()->assistanceRequests()->create([
            'category' => $request->enum('category', AssistanceCategory::class),
            'priority' => $request->enum('priority', AssistancePriority::class),
            'subject' => $request->string('subject')->trim()->toString(),
            'description' => $request->string('description')->trim()->toString(),
            'status' => AssistanceStatus::PENDING->value,
        ]);

        return back()->with('success', "Assistance ticket {$assistanceRequest->ticket_number} submitted successfully! Our support team will review it shortly.");
    }

    public function create(Request $request): Response|RedirectResponse
    {
        Gate::authorize('create', AssistanceRequest::class);
        $accounts = $request->user()->student->tuitionAccounts()->with('academicTerm')
            ->whereHas('academicTerm', fn ($query) => $query->whereDate('starts_on', '<=', today())->whereDate('ends_on', '>=', today()))->get();

        if ($accounts->count() !== 1) {
            return to_route('student.dashboard');
        }

        return Inertia::render('assistance/create', [
            'submissionKey' => (string) Str::uuid(),
            'term' => $accounts->first()->academicTerm->name,
        ]);
    }

    public function storeIntake(StoreAssistanceRequest $request, SubmitAssistanceRequest $submit): RedirectResponse
    {
        $assistance = $submit->handle($request->user(), $request->validated());

        return to_route('assistance.show', $assistance);
    }

    public function show(AssistanceRequest $assistanceRequest): Response
    {
        Gate::authorize('view', $assistanceRequest);

        return Inertia::render('assistance/show', [
            'assistanceRequest' => [
                'id' => $assistanceRequest->id,
                'type' => $assistanceRequest->type,
                'requested_amount' => (string) $assistanceRequest->requested_amount,
                'status' => $assistanceRequest->status instanceof \BackedEnum ? $assistanceRequest->status->value : (string) $assistanceRequest->status,
                'submitted_at' => $assistanceRequest->submitted_at?->toIso8601String() ?? $assistanceRequest->created_at?->toIso8601String(),
                'reason' => $assistanceRequest->reason ?? $assistanceRequest->description,
                'term' => $assistanceRequest->academicTerm?->name ?? 'General Term',
            ],
        ]);
    }
}
