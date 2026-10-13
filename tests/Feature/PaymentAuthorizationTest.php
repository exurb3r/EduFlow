<?php

declare(strict_types=1);

use App\Actions\EnrollPaymentReviewer;
use App\Actions\ReleaseReservedCapacity;
use App\Actions\ReviewReservationRelease;
use App\Actions\ReviewVendorPayment;
use App\Filament\Pages\PaymentReviews;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\PaymentAuthorization;
use App\Models\PaymentReservation;
use App\Models\PaymentReviewerEnrollment;
use App\Models\PaymentSubmissionOutbox;
use App\Models\Student;
use App\Models\Transaction;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;
use function Pest\Laravel\travel;

/** Narrow a Livewire test handle to the page it booted, for computed-page access. */
function paymentReviewsInstance(Testable $page): PaymentReviews
{
    $instance = $page->instance();

    if (! $instance instanceof PaymentReviews) {
        throw new RuntimeException('The Livewire test did not boot the payment review page.');
    }

    return $instance;
}

test('independent MFA payment approval binds exact reserved amount without sending or changing local accounts', function (string $method): void {
    $c = paymentAuthorizationContext($method);
    $input = paymentAuthorizationInput($c);
    $authorization = app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], $input);
    expect($authorization->hasValidEvidence($c['drafts'][0], $c['hold']))->toBeTrue()
        ->and($authorization->snapshot['amount_base_units'])->toBe('25000001')->and($authorization->snapshot['max_fee_base_units'])->toBe('2')
        ->and($authorization->snapshot['balance_observation']['native_units'])->toBe('100000000000000000007')
        ->and($authorization->evidence()['payment_approved'])->toBeTrue()->and($authorization->evidence()['can_execute'])->toBeFalse()
        ->and($authorization->evidence()['payments_submitted'])->toBe(0)->and($authorization->evidence()['current_execution_checks_required'])->toBeTrue()
        ->and($c['drafts'][0]->evidence()['approved'])->toBeTrue()->and($c['drafts'][0]->evidence()['can_execute'])->toBeFalse()
        ->and($c['drafts'][0]->authorization?->id)->toBe($authorization->id)
        ->and(Gate::forUser($c['reviewer'])->allows('execute', $c['drafts'][0]))->toBeFalse()
        ->and($c['wallet']->fresh()->balance)->toBe(999999.0)->and($c['budget']->fresh()->remaining_amount)->toBe(999999.0)
        ->and(Invoice::query()->where('status', '!=', 'pending')->count())->toBe(0)
        ->and(Student::query()->count())->toBe(0)->and(Transaction::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'reserved_vendor_payment_reviewed')->count())->toBe(1);
    $payload = json_encode($authorization->evidence(), JSON_THROW_ON_ERROR);
    expect($payload)->not->toContain($c['secret']);
    expect($payload)->not->toContain($input['mfa_code']);
    expect($payload)->not->toContain('factor_fingerprint');
    expect(json_encode($authorization->toArray(), JSON_THROW_ON_ERROR))->not->toContain('factor_fingerprint');
    expect(json_encode($authorization->toArray(), JSON_THROW_ON_ERROR))->not->toContain('snapshot"');
})->with(['filament_app', 'fortify_totp']);

test('same review retry returns historical evidence without renewing expired payment authority or using TOTP again', function (): void {
    $c = paymentAuthorizationContext();
    $input = paymentAuthorizationInput($c);
    $first = app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], $input);
    travel(4)->minutes();
    $retry = app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], $input);
    expect($retry->id)->toBe($first->id)->and($retry->evidence()['approval_recorded'])->toBeTrue()
        ->and($retry->evidence()['payment_approved'])->toBeFalse()->and($retry->evidence()['authorization_unexpired'])->toBeFalse()
        ->and(PaymentAuthorization::query()->count())->toBe(1)->and(Activity::query()->where('event', 'reserved_vendor_payment_reviewed')->count())->toBe(1);
});

