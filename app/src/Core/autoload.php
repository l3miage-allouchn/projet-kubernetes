<?php

// charge automatiquement les classes, sans require à la main
spl_autoload_register(function (string $className): void {
    $folders = [
        __DIR__ . '/../Controllers/',
        __DIR__ . '/../Entity/',
        __DIR__ . '/../Manager/',
        __DIR__ . '/../Core/',
    ];

    foreach ($folders as $folder) {
        $file = $folder . $className . '.php';

        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});
