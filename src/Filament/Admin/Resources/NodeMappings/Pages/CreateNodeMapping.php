<?php

namespace PelicanDev\PelicanPangolin\Filament\Admin\Resources\NodeMappings\Pages;

use Filament\Resources\Pages\CreateRecord;
use PelicanDev\PelicanPangolin\Filament\Admin\Resources\NodeMappings\NodeMappingResource;
use PelicanDev\PelicanPangolin\Models\NodeMapping;
use PelicanDev\PelicanPangolin\Services\NodeMappingValidator;

final class CreateNodeMapping extends CreateRecord
{
    protected static string $resource = NodeMappingResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return app(NodeMappingValidator::class)->selectedSite($data);
    }

    protected function afterCreate(): void
    {
        if ($this->record instanceof NodeMapping) {
            app(NodeMappingValidator::class)->validate($this->record);
        }
    }
}
