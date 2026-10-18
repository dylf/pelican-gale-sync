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
        Subuser::registerCustomPermissions('gale_sync', ['update'], 'gale-sync::permissions', 'tabler-refresh');
    }

    public function boot(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // Each server is only looked at when its own interval is up, so this is cheap on most runs.
            $schedule->command('gale-sync:check')
                ->everyMinute()
                ->withoutOverlapping(10);
        });
    }
}
