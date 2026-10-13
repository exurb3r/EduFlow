<?php

use App\Enums\SocialLoginProvider;
use App\Http\Controllers\AskEduFlowController;
use App\Http\Controllers\AssistanceRequestController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\AutonomousCycleController;
use App\Http\Controllers\BudgetSnapshotController;
use App\Http\Controllers\ChatHistoryController;
use App\Http\Controllers\CollectionBatchController;
use App\Http\Controllers\FinanceApprovalController;
use App\Http\Controllers\FundingWindowController;
use App\Http\Controllers\InvoiceVersionController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentAuthorizationController;
use App\Http\Controllers\PaymentIntentChangeController;
use App\Http\Controllers\PaymentReservationController;
use App\Http\Controllers\PaymentReviewerEnrollmentController;
use App\Http\Controllers\StudentAssistanceController;
use App\Http\Controllers\StudentBillController;
use App\Http\Controllers\StudentDashboardController;
use App\Http\Controllers\StudentPaymentsController;
use App\Http\Controllers\StudentTuitionPaymentController;
use App\Http\Controllers\StudentWalletController;
use App\Http\Controllers\VendorDestinationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', fn () => Inertia::render('welcome', [
    'canRegister' => Features::enabled(Features::registration()),
]))->name('home');

Route::get('/skills/design', fn () => Inertia::render('skills/design', [
    'canRegister' => Features::enabled(Features::registration()),
]))->name('skills.design');

Route::get('dashboard', [StudentDashboardController::class, 'index'])->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('student/dashboard', [StudentDashboardController::class, 'show'])->name('student.dashboard');
    Route::post('assistance-requests', [AssistanceRequestController::class, 'store'])->name('assistance-requests.store');
    Route::get('assistance/create', [AssistanceRequestController::class, 'create'])->name('assistance.create');
    Route::post('assistance', [AssistanceRequestController::class, 'storeIntake'])->middleware('throttle:10,1')->name('assistance.store');
    Route::get('assistance/{assistanceRequest}', [AssistanceRequestController::class, 'show'])->name('assistance.show');
    Route::post('student/ask-eduflow', [AskEduFlowController::class, 'ask'])->middleware('throttle:30,1')->name('student.ask');
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/{id}/read', [NotificationController::class, 'markAsRead'])->name('notifications.mark-as-read');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.mark-all-read');
    Route::get('financial-assistance', [StudentAssistanceController::class, 'index'])->name('financial-assistance.index');
    Route::post('financial-assistance', [StudentAssistanceController::class, 'store'])->name('financial-assistance.store');
    Route::get('payments', [StudentPaymentsController::class, 'index'])->name('payments.index');
    Route::get('student/wallet', [StudentWalletController::class, 'show'])->name('student.wallet.show');
    Route::post('student/wallet/refresh', [StudentWalletController::class, 'refresh'])->middleware('throttle:10,1')->name('student.wallet.refresh');
    Route::post('student/tuition/quote', [StudentTuitionPaymentController::class, 'quote'])->middleware('throttle:20,1')->name('student.tuition.quote');
    Route::post('student/tuition/pay', [StudentTuitionPaymentController::class, 'store'])->middleware('throttle:5,1')->name('student.tuition.pay');
    Route::get('student/tuition/attempts', [StudentTuitionPaymentController::class, 'index'])->middleware('throttle:60,1')->name('student.tuition.attempts');

    // Non-tuition bills. The quote/consent pair is the only route here that can
    // create anything, and neither can reach a signer: a GET cannot dispatch and
    // a POST cannot send, so a refresh button can never spend money.
    Route::get('student/bills', [StudentBillController::class, 'index'])->name('student.bills');
    Route::get('student/bills/status', [StudentBillController::class, 'status'])->middleware('throttle:60,1')->name('student.bills.status');
    Route::post('student/bills/classify', [StudentBillController::class, 'classify'])->middleware('throttle:20,1')->name('student.bills.classify');
    Route::post('student/bills/quote', [StudentBillController::class, 'quote'])->middleware('throttle:20,1')->name('student.bills.quote');
    Route::post('student/bills/consent', [StudentBillController::class, 'consent'])->middleware('throttle:5,1')->name('student.bills.consent');
    Route::patch('wallet', [StudentWalletController::class, 'update'])->name('wallet.update');
});

