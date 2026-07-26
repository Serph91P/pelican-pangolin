<?php

namespace PelicanDev\PelicanPangolin\Filament\Admin\Resources\NodeMappings\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use PelicanDev\PelicanPangolin\Filament\Admin\Resources\NodeMappings\NodeMappingResource;

final class EditNodeMapping extends EditRecord
{
    protected static string $resource = NodeMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
