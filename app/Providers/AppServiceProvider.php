<?php

namespace App\Providers;

use App\Models\GithubAccount;
use App\Services\AppSettings;
use App\Services\Changelog;
use App\Services\Github\GithubClient;
use App\Services\Github\GithubDeviceFlow;
use App\Services\InstallationKey;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
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
