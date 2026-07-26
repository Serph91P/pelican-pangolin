<?php

namespace PelicanDev\PelicanPangolin\Services;

use PelicanDev\PelicanPangolin\Contracts\PangolinClient;
use Throwable;

final readonly class PangolinPreflight
{
    public function __construct(private PangolinClient $client) {}

    /** @return array{issues: list<string>, warnings: list<string>} */
    public function run(?string $site, ?string $domain): array
    {
        $issues = [];
        $warnings = [];
        $sites = $this->inventory('sites', fn (): array => $this->client->listSites(), $issues);
        $domains = $this->inventory('domains', fn (): array => $this->client->listDomains(), $issues);
        $resources = $this->inventory('resources', fn (): array => $this->client->listResources(), $issues);

        if ($site !== null && ! collect($sites)->contains(fn (array $candidate): bool => (string) ($candidate['siteId'] ?? '') === $site
            || ($candidate['niceId'] ?? null) === $site)) {
            $issues[] = 'Pangolin site was not found.';
        }

        if ($domain !== null && ! collect($domains)->contains(fn (array $candidate): bool => strtolower((string) ($candidate['baseDomain'] ?? '')) === strtolower($domain)
            && ($candidate['verified'] ?? false) === true)) {
            $issues[] = 'Pangolin domain was not found or is not verified.';
        }

        if (! collect($resources)->contains(fn (array $resource): bool => in_array($resource['mode'] ?? null, ['tcp', 'udp'], true))) {
            $warnings[] = 'Raw resource support cannot be confirmed non-destructively because no raw resource exists.';
        }

        return compact('issues', 'warnings');
    }

    /**
     * @param  callable(): list<array<string, mixed>>  $load
     * @param  list<string>  $issues
     * @return list<array<string, mixed>>
     */
    private function inventory(string $name, callable $load, array &$issues): array
    {
        try {
            return $load();
        } catch (Throwable) {
            $issues[] = "Unable to list Pangolin {$name}. Check the API key permission and endpoint.";

            return [];
        }
    }
}
