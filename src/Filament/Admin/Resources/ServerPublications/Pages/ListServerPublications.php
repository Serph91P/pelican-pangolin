<?php

namespace PelicanDev\PelicanPangolin\Filament\Admin\Resources\ServerPublications\Pages;

use Filament\Resources\Pages\ListRecords;
use PelicanDev\PelicanPangolin\Filament\Admin\Resources\ServerPublications\ServerPublicationResource;

final class ListServerPublications extends ListRecords
{
    protected static string $resource = ServerPublicationResource::class;
}