test('new review identity decision amount or expiry cannot replace existing authority', function (string $case): void {
    $c = paymentAuthorizationContext();
    $input = paymentAuthorizationInput($c);
    app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], $input);
    if ($case === 'key') {
        $input['request_key'] = (string) Str::uuid();
    } elseif ($case === 'decision') {
        $input['decision'] = 'reject_payment';
        $input['valid_until'] = null;
    } elseif ($case === 'reason') {
        $input['reason'] = 'Different instruction.';
    } elseif ($case === 'expiry') {
        $input['valid_until'] = now()->addMinutes(4)->utc()->toIso8601String();
    } else {
        $input['intent_digest'] = str_repeat('0', 64);
    }
    expect(fn () => app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], $input))->toThrow(ValidationException::class)
        ->and(PaymentAuthorization::query()->count())->toBe(1);
})->with(['key', 'decision', 'reason', 'expiry', 'digest']);

test('same authenticator timestep cannot approve another bill even through different MFA provider', function (string $method): void {
    $c = paymentAuthorizationContext($method);
    $secondHold = reserveSecondAuthorizationBill($c);
    app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], paymentAuthorizationInput($c));
    $c['hold'] = $secondHold;
    $input = paymentAuthorizationInput($c, index: 1);
    if ($method === 'filament_app') {
        $c['reviewer']->forceFill(['two_factor_secret' => encrypt($c['secret']), 'two_factor_confirmed_at' => now()])->save();
        $input['mfa_method'] = 'fortify_totp';
    }
    expect(fn () => app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][1], $input))->toThrow(ValidationException::class)
        ->and(PaymentAuthorization::query()->count())->toBe(1);
    travel(31)->seconds();
    $input['mfa_method'] = $method;
    $input['mfa_code'] = app(Google2FA::class)->oathTotp($c['secret'], intdiv(now()->timestamp, 30));
    expect(app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][1], $input)->id)->toBeGreaterThan(1)
        ->and(PaymentAuthorization::query()->count())->toBe(2);
})->with(['filament_app', 'fortify_totp']);

test('payment reviewer needs explicit permission verified email and separation even as super admin', function (string $case): void {
    $c = paymentAuthorizationContext();
    $reviewer = $c['reviewer'];
    if ($case === 'permission') {
        $reviewer->revokePermissionTo('AuthorizePayment:PaymentIntent');
    } elseif ($case === 'email') {
        $reviewer->forceFill(['email_verified_at' => null])->save();
    } elseif ($case === 'role') {
        $reviewer->syncRoles([]);
    } elseif ($case === 'role-only-permission') {
        $reviewer->revokePermissionTo('AuthorizePayment:PaymentIntent');
        Role::findOrCreate('admin', 'web')->givePermissionTo('AuthorizePayment:PaymentIntent');
    } else {
        $reviewer = $c['maker'];
        $reviewer->assignRole(Role::findOrCreate('super_admin', 'web'));
        $reviewer->givePermissionTo('AuthorizePayment:PaymentIntent');
    }
    expect(fn () => app(ReviewVendorPayment::class)->handle($reviewer, $c['drafts'][0], paymentAuthorizationInput($c)))->toThrow(AuthorizationException::class)
        ->and(PaymentAuthorization::query()->count())->toBe(0);
})->with(['permission', 'email', 'role', 'role-only-permission', 'maker']);

test('broad Gate override cannot grant payment permission through direct action or HTTP', function (): void {
    $c = paymentAuthorizationContext();
    $c['reviewer']->revokePermissionTo('AuthorizePayment:PaymentIntent');
    Gate::before(fn (): bool => true);
    actingAs($c['reviewer']);
    postJson(route('finance.payment-authorizations.store', $c['drafts'][0]), paymentAuthorizationInput($c))->assertForbidden();
    expect(PaymentAuthorization::query()->count())->toBe(0);
});

test('payment review refuses impersonated session despite valid factor and permissions', function (): void {
    $c = paymentAuthorizationContext();
    session(['impersonated_by' => $c['maker']->id]);
    expect(fn () => app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], paymentAuthorizationInput($c)))->toThrow(ValidationException::class)
        ->and(PaymentAuthorization::query()->count())->toBe(0);
});

