<?php

return [
    // The Gale sync API that profile sync IDs are looked up on.
    'api_url' => env('GALE_SYNC_API_URL', 'https://gale.kesomannen.com/api'),

    // Gale's list of games, their mod loaders and where their mods are hosted.
    'games_url' => env('GALE_SYNC_GAMES_URL', 'https://raw.githubusercontent.com/Kesomannen/gale/refs/heads/master/src-tauri/games.json'),

    // Minutes between looks at each server's profile (the scheduled command runs every minute and only checks when due).
    'check_interval' => (int) env('GALE_SYNC_CHECK_INTERVAL', 5),

    // Hours the games list and a mod's client/server tags are cached.
    'cache_hours' => (int) env('GALE_SYNC_CACHE_HOURS', 12),

    // Minutes a server may stay "syncing" before the plugin assumes the job died and lets you start another.
    'stale_minutes' => (int) env('GALE_SYNC_STALE_MINUTES', 30),

    // Minutes a queued sync may wait for the queue worker before the page says it never started.
    'queue_wait_minutes' => (int) env('GALE_SYNC_QUEUE_WAIT_MINUTES', 2),

    // Seconds to wait for a server to stop before giving up on an automatic update.
    'stop_timeout' => (int) env('GALE_SYNC_STOP_TIMEOUT', 90),

    // Config files of the profile that are never sent to a server: player data and the loader's own client settings.
    'ignored_config' => ['*_player_*', '*.dat', '*.log', '*.old', 'BepInEx.cfg'],
];
