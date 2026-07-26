<?php

namespace PelicanDev\PelicanPangolin\Observers;

use PelicanDev\PelicanPangolin\Models\NodeMapping;
use PelicanDev\PelicanPangolin\Services\PublicationRefreshService;

final class NodeMappingObserver
{
    public function saved(NodeMapping $mapping): void
    {
        app(PublicationRefreshService::class)->refreshNode($mapping->node_id);
    }

    public function deleted(NodeMapping $mapping): void
    {
        app(PublicationRefreshService::class)->refreshNode($mapping->node_id);
    }
}
