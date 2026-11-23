<?php
function env(string $key, string $default = ''): string {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $file = __DIR__ . '/../.env';
        if (!file_exists($file)) return $default;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (!str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            $cache[trim($k)] = trim($v);
        }
    }
    return $cache[$key] ?? $default;
}
