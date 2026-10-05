<?php

use App\Enums\SocialLoginProvider;
use App\Http\Controllers\AskEduFlowController;
use App\Http\Controllers\AssistanceRequestController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\FinanceApprovalController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\StudentAssistanceController;
use App\Http\Controllers\StudentDashboardController;
use App\Http\Controllers\StudentPaymentsController;
use App\Http\Controllers\StudentWalletController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', fn () => Inertia::render('welcome', [
    'canRegister' => Features::enabled(Features::registration()),
]))->name('home');

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
    Route::patch('wallet', [StudentWalletController::class, 'update'])->name('wallet.update');
});

/*
| Human review of paused agent proposals. Authorisation is enforced inside
| ApprovalResumeGate rather than by middleware, so the route cannot be added
| without its checks and the role check lives in one place alongside the
| conversation-ownership check it belongs with.
*/
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::post('finance/approvals/pending', [FinanceApprovalController::class, 'pending'])
        ->middleware('throttle:30,1')
        ->name('finance.approvals.pending');

    Route::post('finance/approvals/resume', [FinanceApprovalController::class, 'resume'])
        ->middleware('throttle:10,1')
        ->name('finance.approvals.resume');
});

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
