<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Resources\RepositoryResource;
use App\Models\GithubAccount;
use App\Models\Repository;
use App\Services\AppSettings;
use App\Services\Github\GithubCli;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GithubSettingsController extends Controller
{
    public function edit(GithubCli $githubCli, AppSettings $settings): Response
    {
        $account = GithubAccount::current();

        return Inertia::render('settings/Github', [
            'account' => [
                'login' => $account->login,
                'name' => $account->name,
                'avatar_url' => $account->avatar_url,
                'token_source' => $account->token_source->label(),
                'scopes' => $account->scopes ?? [],
                'teams' => $account->teams ?? [],
            ],
            'repositories' => RepositoryResource::collection(
                Repository::query()->tracked()->withPullRequestCounts()->orderBy('full_name')->get(),
            )->resolve(),
            'localCodeFolder' => $settings->get(AppSettings::LOCAL_CODE_FOLDER),
            'githubCli' => [
                'binary' => $githubCli->binary(),
                'path_override' => $settings->githubCliPath(),
            ],
        ]);
    }

    public function update(Request $request, AppSettings $settings): RedirectResponse
    {
        $validated = $request->validate([
            'github_cli_path' => ['nullable', 'string', 'max:1024', function (string $attribute, mixed $value, Closure $fail): void {
                if (filled($value) && ! is_executable($value)) {
                    $fail('No executable was found at that path.');
                }
            }],
        ]);

        $settings->set(AppSettings::GITHUB_CLI_PATH, $validated['github_cli_path'] ?? null);

        return back()->with('success', 'GitHub settings saved.');
    }
}
