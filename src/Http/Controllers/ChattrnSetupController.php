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
    public function register(Request $request): JsonResponse
    {
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
