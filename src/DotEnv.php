<?php

declare(strict_types=1);

namespace Tbank\Invest;

final class DotEnv
{
    public static function load(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = self::unquote(trim($value));
            if ($key === '') {
                continue;
            }
            if (getenv($key) !== false && getenv($key) !== '') {
                continue;
            }
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    public static function loadNearest(?string $startDir = null): void
    {
        $dir = $startDir ?? getcwd() ?: dirname(__DIR__);
        for ($i = 0; $i < 6; $i++) {
            $candidate = $dir . DIRECTORY_SEPARATOR . '.env';
            if (is_file($candidate)) {
                self::load($candidate);
                return;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
        $fallback = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
        if (is_file($fallback)) {
            self::load($fallback);
        }
    }

    private static function unquote(string $value): string
    {
        if ($value === '') {
            return $value;
        }
        $first = $value[0];
        $last = $value[strlen($value) - 1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
