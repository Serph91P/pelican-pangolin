<?php

namespace PelicanDev\PelicanPangolin\Observers;

use PelicanDev\PelicanPangolin\Models\EggProfile;
use PelicanDev\PelicanPangolin\Services\PublicationRefreshService;

final class EggProfileObserver
{
    public function saved(EggProfile $profile): void
    {
        app(PublicationRefreshService::class)->refreshEgg($profile->egg_uuid);
    }

    public function deleted(EggProfile $profile): void
    {
        app(PublicationRefreshService::class)->refreshEgg($profile->egg_uuid);
    }
}
