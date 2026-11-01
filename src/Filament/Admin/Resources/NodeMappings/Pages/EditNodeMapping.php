<?php

namespace PelicanDev\PelicanPangolin\Filament\Admin\Resources\NodeMappings\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use PelicanDev\PelicanPangolin\Filament\Admin\Resources\NodeMappings\NodeMappingResource;
use PelicanDev\PelicanPangolin\Models\NodeMapping;
use PelicanDev\PelicanPangolin\Services\NodeMappingValidator;

final class EditNodeMapping extends EditRecord
{
    protected static string $resource = NodeMappingResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return app(NodeMappingValidator::class)->selectedSite($data);
    }

    protected function afterSave(): void
    {
        if ($this->record instanceof NodeMapping) {
            app(NodeMappingValidator::class)->validate($this->record);
        }
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
