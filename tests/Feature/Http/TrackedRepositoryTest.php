<?php

use App\Jobs\SyncRepository;
use App\Models\GithubAccount;
use App\Models\Repository;
use Illuminate\Support\Facades\Queue;

it('tracks the chosen repositories, stops tracking the rest and syncs the new ones', function () {
    Queue::fake([SyncRepository::class]);
    GithubAccount::factory()->create(['untracked_review_requests' => ['acme/api', 'acme/web']]);
    $dropped = Repository::factory()->create();

    $response = $this->put(route('tracked-repositories.update'), [
        'repositories' => [[
            'github_id' => 555,
            'owner' => 'acme',
            'name' => 'api',
            'full_name' => 'acme/api',
            'html_url' => 'https://github.com/acme/api',
            'default_branch' => 'main',
            'is_private' => true,
        ]],
        'redirect_to' => 'onboarding.claude',
    ]);

    $response->assertRedirect(route('onboarding.claude'));
    expect(Repository::where('github_id', 555)->sole()->is_tracked)->toBeTrue()
        ->and($dropped->fresh()->is_tracked)->toBeFalse()
        ->and(GithubAccount::sole()->untracked_review_requests)->toBe(['acme/web']);
    Queue::assertPushed(SyncRepository::class, fn (SyncRepository $job): bool => $job->repository->github_id === 555);
});
