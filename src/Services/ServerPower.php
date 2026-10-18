<?php

namespace Dylf\GaleSync\Services;

use App\Enums\ContainerStatus;
use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use Boy132\PlayerCounter\Extensions\Query\QueryTypeService;
use Boy132\PlayerCounter\Models\GameQuery;
use Throwable;

/**
 * Starting and stopping a server, and the little we need to know about who is on it.
 */
class ServerPower
{
    public function __construct(private readonly DaemonServerRepository $daemon) {}

    public function status(Server $server): ContainerStatus
    {
        // The panel remembers a server's status for 15 seconds; a sync needs the truth.
        cache()->forget("servers.$server->uuid.status");

        return $server->retrieveStatus();
    }

    /**
     * Whether the server is really down. A node in maintenance or out of reach reports "missing", which is not stopped.
     */
    public function isStopped(Server $server): bool
    {
        return in_array($this->status($server), [ContainerStatus::Offline, ContainerStatus::Exited, ContainerStatus::Created, ContainerStatus::Dead], true);
    }

    public function start(Server $server): void
    {
        $this->daemon->setServer($server)->power('start')->throw();
        cache()->forget("servers.$server->uuid.status");
    }

    /**
     * Stops a server and waits until it is really off.
     */
    public function stop(Server $server, int $timeout): void
    {
        $this->daemon->setServer($server)->power('stop')->throw();

        $deadline = time() + $timeout;

        while (time() < $deadline) {
            if ($this->isStopped($server)) {
                return;
            }

            sleep(2);
        }

        throw new GaleSyncException("The server did not stop within $timeout seconds.");
    }

    /**
     * Players online right now, from the Player Counter plugin; null when that can't be told.
     */
    public function players(Server $server): ?int
    {
        if (!class_exists(GameQuery::class) || !app()->bound(QueryTypeService::class)) {
            return null;
        }

        try {
            if (!GameQuery::canRunQuery($server->allocation)) {
                return null;
            }

            /** @var ?GameQuery $query */
            $query = $server->egg->gameQuery; // @phpstan-ignore property.notFound
            $data = $query?->runQuery($server);

            return $data ? (int) $data['current_players'] : null;
        } catch (Throwable) {
            return null;
        }
    }
}
