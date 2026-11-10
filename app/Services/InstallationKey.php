<?php

namespace App\Services;

use Illuminate\Encryption\Encrypter;
use RuntimeException;

/**
 * The encryption key for this installation of the desktop app.
 *
 * Release builds ship an APP_KEY in their .env, but it changes with every build, so data encrypted
 * by one version (the GitHub token, Claude credentials) could not be read after updating. Instead,
 * each installation creates its own key on first launch and keeps it in the app's user data folder,
 * which survives updates.
 */
class InstallationKey
{
    private bool $wasCreated = false;

    public function __construct(private readonly string $path) {}

    /**
     * Read the key, creating it on first use.
     *
     * A file that doesn't hold a usable key (e.g. it was cut short when the computer lost power) is replaced with a new
     * key. Whatever the lost key encrypted can't be read any more, so wasCreated() says so and the app asks the user to
     * reconnect, instead of every request failing.
     */
    public function key(): string
    {
        if (is_file($this->path)) {
            $contents = file_get_contents($this->path);

            if ($contents === false) {
                throw new RuntimeException("Could not read the encryption key at {$this->path}.");
            }

            if ($this->isUsable(trim($contents))) {
                return trim($contents);
            }
        }

        $key = 'base64:'.base64_encode(Encrypter::generateKey(config('app.cipher')));
        $this->write($key);
        $this->wasCreated = true;

        return $key;
    }

    /**
     * Whether this launch created the key, meaning anything already encrypted used another key.
     */
    public function wasCreated(): bool
    {
        return $this->wasCreated;
    }

    private function isUsable(string $key): bool
    {
        if (! str_starts_with($key, 'base64:')) {
            return false;
        }

        $decoded = base64_decode(substr($key, strlen('base64:')), strict: true);

        return $decoded !== false && Encrypter::supported($decoded, config('app.cipher'));
    }

    /**
     * Write the key to a temporary file next to the real one, then move it into place, so the file is never left
     * half-written.
     */
    private function write(#[\SensitiveParameter] string $key): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create {$directory} for the encryption key.");
        }

        $temporaryPath = tempnam($directory, 'installation-key-');

        if ($temporaryPath === false) {
            throw new RuntimeException("Could not write the encryption key in {$directory}.");
        }

        chmod($temporaryPath, 0600);

        if (! $this->writeToDisk($temporaryPath, $key) || ! rename($temporaryPath, $this->path)) {
            @unlink($temporaryPath);

            throw new RuntimeException("Could not write the encryption key to {$this->path}.");
        }
    }

    /**
     * Write the file and wait until it's on disk, so a crash straight afterwards can't leave an empty key behind.
     */
    private function writeToDisk(string $path, #[\SensitiveParameter] string $contents): bool
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            return false;
        }

        $written = fwrite($handle, $contents) === strlen($contents) && fflush($handle) && fsync($handle);

        return fclose($handle) && $written;
    }
}
