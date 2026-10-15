<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\AppSettings;
use App\Services\Changelog;
use App\Services\UpdateChecker;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The app version, updates from GitHub releases, and the changelog.
 */
class AboutController extends Controller
{
    public function show(UpdateChecker $updates, Changelog $changelog, AppSettings $settings): Response
    {
        return Inertia::render('settings/About', [
            'currentVersion' => $updates->currentVersion(),
            'latestRelease' => $updates->latestRelease(),
            'updateAvailable' => $updates->updateIsAvailable(),
            'checkedAt' => $settings->get(UpdateChecker::CHECKED_AT),
            'repositoryUrl' => 'https://github.com/'.config('prism.repository'),
            'releases' => $changelog->releases(),
            'unreleased' => $changelog->notesFor(Changelog::UNRELEASED),
        ]);
    }

    public function check(UpdateChecker $updates): RedirectResponse
    {
        try {
            $latest = $updates->check();
        } catch (RequestException $exception) {
            return back()->with('error', 'Could not check for updates: GitHub returned '.$exception->response->status().'.');
        }

        return back()->with('success', match (true) {
            $latest === null => 'No releases have been published yet.',
            $updates->updateIsAvailable() => "Version {$latest['version']} is available.",
            default => "You're on the latest version ({$updates->currentVersion()}).",
        });
    }

    /**
     * Hide the "What's new" dialog for the version the user is running.
     */
    public function dismissWhatsNew(UpdateChecker $updates): RedirectResponse
    {
        $updates->markVersionSeen();

        return back();
    }
}
