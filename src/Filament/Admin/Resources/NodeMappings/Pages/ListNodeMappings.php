<?php

namespace PelicanDev\PelicanPangolin\Filament\Admin\Resources\NodeMappings\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use PelicanDev\PelicanPangolin\Filament\Admin\Resources\NodeMappings\NodeMappingResource;

final class ListNodeMappings extends ListRecords
{
    protected static string $resource = NodeMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
