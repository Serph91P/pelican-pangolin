<?php

namespace PelicanDev\PelicanPangolin\Services;

use InvalidArgumentException;

final class ResourceNaming
{
    public function resourceName(string $serverUuid, int $allocationId): string
    {
        return $this->identity('pelican', $serverUuid, $allocationId);
    }

    public function subdomain(string $serverUuid, int $allocationId): string
    {
        return $this->identity('p', $serverUuid, $allocationId);
    }

    private function identity(string $prefix, string $serverUuid, int $allocationId): string
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $serverUuid)) {
            throw new InvalidArgumentException('A valid server UUID is required.');
        }

        if ($allocationId < 1) {
            throw new InvalidArgumentException('A positive allocation ID is required.');
        }

        $uuid = substr(str_replace('-', '', strtolower($serverUuid)), 0, 12);

        return substr("{$prefix}-{$uuid}-{$allocationId}", 0, 64);
    }
}
