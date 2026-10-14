<?php

declare(strict_types=1);

namespace App\Services;

/**
 * A starting institution policy that any installation can adopt and edit.
 *
 * The point is that a new school is not blocked waiting for someone to invent
 * finance wording, and not that the platform gets a say in how that school
 * spends money. The categories below are deliberately generic and are only a
 * starting point: the institution renames, splits or removes them in the
 * workspace, and the reviewed revision it publishes is what the agent reads.
 *
 * Nothing here is a hidden default that survives editing. `template()` returns
 * plain data matching the authoring schema exactly, so what a reviewer sees in
 * the workspace is what gets saved and digested. The financial control
 * amounts are a conservative placeholder set in exact base units; the
 * institution types its own before publishing.
 */
final class DefaultInstitutionPolicy
{
    /**
     * The authoring payload an institution starts from.
     *
     * @return array<string, mixed>
     */
    public static function template(): array
    {
        return [
            'title' => 'Institution finance and transaction policy',
            'predecessor_id' => null,
            'expected_current_digest' => null,
            'clauses' => [
                ['id' => 'obligations', 'title' => 'Paying reviewed obligations',
                    'text' => 'Money received as tuition or institutional fees is held as a receipt. It may only be proposed for supplier invoices that staff have already reviewed and approved, and it may only be proposed for those invoices at or below their exact approved amount.'],
                ['id' => 'committees', 'title' => 'Student organisations',
                    'text' => 'Recognised student councils and clubs may receive funding from receipts only for activities recorded against their own approved budget line. Funding is proposed, never paid automatically.'],
                ['id' => 'facilities', 'title' => 'Department facilities',
                    'text' => 'Departmental facilities, laboratories and teaching equipment may receive funding from receipts only where the department holds an active budget line and the spend is recorded against that line.'],
                ['id' => 'priority', 'title' => 'Order of proposals',
                    'text' => 'Proposals rank overdue supplier invoices first, then obligations that are due within the current period, then student organisation and department facility needs. A proposal never ranks an unapproved invoice at all.'],
                ['id' => 'reserve', 'title' => 'Holding back reserve',
                    'text' => 'Every proposal must leave the protected reserve untouched. Where the reserve would be consumed, the proposal is withheld for human review rather than reduced.'],
                ['id' => 'review', 'title' => 'Unclear receipts',
                    'text' => 'Anything that does not clearly match one category above is held for human review. It is never assigned to the nearest category to make a proposal look complete.'],
            ],
            'categories' => [
                ['id' => 'supplier-invoices', 'name' => 'Supplier invoices',
                    'description' => 'Reviewed and approved supplier invoices owed to vendors.',
                    'inclusions' => 'Approved supplier invoices past or near their due date, recurring service invoices with an approved standing order.',
                    'exclusions' => 'Invoices without a staff evidence review, personal purchases, anything addressed to an individual rather than the institution.',
                    'clause_ids' => ['obligations', 'priority']],
                ['id' => 'student-council', 'name' => 'Student council',
                    'description' => 'Recognised student council activities and running costs.',
                    'inclusions' => 'Council activities, student representation, approved council events.',
                    'exclusions' => 'Individual student expenses, unregistered student groups.',
                    'clause_ids' => ['committees', 'priority']],
                ['id' => 'student-clubs', 'name' => 'Student clubs',
                    'description' => 'Recognised student clubs and their activities.',
                    'inclusions' => 'Club materials and activities against a recognised club budget line.',
                    'exclusions' => 'Clubs with no active budget line, unregistered groups.',
                    'clause_ids' => ['committees', 'priority']],
                ['id' => 'department-facilities', 'name' => 'Department facilities',
                    'description' => 'Departmental facilities, laboratories and teaching equipment.',
                    'inclusions' => 'Facilities maintenance, laboratory consumables, teaching equipment against an active department budget line.',
                    'exclusions' => 'Capital projects without a recorded budget line, spend outside the department period.',
                    'clause_ids' => ['facilities', 'priority']],
            ],
            'examples' => [
                ['description' => 'Approved supplier invoice for laboratory glassware, due next week', 'category_id' => 'supplier-invoices'],
                ['description' => 'Student council term event costs', 'category_id' => 'student-council'],
                ['description' => 'Robotics club competition entry and parts', 'category_id' => 'student-clubs'],
                ['description' => 'Science department laboratory bench repair', 'category_id' => 'department-facilities'],
            ],
            'controls' => [
                'minimum_reserve' => '0',
                'max_auto_payment' => '0',
                'max_daily_disbursement' => '0',
                'max_fee' => '0',
            ],
            'priorities' => 'Supplier invoices that are approved and overdue come first, then approved invoices due inside the period, then recognised student council and club needs, then departmental facilities. Nothing is proposed outside an approved invoice or an active budget line.',
            'clarifications' => [],
        ];
    }

    /**
     * The starting control amounts, in exact six-decimal USDC base units.
     *
     * Every automatic limit is zero: an institution that wants the agent to be
     * able to move money without a human has to say so explicitly during
     * review, rather than inheriting it from a template.
     *
     * @return array<string, int>
     */
    public static function controls(): array
    {
        return [
            'minimum_reserve' => 0,
            'max_auto_payment' => 0,
            'max_daily_disbursement' => 0,
            'max_fee' => 0,
        ];
    }
}
