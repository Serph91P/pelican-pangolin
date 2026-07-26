<?php

namespace PelicanDev\PelicanPangolin\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use PelicanDev\PelicanPangolin\Contracts\PangolinClient;
use PelicanDev\PelicanPangolin\Data\DesiredPublication;
use PelicanDev\PelicanPangolin\Data\DesiredResource;
use PelicanDev\PelicanPangolin\Enums\PublicationMode;
use PelicanDev\PelicanPangolin\Enums\SyncStatus;
use PelicanDev\PelicanPangolin\Exceptions\PangolinApiException;
use PelicanDev\PelicanPangolin\Models\ManagedResource;
use PelicanDev\PelicanPangolin\Models\ServerPublication;
use PelicanDev\PelicanPangolin\Support\SanitizesErrors;
use Throwable;

final class PublicationReconciler
{
    use SanitizesErrors;

    public function __construct(
        private readonly PangolinClient $client,
        private readonly PublicPortAllocator $portAllocator,
        private readonly ResourceNaming $naming,
    ) {}

    public function reconcile(ServerPublication $publication, DesiredPublication $desired): void
    {
        if ($publication->desired_revision !== $desired->revision) {
            throw new DomainException('The desired publication revision is stale.');
        }

        if (! $publication->opted_in) {
            $this->remove($publication, $desired->revision);

            return;
        }

        if ($publication->suspended) {
            $this->disable($publication);

            return;
        }

        try {
            $inventory = $this->client->listResources();
            foreach ($desired->resources as $resource) {
                $this->reconcileResource($publication, $desired, $resource, $inventory);
            }

            $desiredKeys = array_map(
                fn (DesiredResource $resource): string => "{$resource->allocationId}:{$resource->mode->value}",
                $desired->resources,
            );

            foreach ($publication->resources()->get() as $owned) {
                if (! in_array("{$owned->allocation_id}:{$owned->mode->value}", $desiredKeys, true)) {
                    $this->removeResource($owned);
                }
            }

            $publication->forceFill([
                'status' => SyncStatus::Synced,
                'last_error' => null,
                'last_synced_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $publication->forceFill([
                'status' => SyncStatus::Error,
                'last_error' => $this->sanitizeError($exception->getMessage()),
            ])->save();

            throw $exception;
        }
    }

    public function disable(ServerPublication $publication): void
    {
        foreach ($publication->resources()->get() as $owned) {
            if ($owned->pangolin_resource_id === null) {
                continue;
            }

            try {
                $this->client->updateResource($owned->pangolin_resource_id, ['enabled' => false]);
                $owned->forceFill(['enabled' => false])->save();
            } catch (PangolinApiException $exception) {
                if ($exception->status !== 404) {
                    throw $exception;
                }

                $owned->forceFill([
                    'pangolin_resource_id' => null,
                    'pangolin_target_id' => null,
                    'enabled' => false,
                ])->save();
            }
        }

        $publication->forceFill(['status' => SyncStatus::Disabled, 'last_error' => null])->save();
    }

    public function remove(ServerPublication $publication, int $revision): void
    {
        foreach ($publication->resources()->get() as $owned) {
            $current = $publication->fresh();
            if ($current === null
                || $current->opted_in
                || ! $current->deletion_pending
                || $current->desired_revision !== $revision) {
                return;
            }

            $this->removeResource($owned);
        }

        ServerPublication::query()
            ->whereKey($publication->id)
            ->where('opted_in', false)
            ->where('deletion_pending', true)
            ->where('desired_revision', $revision)
            ->update([
                'status' => SyncStatus::Disabled,
                'last_error' => null,
                'deletion_pending' => false,
            ]);
    }

