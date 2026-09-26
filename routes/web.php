<?php

use App\Enums\SocialLoginProvider;
use App\Http\Controllers\AssistanceRequestController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\StudentDashboardController;
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
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/{id}/read', [NotificationController::class, 'markAsRead'])->name('notifications.mark-as-read');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.mark-all-read');
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
