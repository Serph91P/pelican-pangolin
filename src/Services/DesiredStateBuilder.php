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

        $resources = [];
        $rawIdentities = [];

        foreach ($allocations as $position => $allocation) {
            $allocationId = filter_var($allocation['id'] ?? null, FILTER_VALIDATE_INT);
            $targetPort = filter_var($allocation['port'] ?? null, FILTER_VALIDATE_INT);
            if ($allocationId === false || $allocationId < 1 || $targetPort === false || $targetPort < 1 || $targetPort > 65535) {
                throw new DomainException('The selected allocation is invalid.');
            }

            $configuration = $overrides[$allocationId] ?? null;

            if ($configuration === null || ! in_array($configuration['purpose'] ?? null, ['game', 'query'], true)) {
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

            $bindIp = (string) ($allocation['ip'] ?? '');
            $targetHost = in_array($bindIp, ['0.0.0.0', '::'], true) ? (string) ($mapping['target_host'] ?? '') : $bindIp;
            if (! NodeMappingValidator::validTarget($targetHost)) {
                throw new DomainException('A concrete allocation bind IP or explicit site-reachable wildcard target is required.');
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

            foreach ($mode === PublicationMode::Both ? [PublicationMode::Tcp, PublicationMode::Udp] : [$mode] as $protocol) {
                if (! $protocol->isHttp()) {
                    $identity = $protocol->value.':'.$targetPort;
                    if (isset($rawIdentities[$identity])) {
                        throw new DomainException('Two allocations require the same public host port/protocol; choose only one allocation. Bind IPs cannot disambiguate a public listener.');
                    }
                    $rawIdentities[$identity] = true;
                }
                $resources[] = new DesiredResource(
                    allocationId: $allocationId,
                    targetPort: $targetPort,
                    mode: $protocol,
                    domain: $domain,
                    bindIp: $bindIp,
                    targetHost: $targetHost,
                );
            }
        }

        if ($resources !== [] && (($mapping['enabled'] ?? false) !== true || empty($mapping['site_id']))) {
            throw new DomainException('An enabled and validated node mapping is required.');
        }

        return new DesiredPublication(
            revision: $expectedRevision,
            siteId: (int) ($mapping['site_id'] ?? 0),
            targetHost: (string) ($mapping['target_host'] ?? ''),
            resources: $resources,
        );
    }

    private function validDomain(string $domain): bool
    {
        return strlen($domain) <= 253
            && (bool) preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $domain);
    }
}
