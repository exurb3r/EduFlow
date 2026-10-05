<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\Advisory\AdvisoryEnvelope;
use App\Ai\Advisory\AdvisoryGate;
use App\Models\AgentDecision;
use App\Models\AssistanceRequest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Records model commentary against a request.
 *
 * Deliberately not `Approvable`: it moves no money, so it needs no human. It is
 * still read-only with respect to policy — it writes advisory metadata and can
 * never set an amount, a verdict or a status.
 */
class RecordHardshipContext implements Tool
{
    public function __construct(private readonly AdvisoryGate $gate) {}

    public function description(): Stringable|string
    {
        return 'Record a short triage note about a hardship request so a reviewer can prioritise it.';
    }

    public function handle(Request $request): Stringable|string
    {
        $advisory = $this->gate->sanitize([
            'hardship_category' => $request['hardship_category'] ?? null,
            'urgency' => $request['urgency'] ?? null,
            'confidence' => $request['confidence'] ?? null,
            'narrative' => $request['narrative'] ?? null,
            'anomaly_flags' => $request['anomaly_flags'] ?? [],
        ]);

        if (! $advisory instanceof AdvisoryEnvelope) {
            return 'Not recorded. The triage note did not match the expected shape, so it was discarded.';
        }

        $id = $request['assistance_request_id'] ?? null;

        // The advisory belongs beside the decision it explains rather than on
        // the request: agent_decisions already records the reasoning, and it
        // carries a metadata column the request does not have.
        $decision = AgentDecision::where('reference_type', AssistanceRequest::class)
            ->where('reference_id', is_numeric($id) ? (int) $id : 0)
            ->latest('id')
            ->first();

        if ($decision === null) {
            return 'Not recorded. No recorded decision exists for that assistance request.';
        }

        $decision->update([
            'metadata' => $advisory->mergeIntoMetadata($decision->metadata ?? []),
        ]);

        return 'Recorded advisory triage note. It carries no authority over the amount or the decision.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'assistance_request_id' => $schema->integer()->required(),
            'hardship_category' => $schema->string()->required(),
            'urgency' => $schema->string()->enum(['standard', 'high'])->required(),
            'confidence' => $schema->number()->min(0)->max(1)->required(),
            'narrative' => $schema->string()->required(),
            'anomaly_flags' => $schema->array()->items($schema->string())->required(),
        ];
    }
}
