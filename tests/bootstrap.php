<?php

declare(strict_types=1);

$autoloadCandidates = [
    dirname(__DIR__) . '/vendor/autoload.php',
    dirname(__DIR__, 2) . '/fluxx-app/vendor/autoload.php',
];

foreach ($autoloadCandidates as $autoloadFile) {
    if (is_file($autoloadFile)) {
        require $autoloadFile;

        foreach (glob(__DIR__ . '/Fixture/*.php') ?: [] as $fixtureFile) {
            require_once $fixtureFile;
        }

        return;
    }
}

throw new RuntimeException('Unable to locate an autoloader for Fluxx tests.');
