---
paths:
  - 'app/Services/InstitutionPolicy*.php, app/Models/InstitutionPolicy*.php, app/Jobs/AssessInstitutionPolicy.php, app/Ai/Agents/InstitutionPolicyAnalyst.php'
---

# Agents

## Institution policy is stored evidence, not code
Institution business criteria (categories, clauses, examples, priorities) live in institution_policy_versions.content as immutable digest-bound JSON, NOT as PHP branches or eval. New category = new revision by staff, no deploy. Three layers stay separate: authored policy text, exact FinancePolicyVersion control amounts (staff-typed, never model-estimated), and platform safety (separation of duties + ActivateFinancePolicy chain). Publication requires a different reviewer than author, an authoring assessment with zero questions/missing_evidence, every saved example matching its expected category, and an unchanged active publication_digest. Model output is advisory by construction: schema carries no amount/recipient/verdict, category_id is restricted to the saved set, every citation must match a verbatim excerpt of saved clause text, and InstitutionPolicyInterpreter::sanitize strips provider keys like approved/can_execute so every stored result carries can_execute=false. Unknown or conflicting input resolves to needs_review, never a forced miscellaneous bucket. AssessInstitutionPolicy claims its row once (queued->running) so queue redelivery cannot call the provider twice; a provider outage must leave a visible needs_review row and change no financial state. Publication is default-off gated by EDUFLOW_INSTITUTION_POLICY_ADVICE.
