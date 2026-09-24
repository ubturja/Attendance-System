<?php

declare(strict_types=1);

namespace App\Http\Requests\Messaging;

use App\Http\Requests\ApiFormRequest;

class StoreMessageRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:10000'],
            'reply_to_message_id' => ['nullable', 'integer'],
            'mention_user_ids' => ['nullable', 'array'],
            'mention_user_ids.*' => ['integer'],
            'mention_everyone' => ['nullable', 'boolean'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:1048576'],
        ];
    }
}
