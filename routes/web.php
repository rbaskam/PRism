<?php

use App\Http\Controllers\AlertController;
use App\Http\Controllers\AppActivityController;
use App\Http\Controllers\AppRestartController;
use App\Http\Controllers\AvailableRepositoryController;
use App\Http\Controllers\CodeChangeController;
use App\Http\Controllers\CodeQuestionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExternalLinkController;
use App\Http\Controllers\GithubConnectionController;
use App\Http\Controllers\GithubDeviceFlowController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\LocalCheckoutScanController;
use App\Http\Controllers\LocalFolderController;
use App\Http\Controllers\LockController;
use App\Http\Controllers\Onboarding\OnboardingController;
use App\Http\Controllers\PendingReviewCommentController;
use App\Http\Controllers\PostedFindingController;
use App\Http\Controllers\PullRequestCommentController;
use App\Http\Controllers\PullRequestController;
use App\Http\Controllers\PullRequestFileContentController;
use App\Http\Controllers\PullRequestFileViewController;
use App\Http\Controllers\PullRequestReviewController;
use App\Http\Controllers\PullRequestSnoozeController;
use App\Http\Controllers\ReplyDraftController;
use App\Http\Controllers\RepositoryController;
use App\Http\Controllers\ReviewFindingController;
use App\Http\Controllers\ReviewRunController;
use App\Http\Controllers\ReviewThreadController;
use App\Http\Controllers\Settings\AboutController;
use App\Http\Controllers\Settings\ClaudeSettingsController;
use App\Http\Controllers\Settings\GithubSettingsController;
use App\Http\Controllers\Settings\NotificationSettingsController;
use App\Http\Controllers\Settings\ReviewSettingsController;
use App\Http\Controllers\Settings\SecuritySettingsController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\SyncOnReturnController;
use App\Http\Controllers\TrackedRepositoryController;
use App\Http\Middleware\EnsureAppIsUnlocked;
use App\Http\Middleware\EnsureGithubIsConnected;
use App\Http\Middleware\EnsureOnboardingIsComplete;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * The lock screen, shown when the app lock (Settings → Security) is on.
 */
Route::get('lock', [LockController::class, 'show'])->name('lock.show');
Route::post('lock/pin', [LockController::class, 'unlockWithPin'])->name('lock.pin');
Route::post('lock/biometrics', [LockController::class, 'unlockWithBiometrics'])->name('lock.biometrics');
Route::post('lock', [LockController::class, 'lock'])->name('lock.store');

/*
 * Restarts the desktop app when its server has stopped answering properly.
 */
Route::post('app/restart', AppRestartController::class)->name('app.restart');

