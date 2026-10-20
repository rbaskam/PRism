<?php

use App\Http\Controllers\SyncOnReturnController;
use App\Jobs\SyncGithub;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    onboardedAccount();
    Queue::fake();
});

it('syncs with GitHub when you come back to the app', function () {
    $response = $this->postJson(route('sync.on-return'));

    $response->assertOk()->assertExactJson(['syncing' => true]);
    Queue::assertPushed(SyncGithub::class);
});

it('waits a while before syncing again when you switch back and forth', function () {
    $this->postJson(route('sync.on-return'));

    $this->postJson(route('sync.on-return'))->assertExactJson(['syncing' => false]);

    $this->travel(SyncOnReturnController::SECONDS_BETWEEN_SYNCS + 1)->seconds();
    $this->postJson(route('sync.on-return'))->assertExactJson(['syncing' => true]);
});
