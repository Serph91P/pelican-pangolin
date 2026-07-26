<?php

namespace PelicanDev\PelicanPangolin\Observers;

use App\Models\Server;
use PelicanDev\PelicanPangolin\Enums\SyncStatus;
use PelicanDev\PelicanPangolin\Jobs\RemoveServerPublication;
use PelicanDev\PelicanPangolin\Models\ServerPublication;
use PelicanDev\PelicanPangolin\Services\PublicationRefreshService;

final class ServerObserver
{
    public function saved(Server $server): void
    {
        $this->reconcile($server);
    }

    public function reconcile(Server $server): void
    {
        app(PublicationRefreshService::class)->refreshServer($server);
    }

    public function deleting(Server $server): void
    {
        $publication = ServerPublication::query()->where('server_id', $server->id)->first();
        if ($publication === null) {
            return;
        }

        $publication->forceFill([
            'opted_in' => false,
            'deletion_pending' => true,
            'desired_revision' => $publication->desired_revision + 1,
            'status' => SyncStatus::Removing,
        ])->save();

        RemoveServerPublication::dispatch($publication->id, $server->id, $publication->desired_revision);
    }
}
