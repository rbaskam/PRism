<?php

namespace App\Services\Github;

use Illuminate\Support\Facades\Http;

/**
 * Signs the user in with GitHub's OAuth device flow, which suits desktop apps because no client secret is needed.
 *
 * @see https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/authorizing-oauth-apps#device-flow
 */
class GithubDeviceFlow
{
    public const SCOPES = 'repo read:org notifications';

    public function __construct(private readonly ?string $clientId) {}

    public function isAvailable(): bool
    {
        return filled($this->clientId);
    }

    /**
     * Ask GitHub for a user code the user enters at github.com/login/device.
     *
     * @return array{device_code: string, user_code: string, verification_uri: string, expires_in: int, interval: int}
     */
    public function start(): array
    {
        return Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->post('https://github.com/login/device/code', [
                'client_id' => $this->clientId,
                'scope' => self::SCOPES,
            ])
            ->throw()
            ->json();
    }

    /**
     * Check whether the user has finished authorising the device.
     *
     * @return array{status: 'pending'|'slow_down'|'expired'|'denied'|'authorized'|'error', token?: string, message?: string}
     */
    public function poll(string $deviceCode): array
    {
        $response = Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->post('https://github.com/login/oauth/access_token', [
                'client_id' => $this->clientId,
                'device_code' => $deviceCode,
                'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            ])
            ->throw();

        if ($token = $response->json('access_token')) {
            return ['status' => 'authorized', 'token' => $token];
        }

        return match ($response->json('error')) {
            'authorization_pending' => ['status' => 'pending'],
            'slow_down' => ['status' => 'slow_down'],
            'expired_token' => ['status' => 'expired'],
            'access_denied' => ['status' => 'denied'],
            default => ['status' => 'error', 'message' => (string) $response->json('error_description', 'GitHub sign in failed.')],
        };
    }
}
