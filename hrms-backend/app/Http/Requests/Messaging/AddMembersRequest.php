<?php

declare(strict_types=1);

namespace App\Http\Requests\Messaging;

use App\Http\Requests\ApiFormRequest;

class AddMembersRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ];
    }
}
