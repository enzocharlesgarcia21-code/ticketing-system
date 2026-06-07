<?php

$rootDir = realpath(__DIR__ . '/..');
if ($rootDir === false) {
    $rootDir = __DIR__ . '/..';
}

if (!function_exists('app_load_env_file')) {
    function app_load_env_file(string $envPath): void
    {
        if (!is_readable($envPath)) {
            return;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            if ($name === '' || getenv($name) !== false) {
                continue;
            }

            $len = strlen($value);
            if ($len >= 2) {
                $first = $value[0];
                $last = $value[$len - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

$autoloadPath = $rootDir . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

if (class_exists(\Dotenv\Dotenv::class)) {
    $dotenv = \Dotenv\Dotenv::createUnsafeImmutable($rootDir);
    $dotenv->safeLoad();
} else {
    app_load_env_file($rootDir . '/.env');
}

