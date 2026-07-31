<?php
declare(strict_types=1);

if (!function_exists('loadEnvironmentFile')) {
    function loadEnvironmentFile(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }

            $parts = explode('=', $line, 2);
            $name = trim($parts[0]);
            $value = isset($parts[1]) ? trim($parts[1]) : '';

            if ($name === '') {
                continue;
            }

            $valueLength = strlen($value);
            if ($valueLength >= 2) {
                $firstCharacter = $value[0];
                $lastCharacter = $value[$valueLength - 1];

                if (($firstCharacter === '"' && $lastCharacter === '"') || ($firstCharacter === "'" && $lastCharacter === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            if (getenv($name) === false) {
                putenv($name . '=' . $value);
            }

            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

$environmentFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
loadEnvironmentFile($environmentFile);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if ($secure) {
        ini_set('session.cookie_secure', '1');
    }
}