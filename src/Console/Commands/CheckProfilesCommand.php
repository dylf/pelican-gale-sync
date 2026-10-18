<?php

namespace Dylf\GaleSync\Console\Commands;

use Dylf\GaleSync\Services\AutoApply;
use Illuminate\Console\Command;

class CheckProfilesCommand extends Command
{
    protected $signature = 'gale-sync:check {--force : Look at every profile now, not only the ones that are due}';

    protected $description = 'Check Gale profiles for updates and apply the ones that are set to apply automatically';

    public function handle(AutoApply $autoApply): int
    {
        foreach ($autoApply->run((bool) $this->option('force')) as $serverId => $decision) {
            if ($this->output->isVerbose()) {
                $this->line("server $serverId: $decision");
            }
        }

        return self::SUCCESS;
    }
}
