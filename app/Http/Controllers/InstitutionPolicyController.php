<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreInstitutionPolicyRequest;
use App\Jobs\AssessInstitutionPolicy;
use App\Models\InstitutionPolicyAssessment;
use App\Models\InstitutionPolicyVersion;
use App\Models\PaymentIntent;
use App\Services\InstallationInstitution;
use App\Services\InstitutionPolicyDocuments;
use App\Services\InstitutionPolicyWorkspace;
use App\Settings\AiSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class InstitutionPolicyController extends Controller
{
    public function import(Request $request, InstitutionPolicyDocuments $documents): JsonResponse
    {
        Gate::forUser($request->user())->authorize('create', InstitutionPolicyVersion::class);
        $request->validate(['document' => ['required', 'file', 'max:2048', 'extensions:txt,md,pdf', 'mimetypes:text/plain,text/markdown,application/pdf']]);

        return response()->json(['data' => $documents->extract($request->file('document'))])->header('Cache-Control', 'no-store, private');
    }

    public function index(Request $request, InstallationInstitution $institutions, InstitutionPolicyWorkspace $workspace, AiSettings $settings): Response
    {
        Gate::forUser($request->user())->authorize('viewAny', InstitutionPolicyVersion::class);
        $institution = $institutions->require();
        $versions = InstitutionPolicyVersion::query()->where('organization_id', $institution->id)->latest('id')->limit(100)->get();
        $current = $workspace->current();
        $advice = InstitutionPolicyAssessment::query()->where('organization_id', $institution->id)->whereNull('requested_by')->latest('id')->limit(50)->get();

        return Inertia::render('finance/policies/index', ['institution' => ['id' => $institution->id, 'name' => $institution->name],
            'versions' => $versions->map(fn (InstitutionPolicyVersion $version): array => $version->only(['id', 'title', 'created_by', 'content', 'content_digest', 'created_at']))->all(),
            'current' => $current?->only(['institution_policy_version_id', 'publication_digest', 'approved_by']),
            'assessments' => InstitutionPolicyAssessment::query()->where('organization_id', $institution->id)->latest('id')->limit(50)->get(),
            'planAdvice' => $advice,
            'financeUrl' => url('/finance'),
            'queueReady' => in_array(config('queue.connections.'.config('eduflow.background_finance.queue_connection', 'database').'.driver'), ['database', 'redis'], true)
                && (int) config('queue.connections.'.config('eduflow.background_finance.queue_connection', 'database').'.retry_after', 0) > 180,
            'canPublish' => $request->user()->hasAnyRole(['admin', 'super_admin']), 'aiEnabled' => $settings->mayCallProvider()]);
    }

    public function store(StoreInstitutionPolicyRequest $request, InstitutionPolicyWorkspace $workspace): JsonResponse
    {
        $version = $workspace->save($request->user(), $request->validated());

        return response()->json(['data' => $version->only(['id', 'title', 'created_by', 'content', 'content_digest', 'created_at'])])->header('Cache-Control', 'no-store, private');
    }

    public function publish(Request $request, InstitutionPolicyVersion $institutionPolicyVersion, InstitutionPolicyWorkspace $workspace): JsonResponse
    {
        Gate::forUser($request->user())->authorize('publish', $institutionPolicyVersion);
        $data = $request->validate(['expected_digest' => ['required', 'string', 'size:64'], 'expected_current_digest' => ['nullable', 'string', 'size:64'],
            'reason' => ['required', 'string', 'max:1000'], 'attestation' => ['required', 'accepted']]);
        $publication = $workspace->publish($request->user(), $institutionPolicyVersion, $data['expected_digest'], $data['expected_current_digest'] ?? null, $data['reason']);

        return response()->json(['data' => $publication, 'can_execute' => false])->header('Cache-Control', 'no-store, private');
    }

    public function assess(Request $request, InstitutionPolicyVersion $institutionPolicyVersion): JsonResponse
    {
        Gate::forUser($request->user())->authorize('view', $institutionPolicyVersion);
        $data = $request->validate(['kind' => ['required', Rule::in(['authoring', 'examples', 'classification'])], 'request_key' => ['required', 'uuid'],
            'expected_digest' => ['required', 'string', 'size:64'], 'description' => ['nullable', 'string', 'max:6000']]);
        if ($data['expected_digest'] !== $institutionPolicyVersion->content_digest || ! $institutionPolicyVersion->hasValidContent()) {
            throw ValidationException::withMessages(['assessment' => 'Save and select exact intact policy version before testing.']);
        }
        if ($data['kind'] === 'examples' && ($institutionPolicyVersion->content['examples'] ?? []) === []) {
            throw ValidationException::withMessages(['assessment' => 'Add at least one expected example before testing publication readiness.']);
        }
        $connection = (string) config('eduflow.background_finance.queue_connection', 'database');
        if (! in_array(config('queue.connections.'.$connection.'.driver'), ['database', 'redis'], true)
            || (int) config('queue.connections.'.$connection.'.retry_after', 0) <= 180) {
            throw ValidationException::withMessages(['assessment' => 'Policy assessments require a durable queue with retry_after greater than 180 seconds.']);
        }
        $input = ['description' => $data['description'] ?? ''];
        $assessment = InstitutionPolicyAssessment::query()->firstOrCreate(['request_key' => $data['request_key']], [
            'organization_id' => $institutionPolicyVersion->organization_id, 'institution_policy_version_id' => $institutionPolicyVersion->id,
            'requested_by' => $request->user()->id, 'kind' => $data['kind'], 'input' => $input,
            'policy_digest' => $institutionPolicyVersion->content_digest, 'input_digest' => PaymentIntent::digest($input)]);
        if ($assessment->organization_id !== $institutionPolicyVersion->organization_id || $assessment->institution_policy_version_id !== $institutionPolicyVersion->id
            || $assessment->requested_by !== $request->user()->id || $assessment->kind !== $data['kind'] || $assessment->input_digest !== PaymentIntent::digest($input)) {
            throw ValidationException::withMessages(['assessment' => 'Assessment identity belongs to different evidence or user.']);
        }
        if ($assessment->wasRecentlyCreated) {
            try {
                Bus::dispatch(new AssessInstitutionPolicy($assessment->id));
            } catch (Throwable $exception) {
                report($exception);
                $assessment->update(['state' => 'needs_review', 'last_error' => 'Queue unavailable. Retry with a new assessment; no data was evaluated.', 'completed_at' => now()]);
            }
        }

        return response()->json(['data' => $assessment->fresh()])->header('Cache-Control', 'no-store, private');
    }
}
