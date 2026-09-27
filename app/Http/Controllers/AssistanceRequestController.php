<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AssistanceCategory;
use App\Enums\AssistancePriority;
use App\Enums\AssistanceStatus;
use App\Http\Requests\StoreAssistanceRequestRequest;
use Illuminate\Http\RedirectResponse;

class AssistanceRequestController extends Controller
{
    /**
     * Store a newly created assistance request submitted by the student.
     */
    public function store(StoreAssistanceRequestRequest $request): RedirectResponse
    {
        $assistanceRequest = $request->user()->assistanceRequests()->create([
            'category' => $request->enum('category', AssistanceCategory::class),
            'priority' => $request->enum('priority', AssistancePriority::class),
            'subject' => $request->string('subject')->trim()->toString(),
            'description' => $request->string('description')->trim()->toString(),
            'status' => AssistanceStatus::PENDING,
        ]);

        return back()->with('success', "Assistance ticket {$assistanceRequest->ticket_number} submitted successfully! Our support team will review it shortly.");
    }
}
