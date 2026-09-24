<?php

declare(strict_types=1);

namespace App\Http\Requests\Messaging;

use App\Http\Requests\ApiFormRequest;

class UpdateMessageRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:10000'],
            'mention_user_ids' => ['nullable', 'array'],
            'mention_user_ids.*' => ['integer'],
            'mention_everyone' => ['nullable', 'boolean'],
            'keep_attachment_ids' => ['sometimes'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:1048576'],
        ];
    }
}
