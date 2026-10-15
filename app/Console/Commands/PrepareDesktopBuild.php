<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Native\Desktop\Drivers\Electron\ElectronServiceProvider;

/**
 * Runs before every `native:build` / `native:publish` (see `prebuild` in config/nativephp.php).
 *
 * Without an Apple Developer ID, electron-builder signs the macOS app ad hoc with the hardened
 * runtime turned on. The hardened runtime only lets the app load libraries signed by the same team,
 * and ad hoc signatures have no team, so on Apple Silicon macOS refuses to load the Electron framework
 * and the app quits at launch. Disabling library validation fixes that; Developer ID builds don't need
 * it, but it's harmless for them and allowed when notarising.
 */
class PrepareDesktopBuild extends Command
{
    public const DISABLE_LIBRARY_VALIDATION = 'com.apple.security.cs.disable-library-validation';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'prism:prepare-desktop-build
        {--entitlements= : Path to the macOS entitlements plist (defaults to the Electron project\'s)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Adjust the Electron build settings so unsigned macOS builds launch on Apple Silicon';

    public function handle(): int
    {
        $path = $this->option('entitlements') ?? ElectronServiceProvider::electronPath('build/entitlements.mac.plist');

        if (! is_file($path)) {
            $this->error("Entitlements file not found: {$path}");

            return self::FAILURE;
        }

        $plist = (string) file_get_contents($path);

        if (str_contains($plist, self::DISABLE_LIBRARY_VALIDATION)) {
            $this->info('macOS entitlements already allow loading the app\'s own frameworks.');

            return self::SUCCESS;
        }

        $entry = '    <key>'.self::DISABLE_LIBRARY_VALIDATION."</key>\n    <true/>\n  </dict>";
        $patched = preg_replace('#\s*</dict>#', "\n".$entry, $plist, 1, $count);

        if ($count !== 1) {
            $this->error("Could not find the <dict> to update in {$path}");

            return self::FAILURE;
        }

        file_put_contents($path, $patched);
        $this->info('Added '.self::DISABLE_LIBRARY_VALIDATION.' to the macOS entitlements.');

        return self::SUCCESS;
    }
}
