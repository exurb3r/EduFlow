<?php

declare(strict_types=1);

use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * students.payout_address becomes the single wallet address.
 *
 * Students who already linked a wallet on the portal wrote it to
 * users.wallet_address, which the disbursement path never read. That address
 * is copied across so nobody loses the one they registered, and so the portal
 * keeps showing a connected wallet afterwards.
 *
 * Only well-formed Arc destinations are copied: a malformed value must not
 * become a payout destination, because the alternative to a rejected address
 * is an accepted one nobody controls.
 */
return new class extends Migration
{
    public function up(): void
    {
        Student::query()
            ->whereNull('payout_address')
            ->each(function (Student $student): void {
                $address = $student->user?->wallet_address;

                if (! is_string($address) || preg_match(Student::PAYOUT_ADDRESS_PATTERN, $address) !== 1) {
                    return;
                }

                $student->forceFill(['payout_address' => $address])->save();

                User::query()->whereKey($student->user_id)->update(['wallet_address' => $address]);
            });
    }

    /**
     * Deliberately irreversible. Reversing would clear payout addresses that
     * staff have since reviewed, and a payout destination is never something
     * to overwrite on the way back down.
     */
    public function down(): void
    {
        //
    }
};
