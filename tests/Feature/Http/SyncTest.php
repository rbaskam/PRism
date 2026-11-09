<?php

use App\Jobs\PollNotifications;
use App\Jobs\SyncGithub;
use Illuminate\Support\Facades\Queue;

it('syncs pull requests and notifications from GitHub when the user asks', function () {
    onboardedAccount();
    Queue::fake();

    $response = $this->from(route('dashboard'))->post(route('sync'));

    $response->assertRedirect(route('dashboard'))->assertSessionHas('success', 'Syncing with GitHub…');
    Queue::assertPushed(SyncGithub::class);
    Queue::assertPushed(PollNotifications::class);
});
