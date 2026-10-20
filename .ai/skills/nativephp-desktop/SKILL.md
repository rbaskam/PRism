---
name: nativephp-desktop
description: "Use when working with NativePHP Desktop v2 (nativephp/desktop, namespace Native\\Desktop) in this app: NativeAppServiceProvider, Window, Notification, Shell, MenuBar, Dock, Settings, queue_workers and the scheduler inside the Electron runtime, running external CLIs (claude, gh) from queued jobs, PATH/env/database/storage differences between `php artisan serve` and the desktop app, native:run / native:migrate / native:build, and testing native calls with fakes or Http::fake()."
---

# NativePHP Desktop v2 (nativephp/desktop 2.3.1)

Verified against `vendor/nativephp/desktop`. The namespace is `Native\Desktop\…`; the v1 `Native\Laravel\…` classes no longer exist. Facades live in `Native\Desktop\Facades\*`.

**How it works:** Electron starts an Express API, then runs `php -S 127.0.0.1:{8100-9000}` with a bundled static PHP (`vendor/nativephp/php-bin`, same major.minor as your CLI PHP). Then it POSTs `/_native/api/booted`, which calls `App\Providers\NativeAppServiceProvider::boot()`. Each facade call (`Window`, `Notification`, …) is an HTTP request through `Native\Desktop\Client\Client`, which uses Laravel `Http` with base URL `config('nativephp-internal.api_url')`. Electron sends events back as POST `/_native/api/events`, and `DispatchEventFromAppController` dispatches them as `new $event(...$payload)` (named args).

## 1. Dev, runtime detection, database

```bash
composer native:dev            # concurrently: php artisan native:run + npm run dev
php artisan native:run         # Electron dev app (APP_ENV=local, APP_DEBUG=true forced)
php artisan native:migrate     # migrate the NativePHP sqlite DB (also native:migrate:fresh, native:seed, native:db:wipe)
```

- **Detect the runtime** with `config('nativephp-internal.running')`, which reads env `NATIVEPHP_RUNNING=true`. Electron sets it for the PHP server, queue workers and the scheduler. It is unset under `php artisan serve`, in tests, and for artisan run from a terminal.
- **Electron env wins over `.env`** (dotenv is immutable). Dev gets `APP_ENV=local`/`APP_DEBUG=true`, a built app gets `production`/`false`. Electron also sets `LARAVEL_STORAGE_PATH`, `NATIVEPHP_STORAGE_PATH`, `NATIVEPHP_DATABASE_PATH`, `NATIVEPHP_API_URL` and `NATIVEPHP_SECRET`. The `NATIVEPHP_*_PATH` vars are registered as disks: `user_home`, `app_data`, `user_data`, `desktop`, `documents`, `downloads`, …
- **What `NativeServiceProvider` forces when running:**
  - `database.default = 'nativephp'` (sqlite, WAL, busy_timeout 5000). `DB_CONNECTION` is ignored.
  - `queue.default = 'database'`, with `queue.connections.database.connection`, `queue.failed.database` and `queue.batching.database` all set to `'nativephp'`.
  - `session.driver = 'file'`.
  - The `PreventRegularBrowserAccess` middleware returns 403 to a normal browser.
- **DB file:** in dev (`app.debug`) it is `database/nativephp.sqlite`. In production it is `{userData}/database/database.sqlite`. Storage is `{userData}/storage`, so logs are in `{userData}/storage/logs`.
- **userData location:** `~/Library/Application Support/{Str::slug(APP_NAME)}` (with a `-dev` suffix under native:run), or `%APPDATA%\{slug}` on Windows. Changing `APP_NAME` starts a new, empty data dir.
- **Migrations:**
  - Dev: they auto-run only when `database/nativephp.sqlite` doesn't exist yet. After that, run `php artisan native:migrate` yourself.
  - Production: Electron runs `artisan migrate --force` at launch only when the app version (`nativephp.version` / `NATIVEPHP_APP_VERSION`) differs from the last migrated one. **Bump the version every release.**
  - Production also runs `artisan optimize` on every launch, so config is cached and `env()` outside `config/` returns null.

