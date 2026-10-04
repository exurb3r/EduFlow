<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\AssistanceRequest;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OverflowException;

class SubmitAssistanceRequest
{
    /** @param array{submission_key: string, type: string, requested_amount: string, reason: string} $data */
    public function handle(User $user, array $data): AssistanceRequest
    {
        try {
            $amount = Money::fromDecimal($data['requested_amount'], CurrencyCode::USDC)->minorUnits;
        } catch (InvalidArgumentException|OverflowException) {
            throw ValidationException::withMessages(['requested_amount' => 'Enter a supported USDC decimal amount with at most six decimal places.']);
        }

        if ($amount <= 0 || $amount > 1_000_000_000000) {
            throw ValidationException::withMessages(['requested_amount' => 'Requested amount must be positive and no more than 1,000,000 USDC.']);
        }

        return DB::transaction(function () use ($user, $data, $amount): AssistanceRequest {
            $student = Student::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $existing = $student->assistanceRequests()->where('submission_key', $data['submission_key'])->first();

            if ($existing) {
                if ($existing->requested_amount !== $amount || $existing->reason !== $data['reason'] || $existing->type !== $data['type']) {
                    throw ValidationException::withMessages(['submission_key' => 'This submission was already used for a different request. Reload the form to start another request.']);
                }

                return $existing;
            }

            $accounts = $student->tuitionAccounts()->whereHas('academicTerm', fn ($query) => $query
                ->whereDate('starts_on', '<=', today())->whereDate('ends_on', '>=', today()))->get();

            if ($accounts->count() !== 1) {
                throw ValidationException::withMessages(['requested_amount' => 'A single current-term tuition account is required. Please contact the school finance office.']);
            }

            $assistance = $student->assistanceRequests()->create([
                'academic_term_id' => $accounts->first()->academic_term_id,
                'submission_key' => $data['submission_key'],
                'type' => 'emergency',
                'requested_amount' => $amount,
                'reason' => $data['reason'],
                'status' => 'submitted',
                'submitted_at' => now(),
            ]);

            activity('education')->causedBy($user)->performedOn($assistance)
                ->event('submitted')->withProperties([
                    'requested_amount' => (string) $amount,
                    'currency' => 'USDC',
                    'academic_term_id' => $assistance->academic_term_id,
                ])->log('Assistance request submitted');

            return $assistance;
        }, 3);
    }
}
