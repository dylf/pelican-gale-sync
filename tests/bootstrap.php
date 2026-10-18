<?php

// The suite exercises the pure-logic classes in src/Core and reads the plugin's files; it doesn't boot the panel.
// config/*.php calls Laravel's env(), so provide a stand-in that returns the default.
if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return $default;
    }
}

require __DIR__ . '/../vendor/autoload.php';
