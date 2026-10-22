<?php

namespace Chattrn\Connector\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Chattrn\Connector\Support\ChattrnRegistrar;
use Chattrn\Connector\Support\SetupLayout;

// A browser-triggerable equivalent of `php artisan chattrn:register`, for a host where
// shell/SSH/one-off-job access is a paid feature the store isn't on (e.g. Render's free
// compute plan blocks all three). Not part of the Chattrn-facing API contract - this is a
// setup utility the store owner hits once themselves, guarded by the same CHATTRN_TOKEN
// already in .env rather than a separate secret, since that value is already only known to
// whoever has the Install package screen open.
//
// Renders the same card-on-light-background look as /chattrn/setup/map (SetupLayout) when
// opened in a browser, and falls back to plain JSON for anything that sends
// Accept: application/json (a script, curl, or a future onboarding-status poller) - so the
// one endpoint serves both a human clicking a link and a machine checking status.
class ChattrnSetupController extends Controller
{
    // Visit .../chattrn/setup/register?debug=1 (no token needed) to see, without exposing any
    // secret value, whether the host's environment and Laravel's cached config actually agree
    // on CHATTRN_TOKEN - the fastest way to tell a stale config:cache apart from an env var
    // that never reached the running container, on a host with no shell/SSH access.
    public function register(Request $request)
    {
        if ($request->query('debug') === '1') {
            $rawEnv = getenv('CHATTRN_TOKEN');

            $diagnostics = [
                'getenv_CHATTRN_TOKEN_present' => $rawEnv !== false && $rawEnv !== '',
                'getenv_CHATTRN_TOKEN_length' => $rawEnv !== false ? strlen($rawEnv) : 0,
                'config_connection_token_present' => (bool) config('chattrn.connection_token'),
                'config_connection_token_length' => strlen((string) config('chattrn.connection_token')),
                'config_access_token_present' => (bool) config('chattrn.access_token'),
                'config_is_cached' => app()->configurationIsCached(),
                'app_env' => config('app.env'),
                'package_version' => ChattrnRegistrar::PACKAGE_VERSION,
            ];

            return $this->respond($request, 200, ['ok' => true, 'diagnostics' => $diagnostics], $this->renderDiagnostics($diagnostics));
        }

        if (config('chattrn.access_token')) {
            $payload = ['ok' => true, 'message' => 'Already registered (CHATTRN_ACCESS_TOKEN is already set).'];
            return $this->respond($request, 200, $payload, $this->renderResult('ok', $payload['message']));
        }

        $expected = config('chattrn.connection_token');
        if (! $expected) {
            $payload = ['ok' => false, 'message' => 'No CHATTRN_TOKEN configured in .env - paste it in first.'];
            return $this->respond($request, 422, $payload, $this->renderResult('err', $payload['message']));
        }
        if (! hash_equals((string) $expected, (string) $request->query('token', ''))) {
            $payload = ['ok' => false, 'message' => 'Missing or incorrect ?token= - pass the same value as CHATTRN_TOKEN.'];
            return $this->respond($request, 403, $payload, $this->renderResult('err', $payload['message']));
        }

        if (! config('chattrn.models.product') || ! config('chattrn.models.order')) {
            $payload = ['ok' => false, 'message' => 'Product/Order aren\'t mapped yet - visit /chattrn/setup/map first.'];
            return $this->respond($request, 422, $payload, $this->renderResult('err', $payload['message']));
        }

        $result = ChattrnRegistrar::register($expected);
        if (! $result['ok']) {
            $payload = ['ok' => false, 'message' => $result['message']];
            return $this->respond($request, 502, $payload, $this->renderResult('err', $payload['message']));
        }

        $payload = [
            'ok' => true,
            'message' => 'Registered. Add this to .env, then remove the CHATTRN_TOKEN line and redeploy.',
            'CHATTRN_ACCESS_TOKEN' => $result['access_token'],
        ];

        $body = $this->renderResult('ok', $payload['message'])
            . '<div class="card"><h2>CHATTRN_ACCESS_TOKEN</h2>'
            . '<p class="section-hint">Add this to your host\'s environment variables, then remove CHATTRN_TOKEN and redeploy.</p>'
            . '<div class="token-box">' . e($result['access_token']) . '</div></div>'
            . '<p><a class="btn-secondary" href="/chattrn/setup/map?token=' . e($result['access_token']) . '">Map your store →</a></p>';

        return $this->respond($request, 200, $payload, $body);
    }

    // JSON for anything that explicitly asks for it (Accept: application/json - scripts,
    // curl -H, a future status poller); the styled HTML page for an ordinary browser visit,
    // which is how every person in this flow actually reaches this URL.
    private function respond(Request $request, int $status, array $payload, string $htmlBody)
    {
        if ($request->wantsJson()) {
            return response()->json($payload, $status);
        }

        return response(SetupLayout::page('Register with Chattrn', $htmlBody), $status)
            ->header('Content-Type', 'text/html; charset=utf-8');
    }

    private function renderResult(string $type, string $message): string
    {
        $title = $type === 'ok' ? 'Register with Chattrn' : 'Couldn\'t register yet';

        return '<h1>' . e($title) . '</h1>' . SetupLayout::banner($type, $message);
    }

    private function renderDiagnostics(array $diagnostics): string
    {
        $rows = [
            ['getenv_CHATTRN_TOKEN_present', 'Is CHATTRN_TOKEN present in the OS environment?'],
            ['getenv_CHATTRN_TOKEN_length', 'Its length, if present'],
            ['config_connection_token_present', 'Does Laravel\'s config see it?'],
            ['config_connection_token_length', 'Its length as Laravel sees it'],
            ['config_access_token_present', 'Is CHATTRN_ACCESS_TOKEN set (already registered)?'],
            ['config_is_cached', 'Is config cached (php artisan config:cache)?'],
            ['app_env', 'APP_ENV'],
            ['package_version', 'chattrn/connector version'],
        ];

        $html = '<h1>Environment diagnostics</h1>';
        $html .= '<p class="lede">No secret values shown — presence and length only.</p>';
        $html .= '<div class="card">';
        foreach ($rows as [$key, $label]) {
            $value = $diagnostics[$key] ?? null;
            $display = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            if (is_bool($value)) {
                $dotClass = $value ? 'ok' : 'warn';
                $dotChar = $value ? '✓' : '!';
            } else {
                $dotClass = 'neutral';
                $dotChar = '•';
            }
            $html .= '<div class="status-row"><div class="status-dot ' . $dotClass . '">' . $dotChar . '</div>'
                . '<div><strong>' . e($label) . '</strong><span class="desc">' . e($key) . ' = <code>' . e($display) . '</code></span></div></div>';
        }
        $html .= '</div>';

        return $html;
    }
}
