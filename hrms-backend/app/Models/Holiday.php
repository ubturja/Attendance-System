<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Company holiday calendar entry.
 *
 * Admin-managed non-working days used by attendance and reporting flows.
 *
 * @property int $id
 * @property string $name
 * @property \Illuminate\Support\Carbon $date
 * @property string|null $description
 * @property string $type
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Holiday extends Model
{
    /**
     * Mass-assignable attributes — explicit whitelist for create/update.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'date',
        'description',
        'type',
    ];

    /**
     * Attribute type casting for consistent API / Eloquent types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'date' => 'date',
    ];
}