/*
| Human review of paused agent proposals. Authorisation is enforced inside
| ApprovalResumeGate rather than by middleware, so the route cannot be added
| without its checks and the role check lives in one place alongside the
| conversation-ownership check it belongs with.
*/
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::post('finance/autonomous-cycle', AutonomousCycleController::class)
        ->middleware('throttle:3,1')->name('finance.autonomous-cycle.store');

    Route::post('finance/collection-batches', [CollectionBatchController::class, 'store'])
        ->middleware('throttle:10,1')->name('finance.collection-batches.store');
    Route::get('finance/collection-batches/{collectionBatch}', [CollectionBatchController::class, 'show'])
        ->name('finance.collection-batches.show');
    Route::get('finance/collection-batches/{collectionBatch}/document', [CollectionBatchController::class, 'document'])
        ->name('finance.collection-batches.document');
    Route::post('finance/collection-batches/{collectionBatch}/review', [CollectionBatchController::class, 'review'])
        ->middleware('throttle:10,1')->name('finance.collection-batches.review');

    Route::post('finance/funding-windows', [FundingWindowController::class, 'store'])
        ->middleware('throttle:10,1')->name('finance.funding-windows.store');
    Route::get('finance/funding-windows/{fundingWindow}', [FundingWindowController::class, 'show'])
        ->name('finance.funding-windows.show');
    Route::post('finance/funding-windows/{fundingWindow}/approve', [FundingWindowController::class, 'approve'])
        ->middleware('throttle:10,1')->name('finance.funding-windows.approve');
    Route::post('finance/payment-intents/{paymentIntent}/reserve', [PaymentReservationController::class, 'store'])
        ->middleware('throttle:10,1')->name('finance.payment-reservations.store');
    Route::post('finance/payment-reviewers/{reviewer}/enroll', [PaymentReviewerEnrollmentController::class, 'store'])
        ->middleware('throttle:5,1')->name('finance.payment-reviewers.enroll');
    Route::post('finance/payment-intents/{paymentIntent}/authorize', [PaymentAuthorizationController::class, 'store'])
        ->middleware('throttle:5,1')->name('finance.payment-authorizations.store');
    Route::get('finance/payment-intents/{paymentIntent}/authorization', [PaymentAuthorizationController::class, 'show'])
        ->name('finance.payment-authorizations.show');

    Route::post('finance/payment-intents/{paymentIntent}/changes', [PaymentIntentChangeController::class, 'store'])
        ->middleware('throttle:10,1')->name('finance.payment-intent-changes.store');
    Route::get('finance/payment-intent-changes/{paymentIntentChange}', [PaymentIntentChangeController::class, 'show'])
        ->name('finance.payment-intent-changes.show');
    Route::post('finance/payment-intent-changes/{paymentIntentChange}/review', [PaymentIntentChangeController::class, 'review'])
        ->middleware('throttle:10,1')->name('finance.payment-intent-changes.review');

    Route::post('finance/vendor-destinations', [VendorDestinationController::class, 'store'])
        ->middleware('throttle:10,1')->name('finance.vendor-destinations.store');
    Route::get('finance/vendor-destinations/{vendorDestinationVersion}', [VendorDestinationController::class, 'show'])
        ->name('finance.vendor-destinations.show');
    Route::post('finance/vendor-destinations/{vendorDestinationVersion}/approve', [VendorDestinationController::class, 'approve'])
        ->middleware('throttle:10,1')->name('finance.vendor-destinations.approve');

    Route::post('finance/budget-snapshots', [BudgetSnapshotController::class, 'store'])
        ->middleware('throttle:10,1')->name('finance.budget-snapshots.store');
    Route::get('finance/budget-snapshots/{budgetSnapshot}', [BudgetSnapshotController::class, 'show'])
        ->name('finance.budget-snapshots.show');
    Route::post('finance/budget-snapshots/{budgetSnapshot}/plan', [BudgetSnapshotController::class, 'plan'])
        ->middleware('throttle:30,1')->name('finance.budget-snapshots.plan');

    Route::post('finance/invoice-versions', [InvoiceVersionController::class, 'store'])
        ->middleware('throttle:10,1')->name('finance.invoice-versions.store');
    Route::get('finance/invoice-versions/{invoiceVersion}', [InvoiceVersionController::class, 'show'])
        ->name('finance.invoice-versions.show');
    Route::post('finance/invoice-versions/{invoiceVersion}/review', [InvoiceVersionController::class, 'review'])
        ->middleware('throttle:10,1')->name('finance.invoice-versions.review');

    Route::post('finance/approvals/pending', [FinanceApprovalController::class, 'pending'])
        ->middleware('throttle:30,1')
        ->name('finance.approvals.pending');

    Route::post('finance/approvals/resume', [FinanceApprovalController::class, 'resume'])
        ->middleware('throttle:10,1')
        ->name('finance.approvals.resume');
});

Route::middleware(['auth'])->prefix('api/chats')->group(function (): void {
    Route::get('', [ChatHistoryController::class, 'sessions'])->name('api.chats.index');
    Route::post('', [ChatHistoryController::class, 'store'])->name('api.chats.store');
    Route::get('search', [ChatHistoryController::class, 'search'])->name('api.chats.search');
    Route::get('{session}', [ChatHistoryController::class, 'show'])->name('api.chats.show');
    Route::get('{session}/messages', [ChatHistoryController::class, 'messages'])->name('api.chats.messages');
    Route::post('{session}/messages', [ChatHistoryController::class, 'message'])->name('api.chats.message');
    Route::post('{session}/stream', [ChatHistoryController::class, 'stream'])->name('api.chats.stream');
    Route::delete('{session}', [ChatHistoryController::class, 'destroy'])->name('api.chats.destroy');
});

Route::middleware(['auth'])->get('api/ai-actions', fn () => response()->json(['data' => []]))->name('api.ai-actions.index');

Route::middleware('guest')->group(function (): void {
    Route::get('auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])
        ->whereIn('provider', SocialLoginProvider::values())
        ->name('auth.social.redirect');

    Route::get('auth/{provider}/callback', [SocialAuthController::class, 'callback'])
        ->whereIn('provider', SocialLoginProvider::values())
        ->name('auth.social.callback');
});

Route::impersonate();

require __DIR__.'/settings.php';