test('stale bill vendor treasury budget driver chain or cash blocks payment authorization', function (string $case): void {
    $c = paymentAuthorizationContext();
    $input = paymentAuthorizationInput($c);
    if ($case === 'invoice') {
        Invoice::query()->whereKey($c['drafts'][0]->invoice_id)->update(['status' => 'paid']);
    } elseif ($case === 'vendor') {
        $c['vendor']->update(['wallet_address' => '0x'.str_repeat('3', 40)]);
    } elseif ($case === 'wallet') {
        $c['wallet']->update(['status' => 'inactive']);
    } elseif ($case === 'budget') {
        $c['budget']->update(['status' => 'inactive']);
    } elseif ($case === 'driver') {
        config(['lepton.default' => 'fake']);
    } elseif ($case === 'chain') {
        config(['lepton.arc.chain' => 'ARC', 'lepton.arc.chain_id' => 5042]);
    } elseif ($case === 'expiry') {
        travel(11)->minutes();
        $input = paymentAuthorizationInput($c);
    } elseif ($case === 'cash') {
        reserveSecondAuthorizationBill($c);
        stubPaymentAuthorizationBalance('50000000000000000000');
    } else {
        DB::table((new PaymentReservation)->getTable())->where('id', $c['hold']->id)->update(['amount_base_units' => 1]);
    }
    expect(fn () => app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], $input))->toThrow(ValidationException::class)
        ->and(PaymentAuthorization::query()->count())->toBe(0)->and(PaymentReservation::query()->count())->toBeGreaterThan(0)
        ->and(Transaction::query()->count())->toBe(0);
})->with(['invoice', 'vendor', 'wallet', 'budget', 'driver', 'chain', 'expiry', 'cash', 'tamper']);

test('reject or hold records independent decision without releasing held funds despite funding expiry', function (string $decision): void {
    $c = paymentAuthorizationContext();
    travel(11)->minutes();
    app()->instance(ArcNetworkGateway::class, Mockery::mock(ArcNetworkGateway::class));
    $review = app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], paymentAuthorizationInput($c, $decision));
    expect($review->hasValidEvidence($c['drafts'][0], $c['hold']))->toBeTrue()->and($review->evidence()['payment_approved'])->toBeFalse()
        ->and($review->evidence()['funds_reserved'])->toBeTrue()->and($review->evidence()['can_execute'])->toBeFalse()
        ->and(PaymentReservation::query()->count())->toBe(1)->and(Transaction::query()->count())->toBe(0);
})->with(['reject_payment', 'hold_payment']);

test('fake payment approval stays simulation only and never grants network payment authority', function (): void {
    $c = paymentAuthorizationContext(fake: true);
    $review = app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], paymentAuthorizationInput($c));
    expect($review->evidence()['is_fake'])->toBeTrue()->and($review->evidence()['authority_scope'])->toBe('simulation_only')
        ->and($review->evidence()['approval_recorded'])->toBeTrue()->and($review->evidence()['payment_approved'])->toBeFalse()
        ->and($review->evidence()['can_execute'])->toBeFalse();
});

test('changing account authenticator cannot bypass independently pinned payment enrollment', function (): void {
    $c = paymentAuthorizationContext();
    $c['secret'] = app(Google2FA::class)->generateSecretKey();
    $c['reviewer']->saveAppAuthenticationSecret($c['secret']);
    expect($c['enrollment']->matchesCurrentFactor($c['reviewer']))->toBeFalse()
        ->and(fn () => app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], paymentAuthorizationInput($c)))->toThrow(ValidationException::class)
        ->and(PaymentAuthorization::query()->count())->toBe(0);
});

test('revoked reviewer permission or changed authenticator invalidates current payment authority without rewriting history', function (string $case): void {
    $c = paymentAuthorizationContext();
    $authorization = app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], paymentAuthorizationInput($c));
    if ($case === 'permission') {
        $c['reviewer']->revokePermissionTo('AuthorizePayment:PaymentIntent');
    } else {
        $c['reviewer']->saveAppAuthenticationSecret(app(Google2FA::class)->generateSecretKey());
    }
    expect($authorization->evidence()['approval_recorded'])->toBeTrue()->and($authorization->evidence()['payment_approved'])->toBeFalse()
        ->and($authorization->evidence()['reviewer_authority_current'])->toBeFalse()->and($authorization->evidence()['can_execute'])->toBeFalse();
})->with(['permission', 'factor']);

