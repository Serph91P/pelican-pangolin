<?php

namespace PelicanDev\PelicanPangolin\Filament\Admin\Resources\EggProfiles\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use PelicanDev\PelicanPangolin\Filament\Admin\Resources\EggProfiles\EggProfileResource;

final class EditEggProfile extends EditRecord
{
    protected static string $resource = EggProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
