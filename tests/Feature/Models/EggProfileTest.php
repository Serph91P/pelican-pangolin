<?php

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PelicanDev\PelicanPangolin\Enums\PublicationMode;
use PelicanDev\PelicanPangolin\Models\EggProfile;
use PelicanDev\PelicanPangolin\Models\EggProfileRule;

uses(RefreshDatabase::class);

it('stores allocation-position suggestions for one profile per Egg UUID', function () {
    $profile = EggProfile::query()->create([
        'egg_uuid' => '8f01ef06-a043-4669-9c9d-032c79a27201',
        'name' => 'Minecraft',
        'enabled' => true,
    ]);

    $rule = $profile->rules()->create([
        'allocation_position' => 0,
        'mode' => PublicationMode::Tcp,
    ]);

    expect($profile->enabled)->toBeTrue()
        ->and($rule->mode)->toBe(PublicationMode::Tcp)
        ->and($rule->profile->is($profile))->toBeTrue();

    EggProfileRule::query()->create([
        'egg_profile_id' => $profile->id,
        'allocation_position' => 0,
        'mode' => PublicationMode::Udp,
    ]);
})->throws(UniqueConstraintViolationException::class);

it('rejects an unknown publication mode', function () {
    $rule = new EggProfileRule(['mode' => 'smtp']);

    $rule->mode;
})->throws(ValueError::class);
