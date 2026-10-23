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
// Renders the same CDS-styled "Connect" screen as /chattrn/setup/map and the (future)
// /chattrn/setup/verify (SetupLayout - stepper, Connection card, Environment card) when
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

        if (\Chattrn\Connector\Support\Credentials::token()) {
            $payload = ['ok' => true, 'message' => 'Already connected. Continue in your Chattrn dashboard.'];
            return $this->respond($request, 200, $payload, $this->renderConnect(
                bannerType: 'ok',
                bannerMessage: $payload['message'],
                connected: true,
                revealToken: null,
                token: $request->query('token', '')
            ));
        }

        $expected = config('chattrn.connection_token');
        if (! $expected) {
            $payload = ['ok' => false, 'message' => 'No CHATTRN_TOKEN configured in .env - paste it in first.'];
            return $this->respond($request, 422, $payload, $this->renderConnect(
                bannerType: 'err',
                bannerMessage: $payload['message'],
                connected: false,
                revealToken: null,
                token: $request->query('token', '')
            ));
        }
        if (! hash_equals((string) $expected, (string) $request->query('token', ''))) {
            $payload = ['ok' => false, 'message' => 'Missing or incorrect ?token= - pass the same value as CHATTRN_TOKEN.'];
            return $this->respond($request, 403, $payload, $this->renderConnect(
                bannerType: 'err',
                bannerMessage: $payload['message'],
                connected: false,
                revealToken: null,
                token: $request->query('token', '')
            ));
        }

        $result = ChattrnRegistrar::register($expected);
        if (! $result['ok']) {
            $payload = ['ok' => false, 'message' => $result['message']];
            return $this->respond($request, 502, $payload, $this->renderConnect(
                bannerType: 'err',
                bannerMessage: $payload['message'],
                connected: false,
                revealToken: null,
                token: $request->query('token', '')
            ));
        }

        $payload = [
            'ok' => true,
            'message' => 'Connected. Access token saved encrypted in your database. Continue with mapping in Chattrn.',
        ];

        return $this->respond($request, 200, $payload, $this->renderConnect(
            bannerType: 'ok',
            bannerMessage: $payload['message'],
            connected: true,
            revealToken: null,
            token: ''
        ));
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

    // The single "Connect" screen (step 1 of 3), covering every state the register endpoint
    // can be in:
    //   - not connected yet (no/wrong token, or Product/Order not mapped) — $connected=false
    //   - just registered this request, access token not saved to .env yet — $revealToken set,
    //     the one moment the real token is shown in full so it can be copied
    //   - already registered on an earlier visit — $connected=true, $revealToken=null, so the
    //     stored token is shown masked rather than re-displayed in full
    private function renderConnect(string $bannerType, string $bannerMessage, bool $connected, ?string $revealToken, string $token): string
    {
        $html = '<div class="panel-head">';
        $html .= '<h1>Register with Chattrn</h1>';
        $html .= '<p class="lede">One-time &mdash; exchanges your setup token for an ongoing access token. Chattrn calls your package from its servers; nothing in your store changes.</p>';
        $html .= SetupLayout::stepper(1);
        $html .= '</div>';

        $html .= '<div class="panel-body">';
        $html .= SetupLayout::banner($bannerType, $bannerMessage);
        $html .= $this->connectionCard($connected, $revealToken);
        $html .= $this->environmentCard($connected);
        $html .= '</div>';

        $continueHtml = $connected
            ? '<span class="desc">Return to your Chattrn dashboard to review mappings.</span>'
            : '<span class="btn btn-disabled">Finish pairing first</span>';

        $html .= '<div class="footer-bar">'
            . '<a class="btn btn-secondary" href="/">Back</a>'
            . $continueHtml
            . '</div>';

        $html .= '<script>
function chattrnCopy(btn, value) {
    navigator.clipboard.writeText(value).then(() => {
        const old = btn.textContent;
        btn.textContent = "Copied";
        setTimeout(() => { btn.textContent = old; }, 1200);
    });
}
</script>';

        return $html;
    }

    private function connectionCard(bool $connected, ?string $revealToken): string
    {
        $badge = $connected
            ? SetupLayout::badge('ok', 'CONNECTED')
            : SetupLayout::badge('err', 'NOT CONNECTED');

        $tokenField = $connected
            ? '<span class="val">Saved securely — no copying required</span>'
            : '<span class="val">Not issued yet</span>';

        $html = '<section class="card">';
        $html .= '<div class="card-head"><div class="titles">'
            . '<p style="margin:0;font:600 16px/24px Inter,sans-serif;color:var(--ink);">Connection</p>'
            . '<p style="margin:0;font:400 13px/20px Inter,sans-serif;color:var(--ink-soft);">Chattrn calls your package from its servers using this access token.</p>'
            . '</div>' . $badge . '</div>';

        $html .= '<div class="tf-row">'
            . '<div class="tf"><label>Access token</label><div class="field">' . $tokenField . '</div></div>'
            . '<div class="tf"><label>Package</label><div class="field"><span class="val">chattrn/connector &middot; v' . e(ChattrnRegistrar::PACKAGE_VERSION) . '</span></div></div>'
            . '</div>';

        $html .= '<div class="note">'
            . '<svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true"><rect x="4" y="8" width="10" height="7" rx="1.5" stroke="#6c7584" stroke-width="1.3" fill="none"/><path d="M6 8V5.5a3 3 0 0 1 6 0V8" stroke="#6c7584" stroke-width="1.3" fill="none"/></svg>'
            . '<p>New credentials are encrypted in your database. Existing environment credentials are also supported.</p></div>';

        $html .= '</section>';

        return $html;
    }

    private function environmentCard(bool $connected): string
    {
        $rawEnv = getenv('CHATTRN_TOKEN');
        $rows = [
            [
                'CHATTRN_TOKEN present in environment',
                $rawEnv !== false && $rawEnv !== '',
            ],
            [
                'Laravel config sees it',
                (bool) (config('chattrn.connection_token') || config('chattrn.access_token')),
            ],
            [
                'Tables mapped',
                (bool) (config('chattrn.models.product') && config('chattrn.models.order')),
                'Not started yet &mdash; happens next, in Step 2.',
            ],
        ];

        $html = '<section class="card"><p style="margin:0;font:600 16px/24px Inter,sans-serif;color:var(--ink);">Environment</p>'
            . '<p class="section-hint">What Chattrn can see on this install so far.</p>'
            . '<div class="row-list">';

        foreach ($rows as $row) {
            [$label, $done] = $row;
            $hint = $row[2] ?? null;
            $hint = $hint ?? null;
            $state = $done
                ? '<span class="state ok">DONE</span>'
                : '<span class="state">&mdash;</span>';
            $html .= '<div class="status-row"><div>'
                . '<strong>' . e($label) . '</strong>'
                . ($hint && ! $done ? '<span class="desc">' . $hint . '</span>' : '')
                . '</div>' . $state . '</div>';
        }

        $html .= '</div></section>';

        return $html;
    }

    private function maskToken(string $token): string
    {
        if ($token === '') {
            return 'Not issued yet';
        }

        $tail = substr($token, -6);
        $prefix = str_contains($token, '_') ? substr($token, 0, strpos($token, '_') + 1) : '';

        return $prefix . str_repeat('&bull;', 12) . $tail;
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

        $html = '<div class="panel-head"><h1>Environment diagnostics</h1>'
            . '<p class="lede">No secret values shown — presence and length only.</p></div>';
        $html .= '<div class="panel-body"><div class="card">';
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
        $html .= '</div></div>';

        return $html;
    }
}
