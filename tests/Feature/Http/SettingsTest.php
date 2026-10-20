<?php

use App\Enums\AlertType;
use App\Enums\ClaudeAuthMode;
use App\Enums\NotificationSound;
use App\Models\Setting;
use App\Services\AppSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('saves an Anthropic API key encrypted', function () {
    onboardedAccount();

    $response = $this->put(route('settings.claude.update'), [
        'auth_mode' => 'api_key',
        'credential' => 'sk-ant-secret',
        'model' => 'sonnet',
        'max_budget_usd' => '2.50',
    ]);

    $response->assertSessionHasNoErrors()->assertSessionHas('success', 'Claude settings saved.');
    $settings = app(AppSettings::class);
    expect($settings->claudeAuthMode())->toBe(ClaudeAuthMode::ApiKey)
        ->and($settings->claudeCredential())->toBe('sk-ant-secret')
        ->and($settings->claudeModel())->toBe('sonnet')
        ->and($settings->claudeMaxBudgetUsd())->toBe(2.5)
        ->and(Setting::where('key', AppSettings::CLAUDE_CREDENTIAL)->value('value'))->not->toContain('sk-ant-secret');
});

it('requires a key when switching to API key authentication', function () {
    onboardedAccount();

    $response = $this->put(route('settings.claude.update'), ['auth_mode' => 'api_key']);

    $response->assertSessionHasErrors(['credential' => 'Paste your API key or token.']);
    expect(app(AppSettings::class)->isClaudeConfigured())->toBeFalse();
});

it('keeps the saved key when the mode is unchanged and the key field is left blank', function () {
    onboardedAccount();
    $settings = app(AppSettings::class);
    $settings->set(AppSettings::CLAUDE_AUTH_MODE, ClaudeAuthMode::ApiKey->value);
    $settings->setSecret(AppSettings::CLAUDE_CREDENTIAL, 'sk-ant-old');

    $this->put(route('settings.claude.update'), ['auth_mode' => 'api_key', 'credential' => null]);

    expect(app(AppSettings::class)->claudeCredential())->toBe('sk-ant-old');
});

it('forgets the saved key when switching to the Claude Code login', function () {
    onboardedAccount();
    $settings = app(AppSettings::class);
    $settings->set(AppSettings::CLAUDE_AUTH_MODE, ClaudeAuthMode::ApiKey->value);
    $settings->setSecret(AppSettings::CLAUDE_CREDENTIAL, 'sk-ant-old');

    $this->put(route('settings.claude.update'), ['auth_mode' => 'cli_login']);

    expect(app(AppSettings::class)->claudeCredential())->toBeNull()
        ->and(app(AppSettings::class)->claudeAuthMode())->toBe(ClaudeAuthMode::CliLogin);
});

it('saves the review prompt and what the app may write to GitHub', function () {
    onboardedAccount();

    $response = $this->put(route('settings.review.update'), [
        'prompt' => 'You are a lead developer reviewing PR #{number}.',
        'allow_posting_findings' => true,
        'allow_replying_to_comments' => false,
        'auto_review_on_request' => true,
    ]);

    $response->assertSessionHas('success', 'Review settings saved.');
    $settings = app(AppSettings::class);
    expect($settings->reviewPrompt())->toBe('You are a lead developer reviewing PR #{number}.')
        ->and($settings->allowsPostingFindings())->toBeTrue()
        ->and($settings->allowsReplyingToComments())->toBeFalse()
        ->and($settings->reviewsAutomaticallyWhenRequested())->toBeTrue();
});

it('saves which desktop alerts the user wants', function () {
    onboardedAccount();

    $response = $this->put(route('settings.notifications.update'), [
        'desktop' => true,
        'follow_up_activity' => false,
        'review_requests' => true,
        'mentions' => false,
        'my_pull_requests' => true,
        'commented' => false,
        'ci' => false,
        'new_pull_requests' => true,
        'review_finished' => true,
        'sound' => 'submarine',
    ]);

    $response->assertSessionHas('success', 'Notification settings saved.');
    $settings = app(AppSettings::class);
    expect($settings->wantsAlert(AlertType::NewPullRequests))->toBeTrue()
        ->and($settings->wantsAlert(AlertType::Mentions))->toBeFalse()
        ->and($settings->alertsOnFollowUpActivity())->toBeFalse()
        ->and($settings->notificationSound())->toBe(NotificationSound::Submarine);
});

it('rejects a notification sound that does not exist', function () {
    onboardedAccount();

    $response = $this->put(route('settings.notifications.update'), [
        'desktop' => true,
        'follow_up_activity' => false,
        ...collect(AlertType::cases())->mapWithKeys(fn (AlertType $type): array => [$type->value => true])->all(),
        'sound' => 'airhorn',
    ]);

    $response->assertSessionHasErrors('sound');
    expect(app(AppSettings::class)->get(AppSettings::NOTIFY_SOUND))->toBeNull();
});

it('plays the sound picked on the page in a test notification before it is saved', function () {
    onboardedAccount();
    config(['nativephp-internal.running' => true]);
    Http::fake(['*/api/notification' => Http::response(['reference' => 'x'])]);
    app(AppSettings::class)->set(AppSettings::NOTIFY_SOUND, NotificationSound::Glass->value);

    $response = $this->post(route('settings.notifications.test'), ['sound' => 'hero']);

    $response->assertSessionHas('success', 'Test notification sent.');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/notification') && $request['sound'] === 'Hero');
});
