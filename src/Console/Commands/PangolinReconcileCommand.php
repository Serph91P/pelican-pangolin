<?php

namespace PelicanDev\PelicanPangolin\Console\Commands;

use App\Models\Server;
use Illuminate\Console\Command;
use PelicanDev\PelicanPangolin\Services\PublicationRefreshService;

final class PangolinReconcileCommand extends Command
{
    protected $signature = 'pangolin:reconcile {server? : Pelican server ID}';

    protected $description = 'Refresh desired state and queue reconciliation for opted-in Pelican servers';

    public function handle(PublicationRefreshService $refresher): int
    {
        $server = $this->argument('server');
        if ($server !== null) {
            $model = Server::query()->find((int) $server);
            if ($model === null) {
                $this->error('Pelican server was not found.');

                return self::FAILURE;
            }

            $count = $refresher->refreshServer($model) ? 1 : 0;
        } else {
            $count = $refresher->refreshAll();
        }

        $this->info("Refreshed and queued {$count} Pangolin publication(s).");

        return self::SUCCESS;
    }
}
