<?php

namespace PelicanDev\PelicanPangolin\Services;

use App\Enums\ServerState;
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use PelicanDev\PelicanPangolin\Enums\SyncStatus;
use PelicanDev\PelicanPangolin\Models\NodeMapping;
use PelicanDev\PelicanPangolin\Models\ServerPublication;

final class PublicationStateSnapshot
{
    /** @param array<int, array<string, mixed>>|null $approvals */
    public function refresh(Server $server, ServerPublication $publication, ?array $approvals = null): void
    {
        DB::transaction(function () use ($server, $publication, $approvals): void {
            ServerPublication::query()->whereKey($publication->id)->lockForUpdate()->firstOrFail();
            $publication->refresh();
            if (! $publication->opted_in) {
                return;
            }
            $mapping = NodeMapping::query()->where('node_id', $server->node_id)->first();
            $allocations = $server->allocations->sortBy('id')->values()
                ->map(fn ($allocation): array => ['id' => $allocation->id, 'ip' => $allocation->ip, 'port' => $allocation->port])->all();
            $previous = $publication->desired_state;
            $overrides = $approvals ?? (is_array($previous['overrides'] ?? null) ? $previous['overrides'] : []);
            // Legacy explicit per-ID approvals survive; a node transfer always needs fresh approval.
            if ($approvals === null && isset($previous['node_id']) && $previous['node_id'] !== $server->node_id) {
                $overrides = [];
            }
            $overrides = array_intersect_key($overrides, array_flip(array_column($allocations, 'id')));
            ksort($overrides);
            $mappingReady = $mapping !== null && $mapping->enabled && $mapping->validated_at !== null && $mapping->validation_error === null;
            $state = [
                'node_id' => $server->node_id,
                'mapping' => $mapping === null ? [] : ['site_id' => $mapping->site_id, 'target_host' => $mapping->target_host, 'enabled' => $mappingReady],
                'allocations' => $allocations,
                'overrides' => $overrides,
                'default_domain' => config('pelican-pangolin.default_domain'),
                'public_host' => config('pelican-pangolin.public_host'),
                'legacy_reapproval' => $approvals === null && (! empty($previous['profile_rules']) || ! empty($previous['legacy_reapproval'])),
            ];
            $suspended = $server->status === ServerState::Suspended;
            if ($previous === $state && $publication->suspended === $suspended) {
                return;
            }
            app(AllocationAliasManager::class)->restore($publication);
            $publication->forceFill([
                'status' => SyncStatus::Pending,
                'desired_revision' => $publication->desired_revision + 1,
                'suspended' => $suspended,
                'desired_state' => $state,
            ])->save();
        });
    }
}
