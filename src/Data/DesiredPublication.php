<?php

namespace PelicanDev\PelicanPangolin\Data;

final readonly class DesiredPublication
{
    /** @param list<DesiredResource> $resources */
    public function __construct(
        public int $revision,
        public int $siteId,
        public string $targetHost,
        public array $resources,
    ) {}
}
