<?php

namespace PelicanDev\PelicanPangolin\Filament\Admin\Resources\NodeMappings\Pages;

use Filament\Resources\Pages\CreateRecord;
use PelicanDev\PelicanPangolin\Filament\Admin\Resources\NodeMappings\NodeMappingResource;

final class CreateNodeMapping extends CreateRecord
{
    protected static string $resource = NodeMappingResource::class;
}