    /** @param list<array<string, mixed>> $inventory */
    private function reconcileResource(ServerPublication $publication, DesiredPublication $desired, DesiredResource $resource, array $inventory): void
    {
        $name = $this->naming->resourceName($publication->server_uuid, $resource->allocationId);
        $owned = $this->reserve($publication, $resource, $inventory, $name);
        $created = false;

        if ($owned->pangolin_resource_id !== null) {
            try {
                $this->client->getResource($owned->pangolin_resource_id);
            } catch (PangolinApiException $exception) {
                if ($exception->status !== 404) {
                    throw $exception;
                }

                $owned->forceFill([
                    'pangolin_resource_id' => null,
                    'pangolin_target_id' => null,
                ])->save();
            }
        }

        if ($owned->pangolin_resource_id === null) {
            $remote = $this->findExistingResource($name, $resource, $owned->public_port, $inventory);
            if ($remote === null) {
                if ($resource->mode->isHttp()) {
                    [$domainId, $subdomain] = $this->resolveDomain($resource->domain ?? '');
                    $remote = $this->client->createHttpResource($name, $domainId, $subdomain);
                } else {
                    $remote = $this->client->createRawResource($name, $resource->mode, $owned->public_port ?? 0);
                }
                $created = true;
            }

            $resourceId = filter_var($remote['resourceId'] ?? null, FILTER_VALIDATE_INT);
            if ($resourceId === false) {
                throw new DomainException('Pangolin did not return a resource ID.');
            }

            $owned->forceFill([
                'pangolin_resource_id' => $resourceId,
                'confirmed_domain' => $resource->mode->isHttp() ? ($remote['fullDomain'] ?? null) : null,
            ])->save();
        }

        $remoteResourceId = $owned->pangolin_resource_id;
        if ($remoteResourceId === null) {
            throw new DomainException('The owned Pangolin resource ID is missing.');
        }

        if ($owned->pangolin_target_id === null) {
            $target = $this->findExistingTarget($remoteResourceId, $desired, $resource)
                ?? $this->client->createTarget(
                    $remoteResourceId,
                    $desired->siteId,
                    $desired->targetHost,
                    $resource->targetPort,
                    $resource->mode,
                );
            $targetId = filter_var($target['targetId'] ?? null, FILTER_VALIDATE_INT);
            if ($targetId === false) {
                throw new DomainException('Pangolin did not return a target ID.');
            }

            $owned->forceFill(['pangolin_target_id' => $targetId])->save();
        } else {
            try {
                $this->client->updateTarget(
                    $owned->pangolin_target_id,
                    $desired->siteId,
                    $desired->targetHost,
                    $resource->targetPort,
                    $resource->mode,
                );
            } catch (PangolinApiException $exception) {
                if ($exception->status !== 404) {
                    throw $exception;
                }

                $target = $this->findExistingTarget($remoteResourceId, $desired, $resource)
                    ?? $this->client->createTarget(
                        $remoteResourceId,
                        $desired->siteId,
                        $desired->targetHost,
                        $resource->targetPort,
                        $resource->mode,
                    );
                $targetId = filter_var($target['targetId'] ?? null, FILTER_VALIDATE_INT);
                if ($targetId === false) {
                    throw new DomainException('Pangolin did not return a target ID.');
                }

                $owned->forceFill(['pangolin_target_id' => $targetId])->save();
            }
        }

        if (! $created) {
            $fields = ['name' => $name, 'enabled' => true];
            if ($resource->mode->isHttp()) {
                [$domainId, $subdomain] = $this->resolveDomain($resource->domain ?? '');
                $fields['domainId'] = $domainId;
                $fields['subdomain'] = $subdomain;
            } else {
                $fields['proxyPort'] = $owned->public_port;
            }
            $remote = $this->client->updateResource($remoteResourceId, $fields);
            if ($resource->mode->isHttp() && is_string($remote['fullDomain'] ?? null)) {
                $owned->confirmed_domain = $remote['fullDomain'];
            }
        }

        $owned->forceFill(['enabled' => true])->save();
    }

    /**
     * @param  list<array<string, mixed>>  $inventory
     * @return array<string, mixed>|null
     */
    private function findExistingResource(string $name, DesiredResource $desired, ?int $publicPort, array $inventory): ?array
    {
        $collisions = array_values(array_filter(
            $inventory,
            function (array $remote) use ($desired, $publicPort): bool {
                if ($desired->mode->isHttp()) {
                    return strtolower((string) ($remote['fullDomain'] ?? '')) === strtolower($desired->domain ?? '');
                }

                return ($remote['mode'] ?? null) === $desired->mode->value
                    && (int) ($remote['proxyPort'] ?? 0) === $publicPort;
            },
        ));
        $matches = array_values(array_filter(
            $collisions,
            fn (array $remote): bool => ($remote['name'] ?? null) === $name,
        ));

        if (count($matches) > 1 || count($collisions) > count($matches)) {
            throw new DomainException("A conflicting Pangolin resource already uses the identity required by {$name}.");
        }

        return $matches[0] ?? null;
    }

