<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Company holiday calendar entry.
 *
 * Admin-managed non-working days used by attendance and reporting flows.
 * Soft-deleted rows are hidden from default queries but remain recoverable.
 *
 * @property int $id
 * @property string $name
 * @property \Illuminate\Support\Carbon $date
 * @property string|null $description
 * @property string $type
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class Holiday extends Model
{
    use SoftDeletes;

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
