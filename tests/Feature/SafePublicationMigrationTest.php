<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PelicanDev\PelicanPangolin\Models\ServerPublication;

uses(RefreshDatabase::class);

it('upgrades legacy rules without inventing approval and safely rolls back an empty installation', function () {
    $migration = require __DIR__.'/../../database/migrations/006_safe_publication_ownership.php';
    $migration->down();
    expect(Schema::hasTable('pangolin_allocation_aliases'))->toBeFalse();
    $profile = DB::table('pangolin_egg_profiles')->insertGetId(['egg_uuid' => 'legacy-egg', 'name' => 'Legacy', 'enabled' => true]);
    DB::table('pangolin_egg_profile_rules')->insert(['egg_profile_id' => $profile, 'allocation_position' => 0, 'mode' => 'tcp']);
    $migration->up();
    $rule = DB::table('pangolin_egg_profile_rules')->first();
    expect($rule->purpose)->toBeNull()->and($rule->allocation_port)->toBeNull()
        ->and(Schema::hasColumn('pangolin_managed_resources', 'ownership_name'))->toBeTrue();
    $migration->down();
    expect(Schema::hasColumn('pangolin_managed_resources', 'ownership_name'))->toBeFalse();
    $migration->up();
});

it('refuses uninstall while remote ownership records remain', function () {
    $publication = ServerPublication::create(['server_id' => 12, 'server_uuid' => '3a3c5ce9-8a95-4a44-ac80-78a5ea811886']);
    $publication->resources()->create(['allocation_id' => 1, 'mode' => 'tcp', 'public_port' => 25565]);
    $migration = require __DIR__.'/../../database/migrations/006_safe_publication_ownership.php';
    expect(fn () => $migration->down())->toThrow(DomainException::class, 'cleanup');
    expect($publication->resources()->count())->toBe(1)->and(Schema::hasTable('pangolin_allocation_aliases'))->toBeTrue();
});
