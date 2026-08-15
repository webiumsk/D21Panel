<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * RegWatch jurisdiction (docs/LEGAL.md): a country (SK, CZ, DE...) or
 * sub-national entity (US-WY) whose tax/legal rules and official sources
 * the module tracks.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property bool $active
 */
class RegWatchJurisdiction extends Model
{
    use HasUuids;

    protected $table = 'regwatch_jurisdictions';

    protected $fillable = [
        'code',
        'name',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function sources(): HasMany
    {
        return $this->hasMany(RegWatchSource::class, 'jurisdiction_id');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(RegWatchRule::class, 'jurisdiction_id');
    }
}
