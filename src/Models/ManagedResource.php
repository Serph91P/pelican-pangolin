<?php

namespace PelicanDev\PelicanPangolin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PelicanDev\PelicanPangolin\Enums\PublicationMode;

/**
 * @property int $id
 * @property int $server_publication_id
 * @property int $allocation_id
 * @property PublicationMode $mode
 * @property int|null $pangolin_resource_id
 * @property int|null $pangolin_target_id
 * @property int|null $public_port
 * @property string|null $confirmed_domain
 * @property bool $enabled
 * @property-read ServerPublication $publication
 */
final class ManagedResource extends Model
{
    protected $table = 'pangolin_managed_resources';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'allocation_id' => 'integer',
            'mode' => PublicationMode::class,
            'pangolin_resource_id' => 'integer',
            'pangolin_target_id' => 'integer',
            'public_port' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<ServerPublication, $this> */
    public function publication(): BelongsTo
    {
        return $this->belongsTo(ServerPublication::class, 'server_publication_id');
    }
}
