<?php

namespace PelicanDev\PelicanPangolin\Services;

use DomainException;
use PelicanDev\PelicanPangolin\Data\DesiredPublication;
use PelicanDev\PelicanPangolin\Data\DesiredResource;
use PelicanDev\PelicanPangolin\Enums\PublicationMode;
use ValueError;

final class DesiredStateBuilder
{
    /**
     * @param  array<string, mixed>  $server
     * @param  array<string, mixed>  $mapping
     * @param  array<int, array<string, mixed>>  $allocations
     * @param  array<int, array<string, mixed>>  $profileRules
     * @param  array<int, array<string, mixed>>  $overrides
     */
    public function build(
        int $expectedRevision,
        array $server,
        array $mapping,
        array $allocations,
        array $profileRules,
        array $overrides,
        ?string $defaultDomain,
    ): DesiredPublication {
        if (($server['desired_revision'] ?? null) !== $expectedRevision) {
            throw new DomainException('The desired publication revision is stale.');
        }

        if (($mapping['enabled'] ?? false) !== true || empty($mapping['site_id']) || empty($mapping['target_host'])) {
            throw new DomainException('An enabled and validated node mapping is required.');
        }

        $resources = [];

        foreach ($allocations as $position => $allocation) {
            $allocationId = filter_var($allocation['id'] ?? null, FILTER_VALIDATE_INT);
            $targetPort = filter_var($allocation['port'] ?? null, FILTER_VALIDATE_INT);
            if ($allocationId === false || $allocationId < 1 || $targetPort === false || $targetPort < 1 || $targetPort > 65535) {
                throw new DomainException('The selected allocation is invalid.');
            }

            $configuration = $overrides[$allocationId] ?? $profileRules[$position] ?? null;

            if ($configuration === null) {
                continue;
            }

            try {
                $mode = PublicationMode::from((string) ($configuration['mode'] ?? ''));
            } catch (ValueError) {
                throw new DomainException('The publication mode is invalid.');
            }

            if ($mode === PublicationMode::Ignored) {
                continue;
            }

            $domain = null;
            if ($mode->isHttp()) {
                $baseDomain = trim((string) ($configuration['domain'] ?? $defaultDomain ?? ''), '.');
                $subdomain = trim((string) ($configuration['subdomain'] ?? ''), '.');
                $domain = $subdomain === '' ? $baseDomain : "{$subdomain}.{$baseDomain}";

                if (! $this->validDomain($domain)) {
                    throw new DomainException('HTTP publication requires one valid explicit domain.');
                }
            }

            $resources[] = new DesiredResource(
                allocationId: $allocationId,
                targetPort: $targetPort,
                mode: $mode,
                domain: $domain,
            );
        }

        return new DesiredPublication(
            revision: $expectedRevision,
            siteId: (int) $mapping['site_id'],
            targetHost: (string) $mapping['target_host'],
            resources: $resources,
        );
    }

    private function validDomain(string $domain): bool
    {
        return strlen($domain) <= 253
            && (bool) preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $domain);
    }
}