## 2. Windows

```php
use Native\Desktop\Facades\Window;

Window::open('main')             // PendingOpenWindow; the POST happens in __destruct (end of statement)
    ->title('PRism')
    ->route('dashboard')         // or ->url($absoluteUrl); default url('/')
    ->width(1200)->height(800)   // default 400x400
    ->minWidth(900)->minHeight(600)
    ->titleBarHiddenInset()      // titleBarStyle('hiddenInset'); also titleBarHidden(), trafficLightPosition($x, $y)
    ->rememberState()            // persists size/position per id
    ->hideMenu();                // auto-hide menu bar (Windows/Linux)
```

- **Other builder methods:** `resizable()`, `alwaysOnTop()`, `frameless()`, `showDevTools(false)` (defaults to `app.debug`), `backgroundColor()`, `preventLeaveDomain()`, `suppressNewWindows()`, `webPreferences([...])`.
- **Existing id:** `Window::open($id)` on an id that is already open only does `show()` and `focus()`; the URL is ignored. To navigate, use `Window::get('main')->route('reviews.show', $review)`. On a non-pending `Window`, `title()`, `url()`, `route()`, `closable()` and `zoomFactor()` POST immediately.
- **Manager methods:** `Window::close($id)`, `hide`, `show`, `maximize`, `minimize`, `reload`, `resize($w, $h, $id)`, `current()`, `all()`.
- **Window title:** Electron blocks `page-title-updated`, so Inertia `<Head title>` won't change it. Use `Window::get('main')->title(...)`.

## 3. Notifications

```php
use Native\Desktop\Facades\Notification;

Notification::new()                       // fresh instance (see Gotchas: the facade instance is cached)
    ->reference("review:{$review->id}")   // optional custom id, echoed back in click events (else auto-generated)
    ->title('Review finished')            // default config('app.name')
    ->message("PR #{$review->number}")    // REQUIRED: body is an uninitialised typed property
    ->show();                             // also: ->silent(), ->sound('Ping'), ->hasReply('…'), ->addAction('Open')
```

