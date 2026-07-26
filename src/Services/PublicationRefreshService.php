<?php

namespace PelicanDev\PelicanPangolin\Services;

use App\Models\Egg;
use App\Models\Server;
use Illuminate\Support\Facades\Schema;
use PelicanDev\PelicanPangolin\Enums\SyncStatus;
use PelicanDev\PelicanPangolin\Jobs\ReconcileServerPublication;
use PelicanDev\PelicanPangolin\Jobs\RemoveServerPublication;
use PelicanDev\PelicanPangolin\Models\ServerPublication;

class PublicationRefreshService
{
    public function __construct(private readonly PublicationStateSnapshot $snapshot) {}

    public function refreshServer(Server $server): bool
    {
        $publication = ServerPublication::query()
            ->where('server_id', $server->id)
            ->where('opted_in', true)
            ->first();

        if ($publication === null) {
            return false;
        }

        $this->snapshot->refresh($server->loadMissing(['egg', 'allocations']), $publication);
        $publication->forceFill(['status' => SyncStatus::Pending])->save();

        ReconcileServerPublication::dispatch(
            $publication->id,
            $server->id,
            $publication->desired_revision,
        );

        return true;
    }

    public function refreshNode(int $nodeId): void
    {
        if (! Schema::hasTable('servers')) {
            return;
        }

        Server::query()
            ->where('node_id', $nodeId)
            ->whereIn('id', ServerPublication::query()->where('opted_in', true)->select('server_id'))
            ->with(['egg', 'allocations'])
            ->each(fn (Server $server) => $this->refreshServer($server));
    }

    public function refreshAll(): int
    {
        ServerPublication::query()
            ->where('opted_in', false)
            ->where('deletion_pending', true)
            ->each(fn (ServerPublication $publication) => RemoveServerPublication::dispatch(
                $publication->id,
                $publication->server_id,
                $publication->desired_revision,
            ));

        if (! Schema::hasTable('servers')) {
            return 0;
        }

        $count = 0;
        Server::query()
            ->whereIn('id', ServerPublication::query()->where('opted_in', true)->select('server_id'))
            ->with(['egg', 'allocations'])
            ->each(function (Server $server) use (&$count): void {
                if ($this->refreshServer($server)) {
                    $count++;
                }
            });

        return $count;
    }

    public function refreshEgg(string $eggUuid): void
    {
        if (! Schema::hasTable('servers') || ! Schema::hasTable('eggs')) {
            return;
        }

        Server::query()
            ->whereIn('egg_id', Egg::query()->where('uuid', $eggUuid)->select('id'))
            ->whereIn('id', ServerPublication::query()->where('opted_in', true)->select('server_id'))
            ->with(['egg', 'allocations'])
            ->each(fn (Server $server) => $this->refreshServer($server));
    }
}