    /** @return array<string, mixed>|null */
    private function findExistingTarget(int $resourceId, DesiredPublication $publication, DesiredResource $desired): ?array
    {
        $matches = array_values(array_filter(
            $this->client->listTargets($resourceId),
            fn (array $target): bool => (int) ($target['siteId'] ?? 0) === $publication->siteId
                && ($target['ip'] ?? null) === $publication->targetHost
                && (int) ($target['port'] ?? 0) === $desired->targetPort
                && ($target['mode'] ?? null) === ($desired->mode->isHttp() ? 'http' : $desired->mode->value)
                && (! $desired->mode->isHttp() || ($target['method'] ?? null) === $desired->mode->value),
        ));

        if (count($matches) > 1) {
            throw new DomainException("More than one Pangolin target matches resource {$resourceId}.");
        }

        return $matches[0] ?? null;
    }

    /** @param list<array<string, mixed>> $inventory */
    private function reserve(ServerPublication $publication, DesiredResource $resource, array $inventory, string $expectedName): ManagedResource
    {
        return DB::transaction(function () use ($publication, $resource, $inventory, $expectedName): ManagedResource {
            $existing = $publication->resources()
                ->where('allocation_id', $resource->allocationId)
                ->where('mode', $resource->mode->value)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            if ($resource->mode->isHttp()) {
                $existingHttp = $publication->resources()
                    ->where('allocation_id', $resource->allocationId)
                    ->whereIn('mode', [PublicationMode::Http->value, PublicationMode::Https->value])
                    ->lockForUpdate()
                    ->first();

                if ($existingHttp !== null) {
                    $existingHttp->forceFill(['mode' => $resource->mode])->save();

                    return $existingHttp;
                }
            }

            $publicPort = null;
            if (in_array($resource->mode, [PublicationMode::Tcp, PublicationMode::Udp], true)) {
                $reserved = array_values(ManagedResource::query()
                    ->where('mode', $resource->mode->value)
                    ->whereNotNull('public_port')
                    ->pluck('public_port')
                    ->map(fn (mixed $port): int => (int) $port)
                    ->all());
                foreach ($inventory as $remote) {
                    if (($remote['name'] ?? null) !== $expectedName
                        && ($remote['mode'] ?? null) === $resource->mode->value
                        && filter_var($remote['proxyPort'] ?? null, FILTER_VALIDATE_INT) !== false) {
                        $reserved[] = (int) $remote['proxyPort'];
                    }
                }
                $reserved = array_values(array_unique($reserved));
                $publicPort = $this->portAllocator->allocate($resource->mode->value, $resource->targetPort, $reserved);
            }

            return $publication->resources()->create([
                'allocation_id' => $resource->allocationId,
                'mode' => $resource->mode,
                'public_port' => $publicPort,
            ]);
        });
    }

    /** @return array{string, ?string} */
    private function resolveDomain(string $fullDomain): array
    {
        $matches = [];
        foreach ($this->client->listDomains() as $domain) {
            $base = strtolower(trim((string) ($domain['baseDomain'] ?? ''), '.'));
            if (($domain['verified'] ?? false) === true && ($fullDomain === $base || str_ends_with($fullDomain, ".{$base}"))) {
                $matches[] = $domain;
            }
        }

        usort($matches, fn (array $a, array $b): int => strlen((string) $b['baseDomain']) <=> strlen((string) $a['baseDomain']));
        if ($matches === [] || ! is_string($matches[0]['domainId'] ?? null)) {
            throw new DomainException("No verified Pangolin domain matches {$fullDomain}.");
        }

        $base = trim((string) $matches[0]['baseDomain'], '.');
        if (isset($matches[1]) && strlen((string) $matches[1]['baseDomain']) === strlen($base)) {
            throw new DomainException("More than one Pangolin domain matches {$fullDomain}.");
        }

        $subdomain = $fullDomain === $base ? null : substr($fullDomain, 0, -strlen(".{$base}"));

        return [$matches[0]['domainId'], $subdomain];
    }

    private function removeResource(ManagedResource $owned): void
    {
        if ($owned->pangolin_resource_id !== null) {
            try {
                $this->client->deleteResource($owned->pangolin_resource_id);
            } catch (PangolinApiException $exception) {
                if ($exception->status !== 404) {
                    throw $exception;
                }
            }
        }

        $owned->delete();
    }
}
