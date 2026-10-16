<?php

use App\Enums\NotificationSound;

it('plays Glass by default on macOS, where it exists', function () {
    expect(NotificationSound::defaultFor('Darwin'))->toBe(NotificationSound::Glass);
});

it('plays the system\'s own sound by default elsewhere', function (string $osFamily) {
    expect(NotificationSound::defaultFor($osFamily))->toBe(NotificationSound::Default);
})->with(['Windows', 'Linux']);