test('TOTP already used in login cannot authorize payment review', function (string $provider): void {
    $c = paymentAuthorizationContext($provider === 'filament' ? 'filament_app' : 'fortify_totp');
    $input = paymentAuthorizationInput($c);
    $key = $provider === 'filament' ? 'filament.app_authentication_codes.'.md5($c['secret']) : 'fortify.2fa_codes.'.md5($input['mfa_code']);
    Cache::put($key, intdiv(now()->timestamp, 30), 600);
    expect(fn () => app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], $input))->toThrow(ValidationException::class)
        ->and(PaymentAuthorization::query()->count())->toBe(0);
})->with(['filament', 'fortify']);

test('successful review publishes login replay markers only after durable commit', function (): void {
    $c = paymentAuthorizationContext();
    $input = paymentAuthorizationInput($c);
    $level = DB::transactionLevel();
    app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], $input);
    app('db.transactions')->commit(DB::connection()->getName(), $level, $level - 1);
    expect(Cache::get('filament.app_authentication_codes.'.md5($c['secret'])))->toBe(intdiv(now()->timestamp, 30))
        ->and(Cache::get('fortify.2fa_codes.'.md5($input['mfa_code'])))->toBe(intdiv(now()->timestamp, 30));
});

test('reviewer enrollment requires different MFA checked staff and cannot replace pinned factor', function (): void {
    $c = paymentAuthorizationContext();
    $input = ['request_key' => $c['enrollment']->request_key, 'reviewer_mfa_method' => 'filament_app',
        'verification_reference' => 'Synthetic independent identity and authenticator check.', 'password' => 'password', 'mfa_method' => 'filament_app',
        'mfa_code' => app(Google2FA::class)->oathTotp($c['checkerSecret'], intdiv(now()->timestamp, 30))];
    expect(app(EnrollPaymentReviewer::class)->handle($c['checker'], $c['reviewer'], $input)->id)->toBe($c['enrollment']->id)
        ->and(fn () => app(EnrollPaymentReviewer::class)->handle($c['reviewer'], $c['reviewer'], $input))->toThrow(ValidationException::class)
        ->and(fn () => $c['enrollment']->update(['mfa_method' => 'fortify_totp']))->toThrow(LogicException::class);
    $input['request_key'] = (string) Str::uuid();
    expect(fn () => app(EnrollPaymentReviewer::class)->handle($c['checker'], $c['reviewer'], $input))->toThrow(ValidationException::class)
        ->and(PaymentReviewerEnrollment::query()->count())->toBe(1);
});

test('fresh password enrolled TOTP and bounded UTC expiry are mandatory', function (string $case): void {
    $c = paymentAuthorizationContext();
    $input = paymentAuthorizationInput($c);
    if ($case === 'password') {
        $input['password'] = 'wrong-password';
    } elseif ($case === 'code') {
        $input['mfa_code'] = 'wrong-code';
    } elseif ($case === 'enrollment') {
        $c['reviewer']->saveAppAuthenticationSecret(null);
    } elseif ($case === 'unconfirmed') {
        $c['reviewer']->forceFill(['two_factor_secret' => encrypt($c['secret']), 'two_factor_confirmed_at' => null])->save();
        $input['mfa_method'] = 'fortify_totp';
    } elseif ($case === 'missing-expiry') {
        $input['valid_until'] = null;
    } elseif ($case === 'wide-expiry') {
        $input['valid_until'] = now()->addMinutes(6)->utc()->toIso8601String();
    } elseif ($case === 'expired') {
        $input['valid_until'] = now()->utc()->toIso8601String();
    } else {
        $input['valid_until'] = now()->addMinutes(3)->setTimezone('Asia/Manila')->toIso8601String();
    }
    expect(fn () => app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], $input))->toThrow(ValidationException::class)
        ->and(PaymentAuthorization::query()->count())->toBe(0);
})->with(['password', 'code', 'enrollment', 'unconfirmed', 'missing-expiry', 'wide-expiry', 'expired', 'non-utc']);

test('authentication rate limit survives failed database review transactions', function (): void {
    $c = paymentAuthorizationContext();
    $bad = paymentAuthorizationInput($c);
    $bad['password'] = 'wrong-password';
    for ($attempt = 0; $attempt < 5; $attempt++) {
        expect(fn () => app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], $bad))->toThrow(ValidationException::class);
    }
    expect(RateLimiter::attempts('finance.payment-review-mfa.'.$c['reviewer']->id))->toBe(5)
        ->and(fn () => app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], paymentAuthorizationInput($c)))->toThrow(ValidationException::class)
        ->and(PaymentAuthorization::query()->count())->toBe(0);
});

