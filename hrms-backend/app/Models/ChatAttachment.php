<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * File attached to a chat message. Removed attachments stay on disk so an
 * edited message can drop a file without destroying the stored bytes.
 *
 * @property int $id
 * @property int $message_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string|null $mime_type
 * @property int $size_bytes
 * @property \Illuminate\Support\Carbon|null $removed_at
 */
class ChatAttachment extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'message_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'removed_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'message_id' => 'integer',
        'size_bytes' => 'integer',
        'removed_at' => 'datetime',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }
}
