<?php

namespace Chattrn\Connector\Support;

use Illuminate\Support\Facades\Http;

// Calls the Chattrn-side register endpoint (devspec.txt: "Register endpoint: on first boot
// the package sends the token, site URL, route prefix, package version and Laravel version.
// Chattrn returns the access key it will send as X-Chattrn-Access-Token..."). Chattrn's side
// of this contract is chattrn-backend/src/server.js's POST /package/register.
class ChattrnRegistrar
{
    public const PACKAGE_VERSION = '1.2.2';

    // Returns ['ok' => true, 'access_token' => ...] or ['ok' => false, 'message' => ...].
    public static function register(string $connectionToken, ?callable $progress = null): array
    {
        return \Illuminate\Support\Facades\Cache::lock('chattrn.registration', 75)->block(15, function () use ($connectionToken, $progress) {
            return self::exchange($connectionToken, $progress);
        });
    }

    private static function exchange(string $connectionToken, ?callable $progress): array
    {
        $hash = hash('sha256', $connectionToken);
        $saved = Settings::tableExists() ? \Illuminate\Support\Facades\DB::table('chattrn_settings')->where('key', '_registration_attempt')->value('value') : null;
        $attempt = $saved ? json_decode($saved, true) : null;
        if (Credentials::token() && (! $connectionToken || (($attempt['token_hash'] ?? null) === $hash && ($attempt['complete'] ?? false)))) {
            return ['ok' => true];
        }
        if (! Settings::tableExists()) {
            return ['ok' => false, 'message' => 'Run php artisan migrate before pairing.'];
        }
        try {
            \Illuminate\Support\Facades\Crypt::encryptString('pairing-storage-check');
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Configure a stable Laravel APP_KEY before pairing.'];
        }
        if (($attempt['token_hash'] ?? null) !== $hash) {
            $attempt = ['token_hash' => $hash, 'id' => (string) \Illuminate\Support\Str::uuid(), 'complete' => false];
            \Illuminate\Support\Facades\DB::table('chattrn_settings')->updateOrInsert(['key' => '_registration_attempt'], ['value' => json_encode($attempt), 'created_at' => now(), 'updated_at' => now()]);
        }
        $backend = rtrim(config('chattrn.backend_url'), '/');
        $siteUrl = rtrim((string) (config('chattrn.store.domain') ?? config('app.url')), '/');

        try {
            $retry = 0;
            $response = Http::connectTimeout(5)->timeout(12)->acceptJson()->retry(
                [1000, 2000, 4000],
                0,
                function ($error) use (&$retry, $progress) {
                    $transient = $error instanceof \Illuminate\Http\Client\ConnectionException ||
                        ($error instanceof \Illuminate\Http\Client\RequestException && in_array($error->response->status(), [429, 502, 503, 504], true));
                    if ($transient && $progress && $retry < 3) {
                        $progress('Chattrn is temporarily unavailable. Retrying pairing (attempt '.(++$retry + 1).' of 4); keep the same setup token.');
                    }
                    return $transient;
                },
                throw: false,
            )->post("{$backend}/package/register", [
                'token' => $connectionToken,
                'registration_id' => $attempt['id'],
                'site_url' => $siteUrl,
                'route_prefix' => config('chattrn.route_prefix'),
                'package_version' => self::PACKAGE_VERSION,
                'framework_version' => app()->version(),
                'models_matched' => [
                    'product' => (bool) config('chattrn.models.product'),
                    'variant' => (bool) config('chattrn.models.variant'),
                    'order' => (bool) config('chattrn.models.order'),
                    'customer' => (bool) config('chattrn.models.customer'),
                ],
            ]);

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return ['ok' => false, 'message' => 'Chattrn could not be reached after bounded retries. Check CHATTRN_BACKEND_URL and retry with the same setup token before expiry.'];
        }

        if (! $response->successful()) {
            $message = match ($response->status()) {
                401, 403 => 'Setup token was rejected. Check the token or generate a fresh reconnect token in Chattrn.',
                410 => 'Setup token expired. Generate a fresh setup token in Chattrn and redeploy.',
                429, 502, 503, 504 => 'Chattrn is still temporarily unavailable after retries. Retry with the same setup token before expiry.',
                default => 'Pairing failed (HTTP '.$response->status().'). Check the public Chattrn backend URL and retry.',
            };
            return ['ok' => false, 'message' => $message];
        }

        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            return ['ok' => false, 'message' => 'Chattrn returned no access token.'];
        }
        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($token, $attempt) {
                Credentials::save($token);
                $attempt['complete'] = true;
                \Illuminate\Support\Facades\DB::table('chattrn_settings')->where('key', '_registration_attempt')->update(['value' => json_encode($attempt), 'updated_at' => now()]);
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not save credentials. Check database access and retry with the same setup token before it expires.'];
        }

        return ['ok' => true];
    }
}
