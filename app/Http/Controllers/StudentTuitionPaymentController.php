<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\Money;
use App\DTOs\StudentWalletSnapshot;
use App\Enums\CurrencyCode;
use App\Models\Student;
use App\Models\StudentBill;
use App\Models\StudentBillPayment;
use App\Models\StudentTuitionPayment;
use App\Models\StudentTuitionPaymentAttempt;
use App\Models\StudentTuitionPaymentIntent;
use App\Models\TuitionAccount;
use App\Models\User;
use App\Notifications\TuitionPaymentSubmittedNotification;
use App\Services\CurrencyConverter;
use App\Services\StudentTuitionPaymentService;
use App\Services\StudentWalletService;
use chillerlan\QRCode\QRCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Throwable;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

/**
 * Tuition paid from the student's own wallet into the institution treasury.
 *
 * The recipient is read from LEPTON_TREASURY_ADDRESS on the server and is never
 * taken from the request, and the student is always the authenticated user's
 * own record, so there is no id or address to forge.
 *
 * The `request_key` is minted by the server and echoed back to the payer on
 * both the quote and the payment, so a confirmation, a retry and a page reload
 * all converge on one durable payment attempt instead of a second transfer.
 */
class StudentTuitionPaymentController extends Controller
{
    public function __construct(
        private readonly StudentTuitionPaymentService $payments,
    ) {}