Route::middleware(EnsureAppIsUnlocked::class)->group(function () {
    Route::post('activity', AppActivityController::class)->name('activity.store');

    /*
     * Step 1 of onboarding: connect GitHub.
     */
    Route::get('onboarding/github', [OnboardingController::class, 'github'])->name('onboarding.github');
    Route::post('github/connection/token', [GithubConnectionController::class, 'storeToken'])->name('github.connection.token');
    Route::post('github/connection/cli', [GithubConnectionController::class, 'storeCliToken'])->name('github.connection.cli');
    Route::post('github/device', [GithubDeviceFlowController::class, 'store'])->name('github.device.store');
    Route::post('github/device/poll', [GithubDeviceFlowController::class, 'poll'])->name('github.device.poll');
    Route::post('external-links', ExternalLinkController::class)->name('external-links.store');

    Route::middleware(EnsureGithubIsConnected::class)->group(function () {
        /*
         * Steps 2 and 3 of onboarding: choose repositories, then optionally connect Claude.
         */
        Route::get('onboarding/repositories', [OnboardingController::class, 'repositories'])->name('onboarding.repositories');
        Route::get('onboarding/claude', [OnboardingController::class, 'claude'])->name('onboarding.claude');
        Route::post('onboarding/complete', [OnboardingController::class, 'complete'])->name('onboarding.complete');

        Route::get('github/repositories', [AvailableRepositoryController::class, 'index'])->name('github.repositories');
        Route::put('tracked-repositories', [TrackedRepositoryController::class, 'update'])->name('tracked-repositories.update');
        Route::delete('github/connection', [GithubConnectionController::class, 'destroy'])->name('github.connection.destroy');
        Route::put('settings/claude', [ClaudeSettingsController::class, 'update'])->name('settings.claude.update');

        Route::middleware(EnsureOnboardingIsComplete::class)->group(function () {
            Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
            Route::post('sync', SyncController::class)->name('sync');
            Route::post('sync/on-return', SyncOnReturnController::class)->name('sync.on-return');

            Route::get('repositories', [RepositoryController::class, 'index'])->name('repositories.index');
            Route::get('repositories/{repository}', [RepositoryController::class, 'show'])->name('repositories.show');
            Route::patch('repositories/{repository}', [RepositoryController::class, 'update'])->name('repositories.update');
            Route::post('local-folders', LocalFolderController::class)->name('local-folders.store');
            Route::post('local-checkouts/scan', LocalCheckoutScanController::class)->name('local-checkouts.scan');

            Route::get('pull-requests/{pullRequest}', [PullRequestController::class, 'show'])->name('pull-requests.show');
            Route::post('pull-requests/{pullRequest}/review-runs', [ReviewRunController::class, 'store'])->name('pull-requests.review-runs.store');
            Route::patch('pull-requests/{pullRequest}/findings', [ReviewFindingController::class, 'bulkUpdate'])->name('pull-requests.findings.bulk-update');
            Route::post('pull-requests/{pullRequest}/reviews', [PullRequestReviewController::class, 'store'])->name('pull-requests.reviews.store');
            Route::post('pull-requests/{pullRequest}/snooze', [PullRequestSnoozeController::class, 'store'])->name('pull-requests.snooze.store');
            Route::delete('pull-requests/{pullRequest}/snooze', [PullRequestSnoozeController::class, 'destroy'])->name('pull-requests.snooze.destroy');
            Route::post('pull-requests/{pullRequest}/comments', [PullRequestCommentController::class, 'store'])->name('pull-requests.comments.store');
            Route::post('pull-requests/{pullRequest}/pending-comments', [PendingReviewCommentController::class, 'store'])->name('pull-requests.pending-comments.store');
            Route::patch('pending-comments/{pendingComment}', [PendingReviewCommentController::class, 'update'])->name('pending-comments.update');
            Route::delete('pending-comments/{pendingComment}', [PendingReviewCommentController::class, 'destroy'])->name('pending-comments.destroy');
            Route::post('pull-requests/{pullRequest}/threads/resolve', [ReviewThreadController::class, 'resolve'])->name('pull-requests.threads.resolve');
            Route::post('pull-requests/{pullRequest}/reply-drafts', [ReplyDraftController::class, 'store'])->name('pull-requests.reply-drafts.store');
            Route::put('pull-requests/{pullRequest}/file-views', [PullRequestFileViewController::class, 'update'])->name('pull-requests.file-views.update');
            Route::get('pull-requests/{pullRequest}/file-contents', PullRequestFileContentController::class)->name('pull-requests.file-contents.show');
            Route::post('pull-requests/{pullRequest}/code-questions', [CodeQuestionController::class, 'store'])->name('pull-requests.code-questions.store');
            Route::delete('code-questions/{codeQuestion}', [CodeQuestionController::class, 'destroy'])->name('code-questions.destroy');
            Route::post('pull-requests/{pullRequest}/code-changes', [CodeChangeController::class, 'store'])->name('pull-requests.code-changes.store');
            Route::post('code-changes/{codeChange}/push', [CodeChangeController::class, 'push'])->name('code-changes.push');
            Route::delete('code-changes/{codeChange}', [CodeChangeController::class, 'destroy'])->name('code-changes.destroy');

            Route::patch('findings/{finding}', [ReviewFindingController::class, 'update'])->name('findings.update');
            Route::post('findings/{finding}/post', [PostedFindingController::class, 'store'])->name('findings.post');

            Route::get('inbox', [InboxController::class, 'index'])->name('inbox.index');
            Route::post('inbox/read-all', [InboxController::class, 'markAllAsRead'])->name('inbox.read-all');
            Route::get('alerts', [AlertController::class, 'index'])->name('alerts.index');
            Route::post('alerts/read-all', [AlertController::class, 'markAllAsRead'])->name('alerts.read-all');
            Route::post('alerts/{alert}/read', [AlertController::class, 'markAsRead'])->name('alerts.read');
            Route::post('inbox/{notification}/read', [InboxController::class, 'markAsRead'])->name('inbox.read');
            Route::get('inbox/{notification}/open', [InboxController::class, 'open'])->name('inbox.open');

            Route::redirect('settings', 'settings/github')->name('settings');
            Route::get('settings/github', [GithubSettingsController::class, 'edit'])->name('settings.github');
            Route::put('settings/github', [GithubSettingsController::class, 'update'])->name('settings.github.update');
            Route::get('settings/claude', [ClaudeSettingsController::class, 'edit'])->name('settings.claude');
            Route::get('settings/review', [ReviewSettingsController::class, 'edit'])->name('settings.review');
            Route::put('settings/review', [ReviewSettingsController::class, 'update'])->name('settings.review.update');
            Route::get('settings/notifications', [NotificationSettingsController::class, 'edit'])->name('settings.notifications');
            Route::put('settings/notifications', [NotificationSettingsController::class, 'update'])->name('settings.notifications.update');
            Route::post('settings/notifications/test', [NotificationSettingsController::class, 'test'])->name('settings.notifications.test');
            Route::get('settings/security', [SecuritySettingsController::class, 'edit'])->name('settings.security');
            Route::get('settings/about', [AboutController::class, 'show'])->name('settings.about');
            Route::post('updates/check', [AboutController::class, 'check'])->name('updates.check');
            Route::post('whats-new/dismiss', [AboutController::class, 'dismissWhatsNew'])->name('whats-new.dismiss');
            Route::put('settings/security', [SecuritySettingsController::class, 'update'])->name('settings.security.update');
            Route::get('settings/appearance', fn () => Inertia::render('settings/Appearance'))->name('appearance');
        });
    });
});
