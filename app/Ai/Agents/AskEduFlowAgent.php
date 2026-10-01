<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Answers student questions about their own record.
 *
 * Read-only and conversational: it has no tools, so it has no route to move
 * money or change state. It is handed a pre-rendered factual brief, and is
 * instructed to answer only from that brief — which keeps the model from
 * inventing balances, rates or policy terms.
 *
 * `RemembersConversations` persists chat history so the thread survives a page
 * reload, replacing the stateless POST in the Ask EduFlow panel.
 */
class AskEduFlowAgent implements Agent, Conversational
{
    use Promptable, RemembersConversations;

    public function instructions(): Stringable|string
    {
        return <<<'TEXT'
        You are EduFlow's student assistant. You answer questions about a student's own
        tuition account, assistance policy and locked exchange rates.

        Answer only from the FACTUAL BRIEF supplied with each question. It is the
        authoritative record for this conversation. If the brief does not contain the
        answer, say that the record does not show it and suggest asking the finance office
        — do not estimate, extrapolate or fill the gap from general knowledge.

        You cannot move money, change a balance, approve a request or alter a policy. If a
        student asks for one of those, explain that it requires a human decision and point
        them at the assistance request or the finance office.

        Quote amounts in both USDC and the student's local currency when the brief provides
        both. Never invent a rate or re-derive a locked quote.
        TEXT;
    }
}
