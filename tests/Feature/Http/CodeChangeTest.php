<?php

use App\Enums\ClaudeAuthMode;
use App\Enums\CodeChangeStatus;
use App\Jobs\MakeCodeChanges;
use App\Jobs\PushCodeChange;
use App\Jobs\RemoveCodeChangeWorktree;
use App\Models\CodeChange;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Services\AppSettings;
use Illuminate\Support\Facades\Process;
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

it('refuses to send changes for a pull request from a fork anywhere its author does not allow', function (string $target, bool $maintainerCanModify) {
    Queue::fake([MakeCodeChanges::class]);
    onboardedAccount();
    allowCodeChanges();
    $pullRequest = PullRequest::factory()
        ->for(Repository::factory()->state(['local_path' => sys_get_temp_dir()]))
        ->fromFork('octo-fork/app', $maintainerCanModify)
        ->create();

    $response = $this->post(route('pull-requests.code-changes.store', $pullRequest), ['target' => $target, 'instructions' => 'Rename the helper']);

    $response->assertSessionHasErrors('target');
    expect(CodeChange::count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'a new pull request in the base repository' => ['new_pull_request', true],
    "the fork's branch without maintainer edits" => ['pull_request_branch', false],
]);

it("lets Claude change a fork's branch when its author allows maintainer edits", function () {
    Queue::fake([MakeCodeChanges::class]);
    onboardedAccount();
    allowCodeChanges();
    $pullRequest = PullRequest::factory()
        ->for(Repository::factory()->state(['local_path' => sys_get_temp_dir()]))
        ->fromFork('octo-fork/app', maintainerCanModify: true)
        ->create();

    $this->post(route('pull-requests.code-changes.store', $pullRequest), ['target' => 'pull_request_branch', 'instructions' => 'Rename the helper'])
        ->assertSessionHas('success');

    Queue::assertPushed(MakeCodeChanges::class);
});

it('refuses to push changes that were too big to review in full', function () {
    Queue::fake([PushCodeChange::class]);
    onboardedAccount();
    allowCodeChanges(pushing: true);
    $change = CodeChange::factory()->create(['diff_truncated' => true]);

    $this->post(route('code-changes.push', $change))->assertSessionHas('error');

    expect($change->fresh()->status)->toBe(CodeChangeStatus::Ready);
    Queue::assertNothingPushed();
});

it('refuses to push to a fork whose author no longer allows maintainer edits', function () {
    Queue::fake([PushCodeChange::class]);
    onboardedAccount();
    allowCodeChanges(pushing: true);
    $change = CodeChange::factory()->for(PullRequest::factory()->fromFork('octo-fork/app', maintainerCanModify: false))->create();

    $this->post(route('code-changes.push', $change))->assertSessionHas('error', "This pull request comes from a fork (octo-fork/app) whose author doesn't allow edits from maintainers.");

    Queue::assertNothingPushed();
});

it('discards changes straight away and removes their worktree in the queue, not during the request', function () {
    Queue::fake([RemoveCodeChangeWorktree::class]);
    Process::fake();
    onboardedAccount();
    $change = CodeChange::factory()->create();

    $this->delete(route('code-changes.destroy', $change))->assertSessionHas('success', 'Changes discarded.');

    expect($change->fresh()->status)->toBe(CodeChangeStatus::Discarded);
    Queue::assertPushedOn('reviews', RemoveCodeChangeWorktree::class);
    Process::assertNothingRan();
});
