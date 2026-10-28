<?php

namespace App\Http\Controllers;

use App\Actions\ConnectGithubAccount;
use App\Enums\GithubTokenSource;
use App\Services\AppSettings;
use App\Services\Github\GithubClient;
use App\Services\Github\GithubDeviceFlow;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Sign in with GitHub" using the OAuth device flow. The page polls until the user approves the code.
 */
class GithubDeviceFlowController extends Controller
{
    public function store(GithubDeviceFlow $deviceFlow): JsonResponse
    {
        abort_unless($deviceFlow->isAvailable(), 404);

        try {
            $code = $deviceFlow->start();
        } catch (RequestException|ConnectionException $exception) {
            return response()->json(['status' => 'error', 'message' => GithubClient::describeFailure($exception)], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json([
            'device_code' => $code['device_code'],
            'user_code' => $code['user_code'],
            'verification_uri' => $code['verification_uri'],
            'interval' => $code['interval'],
            'expires_in' => $code['expires_in'],
        ]);
    }

    /**
     * Once the user approves, the page goes on to choosing repositories during onboarding, or back to Settings →
     * GitHub after reconnecting, where the flashed message says who is connected.
     */
    public function poll(Request $request, GithubDeviceFlow $deviceFlow, ConnectGithubAccount $connect, AppSettings $settings): JsonResponse
    {
        abort_unless($deviceFlow->isAvailable(), 404);

        $validated = $request->validate(['device_code' => ['required', 'string']]);

        try {
            $poll = $deviceFlow->poll($validated['device_code']);

            if ($poll['status'] !== 'authorized') {
                return response()->json($poll);
            }

            $result = $connect->handle($poll['token'], GithubTokenSource::DeviceFlow);
        } catch (RequestException|ConnectionException $exception) {
            return response()->json(['status' => 'error', 'message' => GithubClient::describeFailure($exception)], Response::HTTP_BAD_GATEWAY);
        }

        session()->flash('success', $connect->message($result));

        return response()->json([
            'status' => 'authorized',
            'login' => $result['account']->login,
            'redirect' => route($settings->hasCompletedOnboarding() ? 'settings.github' : 'onboarding.repositories'),
        ]);
    }
}
