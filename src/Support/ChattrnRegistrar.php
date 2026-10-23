<?php

namespace Chattrn\Connector\Support;

use Illuminate\Support\Facades\Http;

// Calls the Chattrn-side register endpoint (devspec.txt: "Register endpoint: on first boot
// the package sends the token, site URL, route prefix, package version and Laravel version.
// Chattrn returns the access key it will send as X-Chattrn-Access-Token..."). Chattrn's side
// of this contract is chattrn-backend/src/server.js's POST /package/register.
class ChattrnRegistrar
{
    public const PACKAGE_VERSION = '1.2.1';

    // Returns ['ok' => true, 'access_token' => ...] or ['ok' => false, 'message' => ...].
    public static function register(string $connectionToken): array
    {
        return \Illuminate\Support\Facades\Cache::lock('chattrn.registration', 30)->block(15, function () use ($connectionToken) {
            return self::exchange($connectionToken);
        });
    }

    private static function exchange(string $connectionToken): array
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
            $response = Http::timeout(10)->post("{$backend}/package/register", [
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
            return ['ok' => false, 'message' => 'Could not reach Chattrn. Check CHATTRN_BACKEND_URL and try again.'];
        }

        if (! $response->successful()) {
            $message = $response->json('error.message') ?? $response->json('errors') ?? $response->body();
            return ['ok' => false, 'message' => is_string($message) ? $message : 'Register call failed.'];
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
