<?php

namespace PelicanDev\PelicanPangolin\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PelicanDev\PelicanPangolin\Enums\SyncStatus;

/**
 * @property int $id
 * @property int $server_id
 * @property string $server_uuid
 * @property bool $opted_in
 * @property bool $suspended
 * @property bool $deletion_pending
 * @property int $desired_revision
 * @property SyncStatus $status
 * @property array<string, mixed> $desired_state
 * @property string|null $last_error
 * @property-read Server|null $server
 * @property-read Collection<int, ManagedResource> $resources
 */
final class ServerPublication extends Model
{
    protected $table = 'pangolin_server_publications';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $attributes = [
        'opted_in' => false,
        'suspended' => false,
        'deletion_pending' => false,
        'desired_revision' => 1,
        'status' => 'pending',
        'desired_state' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'opted_in' => 'boolean',
            'suspended' => 'boolean',
            'deletion_pending' => 'boolean',
            'desired_revision' => 'integer',
            'status' => SyncStatus::class,
            'desired_state' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return HasMany<ManagedResource, $this> */
    public function resources(): HasMany
    {
        return $this->hasMany(ManagedResource::class);
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }
}
