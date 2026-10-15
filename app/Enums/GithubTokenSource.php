<?php

namespace App\Enums;

enum GithubTokenSource: string
{
    case GithubCli = 'gh_cli';
    case PersonalAccessToken = 'personal_access_token';
    case DeviceFlow = 'device_flow';

    public function label(): string
    {
        return match ($this) {
            self::GithubCli => 'GitHub CLI login',
            self::PersonalAccessToken => 'Personal access token',
            self::DeviceFlow => 'Signed in with GitHub',
        };
    }
}
