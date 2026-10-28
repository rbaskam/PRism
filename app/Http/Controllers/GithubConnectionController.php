<?php

namespace App\Http\Controllers;

use App\Actions\ConnectGithubAccount;
use App\Enums\GithubTokenSource;
use App\Models\GithubAccount;
use App\Services\AppSettings;
use App\Services\Github\GithubCli;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class GithubConnectionController extends Controller
{
    /**
     * Connect with a personal access token the user pasted.
     */
    public function storeToken(Request $request, ConnectGithubAccount $connect, AppSettings $settings): RedirectResponse
    {
        $validated = $request->validate(['token' => ['required', 'string', 'max:255']]);

        return $this->connect($connect, $settings, trim($validated['token']), GithubTokenSource::PersonalAccessToken, 'token');
    }

    /**
     * Connect by reusing the GitHub CLI's login.
     */
    public function storeCliToken(GithubCli $githubCli, ConnectGithubAccount $connect, AppSettings $settings): RedirectResponse
    {
        $token = $githubCli->token();

        if ($token === null) {
            throw ValidationException::withMessages([
                'gh' => 'The GitHub CLI is not logged in. Run `gh auth login` in a terminal, or paste a token instead.',
            ]);
        }

        return $this->connect($connect, $settings, $token, GithubTokenSource::GithubCli, 'gh');
    }

    public function destroy(): RedirectResponse
    {
        GithubAccount::query()->delete();

        return redirect()->route('onboarding.github');
    }

    /**
     * Carry on to choosing repositories during onboarding, or go back to Settings → GitHub after reconnecting.
     */
    private function connect(ConnectGithubAccount $connect, AppSettings $settings, string $token, GithubTokenSource $source, string $errorKey): RedirectResponse
    {
        try {
            $result = $connect->handle($token, $source);
        } catch (RequestException $exception) {
            throw ValidationException::withMessages([
                $errorKey => $exception->response->unauthorized()
                    ? 'GitHub rejected that token. Check it has not expired.'
                    : 'Could not reach GitHub: '.$exception->response->reason(),
            ]);
        }

        return redirect()
            ->route($settings->hasCompletedOnboarding() ? 'settings.github' : 'onboarding.repositories')
            ->with('success', $connect->message($result));
    }
}
