<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property bool $healthy
 * @property array<string, array{ok?: bool, detail?: string, duration_ms?: int}> $checks
 * @property Carbon $created_at
 */
class SystemHealthSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'healthy',
        'checks',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'healthy' => 'boolean',
            'checks' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
