---
paths:
  - 'app/Services/Advisory/**'
---

# Advisory

## Jev ranks mandate candidates; it never decides a payment
`MandateCandidateRanker` asks Jev (a classification model, via laravel/ai's `Str::decide()`) which bills might deserve a standing mandate: does it recur, is the amount stable. Advisory only.

Return type `AdvisoryMandateRanking` has no amount, no recipient, no ceiling and no verdict in it -- nothing a caller could promote into a payment. Every row carries `advisory_only: true` and `decides_payment: false`.

Never feed its output to `MandateReleaseEvaluator`, which is a pure function of evidence. It can make a mandate review faster; it cannot replace the reviewer who signs it, or the deterministic per-occurrence evaluate step.

Test with `Ai::fakeClassification([['decision' => new BooleanAnswer(0.9)], ...])`. The fake passes values through unmarshalled, so raw booleans raise a TypeError on `answer()`; and answers must be keyed by question name (`decision` for Str::decide), not bare values.

Fail closed: no key, unreachable provider, or provider error returns null. A provider error discards the whole ranking -- a partial one reads as a considered judgement about whichever bills survived.
