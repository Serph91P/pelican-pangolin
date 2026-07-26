<?php

namespace PelicanDev\PelicanPangolin\Console\Commands;

use Illuminate\Console\Command;
use PelicanDev\PelicanPangolin\Services\InfrastructureSnippetGenerator;

final class PangolinInfrastructureCommand extends Command
{
    protected $signature = 'pangolin:infrastructure';

    protected $description = 'Print read-only host configuration guidance for raw public ports';

    public function handle(InfrastructureSnippetGenerator $generator): int
    {
        $snippets = $generator->generate(
            (string) config('pelican-pangolin.tcp_ports'),
            (string) config('pelican-pangolin.udp_ports'),
        );

        $this->warn($snippets['warning']);
        foreach (['firewall', 'compose', 'traefik'] as $section) {
            $this->newLine();
            $this->info(ucfirst($section));
            $this->line($snippets[$section]);
        }

        return self::SUCCESS;
    }
}
