<?php

declare(strict_types=1);

namespace App\Ai\Advisory;

/**
 * A verified model answer, plus the conversation to continue it in.
 *
 * readonly for the same reason `AdvisoryEnvelope` is: a plain mutable object
 * travelling between the gate and the controller is where a field would get
 * repurposed into an amount or a verdict.
 */
final readonly class QnaAnswer
{
    public function __construct(
        public string $text,
        public ?string $conversationId = null,
    ) {}
}
