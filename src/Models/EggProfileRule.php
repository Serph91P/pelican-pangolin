<?php

namespace PelicanDev\PelicanPangolin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PelicanDev\PelicanPangolin\Enums\PublicationMode;

/**
 * @property int $id
 * @property int $egg_profile_id
 * @property int $allocation_position
 * @property int|null $allocation_port
 * @property string|null $purpose
 * @property PublicationMode $mode
 * @property string|null $domain
 * @property string|null $subdomain
 * @property-read EggProfile $profile
 */
final class EggProfileRule extends Model
{
    protected $table = 'pangolin_egg_profile_rules';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'allocation_position' => 'integer',
            'allocation_port' => 'integer',
            'mode' => PublicationMode::class,
        ];
    }

    /** @return BelongsTo<EggProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(EggProfile::class, 'egg_profile_id');
    }
}