test('payment authorization write failure rolls back audit and MFA consumption for safe retry', function (): void {
    $c = paymentAuthorizationContext();
    $input = paymentAuthorizationInput($c);
    DB::unprepared("CREATE TRIGGER reject_test_payment_authorization BEFORE INSERT ON payment_authorizations
        BEGIN SELECT RAISE(ABORT, 'Injected authorization write failure'); END");
    try {
        expect(fn () => app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], $input))->toThrow(QueryException::class)
            ->and(PaymentAuthorization::query()->count())->toBe(0)->and(Activity::query()->where('event', 'reserved_vendor_payment_reviewed')->count())->toBe(0);
    } finally {
        DB::unprepared('DROP TRIGGER reject_test_payment_authorization');
    }
    expect(app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], $input)->hasValidEvidence($c['drafts'][0], $c['hold']))->toBeTrue();
});

test('authorization evidence rejects mutation deletion tampering and destructive rollback', function (): void {
    $c = paymentAuthorizationContext();
    $review = app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], paymentAuthorizationInput($c));
    expect(fn () => $review->update(['decision' => 'reject_payment']))->toThrow(LogicException::class)
        ->and(fn () => $review->delete())->toThrow(LogicException::class)
        ->and(fn () => DB::table((new PaymentReservation)->getTable())->where('id', $c['hold']->id)->delete())->toThrow(QueryException::class);
    $migration = require database_path('migrations/2026_10_09_142623_create_payment_authorizations_table.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    DB::table((new PaymentAuthorization)->getTable())->where('id', $review->id)->update(['decision' => 'reject_payment']);
    expect($review->fresh()->evidence()['evidence_valid'])->toBeFalse()->and($review->fresh()->evidence()['payment_approved'])->toBeFalse();
});

test('payment review HTTP endpoints enforce authority exact input and private evidence response', function (): void {
    $c = paymentAuthorizationContext();
    $input = paymentAuthorizationInput($c);
    $url = route('finance.payment-authorizations.store', $c['drafts'][0]);
    actingAs($c['maker']);
    postJson($url, $input)->assertForbidden();
    actingAs($c['reviewer']);
    postJson($url, [...$input, 'amount_base_units' => 1])->assertUnprocessable()->assertJsonValidationErrors('amount_base_units');
    postJson($url, $input)->assertSuccessful()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.payment_approved', true)->assertJsonPath('data.can_execute', false)->assertJsonMissingPath('data.factor_fingerprint');
    getJson(route('finance.payment-authorizations.show', $c['drafts'][0]))->assertSuccessful()->assertJsonPath('data.decision', 'approve_payment');
    $outsider = User::factory()->create();
    actingAs($outsider);
    getJson(route('finance.payment-authorizations.show', $c['drafts'][0]))->assertForbidden();
});

test('finance payment review page displays exact held evidence and records independent MFA decision', function (): void {
    $c = paymentAuthorizationContext();
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    actingAs($c['reviewer']);
    get(PaymentReviews::getUrl(panel: 'finance'))->assertSuccessful()->assertSee('Approval is authority, not settlement.');
    $page = Livewire::test(PaymentReviews::class)->assertSee('Payment review queue')->assertSee('25.000001 USDC');
    $page->__call('mountAction', [TestAction::make('viewPaymentEvidence')->table($c['hold'])]);
    $page->assertSet('mountedReservationDigest', $c['hold']->snapshot_digest)
        ->assertSet('mountedIntentDigest', $c['drafts'][0]->snapshot_digest);
    $page->__call('unmountAction', []);
    $input = paymentAuthorizationInput($c);
    $page->__call('callAction', [TestAction::make('reviewReservedPayment')->table($c['hold']), [
        'decision' => 'approve_payment', 'reason' => $input['reason'], 'valid_until' => $input['valid_until'],
        'password' => $input['password'], 'mfa_code' => $input['mfa_code'], 'mfa_method' => 'filament_app', 'attestation' => true,
    ]]);
    $page->__call('assertHasNoFormErrors', []);
    expect(PaymentAuthorization::query()->count())->toBe(1)->and(Transaction::query()->count())->toBe(0)
        ->and(PaymentAuthorization::query()->firstOrFail()->evidence()['can_execute'])->toBeFalse();
});

