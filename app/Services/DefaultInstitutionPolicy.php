<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Budget;
use App\Models\Organization;
use Illuminate\Support\Str;

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
                    'text' => 'Proposals follow the saved category order. Within one category, independently approved supplier invoices precede active budget lines. This policy does not implement percentage splits or due-date ordering within a category. A proposal never ranks an unapproved invoice at all.'],
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
            'priorities' => 'Follow saved category order: approved supplier invoices, student council, student clubs, then departmental facilities. Approved invoices precede active budget lines within a category. Nothing is proposed outside an approved invoice or an active budget line.',
            'clarifications' => [],
        ];
    }

    /**
     * Suggested wording tied to the installation's real budget names, never an active mandate.
     *
     * @return array<string, mixed>
     */
    public static function forInstitution(Organization $institution): array
    {
        $template = self::template();
        $template['title'] = Str::limit($institution->name, 100, '').' collections and allocation policy';
        $template['clauses'][0]['text'] = 'Outgoing supplier invoices may only be proposed after staff independently approve their evidence, at or below the exact approved amount. Incoming tuition and other fee receipts are classified under their own receipt categories, never as supplier invoices. An outgoing invoice proposal is not payment authorization.';
        $template['categories'][0]['exclusions'] .= ' Incoming tuition payments and other student fee receipts.';
        $template['clauses'][] = ['id' => 'tuition-receipts', 'title' => 'Partial tuition collections',
            'text' => 'Tuition receipts include verified partial payments and downpayments. For example, receiving 5 USDC toward a 200 USDC tuition account records 5 USDC received and 195 USDC still owed. Only received cash may enter allocation planning; unpaid tuition is never spendable cash. This allocation policy does not enforce enrollment minimums, installment schedules or term deadlines; those require separate tuition controls.'];
        $template['clauses'][] = ['id' => 'other-fees', 'title' => 'Other student fee collections',
            'text' => 'Registration, examination, laboratory and other institution-approved student fee receipts are classified separately from tuition. A fee receipt is not permission to pay a vendor. Independently reviewed collections must be bound to a current budget snapshot before any allocation amount is proposed.'];
        $template['clauses'][] = ['id' => 'cash-evidence', 'title' => 'Received cash and restrictions',
            'text' => 'A recorded transaction or hash alone does not prove collected cash. Digital collections require verified settlement and independent receipt review. Restricted receipts, refundable overpayments, protected reserve and existing commitments remain unavailable for general spending. Re-evaluation creates new advice and never changes receipts, tuition balances or prior approvals.'];
        $template['clauses'][] = ['id' => 'network-fees', 'title' => 'Who pays network fees',
            'text' => 'Network fees and tuition are separate. Sponsored network fees are not deducted from tuition credited to a student. Institution-paid network fees require their own bounded financial controls. Never assume every Arc transfer is sponsored, and never interpret this policy as payment authorization.'];
        $template['clauses'][] = ['id' => 'bill-affordability', 'title' => 'Bills before discretionary allocations',
            'text' => 'Propose only intact independently approved supplier invoices within the reviewed budget context. Never treat the ledger, forecast tuition, an unverified receipt, a budget allocation or an AI answer as available wallet cash. Rank by saved category order, then approved invoices before budget lines. Pay an invoice in full only when its exact amount fits cumulative cash and budget headroom; otherwise hold it. No automatic invoice installments or percentage splits are authorized.'];
        $template['clauses'][] = ['id' => 'pilot', 'title' => 'Testnet tuition pilot',
            'text' => 'The operator may designate an existing testnet tuition account for a 5 USDC payment demonstration after explicit payer consent and a named independent recovery owner. Faucet funding is testnet minting, not institution revenue. Verified pilot tuition is testnet evidence only, never official revenue or permission to pay vendors. A prior unresolved attempt blocks replacement payments until investigated.'];

        $budgets = Budget::query()->where('organization_id', $institution->id)->where('status', 'active')
            ->orderBy('id')->limit(20)->get()->unique('category');
        if ($budgets->isNotEmpty()) {
            $template['categories'] = [$template['categories'][0]];
            $template['examples'] = [$template['examples'][0]];
            $template['clauses'] = array_values(array_filter($template['clauses'],
                fn (array $clause): bool => ! in_array($clause['id'], ['committees', 'facilities'], true)));
            $template['clauses'] = array_map(static function (array $clause): array {
                if ($clause['id'] === 'review') {
                    $clause['text'] = 'Anything that does not clearly match one saved category is held for human review. It is never assigned to the nearest category to make a proposal look complete.';
                }

                return $clause;
            }, $template['clauses']);
        }
        foreach ($budgets as $budget) {
            $id = 'budget-'.Str::limit(Str::slug($budget->category), 48, '');
            $clauseId = 'scope-'.$id;
            $template['clauses'][] = ['id' => $clauseId, 'title' => $budget->name,
                'text' => 'The active budget line "'.$budget->name.'" covers institution-approved '.$budget->category
                    .' needs. Classify this budget line under its named category. Supplier invoices remain in Supplier invoices and require independent evidence approval. An active budget line is a planning destination, not authority to disburse money.'];
            $template['categories'][] = ['id' => $id, 'name' => $budget->name,
                'description' => 'Active '.$budget->category.' budget needs for '.$institution->name.'.',
                'inclusions' => 'Active budget line "'.$budget->name.'" in category '.$budget->category.'.',
                'exclusions' => 'Supplier invoices, inactive budgets, personal spending and spending without reviewed available cash.',
                'clause_ids' => [$clauseId, 'cash-evidence', 'priority']];
            $template['examples'][] = ['description' => 'Active budget line "'.$budget->name.'" in category '.$budget->category
                .' with a reviewed available balance.', 'category_id' => $id];
        }
        $template['categories'][] = ['id' => 'tuition-receipts', 'name' => 'Tuition receipts',
            'description' => 'Incoming full or partial tuition payments, not spending destinations.',
            'inclusions' => 'Verified tuition payments and downpayments.', 'exclusions' => 'Unpaid tuition, vendor invoices and other student fees.',
            'clause_ids' => ['tuition-receipts', 'cash-evidence']];
        $template['categories'][] = ['id' => 'other-fee-receipts', 'name' => 'Other fee receipts',
            'description' => 'Incoming institution-approved student fees other than tuition.',
            'inclusions' => 'Registration, examination and laboratory fee collections.',
            'exclusions' => 'Tuition, vendor invoices, unverified transfers and refundable overpayments.',
            'clause_ids' => ['other-fees', 'cash-evidence']];
        $template['examples'][] = ['description' => 'Verified partial tuition downpayment of 5 USDC against 200 USDC tuition; 195 USDC is still unpaid.',
            'category_id' => 'tuition-receipts'];
        $template['examples'][] = ['description' => 'Independently reviewed registration fee receipt from the student bill portal.',
            'category_id' => 'other-fee-receipts'];
        $template['examples'][] = ['description' => 'Personal shopping request with no approved invoice or active institution budget line.',
            'category_id' => 'needs_review'];
        $template['priorities'] = 'Follow category order. Approved supplier invoices come before active budget lines. Receipts are incoming evidence, not spending destinations. Use received cash only; protect restricted funds and reserve. Ambiguous evidence stays Needs review. All results require finance review; no automatic payment authority.';

        return $template;
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
