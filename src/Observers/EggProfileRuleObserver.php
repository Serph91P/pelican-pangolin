<?php

namespace PelicanDev\PelicanPangolin\Observers;

use PelicanDev\PelicanPangolin\Models\EggProfileRule;
use PelicanDev\PelicanPangolin\Services\PublicationRefreshService;

final class EggProfileRuleObserver
{
    public function saved(EggProfileRule $rule): void
    {
        app(PublicationRefreshService::class)->refreshEgg($rule->profile->egg_uuid);
    }

    public function deleted(EggProfileRule $rule): void
    {
        app(PublicationRefreshService::class)->refreshEgg($rule->profile->egg_uuid);
    }
}
