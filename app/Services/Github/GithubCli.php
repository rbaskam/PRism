<?php

namespace App\Services\Github;

use App\Services\AppSettings;
use App\Services\CommandLocator;
use Illuminate\Support\Facades\Process;

/**
 * Reads the login of a locally installed GitHub CLI (`gh`) so users can reuse it.
 */
class GithubCli
{
    public function __construct(
        private readonly CommandLocator $locator,
        private readonly AppSettings $settings,
    ) {}

    public function binary(): ?string
    {
        return $this->locator->find('gh', $this->settings->githubCliPath());
    }

    public function isInstalled(): bool
    {
        return $this->binary() !== null;
    }

    /**
     * Get the token the GitHub CLI is logged in with, if any.
     */
    public function token(): ?string
    {
        $binary = $this->binary();

        if ($binary === null) {
            return null;
        }

        $result = Process::env(['PATH' => $this->locator->searchPath([$binary])])
            ->timeout(15)
            ->run([$binary, 'auth', 'token']);

        $token = trim($result->output());

        return $result->successful() && $token !== '' ? $token : null;
    }
}
