<?php

namespace Dylf\GaleSync\Providers;

use App\Models\Subuser;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class GaleSyncPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        // Lets owners give subusers the right to follow a profile and sync the server's mods.
        Subuser::registerCustomPermissions('gale_sync', ['update'], 'gale-sync::permissions', 'tabler-wind');
    }

    public function boot(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // Also runs the syncs: a fresh process each minute, so no queue worker to restart after a plugin update.
            // Each server is only looked at when its own interval is up, so this is cheap on most runs.
            $schedule->command('gale-sync:check')
                ->everyMinute()
                ->withoutOverlapping(10)
                ->runInBackground();
        });
    }
}