test('finance page independently enrolls another verified payment reviewer without granting payment or sending money', function (): void {
    $c = paymentAuthorizationContext();
    /** @var User $target */
    $target = User::factory()->create();
    $target->assignRole(Role::findOrCreate('finance_officer', 'web'));
    $target->givePermissionTo('AuthorizePayment:PaymentIntent');
    $target->saveAppAuthenticationSecret(app(Google2FA::class)->generateSecretKey());
    travel(31)->seconds();
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    actingAs($c['checker']);
    $page = Livewire::test(PaymentReviews::class);
    $page->__call('callAction', ['enrollPaymentReviewer', [
        'reviewer_id' => $target->id, 'reviewer_mfa_method' => 'filament_app',
        'verification_reference' => 'Synthetic independent staff and authenticator verification.',
        'password' => 'password', 'mfa_method' => 'filament_app',
        'mfa_code' => app(Google2FA::class)->oathTotp($c['checkerSecret'], intdiv(now()->timestamp, 30)), 'attestation' => true,
    ]]);
    $page->__call('assertHasNoFormErrors', []);
    expect(PaymentReviewerEnrollment::query()->where('reviewer_id', $target->id)->firstOrFail()->matchesCurrentFactor($target))->toBeTrue()
        ->and(PaymentAuthorization::query()->count())->toBe(0)->and(Transaction::query()->count())->toBe(0);
});

test('finance page hides review from staff without separately granted payment authority', function (): void {
    $c = paymentAuthorizationContext();
    $c['reviewer']->revokePermissionTo('AuthorizePayment:PaymentIntent');
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    actingAs($c['reviewer']);
    $page = Livewire::test(PaymentReviews::class);
    $page->__call('assertActionHidden', [TestAction::make('reviewReservedPayment')->table($c['hold'])]);
    expect(PaymentAuthorization::query()->count())->toBe(0);
});

test('failed HTML payment review never flashes password or MFA code into session', function (): void {
    $c = paymentAuthorizationContext();
    $input = paymentAuthorizationInput($c);
    $input['reason'] = '';
    actingAs($c['reviewer']);
    post(route('finance.payment-authorizations.store', $c['drafts'][0]), $input)->assertSessionHasErrors('reason')
        ->assertSessionMissing('_old_input.password')->assertSessionMissing('_old_input.mfa_code');
});

test('payment approval workspace reports historical decisions separately from awaiting review', function (string $decision, string $bucket): void {
    $c = paymentAuthorizationContext();
    $second = reserveSecondAuthorizationBill($c);
    app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], paymentAuthorizationInput($c, $decision));
    travel(4)->minutes();
    $c['reviewer']->assignRole(Role::findOrCreate('super_admin', 'web'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    actingAs($c['reviewer']);

    $page = Livewire::test(PaymentReviews::class)
        ->assertSee('Historical decisions, not current authority or settlement')
        ->assertSee('Your reviewer setup')
        ->assertSee('Account prerequisites met');
    $overview = paymentReviewsInstance($page)->reviewOverview();
    expect($overview['awaiting'])->toBe(1)->and($overview[$bucket])->toBe(1)->and($overview['released'])->toBe(0);
    $page->__call('filterTable', ['review_status', 'awaiting']);
    $page->__call('assertCanSeeTableRecords', [[$second]]);
    $page->__call('assertCanNotSeeTableRecords', [[$c['hold']]]);
    $page->__call('filterTable', ['review_status', $bucket]);
    $page->__call('assertCanSeeTableRecords', [[$c['hold']]]);
    $page->__call('assertCanNotSeeTableRecords', [[$second]]);
    expect(Transaction::query()->count())->toBe(0);
})->with([
    ['approve_payment', 'approved'], ['hold_payment', 'held_or_rejected'], ['reject_payment', 'held_or_rejected'],
]);

