<?php

namespace App\Providers;

use App\Jobs\SyncGithub;
use App\Models\GithubAccount;
use App\Services\AppLock;
use App\Services\AppSettings;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\Window;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    public const MAIN_WINDOW = 'main';

    /**
     * Executed once the native application has been booted.
     * Use this method to open windows, register global shortcuts, etc.
     */
    public function boot(): void
    {
        $settings = app(AppSettings::class);

        if ($settings->isAppLockEnabled() && $settings->locksOnLaunch()) {
            app(AppLock::class)->lockEverywhere();
        }

        // Catch up on what changed on GitHub while PRism was closed, rather than at the next scheduled sync.
        if (GithubAccount::query()->exists()) {
            SyncGithub::dispatch();
        }

        Window::open(self::MAIN_WINDOW)
            ->title(config('app.name'))
            ->route('dashboard')
            ->width(1440)
            ->height(920)
            ->minWidth(960)
            ->minHeight(640)
            ->rememberState();
    }

    /**
     * Return an array of php.ini directives to be set.
     *
     * @return array<string, string>
     */
    public function phpIni(): array
    {
        // The desktop app runs PHP's built-in server, which is the CLI SAPI, where OPcache is off by
        // default. Without it every request recompiles the whole framework.
        return [
            'opcache.enable_cli' => '1',
            'opcache.memory_consumption' => '192',
            'opcache.interned_strings_buffer' => '16',
            'opcache.max_accelerated_files' => '20000',
        ];
    }
}
