<?php

namespace Chattrn\Connector\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

// Scans app/Models for Eloquent models and proposes best-guess column matches, so the
// /chattrn/setup/map screen (and `chattrn:map`) can start from a pre-filled guess instead
// of asking the dev to type every column name from a blank form - the same shape as how
// Fivetran/Segment-style schema mappers work: introspect first, confirm/override second.
class Introspector
{
    // [Fully\Qualified\Class => [columns...]] for every Eloquent model found under
    // app/Models (or wherever app.php's model namespace points, for older Laravel apps).
    public static function models(): array
    {
        $directories = array_filter([app_path('Models'), app_path()], 'is_dir');
        $found = [];

        foreach ($directories as $directory) {
            foreach (glob($directory . '/*.php') ?: [] as $file) {
                $class = self::classFromFile($file, $directory);
                if (! $class || isset($found[$class]) || ! class_exists($class)) {
                    continue;
                }
                if (! is_subclass_of($class, Model::class)) {
                    continue;
                }

                try {
                    $instance = new $class;
                    $found[$class] = Schema::getColumnListing($instance->getTable());
                } catch (\Throwable $e) {
                    continue; // abstract, needs constructor args, no table yet, etc.
                }
            }

            if ($directory === app_path('Models') && $found) {
                break; // app/Models is the modern convention; don't also scan the flatter app/ root if it found models
            }
        }

        return $found;
    }

    // Best-guess class name for a concept from the discovered model list, by exact or
    // substring match against common names ('Product', 'ProductVariant', 'Order', ...).
    public static function guessModel(array $models, array $nameCandidates): ?string
    {
        $classNames = array_keys($models);

        foreach ($nameCandidates as $candidate) {
            foreach ($classNames as $class) {
                $short = class_basename($class);
                if (strcasecmp($short, $candidate) === 0) {
                    return $class;
                }
            }
        }
        foreach ($nameCandidates as $candidate) {
            foreach ($classNames as $class) {
                if (stripos(class_basename($class), $candidate) !== false) {
                    return $class;
                }
            }
        }

        return null;
    }

    // Best-guess column name for a concept ('price', 'sku', ...) from one model's column list.
    public static function guessColumn(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            foreach ($columns as $column) {
                if (strcasecmp($column, $candidate) === 0) {
                    return $column;
                }
            }
        }

        return null;
    }

    private static function classFromFile(string $file, string $directory): ?string
    {
        $relative = trim(str_replace($directory, '', $file), '/');
        $relative = str_replace('.php', '', $relative);
        $relative = str_replace('/', '\\', $relative);

        $base = $directory === app_path('Models') ? 'App\\Models\\' : 'App\\';

        return $base . $relative;
    }
}