test('payment approval workspace explains missing reviewer prerequisites without exposing a review action', function (string $case): void {
    $c = paymentAuthorizationContext();
    if ($case === 'permission') {
        $c['reviewer']->revokePermissionTo('AuthorizePayment:PaymentIntent');
    } elseif ($case === 'email') {
        $c['reviewer']->forceFill(['email_verified_at' => null])->save();
    } else {
        $c['reviewer']->saveAppAuthenticationSecret(app(Google2FA::class)->generateSecretKey());
    }
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    actingAs($c['reviewer']);
    $page = Livewire::test(PaymentReviews::class)->assertSee('Setup required')->assertSee('Reviewer setup required');
    $page->__call('assertActionHidden', [TestAction::make('reviewReservedPayment')->table($c['hold'])]);
    expect(paymentReviewsInstance($page)->reviewerReadiness()['ready'])->toBeFalse()
        ->and(PaymentAuthorization::query()->count())->toBe(0)->and(Transaction::query()->count())->toBe(0);
})->with(['permission', 'email', 'factor']);

test('payment approval workspace excludes released capacity from awaiting reviews and preserves readable history', function (): void {
    $c = paymentAuthorizationContext();
    $proposer = User::factory()->create()->assignRole(Role::findOrCreate('admin', 'web'));
    $proposal = app(ReleaseReservedCapacity::class)->handle($proposer, $c['hold'], (string) Str::uuid(), $c['hold']->snapshot_digest, 'Capacity no longer needed.');
    app(ReviewReservationRelease::class)->handle($c['reviewer'], $proposal, $proposal->content_digest, 'approve_release', 'Capacity no longer needed.');
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    actingAs($c['reviewer']);
    $page = Livewire::test(PaymentReviews::class)->assertSee('Capacity released · history only');
    expect(paymentReviewsInstance($page)->reviewOverview())->toBe(['awaiting' => 0, 'approved' => 0, 'held_or_rejected' => 0, 'released' => 1]);
    $page->__call('assertActionHidden', [TestAction::make('reviewReservedPayment')->table($c['hold'])]);
    $page->__call('mountAction', [TestAction::make('viewPaymentEvidence')->table($c['hold'])]);
    $page->assertSet('mountedPaymentEvidence.reservation_state', 'Released — application capacity returned');
    expect(PaymentAuthorization::query()->count())->toBe(0)->and(Transaction::query()->count())->toBe(0);
});

test('payment approval workspace fails closed when institution context becomes ambiguous', function (): void {
    $c = paymentAuthorizationContext();
    Organization::factory()->create();
    config(['eduflow.institution_id' => $c['institution']->id]);
    $c['reviewer']->assignRole(Role::findOrCreate('super_admin', 'web'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    actingAs($c['reviewer']);
    get(PaymentReviews::getUrl(panel: 'admin'))->assertForbidden();
    expect(PaymentAuthorization::query()->count())->toBe(0);
});

test('payment approval workspace labels simulation approval as historical rather than network authority', function (): void {
    $c = paymentAuthorizationContext(fake: true);
    app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], paymentAuthorizationInput($c));
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    actingAs($c['reviewer']);
    $page = Livewire::test(PaymentReviews::class)->assertSee('Simulation only')
        ->assertSee('Historical decisions, not current authority or settlement');
    expect(paymentReviewsInstance($page)->reviewOverview()['approved'])->toBe(1)
        ->and(PaymentAuthorization::query()->firstOrFail()->evidence()['payment_approved'])->toBeFalse()
        ->and(PaymentSubmissionOutbox::query()->count())->toBe(0);
});

test('payment approval workspace explains maker checker separation even when account prerequisites are met', function (): void {
    $c = paymentAuthorizationContext();
    $c['maker']->givePermissionTo('AuthorizePayment:PaymentIntent');
    $secret = app(Google2FA::class)->generateSecretKey();
    $c['maker']->saveAppAuthenticationSecret($secret);
    travel(31)->seconds();
    app(EnrollPaymentReviewer::class)->handle($c['checker'], $c['maker'], [
        'request_key' => (string) Str::uuid(), 'reviewer_mfa_method' => 'filament_app', 'verification_reference' => 'Synthetic independent maker factor verification.',
        'password' => 'password', 'mfa_method' => 'filament_app',
        'mfa_code' => app(Google2FA::class)->oathTotp($c['checkerSecret'], intdiv(now()->timestamp, 30)),
    ]);
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    actingAs($c['maker']);
    $page = Livewire::test(PaymentReviews::class)->assertSee('Account prerequisites met')->assertSee('Another independent reviewer required');
    $page->__call('assertActionHidden', [TestAction::make('reviewReservedPayment')->table($c['hold'])]);
    expect(PaymentAuthorization::query()->count())->toBe(0);
});
