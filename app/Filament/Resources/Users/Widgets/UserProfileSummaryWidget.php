<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Widgets;

use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\User;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

class UserProfileSummaryWidget extends Widget
{
    protected string $view = 'filament.resources.users.widgets.user-profile-summary-widget';

    protected int|string|array $columnSpan = 'full';

    public ?Model $record = null;

    public function mount(?Model $record = null): void
    {
        $this->record = $record;
    }

    public function getUser(): ?User
    {
        if ($this->record instanceof User) {
            return $this->record;
        }

        return null;
    }

    #[\Override]
    protected function getViewData(): array
    {
        $user = $this->getUser();

        if (! $user) {
            return [];
        }

        $user->loadMissing(['student.tuitionAccounts', 'roles', 'passkeys']);

        $name = $user->name;
        $words = explode(' ', trim($name));
        $initials = '';
        foreach (array_slice($words, 0, 2) as $w) {
            $initials .= Str::upper(substr($w, 0, 1));
        }

        $roles = $user->roles->pluck('name')->all();
        $primaryRole = $roles[0] ?? 'user';

        $hasAppMfa = filled($user->app_authentication_secret);
        $hasEmailMfa = (bool) $user->has_email_authentication;
        $passkeysCount = $user->passkeys->count();
        $isMfaSecured = $hasAppMfa || $hasEmailMfa || $passkeysCount > 0;

        $student = $user->student;
        $walletAddress = $student?->payout_address ?? $user->wallet_address;
        $explorerUrl = null;
        if ($walletAddress) {
            try {
                $explorerUrl = app(ArcNetworkGateway::class)->addressExplorerUrl($walletAddress);
            } catch (Throwable) {
                $explorerUrl = null;
            }
        }

        $tuitionBalanceFormatted = null;
        $hasTuitionDebt = false;
        if ($student) {
            $tuitionDebtUnits = $student->totalTuitionBalance();
            $hasTuitionDebt = $tuitionDebtUnits > 0;
            $tuitionBalanceFormatted = (new Money($tuitionDebtUnits, CurrencyCode::USDC))->format(2).' USDC';
        }

        return [
            'user' => $user,
            'name' => $name,
            'initials' => $initials ?: 'US',
            'email' => $user->email,
            'emailVerified' => $user->email_verified_at !== null,
            'emailVerifiedAt' => $user->email_verified_at?->format('M d, Y'),
            'roles' => $roles,
            'primaryRole' => $primaryRole,
            'hasAppMfa' => $hasAppMfa,
            'hasEmailMfa' => $hasEmailMfa,
            'passkeysCount' => $passkeysCount,
            'isMfaSecured' => $isMfaSecured,
            'student' => $student,
            'walletAddress' => $walletAddress,
            'explorerUrl' => $explorerUrl,
            'hasTuitionDebt' => $hasTuitionDebt,
            'tuitionBalanceFormatted' => $tuitionBalanceFormatted,
            'canBeImpersonated' => $user->canBeImpersonated(),
        ];
    }
}
