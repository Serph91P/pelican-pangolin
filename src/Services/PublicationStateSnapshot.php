<?php

namespace PelicanDev\PelicanPangolin\Services;

use App\Enums\ServerState;
use App\Models\Server;
use PelicanDev\PelicanPangolin\Models\EggProfile;
use PelicanDev\PelicanPangolin\Models\NodeMapping;
use PelicanDev\PelicanPangolin\Models\ServerPublication;

final class PublicationStateSnapshot
{
    public function refresh(Server $server, ServerPublication $publication): void
    {
        $mapping = NodeMapping::query()->where('node_id', $server->node_id)->first();
        $profile = EggProfile::query()
            ->with('rules')
            ->where('egg_uuid', $server->egg->uuid)
            ->where('enabled', true)
            ->first();
        $allocations = $server->allocations
            ->sortBy(fn ($allocation): string => ($allocation->id === $server->allocation_id ? '0-' : '1-').str_pad((string) $allocation->id, 20, '0', STR_PAD_LEFT))
            ->values()
            ->map(fn ($allocation): array => ['id' => $allocation->id, 'port' => $allocation->port])
            ->all();
        $state = $publication->desired_state;
        $mappingReady = $mapping !== null
            && $mapping->enabled
            && $mapping->validated_at !== null
            && $mapping->validation_error === null;

        $publication->forceFill([
            'desired_revision' => $publication->desired_revision + 1,
            'suspended' => $server->status === ServerState::Suspended || ! $mappingReady,
            'desired_state' => [
                'mapping' => $mapping === null ? [] : [
                    'site_id' => $mapping->site_id,
                    'target_host' => $mapping->target_host,
                    'enabled' => $mappingReady,
                ],
                'allocations' => $allocations,
                'profile_rules' => $profile?->rules->mapWithKeys(fn ($rule): array => [
                    $rule->allocation_position => array_filter([
                        'mode' => $rule->mode->value,
                        'domain' => $rule->domain,
                        'subdomain' => $rule->subdomain,
                    ], fn (mixed $value): bool => $value !== null),
                ])->all() ?? [],
                'overrides' => is_array($state['overrides'] ?? null) ? $state['overrides'] : [],
                'default_domain' => config('pelican-pangolin.default_domain'),
            ],
        ])->save();
    }
}
