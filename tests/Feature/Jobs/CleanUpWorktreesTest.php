<?php

use App\Enums\CodeChangeStatus;
use App\Jobs\CleanUpWorktrees;
use App\Jobs\RemoveCodeChangeWorktree;
use App\Models\CodeChange;
use App\Models\PullRequest;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function () {
    // Worktrees go under storage, so point it somewhere disposable.
    $this->storage = sys_get_temp_dir().'/prism-storage-'.Str::random(8);
    $this->checkout = sys_get_temp_dir().'/prism-checkout-'.Str::random(8);
    File::ensureDirectoryExists($this->storage.'/app/worktrees');
    File::ensureDirectoryExists($this->checkout);
    app()->useStoragePath($this->storage);
});

afterEach(function () {
    File::deleteDirectory($this->storage);
    File::deleteDirectory($this->checkout);
});

function worktreeDirectory(string $name, bool $old = true): string
{
    $path = storage_path("app/worktrees/{$name}");
    File::ensureDirectoryExists($path);
    touch($path, $old ? now()->subHours(2)->getTimestamp() : now()->getTimestamp());

    return $path;
}

/**
 * The git commands run with an argument, as strings.
 *
 * @return list<string>
 */
function gitCommandsWith(string $argument): array
{
    $commands = [];
    Process::assertRan(function (PendingProcess $process) use ($argument, &$commands): bool {
        if (in_array($argument, $process->command, true)) {
            $commands[] = implode(' ', array_slice($process->command, 1));
        }

        return true;
    });

    return $commands;
}

it('removes leftover worktrees, branches and refs but keeps what changes waiting to be pushed need', function () {
    $pullRequest = PullRequest::factory()->for(trackedRepository('acme/app', ['local_path' => $this->checkout]))->create();
    $leftOver = worktreeDirectory('acme-app-pr-7-review-abc123');
    $recent = worktreeDirectory('acme-app-pr-8-review-def456', old: false);
    $ready = CodeChange::factory()->for($pullRequest)->create(['worktree_path' => worktreeDirectory('acme-app-pr-9-change-1-ghi789'), 'branch' => 'prism/change-1']);
    CodeChange::factory()->for($pullRequest)->create(['status' => CodeChangeStatus::Failed, 'branch' => 'prism/change-2']);
    $orphan = worktreeDirectory('acme-app-pr-10-review-jkl012');
    Process::fake([
        '*worktree*list*' => Process::result(collect([$this->checkout, $leftOver, $recent, $ready->worktree_path])->map(fn (string $path): string => "worktree {$path}\nHEAD abc\n")->implode("\n")),
        '*for-each-ref*refs/heads/prism*' => Process::result("refs/heads/prism/change-1\nrefs/heads/prism/change-2\nrefs/heads/prism/pr-5-fixes-2"),
        '*for-each-ref*refs/prism*' => Process::result('refs/prism/pr-7'),
        '*' => Process::result(''),
    ]);

    CleanUpWorktrees::dispatchSync();

    expect(gitCommandsWith('remove'))->toBe(["worktree remove --force {$leftOver}"])
        ->and(gitCommandsWith('-D'))->toBe(['branch -D prism/change-2'])
        ->and(gitCommandsWith('update-ref'))->toBe(['update-ref -d refs/prism/pr-7'])
        ->and(gitCommandsWith('prune'))->not->toBeEmpty()
        ->and(is_dir($orphan))->toBeFalse()
        ->and(is_dir($recent))->toBeTrue()
        ->and(is_dir($ready->worktree_path))->toBeTrue();
});

it('keeps the pull request refs while Claude is working', function () {
    $pullRequest = PullRequest::factory()->for(trackedRepository('acme/app', ['local_path' => $this->checkout]))->create();
    CodeChange::factory()->for($pullRequest)->create(['status' => CodeChangeStatus::Running]);
    Process::fake([
        '*for-each-ref*refs/prism*' => Process::result('refs/prism/pr-7'),
        '*' => Process::result(''),
    ]);

    CleanUpWorktrees::dispatchSync();

    expect(gitCommandsWith('update-ref'))->toBe([]);
});

it('removes the worktree and branch of discarded changes', function () {
    Process::fake(['*' => Process::result('')]);
    $change = CodeChange::factory()->create(['status' => CodeChangeStatus::Discarded, 'checkout_path' => $this->checkout, 'branch' => 'prism/change-3']);

    RemoveCodeChangeWorktree::dispatchSync($change);

    expect($change->fresh()->worktree_path)->toBeNull()
        ->and(gitCommandsWith('-D'))->toBe(['branch -D prism/change-3']);
});

it('leaves the worktree of changes that are ready to push', function () {
    Process::fake();
    $change = CodeChange::factory()->create(['status' => CodeChangeStatus::Ready]);

    RemoveCodeChangeWorktree::dispatchSync($change);

    Process::assertNothingRan();
    expect($change->fresh()->worktree_path)->not->toBeNull();
});
