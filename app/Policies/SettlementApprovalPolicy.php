<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Who may answer a paused disbursement proposal.
 *
 * Separate from `AssistanceRequestPolicy` on purpose. That policy governs
 * viewing and creating requests; this one governs releasing funds, which is a
 * narrower authority and should not be inherited by accident from a broader
 * ability. A reviewer who may read every request must still be granted this.
 */
class SettlementApprovalPolicy
{
    /**
     * Roles permitted to approve or reject an agent's disbursement proposal.
     *
     * @var list<string>
     */
    private const REVIEWER_ROLES = ['finance_officer', 'admin', 'super_admin'];

    public function before(AuthUser $authUser, string $ability): ?bool
    {
        return $authUser->hasRole('super_admin') ? true : null;
    }

    /**
     * Whether the user may resume a paused proposal.
     *
     * Declared for a null model because the gate authorises the *action*, which
     * has no single owning record: the conversation, not an AssistanceRequest,
     * is what a reviewer is being granted authority over. The tool's own
     * `handle()` still re-evaluates policy for the concrete request.
     */
    public function approveSettlementProposals(AuthUser $authUser): bool
    {
        return $authUser->hasAnyRole(self::REVIEWER_ROLES);
    }
}
