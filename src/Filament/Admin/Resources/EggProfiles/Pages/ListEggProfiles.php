<?php

namespace PelicanDev\PelicanPangolin\Filament\Admin\Resources\EggProfiles\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use PelicanDev\PelicanPangolin\Filament\Admin\Resources\EggProfiles\EggProfileResource;

final class ListEggProfiles extends ListRecords
{
    protected static string $resource = EggProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
