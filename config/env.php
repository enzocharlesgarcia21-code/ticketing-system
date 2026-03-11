<?php

$rootDir = realpath(__DIR__ . '/..');
if ($rootDir === false) {
    $rootDir = __DIR__ . '/..';
}

$autoloadPath = $rootDir . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

if (class_exists(\Dotenv\Dotenv::class)) {
    $dotenv = \Dotenv\Dotenv::createUnsafeImmutable($rootDir);
    $dotenv->safeLoad();
}

