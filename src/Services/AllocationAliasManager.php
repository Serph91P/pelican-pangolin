<?php

namespace PelicanDev\PelicanPangolin\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PelicanDev\PelicanPangolin\Enums\SyncStatus;
use PelicanDev\PelicanPangolin\Models\ServerPublication;

final class AllocationAliasManager
{
    public function apply(ServerPublication $publication, string $host): void
    {
        PluginSettings::validatePublicHost($host);
        if (! Schema::hasTable('allocations') || ! $publication->opted_in || $publication->suspended || $publication->status !== SyncStatus::Synced) {
            return;
        }
        foreach ($publication->resources()->where('enabled', true)->whereIn('mode', ['tcp', 'udp'])->whereNotNull('pangolin_target_id')->get() as $resource) {
            DB::transaction(function () use ($publication, $host, $resource): void {
                $current = ServerPublication::query()->whereKey($publication->id)->lockForUpdate()->first();
                if ($current === null || $current->desired_revision !== $publication->desired_revision || ! $current->opted_in || $current->suspended || $current->status !== SyncStatus::Synced) {
                    return;
                }
                $allocation = DB::table('allocations')->where('id', $resource->allocation_id)->lockForUpdate()->first();
                if ($allocation === null || (int) $allocation->server_id !== $publication->server_id || (int) $allocation->port !== $resource->public_port) {
                    return;
                }
                $owner = DB::table('pangolin_allocation_aliases')->where('allocation_id', $resource->allocation_id)->first();
                if ($allocation->ip_alias !== null && ($owner === null || $owner->server_publication_id !== $publication->id || $owner->applied_alias !== $allocation->ip_alias)) {
                    return;
                }
                // Query builder deliberately avoids allocation observers; bind data is never written.
                DB::table('allocations')->where('id', $allocation->id)->where('ip_alias', $allocation->ip_alias)->update(['ip_alias' => $host]);
                DB::table('pangolin_allocation_aliases')->updateOrInsert(['allocation_id' => $allocation->id], ['server_publication_id' => $publication->id, 'applied_alias' => $host]);
            });
        }
    }

    public function restore(ServerPublication $publication): void
    {
        if (! Schema::hasTable('allocations')) {
            return;
        }
        DB::transaction(function () use ($publication): void {
            foreach (DB::table('pangolin_allocation_aliases')->where('server_publication_id', $publication->id)->get() as $owner) {
                DB::table('allocations')->where('id', $owner->allocation_id)->where('ip_alias', $owner->applied_alias)->update(['ip_alias' => null]);
            }
            DB::table('pangolin_allocation_aliases')->where('server_publication_id', $publication->id)->delete();
        });
    }
}
