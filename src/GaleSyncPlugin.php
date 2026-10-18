<?php

namespace Dylf\GaleSync;

use Filament\Contracts\Plugin;
use Filament\Panel;

class GaleSyncPlugin implements Plugin
{
    public function getId(): string
    {
        return 'gale-sync';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "Dylf\\GaleSync\\Filament\\$id\\Pages");
    }

    public function boot(Panel $panel): void {}
}
