<?php

namespace PelicanDev\PelicanPangolin\Console\Commands;

use Illuminate\Console\Command;
use PelicanDev\PelicanPangolin\Services\PangolinPreflight;

final class PangolinPreflightCommand extends Command
{
    protected $signature = 'pangolin:preflight {--site=} {--domain=}';

    protected $description = 'Validate Pangolin API access and configured inventory without mutation';

    public function handle(PangolinPreflight $preflight): int
    {
        $result = $preflight->run(
            is_string($this->option('site')) ? $this->option('site') : null,
            is_string($this->option('domain')) ? $this->option('domain') : null,
        );

        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }

        foreach ($result['issues'] as $issue) {
            $this->error($issue);
        }

        if ($result['issues'] !== []) {
            return self::FAILURE;
        }

        $this->info('Pangolin preflight passed.');

        return self::SUCCESS;
    }
}
