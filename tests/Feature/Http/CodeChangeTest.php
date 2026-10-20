<?php

use App\Enums\ClaudeAuthMode;
use App\Enums\CodeChangeStatus;
use App\Jobs\MakeCodeChanges;
use App\Jobs\PushCodeChange;
use App\Models\CodeChange;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Services\AppSettings;
use Illuminate\Support\Facades\Queue;

function allowCodeChanges(bool $pushing = false): void
{
    $settings = app(AppSettings::class);
    $settings->set(AppSettings::CLAUDE_AUTH_MODE, ClaudeAuthMode::CliLogin->value);
    $settings->set(AppSettings::ALLOW_CODE_CHANGES, true);
    $settings->set(AppSettings::ALLOW_PUSHING, $pushing);
}

it('asks Claude to change code in a worktree of the local checkout', function () {
    Queue::fake([MakeCodeChanges::class]);
    onboardedAccount();
    allowCodeChanges();
    $pullRequest = PullRequest::factory()->authoredByMe()->for(Repository::factory()->state(['local_path' => sys_get_temp_dir()]))->create();

    $response = $this->post(route('pull-requests.code-changes.store', $pullRequest), [
        'target' => 'pull_request_branch',
        'instructions' => 'Rename the helper',
    ]);

    $response->assertSessionHas('success');
    $change = CodeChange::sole();
    expect($change)
        ->status->toBe(CodeChangeStatus::Queued)
        ->checkout_path->toBe(sys_get_temp_dir());
    Queue::assertPushedOn('reviews', MakeCodeChanges::class);
});

it('refuses to change code while the setting is off', function () {
    Queue::fake([MakeCodeChanges::class]);
    onboardedAccount();
    $pullRequest = PullRequest::factory()->for(Repository::factory()->state(['local_path' => sys_get_temp_dir()]))->create();

    $response = $this->post(route('pull-requests.code-changes.store', $pullRequest), ['target' => 'new_pull_request', 'instructions' => 'x']);

    $response->assertSessionHas('error', 'Letting Claude change code is turned off in Settings → PR review.');
    expect(CodeChange::count())->toBe(0);
});

it('needs a local checkout to change code', function () {
    Queue::fake([MakeCodeChanges::class]);
    onboardedAccount();
    allowCodeChanges();
    $pullRequest = PullRequest::factory()->create();

    $response = $this->post(route('pull-requests.code-changes.store', $pullRequest), ['target' => 'new_pull_request', 'instructions' => 'x']);

    $response->assertSessionHas('error');
    Queue::assertNothingPushed();
});

it('pushes approved changes only when pushing is allowed', function () {
    Queue::fake([PushCodeChange::class]);
    onboardedAccount();
    allowCodeChanges(pushing: false);
    $change = CodeChange::factory()->create();

    $this->post(route('code-changes.push', $change))->assertSessionHas('error', 'Pushing is turned off in Settings → PR review.');
    app(AppSettings::class)->set(AppSettings::ALLOW_PUSHING, true);
    $this->post(route('code-changes.push', $change));

    expect($change->fresh()->status)->toBe(CodeChangeStatus::Pushing);
    Queue::assertPushed(PushCodeChange::class, 1);
});
