<?php
declare(strict_types=1);

namespace App\Support;

/** Minimal .env loader. Real environment variables always win over the file. */
final class Env
{
    /** @return array<string,string> */
    public static function parseFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }
        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            $value = trim(substr($line, $eq + 1));
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $quote = $value[0];
                $end = strrpos($value, $quote);
                $value = $end > 0 ? substr($value, 1, $end - 1) : substr($value, 1);
                if ($quote === '"') {
                    $value = str_replace(['\\n', '\\"', '\\\\'], ["\n", '"', '\\'], $value);
                }
            } else {
                $hash = strpos($value, ' #');
                if ($hash !== false) {
                    $value = rtrim(substr($value, 0, $hash));
                }
            }
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) === 1) {
                $values[$key] = $value;
            }
        }
        return $values;
    }

    /** @return array<string,string> file values overlaid with the process environment */
    public static function load(string $path): array
    {
        $values = self::parseFile($path);
        foreach (getenv() as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $values[$key] = $value;
            }
        }
        return $values;
    }
}
