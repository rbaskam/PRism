<?php

namespace App\Providers;

use App\Models\GithubAccount;
use App\Services\AppSettings;
use App\Services\Changelog;
use App\Services\Github\GithubClient;
use App\Services\Github\GithubDeviceFlow;
use App\Services\InstallationKey;
use App\Services\ReviewRunners;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * How long a desktop app session lasts: a year.
     */
    public const DESKTOP_SESSION_MINUTES = 60 * 24 * 365;

    /**
     * The name of the queue worker running in this process (its --name), if it is one.
     */
    private ?string $queueWorkerName = null;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(AppSettings::class);

        $this->app->singleton(InstallationKey::class, fn (): InstallationKey => new InstallationKey(storage_path('app/private/installation.key')));

        if ($this->runsAsInstalledDesktopApp()) {
            config(['app.key' => $this->app->make(InstallationKey::class)->key()]);
        }

        // The desktop app has one user, on their own machine, behind the app lock. Its session has to outlive the
        // window being left open, e.g. on the lock screen overnight; with Laravel's two-hour default, entering the
        // PIN afterwards failed with "419 Page expired".
        if (config('nativephp-internal.running')) {
            config(['session.lifetime' => self::DESKTOP_SESSION_MINUTES]);
        }

        $this->app->bind(GithubClient::class, fn (): GithubClient => new GithubClient(
            GithubAccount::current()->token ?? '',
        ));

        $this->app->bind(Changelog::class, fn (): Changelog => new Changelog(config('prism.changelog_path')));

        $this->app->bind(GithubDeviceFlow::class, fn (): GithubDeviceFlow => new GithubDeviceFlow(
            config('services.github.client_id'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->runsAsInstalledDesktopApp() && $this->app->make(InstallationKey::class)->wasCreated()) {
            $this->forgetDataEncryptedWithAnotherKey();
        }

        $this->pauseReviewRunnersAboveTheSetting();
    }

    /**
     * A review runner above the number the user chose takes no new jobs: Laravel's worker waits instead while a
     * Looping listener returns false. It is checked between jobs, so a review already running finishes.
     */
    private function pauseReviewRunnersAboveTheSetting(): void
    {
        Event::listen(WorkerStarting::class, function (WorkerStarting $event): void {
            $this->queueWorkerName = $event->workerOptions->name;
        });

        Event::listen(Looping::class, fn (): ?bool => rescue(
            fn (): bool => $this->app->make(ReviewRunners::class)->pauses($this->queueWorkerName),
            false,
            report: false,
        ) ? false : null);
    }

    /**
     * The packaged desktop app (not `native:run` during development, tests or the web version).
     */
    private function runsAsInstalledDesktopApp(): bool
    {
        return config('nativephp-internal.running') && $this->app->isProduction();
    }

    /**
     * After updating from a version that used the build's key, saved credentials can't be decrypted.
     * Forget them so the app asks the user to reconnect instead of failing.
     */
    private function forgetDataEncryptedWithAnotherKey(): void
    {
        rescue(function (): void {
            if (! Schema::hasTable('github_accounts')) {
                return;
            }

            GithubAccount::query()->delete();
            $this->app->make(AppSettings::class)->setSecret(AppSettings::CLAUDE_CREDENTIAL, null);
        }, report: false);
    }
}