    /**
     * Render the dedicated crypto wallet transfer screen for student tuition and campus bills.
     */
    public function create(Request $request, CurrencyConverter $converter): Response
    {
        abort_unless($request->user()?->hasRole('student'), 403);
        $student = $request->user()->student()->firstOrFail();

        self::expireStalePreparedAttempts($student->id);

        $requestedAccountId = $request->query('account') ?? $request->query('account_id');
        $account = null;
        if ($requestedAccountId !== null) {
            $account = $student->tuitionAccounts()->with('academicTerm')->find((int) $requestedAccountId);
        }

        if ($account === null) {
            $accounts = $student->tuitionAccounts()->with('academicTerm')
                ->whereHas('academicTerm', fn ($query) => $query->whereDate('starts_on', '<=', today())->whereDate('ends_on', '>=', today()))
                ->get();
            $account = $accounts->count() === 1 ? $accounts->first() : null;
        }

        if ($account === null) {
            $account = $student->tuitionAccounts()->with('academicTerm')->latest('id')->first();
        }

        $displayCurrency = CurrencyCode::tryFrom(strtoupper((string) config('eduflow.display_currency', 'PHP'))) ?? CurrencyCode::PHP;
        $quote = $converter->lockQuote($displayCurrency);

        $tuitionAccountData = null;
        if ($account instanceof TuitionAccount) {
            $totalBase = (int) $account->total_amount;
            $paidBase = (int) $account->paid_amount;
            $heldBase = (int) $account->heldAmount();
            $effectiveRemainingBase = (int) $account->effectiveRemainingAmount();

            $tuitionAccountData = [
                'id' => $account->getKey(),
                'term' => $account->academicTerm->name,
                'total_amount' => (string) $account->total_amount,
                'paid_amount' => (string) $account->paid_amount,
                'held_amount' => (string) $heldBase,
                'remaining_amount' => (string) $effectiveRemainingBase,
                'unadjusted_remaining_amount' => (string) $account->remainingAmount(),
                'remaining_amount_fiat' => $converter->formatDual($effectiveRemainingBase, $displayCurrency),
                'total_amount_fiat' => $converter->formatDual($totalBase, $displayCurrency),
                'paid_amount_fiat' => $converter->formatDual($paidBase, $displayCurrency),
                'held_amount_fiat' => $converter->formatDual($heldBase, $displayCurrency),
            ];
        }

        $walletService = app(StudentWalletService::class);
        $walletAddress = $walletService->addressFor($student);
        $walletSnapshot = $walletService->snapshot($student);

        $treasuryAddress = null;
        try {
            $treasuryAddress = $this->payments->recipientAddress();
        } catch (Throwable) {
            $treasuryAddress = (string) config('lepton.arc.treasury_address', '');
        }

        $treasuryExplorerUrl = null;
        if (is_string($treasuryAddress) && $treasuryAddress !== '') {
            try {
                $treasuryExplorerUrl = app(ArcNetworkGateway::class)->addressExplorerUrl($treasuryAddress);
            } catch (Throwable) {
                $treasuryExplorerUrl = 'https://testnet.arcscan.app/address/'.$treasuryAddress;
            }
        }

        $treasuryQr = null;
        if (is_string($treasuryAddress) && $treasuryAddress !== '') {
            try {
                $treasuryQr = (new QRCode)->render($treasuryAddress);
            } catch (Throwable) {
                $treasuryQr = null;
            }
        }

        $walletQr = null;
        if (is_string($walletAddress) && $walletAddress !== '') {
            try {
                $walletQr = (new QRCode)->render($walletAddress);
            } catch (Throwable) {
                $walletQr = null;
            }
        }

        $bills = StudentBill::query()
            ->where('student_id', $student->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (StudentBill $bill): array => [
                'id' => $bill->id,
                'category' => $bill->category->value,
                'category_label' => $bill->category->label(),
                'description' => $bill->description,
                'amount_base_units' => (string) $bill->amount_base_units,
                'paid_base_units' => (string) $bill->paid_base_units,
                'remaining_base_units' => (string) $bill->remainingAmount(),
                'due_date' => $bill->due_date?->toDateString(),
                'status' => $bill->status,
                'is_pilot' => in_array($bill->id, config('eduflow.student_bills.pilot_bill_ids', []), true),
            ])
            ->all();

        $billPayments = StudentBillPayment::query()
            ->where('student_id', $student->id)
            ->with('studentBill')
            ->latest('id')
            ->limit(25)
            ->get()
            ->map(function (StudentBillPayment $payment) use ($request, $student): array {
                $verified = $payment->hasValidSettlement();
                $explorerUrl = null;
                if (is_string($payment->tx_hash) && trim($payment->tx_hash) !== '') {
                    try {
                        $explorerUrl = app(ArcNetworkGateway::class)->explorerUrl($payment->tx_hash);
                    } catch (Throwable) {
                        $explorerUrl = 'https://testnet.arcscan.app/tx/'.$payment->tx_hash;
                    }
                }

                $rawBlock = $payment->settlement_evidence['receipt']['blockNumber'] ?? null;
                $blockNumber = null;
                if (is_string($rawBlock)) {
                    $blockNumber = str_starts_with($rawBlock, '0x') ? (string) hexdec($rawBlock) : $rawBlock;
                } elseif (is_int($rawBlock)) {
                    $blockNumber = (string) $rawBlock;
                }

                return [
                    'id' => $payment->id,
                    'request_key' => $payment->request_key,
                    'student_bill_id' => $payment->student_bill_id,
                    'amount_base_units' => (string) $payment->amount_base_units,
                    'applied_base_units' => (string) $payment->applied_base_units,
                    'overpayment_base_units' => (string) $payment->overpayment_base_units,
                    'quoted_network_fee' => $payment->snapshot['quoted_network_fee'] ?? null,
                    'fee_paid_by' => $payment->fee_guarantee,
                    'tx_hash' => $payment->tx_hash,
                    'provider_handle' => $payment->provider_handle,
                    'submission_state' => $payment->submission_state,
                    'submission_label' => $this->billPaymentStateLabel($payment),
                    'policy_decision' => $payment->policy_decision,
                    'policy_code' => $payment->policy_code,
                    'requires_human_approval' => $payment->requires_human_approval,
                    'authorizes_outgoing_spend' => $payment->authorizesOutgoingSpend(),
                    'is_rehearsal' => $payment->isRehearsal(),
                    'is_allocated' => $payment->isAllocated(),
                    'verified' => $verified,
                    'last_error' => $payment->last_error,
                    'created_at' => $payment->created_at?->toIso8601String(),
                    'receipt' => $verified ? [
                        'receipt_number' => sprintf('OR-ARC-B%s-%06d', $payment->created_at?->format('Y') ?? date('Y'), $payment->id),
                        'payment_type' => 'bill',
                        'student_name' => $request->user()->name,
                        'student_number' => $student->student_number,
                        'program' => $student->program,
                        'term' => $payment->studentBill?->category->label() ?? 'Campus Bill',
                        'description' => $payment->studentBill?->description ?? 'Campus Bill Payment',
                        'amount_base_units' => (string) $payment->amount_base_units,
                        'applied_base_units' => (string) $payment->applied_base_units,
                        'overpayment_base_units' => (string) $payment->overpayment_base_units,
                        'remaining_balance_base_units' => (string) ($payment->studentBill?->remainingAmount() ?? 0),
                        'network_fee' => $payment->network_fee ?? '0.00',
                        'fee_guarantee' => $payment->fee_guarantee ?? 'relayer_sponsored',
                        'payer_address' => $payment->payer_address,
                        'recipient_address' => $payment->recipient_address,
                        'chain' => $payment->chain ?: 'ARC-TESTNET',
                        'chain_id' => 5042002,
                        'block_number' => $blockNumber,
                        'verified_status' => 'Verified on Arc Testnet',
                        'tx_hash' => $payment->tx_hash,
                        'explorer_url' => $explorerUrl,
                        'settlement_digest' => $payment->settlement_evidence_digest,
                        'consent_digest' => $payment->consent_digest,
                        'issued_at' => ($payment->executed_at ?? $payment->settlement_verified_at ?? $payment->created_at)?->format('M d, Y h:i:s A') ?? now()->format('M d, Y h:i:s A'),
                    ] : null,
                ];
            })
            ->all();

        $requestedBillId = $request->query('bill') !== null ? (int) $request->query('bill') : ($request->query('bill_id') !== null ? (int) $request->query('bill_id') : null);
        $requestedMode = ($request->query('mode') === 'bill' || $requestedBillId !== null) ? 'bill' : 'tuition';
        $initialAmount = $request->query('amount') !== null ? (string) $request->query('amount') : null;

        return Inertia::render('student/pay', [
            'initialMode' => $requestedMode,
            'initialBillId' => $requestedBillId,
            'initialAmount' => $initialAmount,
            'initialAccountId' => $account?->getKey(),
            'student' => [
                'name' => $request->user()->name,
                'student_number' => $student->student_number,
                'program' => $student->program,
                'year_level' => $student->year_level,
            ],
            'wallet' => [
                'address' => $walletAddress,
                'qr_code' => $walletQr,
                'snapshot' => $walletSnapshot->toArray(),
                'balance_fiat' => $this->walletBalanceFiat($walletSnapshot, $converter, $displayCurrency),
            ],
            'treasury' => [
                'address' => $treasuryAddress ?: null,
                'qr_code' => $treasuryQr,
                'explorer_url' => $treasuryExplorerUrl,
                'chain' => (string) config('lepton.arc.chain', 'ARC-TESTNET'),
                'chain_id' => (int) config('lepton.arc.chain_id', 5042002),
            ],
            'tuitionAccount' => $tuitionAccountData,
            'tuitionAttempts' => StudentTuitionPaymentAttempt::query()
                ->where('student_id', $student->id)
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (StudentTuitionPaymentAttempt $attempt): array => self::statusData($attempt))
                ->all(),
            'bills' => $bills,
            'billPayments' => $billPayments,
            'pilot_enabled' => (bool) config('eduflow.student_bills.enabled', false),
            'tuition_enabled' => (bool) config('eduflow.student_tuition.enabled', false),
            'currency' => [
                'display' => $displayCurrency->value,
                'symbol' => $displayCurrency->symbol(),
                'units_per_usdc' => (int) $quote['units_per_usdc'],
                'rate_description' => "1 USDC ≈ {$quote['units_per_usdc']} minor {$quote['quote']}",
            ],
        ]);
    }

    public function quote(Request $request): JsonResponse
    {
        set_time_limit(120);

        [$student, $account] = $this->resolve($request);

        $validated = $request->validate([
            'amount_base_units' => ['required', 'integer', 'min:1'],
            'request_key' => ['sometimes', 'nullable', 'uuid'],
        ]);

        return response()->json($this->payments->quote($student, $account, (int) $validated['amount_base_units'], $validated['request_key'] ?? null));
    }

    public function store(Request $request): JsonResponse
    {
        set_time_limit(120);

        [$student, $account] = $this->resolve($request);

        $validated = $request->validate([
            'amount_base_units' => ['required', 'integer', 'min:1'],
            // Echoed back from the quote, so the same identity is used for the
            // transfer and for the posting that consumes its verification.
            'request_key' => ['required', 'uuid'],
            'request_digest' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/D'],
            'consent' => ['required', 'accepted'],
        ]);

        $requestKey = (string) $validated['request_key'];

        $attempt = $this->payments->pay($student, $account, (int) $validated['amount_base_units'], $requestKey, $validated['request_digest'], true);

        $user = $request->user();
        if ($user instanceof User) {
            $amountUsdc = number_format((int) $validated['amount_base_units'] / 1_000_000, 2);
            $termName = $account->academicTerm->name;
            $user->notify(new TuitionPaymentSubmittedNotification(
                amount: $amountUsdc,
                description: $termName !== ''
                    ? "Tuition payment of {$amountUsdc} USDC sent to Arc Testnet rail for {$termName}."
                    : "Tuition payment of {$amountUsdc} USDC sent to Arc Testnet rail for processing.",
                term: $termName,
                requestKey: $requestKey,
            ));
        }

        return response()->json(self::statusData($attempt->refresh()), 202);
    }

    /**
     * The identity a payment attempt keeps for its whole life.
     *
     * A client that already holds a key sends it back and keeps it; otherwise
     * the server mints one. Either way the key is not derived from anything the
     * client can influence about the transfer itself.
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasRole('student'), 403);
        $student = $request->user()->student()->firstOrFail();

        self::expireStalePreparedAttempts($student->id);

        return response()->json(['data' => StudentTuitionPaymentAttempt::query()->where('student_id', $student->id)
            ->latest('id')->limit(50)->get()->map(fn (StudentTuitionPaymentAttempt $a): array => self::statusData($a))]);
    }

    public static function expireStalePreparedAttempts(int $studentId): void
    {
        StudentTuitionPaymentAttempt::query()
            ->where('student_id', $studentId)
            ->where('state', 'prepared')
            ->whereNull('submission_started_at')
            ->get()
            ->each(function (StudentTuitionPaymentAttempt $attempt): void {
                $intent = StudentTuitionPaymentIntent::query()->find($attempt->student_tuition_payment_intent_id);
                if ($intent instanceof StudentTuitionPaymentIntent
                    && isset($intent->snapshot['expires_at'])
                    && Carbon::parse($intent->snapshot['expires_at'])->lte(now())) {
                    $attempt->update([
                        'state' => 'failed',
                        'stage' => 'quote_expired_unsubmitted',
                        'last_error' => $attempt->last_error ?? 'Consent quote expired before submission. No signer called.',
                        'leased_until' => null,
                        'next_attempt_at' => null,
                    ]);
                }
            });
    }

    public function clearHold(Request $request, StudentTuitionPaymentAttempt $attempt): JsonResponse
    {
        abort_unless($request->user()?->hasRole('student'), 403);
        $student = $request->user()->student()->firstOrFail();
        abort_unless($attempt->student_id === $student->id, 403);

        if ($attempt->submission_started_at === null && $attempt->state === 'prepared') {
            $attempt->update([
                'state' => 'failed',
                'stage' => 'quote_expired_unsubmitted',
                'last_error' => 'Unsubmitted hold cleared by student.',
                'leased_until' => null,
                'next_attempt_at' => null,
            ]);
        }

        return response()->json(self::statusData($attempt->refresh()));
    }

    /** @return array<string, mixed> */
    public static function statusData(StudentTuitionPaymentAttempt $attempt): array
    {
        $payment = StudentTuitionPayment::query()->where('request_key', $attempt->request_key)->first();
        $explorerUrl = null;
        if (is_string($attempt->provider_reference) && trim($attempt->provider_reference) !== '') {
            try {
                $explorerUrl = app(ArcNetworkGateway::class)->explorerUrl($attempt->provider_reference);
            } catch (Throwable) {
                $explorerUrl = 'https://testnet.arcscan.app/tx/'.$attempt->provider_reference;
            }
        }

        $verified = $attempt->isVerified() && $attempt->hasValidSettlement();
        $posted = $attempt->posted_at !== null;
        $receipt = null;

        if ($payment instanceof StudentTuitionPayment || $verified || $posted) {
            $account = $attempt->tuitionAccount()->with('academicTerm')->first();
            $studentModel = $attempt->student()->with('user')->first();

            $rawBlock = $attempt->settlement_evidence['receipt']['blockNumber'] ?? null;
            $blockNumber = null;
            if (is_string($rawBlock)) {
                $blockNumber = str_starts_with($rawBlock, '0x') ? (string) hexdec($rawBlock) : $rawBlock;
            } elseif (is_int($rawBlock)) {
                $blockNumber = (string) $rawBlock;
            }

            $receipt = [
                'receipt_number' => sprintf('OR-ARC-T%s-%06d', $attempt->created_at->format('Y'), $payment?->id ?? $attempt->id),
                'payment_type' => 'tuition',
                'student_name' => $studentModel?->user?->name ?? 'Student',
                'student_number' => $studentModel?->student_number ?? '',
                'program' => $studentModel?->program ?? '',
                'term' => $account?->academicTerm?->name ?? 'Current Term',
                'description' => 'Official Tuition Settlement'.($account?->academicTerm?->name ? ' · '.$account->academicTerm->name : ''),
                'amount_base_units' => (string) ($payment?->amount_base_units ?? $attempt->amount_base_units),
                'applied_base_units' => (string) ($payment?->applied_base_units ?? $attempt->amount_base_units),
                'overpayment_base_units' => (string) ($payment?->overpayment_base_units ?? 0),
                'remaining_balance_base_units' => $account ? (string) $account->remainingAmount() : '0',
                'total_tuition_base_units' => $account ? (string) $account->total_amount : '0',
                'paid_tuition_base_units' => $account ? (string) $account->paid_amount : '0',
                'network_fee' => $attempt->actual_network_fee ?? $payment?->network_fee ?? '0.00',
                'fee_guarantee' => $attempt->fee_guarantee ?? 'relayer_sponsored',
                'payer_address' => $attempt->payer_address,
                'recipient_address' => $attempt->recipient_address,
                'chain' => $attempt->chain ?: 'ARC-TESTNET',
                'chain_id' => $attempt->chain_id ?: 5042002,
                'block_number' => $blockNumber,
                'verified_status' => $verified ? 'Verified on Arc Testnet' : null,
                'tx_hash' => $attempt->provider_reference ?? $payment?->tx_hash,
                'explorer_url' => $explorerUrl,
                'settlement_digest' => $attempt->settlement_evidence_digest,
                'consent_digest' => $attempt->consent_digest,
                'issued_at' => ($payment?->executed_at ?? $attempt->posted_at ?? $attempt->settlement_verified_at ?? $attempt->updated_at)?->format('M d, Y h:i:s A') ?? now()->format('M d, Y h:i:s A'),
            ];
        }

        return ['id' => $attempt->id, 'request_key' => $attempt->request_key, 'state' => $attempt->state,
            'state_label' => $attempt->state()->label(), 'guidance' => $attempt->state()->guidance(), 'stage' => $attempt->stage,
            'amount_base_units' => (string) $attempt->amount_base_units, 'quoted_network_fee' => $attempt->quoted_network_fee,
            'fee_ceiling_base_units' => (string) $attempt->fee_ceiling_base_units, 'actual_network_fee' => $attempt->actual_network_fee,
            'tx_hash' => $attempt->provider_reference, 'provider_handle' => $attempt->provider_handle,
            'explorer_url' => $explorerUrl,
            'simulated' => $attempt->state === 'simulated', 'verified' => $verified,
            'posted' => $posted, 'applied_base_units' => (string) ($payment instanceof StudentTuitionPayment ? $payment->applied_base_units : 0),
            'overpayment_base_units' => (string) ($payment instanceof StudentTuitionPayment ? $payment->overpayment_base_units : 0),
            'requires_investigation' => $attempt->stage === 'needs_operator_review', 'created_at' => $attempt->created_at->toIso8601String(),
            'last_error' => $attempt->last_error,
            'receipt' => $receipt];
    }

    /**
     * The authenticated student and the tuition account being paid.
     *
     * @return array{Student, TuitionAccount}
     */
    private function resolve(Request $request): array
    {
        abort_unless($request->user()?->hasRole('student'), 403);

        $student = $request->user()->student()->firstOrFail();

        $validated = validator($request->all(), [
            'tuition_account_id' => ['required', 'integer'],
        ])->validate();

        $account = $student->tuitionAccounts()
            ->whereKey((int) $validated['tuition_account_id'])
            ->first();

        abort_if($account === null, 404);

        return [$student, $account];
    }

    private function walletBalanceFiat(StudentWalletSnapshot $snapshot, CurrencyConverter $converter, CurrencyCode $displayCurrency): ?string
    {
        if (! $snapshot->isReadable() || ! is_string($snapshot->balance)) {
            return null;
        }

        $baseUnits = $this->usdcBaseUnitsFrom($snapshot->balance);
        if ($baseUnits === null) {
            return null;
        }

        try {
            $fiat = new Money($converter->usdcToFiat($baseUnits, $displayCurrency), $displayCurrency);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $displayCurrency->symbol().$fiat->format().' '.$displayCurrency->value;
    }

    private function usdcBaseUnitsFrom(string $decimal): ?int
    {
        if (! preg_match('/^\d+(\.\d+)?$/', $decimal)) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';

        if (strlen($whole) > 12) {
            return null;
        }

        $fraction = str_pad(substr($fraction, 0, 6), 6, '0');

        return ((int) $whole) * 1_000_000 + (int) $fraction;
    }

    private function billPaymentStateLabel(StudentBillPayment $payment): string
    {
        if ($payment->isRehearsal()) {
            return 'Rehearsal — nothing was sent.';
        }

        return match ($payment->submission_state) {
            StudentBillPayment::SUBMISSION_CONSENTED => 'Accepted. Waiting for the queue to dispatch it.',
            StudentBillPayment::SUBMISSION_PREPARED => 'Queued for sending. Not sent yet.',
            StudentBillPayment::SUBMISSION_SUBMITTING => 'Sent to the payment provider. The outcome is not known yet — do not send it again.',
            StudentBillPayment::SUBMISSION_ACCEPTED => 'The provider accepted it. Waiting for it to appear on chain.',
            StudentBillPayment::SUBMISSION_AWAITING_VERIFICATION => 'On chain. Checking that the exact amount moved.',
            StudentBillPayment::SUBMISSION_VERIFIED => $payment->isAllocated()
                ? 'Verified on chain and applied to your bill.'
                : 'Verified on chain. Applying it to your bill.',
            StudentBillPayment::SUBMISSION_NEEDS_REVIEW => 'A staff member must finish this one. Do not send it again.',
            default => 'Status unavailable.',
        };
    }
}
