<?php

use PelicanDev\PelicanPangolin\Services\ResourceNaming;

it('builds stable bounded identities from immutable server and allocation identifiers', function () {
    $naming = new ResourceNaming;

    expect($naming->resourceName('3a3c5ce9-8a95-4a44-ac80-78a5ea811886', 123456789))->toBe('pelican-3a3c5ce98a95-123456789')
        ->and($naming->subdomain('3a3c5ce9-8a95-4a44-ac80-78a5ea811886', 123456789))->toBe('p-3a3c5ce98a95-123456789')
        ->and(strlen($naming->resourceName('ffffffff-ffff-4fff-afff-ffffffffffff', PHP_INT_MAX)))->toBeLessThanOrEqual(64);
});

it('rejects malformed UUIDs and allocation identifiers', function (string $uuid, int $allocationId) {
    (new ResourceNaming)->resourceName($uuid, $allocationId);
})->with([
    ['not-a-uuid', 1],
    ['3a3c5ce9-8a95-4a44-ac80-78a5ea811886', 0],
])->throws(InvalidArgumentException::class);
