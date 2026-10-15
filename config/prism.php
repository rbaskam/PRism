<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Release repository
    |--------------------------------------------------------------------------
    |
    | The GitHub repository ("owner/name") PRism is released from. The app checks
    | its latest release to tell users when an update is available.
    |
    */

    'repository' => env('PRISM_REPOSITORY', 'rbaskam/PRism'),

    /*
    |--------------------------------------------------------------------------
    | Version
    |--------------------------------------------------------------------------
    |
    | The running version. Release builds set NATIVEPHP_APP_VERSION from the git
    | tag, which also tells NativePHP to migrate the database on first launch.
    |
    */

    'version' => env('NATIVEPHP_APP_VERSION', '0.0.0'),

    'changelog_path' => base_path('CHANGELOG.md'),

    /*
    |--------------------------------------------------------------------------
    | Running in a container
    |--------------------------------------------------------------------------
    |
    | True when the web version runs in Laravel Sail. The container can't see the
    | host's claude and gh CLIs or local checkouts, so the UI says so.
    |
    */

    'running_in_container' => (bool) env('LARAVEL_SAIL', false),

];
