<?php

use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use PelicanDev\PelicanPangolin\Filament\Admin\Resources\EggProfiles\EggProfileResource;
use PelicanDev\PelicanPangolin\Filament\Admin\Resources\NodeMappings\NodeMappingResource;
use PelicanDev\PelicanPangolin\Filament\Admin\Resources\ServerPublications\ServerPublicationResource;
use PelicanDev\PelicanPangolin\Filament\Components\Actions\ManagePangolinPublicationAction;

it('exposes administrator resources and a server publication action', function () {
    expect(NodeMappingResource::class)->toExtend(Resource::class)
        ->and(EggProfileResource::class)->toExtend(Resource::class)
        ->and(ServerPublicationResource::class)->toExtend(Resource::class)
        ->and(ManagePangolinPublicationAction::make())->toBeInstanceOf(Action::class)
        ->and(array_keys(NodeMappingResource::getPages()))->toContain('index', 'create', 'edit')
        ->and(array_keys(EggProfileResource::getPages()))->toContain('index', 'create', 'edit')
        ->and(array_keys(ServerPublicationResource::getPages()))->toBe(['index']);
});

it('limits global Egg profile administration to root administrators', function () {
    $root = Mockery::mock(User::class);
    $root->shouldReceive('isRootAdmin')->andReturnTrue();
    $scoped = Mockery::mock(User::class);
    $scoped->shouldReceive('isRootAdmin')->andReturnFalse();

    expect(EggProfileResource::canManageGlobalConfiguration($root))->toBeTrue()
        ->and(EggProfileResource::canManageGlobalConfiguration($scoped))->toBeFalse()
        ->and(EggProfileResource::canManageGlobalConfiguration(null))->toBeFalse();
});
