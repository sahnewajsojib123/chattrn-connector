<?php

namespace Chattrn\Connector\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Chattrn\Connector\Support\ChattrnRegistrar;

// A browser-triggerable equivalent of `php artisan chattrn:register`, for a host where
// shell/SSH/one-off-job access is a paid feature the store isn't on (e.g. Render's free
// compute plan blocks all three). Not part of the Chattrn-facing API contract - this is a
// setup utility the store owner hits once themselves, guarded by the same CHATTRN_TOKEN
// already in .env rather than a separate secret, since that value is already only known to
// whoever has the Install package screen open.
class ChattrnSetupController extends Controller
{
    // Visit .../chattrn/setup/register?debug=1 (no token needed) to see, without exposing any
    // secret value, whether the host's environment and Laravel's cached config actually agree
    // on CHATTRN_TOKEN - the fastest way to tell a stale config:cache apart from an env var
    // that never reached the running container, on a host with no shell/SSH access.
    public function register(Request $request): JsonResponse
    {
        if ($request->query('debug') === '1') {
            $rawEnv = getenv('CHATTRN_TOKEN');

            return response()->json([
                'ok' => true,
                'diagnostics' => [
                    'getenv_CHATTRN_TOKEN_present' => $rawEnv !== false && $rawEnv !== '',
                    'getenv_CHATTRN_TOKEN_length' => $rawEnv !== false ? strlen($rawEnv) : 0,
                    'config_connection_token_present' => (bool) config('chattrn.connection_token'),
                    'config_connection_token_length' => strlen((string) config('chattrn.connection_token')),
                    'config_access_token_present' => (bool) config('chattrn.access_token'),
                    'config_is_cached' => app()->configurationIsCached(),
                    'app_env' => config('app.env'),
                    'package_version' => \Chattrn\Connector\Support\ChattrnRegistrar::PACKAGE_VERSION,
                ],
            ]);
        }

        if (config('chattrn.access_token')) {
            return response()->json(['ok' => true, 'message' => 'Already registered (CHATTRN_ACCESS_TOKEN is already set).']);
        }

        $expected = config('chattrn.connection_token');
        if (! $expected) {
            return response()->json(['ok' => false, 'message' => 'No CHATTRN_TOKEN configured in .env - paste it in first.'], 422);
        }
        if (! hash_equals((string) $expected, (string) $request->query('token', ''))) {
            return response()->json(['ok' => false, 'message' => 'Missing or incorrect ?token= - pass the same value as CHATTRN_TOKEN.'], 403);
        }

        if (! config('chattrn.models.product') || ! config('chattrn.models.order')) {
            return response()->json(['ok' => false, 'message' => 'config/chattrn.php has no Product/Order model mapped yet.'], 422);
        }

        $result = ChattrnRegistrar::register($expected);
        if (! $result['ok']) {
            return response()->json(['ok' => false, 'message' => $result['message']], 502);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Registered. Add this to .env, then remove the CHATTRN_TOKEN line and redeploy.',
            'CHATTRN_ACCESS_TOKEN' => $result['access_token'],
        ]);
    }
}
