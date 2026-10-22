<?php

namespace Chattrn\Connector\Support;

// Rewrites config/chattrn.php's models/columns after `php artisan chattrn:map`. Regenerates
// the whole file from the merged array rather than patching text, so it stays valid PHP
// however the merchant has it indented - the trade-off (devspec.txt doesn't prescribe a
// format) is that hand-written comments in that file are lost on a re-run.
class ConfigWriter
{
    // Dot-paths that must stay wired to env() in the generated file rather than a literal
    // var_export()'d value: connection_token and access_token are secrets that must never be
    // baked into a file merchants commit to git, and backend_url/store.name/store.domain are
    // meant to track whatever the current environment says, not freeze whatever they resolved
    // to the moment `chattrn:map` happened to run.
    private const ENV_BACKED = [
        'access_token' => "env('CHATTRN_ACCESS_TOKEN')",
        'backend_url' => "env('CHATTRN_BACKEND_URL', 'https://api.chattrn.com')",
        'connection_token' => "env('CHATTRN_TOKEN')",
        'store.name' => "env('APP_NAME', 'My Store')",
        'store.domain' => "env('APP_URL')",
    ];

    public static function write(string $path, array $config): bool
    {
        $body = self::exportArray($config, 1);
        $contents = "<?php\n\n"
            . "// Rewritten by `php artisan chattrn:map`. See vendor/chattrn/connector/config/chattrn.php\n"
            . "// for what each key means. access_token/connection_token/backend_url/store.name/store.domain\n"
            . "// stay wired to env() here (never baked in as literals) so secrets never land in version\n"
            . "// control and a new token, access token, or backend URL doesn't need a re-run of chattrn:map.\n"
            . "return [\n{$body}];\n";

        return @file_put_contents($path, $contents) !== false;
    }

    private static function exportArray(array $array, int $depth, string $prefix = ''): string
    {
        $indent = str_repeat('    ', $depth);
        $out = '';

        foreach ($array as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            $keyCode = var_export($key, true);

            if (is_array($value)) {
                $out .= "{$indent}{$keyCode} => [\n" . self::exportArray($value, $depth + 1, $path) . "{$indent}],\n";
                continue;
            }

            if (isset(self::ENV_BACKED[$path])) {
                $out .= "{$indent}{$keyCode} => " . self::ENV_BACKED[$path] . ",\n";
                continue;
            }

            $out .= "{$indent}{$keyCode} => " . var_export($value, true) . ",\n";
        }

        return $out;
    }
}
