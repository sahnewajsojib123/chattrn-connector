<?php

namespace Chattrn\Connector\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The chattrn_settings table's read/write layer. Rows are dot-paths under the 'chattrn'
// config tree ('models.product', 'columns.variant.price', ...) with a JSON-encoded value,
// written by chattrn:map or the /chattrn/setup/map web screen, and overlaid onto config()
// on every boot by ChattrnServiceProvider - so a mapping change is live the moment it's
// saved, with no git push or redeploy needed. Never put a secret through this - it's a
// plain DB table, not an encrypted store; access_token/connection_token/backend_url stay
// in env() (see config/chattrn.php).
class Settings
{
    private const CACHE_KEY = 'chattrn.settings.v1';
    private const CACHE_TTL = 60;

    // Applies every saved row onto config('chattrn.*') for this request. Safe to call even
    // before the table exists (a fresh install that hasn't migrated yet) or when the DB
    // connection isn't ready - fails open to whatever config/chattrn.php + env() already say.
    public static function applyOverrides(): void
    {
        foreach (self::all() as $path => $value) {
            config(['chattrn.' . $path => $value]);
        }
    }

    // [dot.path => decoded value] for every saved row, cached briefly since this runs on
    // every request.
    public static function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            if (! self::tableExists()) {
                return [];
            }

            try {
                $rows = DB::table('chattrn_settings')->pluck('value', 'key');
            } catch (\Throwable $e) {
                return [];
            }

            $out = [];
            foreach ($rows as $key => $value) {
                if (str_starts_with($key, '_')) {
                    continue;
                }
                $out[$key] = json_decode((string) $value, true);
            }

            return $out;
        });
    }

    public static function get(string $path, $default = null)
    {
        return self::all()[$path] ?? $default;
    }

    // Saves several dot-path => value pairs in one go (one query per key - this table is
    // small and this only runs on an explicit save, not per-request).
    public static function setMany(array $values): void
    {
        foreach (array_keys($values) as $key) {
            if (! preg_match('/^(models|columns|inventory|store)\./', $key)) {
                throw new \InvalidArgumentException('Only mapping settings can be saved here.');
            }
        }
        self::ensureTable();

        DB::transaction(function () use ($values) {
            $now = now();
            foreach ($values as $path => $value) {
                DB::table('chattrn_settings')->updateOrInsert(
                    ['key' => $path],
                    ['value' => json_encode($value), 'updated_at' => $now, 'created_at' => $now]
                );
            }
        });

        Cache::forget(self::CACHE_KEY);
    }

    public static function tableExists(): bool
    {
        try {
            return Schema::hasTable('chattrn_settings');
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function ensureTable(): void
    {
        if (! self::tableExists()) {
            throw new \RuntimeException(
                'chattrn_settings table does not exist yet. Run `php artisan migrate` (the chattrn/connector '
                . 'migration publishes automatically) before saving a mapping.'
            );
        }
    }
}
