<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AssistanceRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StudentDashboardController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        if ($request->user()->hasRole('student')) {
            return to_route('student.dashboard');
        }

        if ($request->user()->hasAnyRole(['finance_officer', 'admin', 'super_admin'])) {
            return redirect()->route('filament.finance.resources.assistance-requests.index');
        }

        return app(DashboardController::class)->index($request);
    }

    public function show(Request $request): Response
    {
        abort_unless($request->user()->hasRole('student'), 403);
        $student = $request->user()->student()->firstOrFail();
        $accounts = $student->tuitionAccounts()->with('academicTerm')
            ->whereHas('academicTerm', fn ($query) => $query->whereDate('starts_on', '<=', today())->whereDate('ends_on', '>=', today()))->get();
        $account = $accounts->count() === 1 ? $accounts->first() : null;

        return Inertia::render('student/dashboard', [
            'student' => [
                'name' => $request->user()->name,
                'student_number' => $student->student_number,
                'program' => $student->program,
                'year_level' => $student->year_level,
            ],
            'tuitionAccount' => $account ? [
                'term' => $account->academicTerm->name,
                'total_amount' => (string) $account->total_amount,
                'paid_amount' => (string) $account->paid_amount,
                'remaining_amount' => (string) $account->remainingAmount(),
            ] : null,
            'canRequest' => $account !== null,
            'requests' => $student->assistanceRequests()->latest('id')->get()->map(fn (AssistanceRequest $assistance) => [
                'id' => $assistance->id,
                'type' => $assistance->type,
                'requested_amount' => (string) $assistance->requested_amount,
                'status' => $assistance->status,
                'submitted_at' => $assistance->submitted_at->toIso8601String(),
            ]),
        ]);
    }
}
