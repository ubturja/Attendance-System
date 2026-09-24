<?php

declare(strict_types=1);

namespace App\Http\Requests\Messaging;

use App\Http\Requests\ApiFormRequest;

class OpenDirectRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
