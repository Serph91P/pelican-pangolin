<?php

namespace PelicanDev\PelicanPangolin\Services;

use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PelicanDev\PelicanPangolin\Contracts\PangolinClient;
use PelicanDev\PelicanPangolin\Data\DesiredPublication;
use PelicanDev\PelicanPangolin\Data\DesiredResource;
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
        // All workers must share the panel cache: serialize inventory/reservation/API mutation.
        Cache::lock('pangolin:remote-reconciliation', 300)->block(5, function () use ($publication, $desired): void {
            $this->assertCurrent($publication);
            if ($publication->desired_revision !== $desired->revision) {
                throw new DomainException('The desired publication revision is stale.');
            }
            app(AllocationAliasManager::class)->restore($publication);
            if (! $publication->opted_in) {
                $this->removeLocked($publication, $desired->revision);

                return;
            }
            if ($publication->suspended) {
                $this->disableLocked($publication);

                return;
            }
            try {
                $host = (string) ($publication->desired_state['public_host'] ?? config('pelican-pangolin.public_host'));
                if (array_any($desired->resources, fn (DesiredResource $resource): bool => ! $resource->mode->isHttp())) {
                    PluginSettings::validatePublicHost($host);
                }
                // Release obsolete ownership before reserving exact ports; then read fresh remote inventory.
                foreach ($publication->resources()->get() as $owned) {
                    $retained = array_any($desired->resources, fn (DesiredResource $resource): bool => $resource->allocationId === $owned->allocation_id
                        && ($resource->mode === $owned->mode || ($resource->mode->isHttp() && $owned->mode->isHttp())));
                    if (! $retained) {
                        $this->assertCurrent($publication);
                        $this->removeResource($publication, $owned);
                    }
                }
                $this->assertCurrent($publication);
                $inventory = $this->client->listResources();
                foreach ($desired->resources as $resource) {
                    $this->assertCurrent($publication);
                    $this->reconcileResource($publication, $desired, $resource, $inventory, $host);
                }
                $this->assertCurrent($publication);
                ServerPublication::query()->whereKey($publication->id)->where('desired_revision', $desired->revision)->update([
                    'status' => SyncStatus::Synced, 'last_error' => null, 'last_synced_at' => now(),
                ]);
                $publication->refresh();
                if ($host !== '') {
                    app(AllocationAliasManager::class)->apply($publication, $host);
                }
            } catch (Throwable $exception) {
                app(AllocationAliasManager::class)->restore($publication);
                ServerPublication::query()->whereKey($publication->id)->where('desired_revision', $desired->revision)->update([
                    'status' => SyncStatus::Error, 'last_error' => $this->sanitizeError($exception->getMessage()),
                ]);
                throw $exception;
            }
        });
    }

    public function disable(ServerPublication $publication): void
    {
        Cache::lock('pangolin:remote-reconciliation', 300)->block(5, fn () => $this->disableLocked($publication));
    }

    private function disableLocked(ServerPublication $publication): void
    {
        $this->assertCurrent($publication);
        app(AllocationAliasManager::class)->restore($publication);
        foreach ($publication->resources()->get() as $owned) {
            $this->assertCurrent($publication);
            if ($owned->pangolin_resource_id !== null) {
                try {
                    $this->assertOwnership($owned);
                    $id = $owned->pangolin_resource_id ?? throw new DomainException('Missing resource ID.');
                    $this->client->updateResource($id, ['enabled' => false]);
                } catch (PangolinApiException $exception) {
                    if ($exception->status !== 404) {
                        throw $exception;
                    }
                    $owned->forceFill(['pangolin_resource_id' => null, 'pangolin_target_id' => null]);
                }
            }
            $owned->forceFill(['enabled' => false, 'confirmed_endpoint' => null])->save();
        }
        $this->assertCurrent($publication);
        ServerPublication::query()->whereKey($publication->id)->where('desired_revision', $publication->desired_revision)
            ->update(['status' => SyncStatus::Disabled, 'last_error' => null]);
    }

    public function remove(ServerPublication $publication, int $revision): void
    {
        Cache::lock('pangolin:remote-reconciliation', 300)->block(5, fn () => $this->removeLocked($publication, $revision));
    }

    private function removeLocked(ServerPublication $publication, int $revision): void
    {
        $this->assertCurrent($publication);
        if ($publication->opted_in || ! $publication->deletion_pending || $publication->desired_revision !== $revision) {
            return;
        }
        app(AllocationAliasManager::class)->restore($publication);
        foreach ($publication->resources()->get() as $owned) {
            $this->assertCurrent($publication);
            $this->removeResource($publication, $owned);
        }
        ServerPublication::query()->whereKey($publication->id)->where('opted_in', false)->where('desired_revision', $revision)
            ->update(['status' => SyncStatus::Disabled, 'last_error' => null, 'deletion_pending' => false]);
    }

    /** @param list<array<string, mixed>> $inventory */
    private function reconcileResource(ServerPublication $publication, DesiredPublication $desired, DesiredResource $resource, array $inventory, string $host): void
    {
        $owned = $this->reserve($publication, $resource, $inventory);
        $name = $owned->ownership_name ?? $this->naming->resourceName($publication->server_uuid, $resource->allocationId);
        if ($owned->pangolin_resource_id !== null) {
            try {
                $this->assertOwnership($owned);
            } catch (PangolinApiException $exception) {
                if ($exception->status !== 404) {
                    throw $exception;
                }
                $owned->forceFill(['pangolin_resource_id' => null, 'pangolin_target_id' => null])->save();
            }
        }
        if ($owned->pangolin_resource_id === null) {
            $this->assertCurrent($publication);
            $remote = $this->findExistingResource($name, $resource, $owned->public_port, $inventory);
            if ($remote === null) {
                if ($resource->mode->isHttp()) {
                    [$domainId, $subdomain] = $this->resolveDomain($resource->domain ?? '');
                    $remote = $this->client->createHttpResource($name, $domainId, $subdomain);
                } else {
                    $remote = $this->client->createRawResource($name, $resource->mode, $owned->public_port ?? 0);
                }
            }
            $id = filter_var($remote['resourceId'] ?? null, FILTER_VALIDATE_INT);
            if ($id === false || $id < 1) {
                throw new DomainException('Pangolin did not return a resource ID.');
            }
            // Persist before the next remote call, including when a later step fails.
            $owned->forceFill(['pangolin_resource_id' => $id, 'confirmed_domain' => $resource->mode->isHttp() ? ($remote['fullDomain'] ?? null) : null])->save();
        }
        $resourceId = $owned->pangolin_resource_id ?? throw new DomainException('The owned resource ID is missing.');
        $this->assertCurrent($publication);
        $targets = $this->client->listTargets($resourceId);
        // An extra target could expose or route traffic somewhere the administrator never approved.
        foreach ($targets as $target) {
            if ($owned->pangolin_target_id !== null && (int) ($target['targetId'] ?? 0) !== $owned->pangolin_target_id) {
                throw new DomainException('Unexpected target on the owned resource; operator reconciliation required.');
            }
        }
        if ($owned->pangolin_target_id !== null && ! array_any($targets, fn (array $target): bool => (int) ($target['targetId'] ?? 0) === $owned->pangolin_target_id)) {
            $owned->forceFill(['pangolin_target_id' => null])->save();
        }
        if ($owned->pangolin_target_id === null) {
            $matches = array_values(array_filter($targets, fn (array $target): bool => (int) ($target['siteId'] ?? 0) === $desired->siteId
                && ($target['ip'] ?? null) === $desired->targetHost && (int) ($target['port'] ?? 0) === $resource->targetPort
                && ($target['mode'] ?? null) === ($resource->mode->isHttp() ? 'http' : $resource->mode->value)
                && (! $resource->mode->isHttp() || ($target['method'] ?? null) === $resource->mode->value)));
            if (count($targets) > 1 || count($targets) !== count($matches)) {
                throw new DomainException('Unexpected or ambiguous remote targets.');
            }
            $this->assertCurrent($publication);
            $target = $matches[0] ?? $this->client->createTarget($resourceId, $desired->siteId, $desired->targetHost, $resource->targetPort, $resource->mode);
            $id = filter_var($target['targetId'] ?? null, FILTER_VALIDATE_INT);
            if ($id === false || $id < 1) {
                throw new DomainException('Pangolin did not return a target ID.');
            }
            $owned->forceFill(['pangolin_target_id' => $id])->save();
        } else {
            try {
                $this->client->updateTarget($owned->pangolin_target_id, $desired->siteId, $desired->targetHost, $resource->targetPort, $resource->mode);
            } catch (PangolinApiException $exception) {
                if ($exception->status === 404) {
                    $owned->forceFill(['pangolin_target_id' => null])->save();
                }
                throw $exception; // Retry from fresh inventory; never mutate an uncertain target ID.
            }
        }
        $this->assertCurrent($publication);
        $fields = ['name' => $name, 'enabled' => true];
        if ($resource->mode->isHttp()) {
            [$fields['domainId'], $fields['subdomain']] = $this->resolveDomain($resource->domain ?? '');
        } else {
            $fields['proxyPort'] = $owned->public_port;
        }
        $remote = $this->client->updateResource($resourceId, $fields);
        if ($resource->mode->isHttp()) {
            if (($remote['fullDomain'] ?? null) !== $resource->domain) {
                throw new DomainException('Pangolin did not confirm the requested HTTP domain.');
            }
            $owned->confirmed_domain = $remote['fullDomain'];
        }
        $this->assertCurrent($publication);
        $endpoint = $resource->mode->isHttp() ? $resource->mode->value.'://'.$owned->confirmed_domain
            : (str_contains($host, ':') ? "[{$host}]" : $host).':'.$owned->public_port;
        $owned->forceFill(['enabled' => true, 'confirmed_endpoint' => $endpoint])->save();
    }

    /** @param list<array<string, mixed>> $inventory */
    private function reserve(ServerPublication $publication, DesiredResource $resource, array $inventory): ManagedResource
    {
        return DB::transaction(function () use ($publication, $resource, $inventory): ManagedResource {
            $existing = $publication->resources()->where('allocation_id', $resource->allocationId)->where('mode', $resource->mode->value)->lockForUpdate()->first();
            if ($existing === null && $resource->mode->isHttp()) {
                $existing = $publication->resources()->where('allocation_id', $resource->allocationId)->whereIn('mode', ['http', 'https'])->lockForUpdate()->first();
                if ($existing !== null) {
                    $existing->forceFill(['mode' => $resource->mode])->save();
                }
            }
            if ($existing !== null) {
                if (! $resource->mode->isHttp() && $existing->public_port !== $resource->targetPort) {
                    throw new DomainException('Existing raw publication uses a different port. Opt out, finish removal, then approve matching ports.');
                }
                if (! $resource->mode->isHttp()) {
                    $this->portAllocator->allocate($resource->mode->value, $resource->targetPort, []);
                }

                return $existing;
            }
            $publicPort = null;
            if (! $resource->mode->isHttp()) {
                $reserved = ManagedResource::query()->where('mode', $resource->mode->value)->whereNotNull('public_port')->pluck('public_port')->map(fn ($port): int => (int) $port)->all();
                foreach ($inventory as $remote) {
                    if (($remote['mode'] ?? null) === $resource->mode->value) {
                        $reserved[] = (int) ($remote['proxyPort'] ?? 0);
                    }
                }
                $publicPort = $this->portAllocator->allocate($resource->mode->value, $resource->targetPort, array_values(array_unique($reserved)));
            }

            return $publication->resources()->create([
                'allocation_id' => $resource->allocationId, 'mode' => $resource->mode, 'public_port' => $publicPort,
                'ownership_name' => $this->naming->resourceName($publication->server_uuid, $resource->allocationId).'-'.$resource->mode->value.'-'.bin2hex(random_bytes(12)),
            ]);
        });
    }

    /** @param list<array<string, mixed>> $inventory
     * @return array<string, mixed>|null
     */
    private function findExistingResource(string $name, DesiredResource $desired, ?int $publicPort, array $inventory): ?array
    {
        $collisions = array_values(array_filter($inventory, fn (array $remote): bool => $desired->mode->isHttp()
            ? strtolower((string) ($remote['fullDomain'] ?? '')) === strtolower($desired->domain ?? '')
            : ($remote['mode'] ?? null) === $desired->mode->value && (int) ($remote['proxyPort'] ?? 0) === $publicPort));
        if (count($collisions) > 1 || (isset($collisions[0]) && ($collisions[0]['name'] ?? null) !== $name)) {
            throw new DomainException('A conflicting Pangolin resource already uses the requested identity.');
        }

        return $collisions[0] ?? null;
    }

    /** @return array{string, ?string} */
    private function resolveDomain(string $fullDomain): array
    {
        $matches = array_values(array_filter($this->client->listDomains(), function (array $domain) use ($fullDomain): bool {
            $base = strtolower(trim((string) ($domain['baseDomain'] ?? ''), '.'));

            return ($domain['verified'] ?? false) === true && ($fullDomain === $base || str_ends_with($fullDomain, ".{$base}"));
        }));
        usort($matches, fn (array $a, array $b): int => strlen((string) $b['baseDomain']) <=> strlen((string) $a['baseDomain']));
        if ($matches === [] || ! is_string($matches[0]['domainId'] ?? null)) {
            throw new DomainException('No verified Pangolin domain matches the requested domain.');
        }
        $base = trim((string) $matches[0]['baseDomain'], '.');
        if (isset($matches[1]) && strlen((string) $matches[1]['baseDomain']) === strlen($base)) {
            throw new DomainException('More than one Pangolin domain matches.');
        }

        return [$matches[0]['domainId'], $fullDomain === $base ? null : substr($fullDomain, 0, -strlen(".{$base}"))];
    }

    private function removeResource(ServerPublication $publication, ManagedResource $owned): void
    {
        if ($owned->pangolin_resource_id !== null) {
            try {
                $id = $owned->pangolin_resource_id;
                $this->assertOwnership($owned);
                foreach ($this->client->listTargets($id) as $target) {
                    if ($owned->pangolin_target_id === null || (int) ($target['targetId'] ?? 0) !== $owned->pangolin_target_id) {
                        throw new DomainException('Unexpected target on the owned resource; operator cleanup required before deletion.');
                    }
                }
                $this->assertCurrent($publication);
                $this->client->deleteResource($id);
            } catch (PangolinApiException $exception) {
                if ($exception->status !== 404) {
                    throw $exception;
                }
            }
        }
        $owned->delete();
    }

    private function assertCurrent(ServerPublication $publication): void
    {
        $current = $publication->fresh();
        if ($current === null || $current->desired_revision !== $publication->desired_revision || $current->opted_in !== $publication->opted_in || $current->suspended !== $publication->suspended) {
            throw new DomainException('The desired publication revision is stale.');
        }
    }

    private function assertOwnership(ManagedResource $owned): void
    {
        $remote = $this->client->getResource($owned->pangolin_resource_id ?? 0);
        $name = $owned->ownership_name ?? $this->naming->resourceName($owned->publication->server_uuid, $owned->allocation_id);
        if (($remote['name'] ?? null) !== $name || (int) ($remote['resourceId'] ?? 0) !== $owned->pangolin_resource_id) {
            throw new DomainException('Remote ownership changed; no remote mutation performed.');
        }
    }
}
