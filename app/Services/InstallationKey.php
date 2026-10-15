<?php

namespace App\Services;

use Illuminate\Encryption\Encrypter;

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
     */
    public function key(): string
    {
        if (is_file($this->path)) {
            return trim((string) file_get_contents($this->path));
        }

        $directory = dirname($this->path);

        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $key = 'base64:'.base64_encode(Encrypter::generateKey(config('app.cipher')));
        file_put_contents($this->path, $key);
        chmod($this->path, 0600);
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
}
