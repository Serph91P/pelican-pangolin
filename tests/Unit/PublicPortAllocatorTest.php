<?php

use PelicanDev\PelicanPangolin\Services\PublicPortAllocator;

it('uses the exact approved allocation port independently per protocol', function () {
    $allocator = new PublicPortAllocator;
    expect($allocator->allocate('tcp', 25565, []))->toBe(25565)
        ->and($allocator->allocate('tcp', 30001, [25565, 30000]))->toBe(30001)
        ->and($allocator->allocate('udp', 25565, []))->toBe(25565);
});

it('rejects invalid or protected allocation ports', function (int $port) {
    (new PublicPortAllocator)->allocate('tcp', $port, []);
})->with([0, 65536, 2022, 8080, 8443, 51820, 51821])->throws(InvalidArgumentException::class);

it('fails when the exact protocol-specific public port is reserved', function () {
    (new PublicPortAllocator)->allocate('tcp', 25565, [25565]);
})->throws(RuntimeException::class, 'exact public allocation port');

it('rejects unknown protocols without guessing from port numbers', function () {
    (new PublicPortAllocator)->allocate('sctp', 30000, []);
})->throws(InvalidArgumentException::class);
