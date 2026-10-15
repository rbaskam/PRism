<?php

namespace App\Http\Controllers;

use App\Actions\ConnectGithubAccount;
use App\Enums\GithubTokenSource;
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

    public function poll(Request $request, GithubDeviceFlow $deviceFlow, ConnectGithubAccount $connect): JsonResponse
    {
        abort_unless($deviceFlow->isAvailable(), 404);

        $validated = $request->validate(['device_code' => ['required', 'string']]);

        try {
            $result = $deviceFlow->poll($validated['device_code']);

            if ($result['status'] !== 'authorized') {
                return response()->json($result);
            }

            $account = $connect->handle($result['token'], GithubTokenSource::DeviceFlow);
        } catch (RequestException|ConnectionException $exception) {
            return response()->json(['status' => 'error', 'message' => GithubClient::describeFailure($exception)], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json([
            'status' => 'authorized',
            'login' => $account->login,
            'redirect' => route('onboarding.repositories'),
        ]);
    }
}
