<?php

namespace PelicanDev\PelicanPangolin\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $egg_uuid
 * @property string $name
 * @property bool $enabled
 * @property-read Collection<int, EggProfileRule> $rules
 */
final class EggProfile extends Model
{
    protected $table = 'pangolin_egg_profiles';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    /** @return HasMany<EggProfileRule, $this> */
    public function rules(): HasMany
    {
        return $this->hasMany(EggProfileRule::class)->orderBy('allocation_position');
    }
}
