<?php

namespace PelicanDev\PelicanPangolin\Contracts;

use PelicanDev\PelicanPangolin\Enums\PublicationMode;

interface PangolinClient
{
    /** @return list<array<string, mixed>> */
    public function listSites(int $pageSize = 100): array;

    /** @return list<array<string, mixed>> */
    public function listDomains(int $pageSize = 100): array;

    /** @return list<array<string, mixed>> */
    public function listResources(int $pageSize = 100): array;

    /** @return array<string, mixed> */
    public function getResource(int $resourceId): array;

    /** @return list<array<string, mixed>> */
    public function listTargets(int $resourceId, int $pageSize = 100): array;

    /** @return array<string, mixed> */
    public function createRawResource(string $name, PublicationMode $mode, int $publicPort): array;

    /** @return array<string, mixed> */
    public function createHttpResource(string $name, string $domainId, ?string $subdomain): array;

    /** @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function updateResource(int $resourceId, array $fields): array;

    public function deleteResource(int $resourceId): void;

    /** @return array<string, mixed> */
    public function createTarget(int $resourceId, int $siteId, string $host, int $port, PublicationMode $mode): array;

    /** @return array<string, mixed> */
    public function updateTarget(int $targetId, int $siteId, string $host, int $port, PublicationMode $mode): array;
}