**Click events** (`Native\Desktop\Events\Notifications\*`; the trailing `$event` arg is Electron's event as JSON):
- `NotificationClicked(string $reference, string $event)`
- `NotificationActionClicked(string $reference, int $index, string $event)`
- `NotificationReply(string $reference, string $reply, string $event)`
- `NotificationClosed(string $reference, string $event)`
- `->event(MyEvent::class)` replaces `NotificationClicked` with your class, built with named args `reference:` and `event:`.

```php
// app/Listeners/OpenReviewFromNotification.php (event discovery). Keep it SYNC: it runs inside the
// PHP-server request, so route() yields http://127.0.0.1:{port}. A queued listener would use APP_URL.
public function handle(NotificationClicked $event): void
{
    if (! str_starts_with($event->reference, 'review:')) {
        return;
    }
    $reviewId = (int) str($event->reference)->after('review:')->toString();
    Window::open('main'); // re-creates if closed, else show+focus (URL ignored)
    Window::get('main')->route('reviews.show', ['review' => $reviewId]); // params MUST be an array (TypeError otherwise)
}
```

## 4. Shell

```php
use Native\Desktop\Facades\Shell;

Shell::openExternal('https://github.com/org/repo/pull/12'); // system browser
Shell::showInFolder($absolutePath);                         // reveal in Finder/Explorer
Shell::openFile($absolutePath);                             // default app; returns error string ('' = ok)
Shell::trashFile($absolutePath);
```

## 5. MenuBar (tray), Dock, badges

```php
use Native\Desktop\Facades\{Menu, MenuBar};

MenuBar::create()                         // PendingCreateMenuBar; calling again replaces the tray
    ->onlyShowContextMenu()               // tray icon + menu, no popover window
    ->withContextMenu(Menu::make(
        Menu::route('dashboard', 'Open dashboard'),
        Menu::link('https://github.com', 'GitHub')->openInBrowser(),
        Menu::separator(),
        Menu::quit(),
    ))
    ->icon(public_path('menuBarIconTemplate.png')) // absolute path; default build/IconTemplate.png
    ->label('3')->tooltip('3 reviews running')
    ->showDockIcon();                     // REQUIRED alongside a main window, or the macOS Dock icon is hidden
```

- **Runtime updates:** `MenuBar::label('2')` (tray title, macOS only), `MenuBar::tooltip()`, `MenuBar::icon($path)`, `MenuBar::contextMenu(Menu::make(...))`, `MenuBar::show()`/`hide()`.
- **Events:** `Native\Desktop\Events\MenuBar\MenuBarClicked`, `MenuBarCreated`, …; `Native\Desktop\Events\Menu\MenuItemClicked(array $item, array $combo)`.
- **Dock is macOS only.** On Windows `app.dock` is undefined and the call silently returns 500.
  - Set and clear: `Dock::badge('3')`, `Dock::badge('')`.
  - Don't call `Dock::badge()` with no arg: the getter has a TypeError bug.
  - Also: `Dock::bounce('critical')`, `cancelBounce()`, `menu(Menu)`, `icon()`, `hide()`, `show()`.
- **`App::badgeCount(int)`** uses Electron `setBadgeCount`, which works on macOS/Linux only.
- **`App::openAtLogin(true)`**, `App::quit()`, `App::version()`.

## 6. Scheduler

- Electron runs `php artisan schedule:run` every 60s, aligned to the minute, in dev and production. Source: `resources/electron/electron-plugin/src/index.ts` (`startScheduler`/`runScheduler`) and `src/server/php.ts` (`startScheduler`).
- It starts only after the PHP server is up and gets the same env as the workers, so native facades work. Schedules go in `routes/console.php` as usual.
- **Each tick SIGTERMs the previous `schedule:run`.** A foreground task running longer than 60s gets killed. Keep tasks tiny (dispatch a job) or use `->runInBackground()`.
- The scheduler stops on system suspend and restarts on resume. Nothing runs while the app is closed, and missed runs are not caught up.
- A killed `->withoutOverlapping()` keeps its lock for 24h by default, so pass an expiry: `->withoutOverlapping(10)`.

## 7. Queue workers (`config/nativephp.php`)

```php
'queue_workers' => [
    'default' => ['queues' => ['default'], 'memory_limit' => 128, 'timeout' => 60, 'sleep' => 3],
    'reviews' => ['queues' => ['reviews'], 'memory_limit' => 512, 'timeout' => 1800, 'sleep' => 3],
],
// ReviewPullRequest::dispatch($pr)->onQueue('reviews');
```

- **Parsing:** `Native\Desktop\DataObjects\QueueConfig::fromConfigArray()`. Defaults are `queues ['default']`, `memory_limit 128`, `timeout 60`, `sleep 3`.
- **Startup:** `NativeServiceProvider::fireUpQueueWorkers()` runs on PHP-server requests and calls `Native\Desktop\QueueWorker::up()`. That calls `ChildProcess::artisan([...], 'queue_{key}', persistent: true, iniSettings: ['memory_limit' => "{n}M"])`. It is idempotent per alias, and Electron restarts a worker 1s after it exits.
- **Command:** `queue:listen` when `app()->isLocal()` (dev), else `queue:work`, with `--name={key} --queue=… --memory --timeout --sleep --quiet`. There is no `--connection`, so it uses the forced `database` connection on sqlite `nativephp`. There is no `--tries`, so tries is 1.
- **Timeout:**
  - Dev: the `queue:listen` Symfony Process timeout applies per job.
  - **Production: not enforced.** The bundled PHP has no `pcntl` (`php-bin/php-extensions.txt`), so `queue:work` never arms SIGALRM. A job's `$timeout` is ignored too.
  - Enforce limits with `Process::timeout(1800)`, which works without pcntl.
- **`retry_after`:** `config/queue.php` → `connections.database.retry_after` (`DB_QUEUE_RETRY_AFTER`, default 90). Keep it above your longest job (e.g. 1900), or run only one worker per long queue.
- **Quitting the app** tree-kills the workers and their children. The in-flight job stays reserved. On next launch it is re-reserved with attempts=2 > tries=1 and marked failed. Give such jobs a `failed()` handler or `$tries = 2`.
- **Manual control:** `Native\Desktop\Facades\QueueWorker::up('reviews')` / `::down('reviews')`. The `native:run --no-queue` flag does nothing (`NATIVE_PHP_SKIP_QUEUE` is never read).

## 8. Child processes, PATH, environment

- **macOS/Linux PATH:** `resources/electron/src/main/index.js` calls `fixPath()` (fix-path v5) at startup. It replaces `process.env.PATH` with your login shell's PATH (`$SHELL -ilc env`), so Homebrew, `~/.local/bin`, nvm etc. resolve even when the app is launched from Finder.
- **Only PATH is imported.** Exports from shell profiles (`GH_TOKEN`, `ANTHROPIC_API_KEY`, …) are absent. `gh`/`claude` must rely on their own stored auth (keychain, `~/.config/gh`, `~/.claude`). `HOME` is present.
- **Windows:** `fixPath` is a no-op. The app inherits Explorer's env, i.e. user+system PATH as of login, so newly installed CLIs may need a re-login. npm CLIs are `.cmd` shims.
- **What children inherit:**
  - The PHP server and the scheduler get `{...process.env, ...nativeEnv}`.
  - Queue workers and `ChildProcess` (Electron `utilityProcess`) get `{...process.env, ...yourEnv, ...nativeEnv}`.
  - So `Illuminate\Support\Facades\Process` in a job inherits that PATH.
- **Recommendations:** resolve absolute binary paths once (`which`, or `where` when `Native\Desktop\Support\Environment::isWindows()`), allow a user override (Settings/DB), and always set `->path($repo)`, `->timeout()` and explicit `->env([...])`.
- **Name clash:** `Native\Desktop\Facades\Process` (Electron arch/platform/uptime) clashes with `Illuminate\Support\Facades\Process`. Alias your imports.
- **Single-threaded server:** the built-in `php -S` handles one request at a time. A slow controller freezes the UI and delays Electron→PHP events. **Never run claude/gh inside a request; dispatch a job.**
- **Daemons:** for an Electron-managed long-running process use `ChildProcess::start($cmd, $alias, cwd: …, env: …, persistent: false)`. Its output arrives as `Events\ChildProcess\MessageReceived`/`ErrorReceived`.

## 9. Settings and php.ini

```php
use Native\Desktop\Facades\Settings;

Settings::set('claude.binary', '/opt/homebrew/bin/claude'); // electron-store: {userData}/config.json
Settings::get('claude.binary', fn () => 'claude');          // null => default (closure allowed)
Settings::forget('claude.binary'); Settings::clear();       // event: Events\Settings\SettingChanged($key, $value)
```

- Settings live outside the DB, so they survive `native:migrate:fresh`.
- Every Settings call is HTTP to Electron, so it throws outside the app (tests, `php artisan serve`).
- `NativeAppServiceProvider implements Native\Desktop\Contracts\ProvidesPhpIni`, whose `phpIni(): array` returns ini directives.
  - Electron reads them once at launch (`artisan native:php-ini`) and passes them as `-d` flags to the server, the scheduler and every PHP child (queue workers).
  - Defaults: `memory_limit=512M`, `curl.cainfo`/`openssl.cafile` = bundled cacert. A worker's `memory_limit` overrides yours.
  - Restart the app after changing them.

## 10. Building

```bash
php artisan native:build mac arm64      # or: mac x64 | mac all | win x64 ; --publish uses the updater provider
```

- **Output:** `nativephp/electron/dist/`.
- **Set in `.env`:**
  - `NATIVEPHP_APP_ID` (the default `com.nativephp.app` must change).
  - `NATIVEPHP_APP_VERSION` (drives migrations).
  - `APP_NAME` (product name and userData dir).
  - Optional: `NATIVEPHP_APP_AUTHOR`, `NATIVEPHP_DEEPLINK_SCHEME`, `NATIVEPHP_UPDATER_ENABLED` (default true; the provider defaults to `spaces`).
- **`.env` in the build:** it is copied into the build, then every key matching `cleanup_env_keys` (Str::is patterns) is removed. That includes `GITHUB_*`, `AWS_*`, `AZURE_*`, `DO_SPACES_*`, `*_SECRET`, `BIFROST_*` and `NATIVEPHP_APPLE_*`, plus all comments.
- **Forced log settings in builds:** `LOG_STACK=daily`, `LOG_DAILY_DAYS=3`, `LOG_LEVEL=warning`, so `Log::info` is dropped.
- **Hooks:** add `'npm run build'` to `nativephp.prebuild` (runs in base_path, 300s timeout) so `public/build` exists. Use `postbuild` for cleanup.
- **Signing:** `NATIVEPHP_APPLE_ID`, `NATIVEPHP_APPLE_ID_PASS`, `NATIVEPHP_APPLE_TEAM_ID` (notarization). Azure Trusted Signing env vars for Windows.

## 11. Testing (Pest)

`NATIVEPHP_RUNNING` is unset in tests, so phpunit.xml's DB applies. Native facades still POST to `http://localhost:4000/api/…` and throw unless faked.
- **Fakes with `::fake()`:** `Window` (WindowManagerFake), `ChildProcess`, `QueueWorker`, `Shell`, `GlobalShortcut`, `PowerMonitor`.
- **No fake** for `Notification`, `MenuBar`, `Dock`, `Settings`, `App`. Use `Http::fake()` (verified: `Client` uses the Http facade).
- Native `Notification::fake()` does not exist. `Illuminate\Support\Facades\Notification::fake()` fakes Laravel notifications, which is a different thing.

```php
use Illuminate\Support\Facades\Http;
use Native\Desktop\Events\Notifications\NotificationClicked;
use Native\Desktop\Facades\{Shell, Window};
use Native\Desktop\Windows\Window as NativeWindow;

it('notifies when a review finishes', function () {
    Http::fake(['*/notification' => Http::response(['reference' => 'review:1'])]);
    // ... run the job ...
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/notification')
        && $request['title'] === 'Review finished');
});

it('opens the review window on notification click', function () {
    Http::fake();                                     // route()/title() on returned windows still POST
    $windows = Window::fake()->alwaysReturnWindows([new NativeWindow('main')]); // required, else open() fails
    event(new NotificationClicked('review:1', '{}'));
    $windows->assertOpened('main');                   // also assertClosed/Shown/Hidden/Reloaded, assertOpenedCount
});

it('opens the PR externally', function () {
    $shell = Shell::fake();
    // ... act ...
    $shell->assertOpenedExternal('https://github.com/org/repo/pull/1'); // assertShowInFolder, assertOpenedFile
});
```

The other fakes have their own assertions:
- `ChildProcessFake`: `assertStarted`, `assertPhp`, `assertArtisan`, `assertStop`, `assertMessage`.
- `QueueWorkerFake`: `assertUp(fn (QueueConfig $c) => …)`, `assertDown('reviews')`.

## 12. Gotchas

- **The facade instance is cached per request/job.** Repeated `Notification::title()->…->show()` calls reuse one object: actions pile up and the previous `reference` is resent. Use `Notification::new()`.
- **Native calls fail outside the app** (serve, tests, tinker) with `ConnectionException`. Guard optional UI side effects with `config('nativephp-internal.running')`.
- **`url()`/`route()` in jobs and the scheduler use `APP_URL`**, not the random `127.0.0.1` port. Build window URLs only in HTTP context: `NativeAppServiceProvider::boot()`, controllers, and sync listeners for Electron events.
- **`NativeAppServiceProvider::boot()` runs again** when the macOS Dock icon is clicked with no windows open. Keep it idempotent.
- **Windows quits the app when the last window closes** (`window-all-closed`), which stops workers and the scheduler. macOS keeps running.
- **The bundled PHP lacks `pcntl`, `posix`, `pdo_mysql` and `pdo_pgsql`.** Sqlite only, no job timeouts, no graceful worker shutdown.
- **Vue/Inertia can listen to events in the browser:** `window.Native.on('Native\\Desktop\\Events\\…', (payload) => …)`. Your own events whose `broadcastOn()` includes `new Channel('nativephp')` are forwarded to every window.
