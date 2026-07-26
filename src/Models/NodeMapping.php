<?php

namespace PelicanDev\PelicanPangolin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $node_id
 * @property int $site_id
 * @property string $site_nice_id
 * @property string $target_host
 * @property bool $enabled
 * @property Carbon|null $validated_at
 * @property string|null $validation_error
 */
final class NodeMapping extends Model
{
    protected $table = 'pangolin_node_mappings';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected static function booted(): void
    {
        self::saving(function (NodeMapping $mapping): void {
            if ($mapping->exists && $mapping->isDirty(['site_id', 'site_nice_id', 'target_host'])) {
                $mapping->validated_at = null;
                $mapping->validation_error = 'Mapping changed and must be validated again.';
            }
        });
    }

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'enabled' => 'boolean',
            'validated_at' => 'datetime',
        ];
    }
}
