<?php

use PelicanDev\PelicanPangolin\Services\PublicPortAllocator;

it('prefers the allocation port and otherwise chooses the lowest free pool port', function () {
    $allocator = PublicPortAllocator::from('25565,30000-30002', '25565,30000-30002');

    expect($allocator->allocate('tcp', 25565, []))->toBe(25565)
        ->and($allocator->allocate('tcp', 25565, [25565, 30000]))->toBe(30001)
        ->and($allocator->allocate('udp', 25565, [25565]))->toBe(30000);
});

it('rejects invalid or overlapping port pools', function (string $pool) {
    PublicPortAllocator::from($pool, '30000');
})->with([
    'out of range' => '0-100',
    'reversed' => '30002-30000',
    'malformed' => 'abc',
    'overlapping' => '30000-30002,30002-30004',
])->throws(InvalidArgumentException::class);

it('fails when no protocol-specific public port is available', function () {
    $allocator = PublicPortAllocator::from('30000', '30000');

    $allocator->allocate('tcp', 25565, [30000]);
})->throws(RuntimeException::class, 'No TCP public ports are available');
