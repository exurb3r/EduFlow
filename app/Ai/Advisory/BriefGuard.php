<?php

declare(strict_types=1);

namespace App\Ai\Advisory;

/**
 * Rejects a model answer that states a figure the application did not compute.
 *
 * The conversational agent is allowed to rephrase, be warm, and answer
 * follow-ups. It is not allowed to introduce a number, because a student cannot
 * tell an invented balance from a real one. So every numeric token in a
 * response is checked against the brief, and anything absent fails the whole
 * answer closed.
 *
 * Failing closed rather than stripping is deliberate. Silently deleting a
 * fabricated figure leaves an answer that reads as authoritative while quietly
 * omitting what the student asked about, which is harder to notice than a
 * refusal and worse for the student.
 *
 * This is the same shape as `AdvisorySanitizer`, and for the same reason: the
 * DTO cannot express an unsafe value, and the guard is what proves it.
 */
final class BriefGuard
{
    /**
     * Canonical figures permitted by the brief, keyed for exact lookup.
     *
     * @var array<string, true>
     */
    private array $allowed = [];

    public function __construct(?StudentBrief $brief = null)
    {
        if ($brief instanceof StudentBrief) {
            $this->allow($brief->toPromptBlock());
        }
    }

    /**
     * Add text whose numbers become permitted figures.
     */
    public function allow(string $text): self
    {
        foreach ($this->figures($text) as $figure) {
            $this->allowed[$figure] = true;
        }

        return $this;
    }

    /**
     * The figures a response states that the brief does not contain.
     *
     * An empty array means the response is safe to show.
     *
     * @return list<string>
     */
    public function unverifiedFigures(string $answer): array
    {
        $unverified = [];

        foreach ($this->figures($answer) as $figure) {
            if (! isset($this->allowed[$figure])) {
                $unverified[] = $figure;
            }
        }

        return array_values(array_unique($unverified));
    }

    public function permits(string $answer): bool
    {
        return $this->unverifiedFigures($answer) === [];
    }

    /**
     * Extract comparable figures from free text, as canonical strings.
     *
     * Comparison is done on digit strings, never on floats. Two reasons, and the
     * second one is not theoretical: PHP 8.5 casts a float array key to int, so
     * keying by `(float) 1000.5` silently stores it as `1000` and the guard
     * then permits `1000.5` because it collides with a permitted `1000`.
     *
     * Canonicalising to a string also makes "1,000.00", "1000.0" and "1000"
     * compare equal, which is what a reader means by all three, without any
     * rounding rule to get wrong.
     *
     * @return list<string>
     */
    private function figures(string $text): array
    {
        preg_match_all('/(?<![\d.])(\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?:\.\d+)?)(?![\d.])/', $text, $matches);

        return array_map($this->canonicalise(...), $matches[1]);
    }

    /**
     * Strip thousands separators and trailing fractional zeros.
     */
    private function canonicalise(string $figure): string
    {
        $figure = str_replace(',', '', $figure);

        if (str_contains($figure, '.')) {
            return rtrim(rtrim($figure, '0'), '.');
        }

        return $figure;
    }
}
