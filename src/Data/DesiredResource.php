<?php

namespace PelicanDev\PelicanPangolin\Data;

use PelicanDev\PelicanPangolin\Enums\PublicationMode;

final readonly class DesiredResource
{
    public function __construct(
        public int $allocationId,
        public int $targetPort,
        public PublicationMode $mode,
        public ?string $domain,
    ) {}

    public function targetMethod(): ?string
    {
        return $this->mode->isHttp() ? $this->mode->value : null;
    }
}
