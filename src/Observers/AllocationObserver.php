<?php

namespace PelicanDev\PelicanPangolin\Observers;

use App\Models\Allocation;
use App\Models\Server;

final class AllocationObserver
{
    public function updated(Allocation $allocation): void
    {
        if (! $allocation->wasChanged(['port', 'server_id', 'node_id'])) {
            return;
        }

        $serverIds = array_unique(array_filter([
            $allocation->server_id,
            $allocation->getOriginal('server_id'),
        ]));

        Server::query()->whereKey($serverIds)->each(function (Server $server): void {
            (new ServerObserver)->reconcile($server);
        });
    }
}
