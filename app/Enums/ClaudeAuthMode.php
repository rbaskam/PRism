<?php

namespace App\Enums;

enum ClaudeAuthMode: string
{
    case CliLogin = 'cli_login';
    case ApiKey = 'api_key';
    case OauthToken = 'oauth_token';

    public function label(): string
    {
        return match ($this) {
            self::CliLogin => 'Existing Claude Code login',
            self::ApiKey => 'Anthropic API key',
            self::OauthToken => 'Claude subscription token (claude setup-token)',
        };
    }
}
