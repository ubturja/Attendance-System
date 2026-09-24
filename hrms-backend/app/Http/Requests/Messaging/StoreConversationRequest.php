<?php

declare(strict_types=1);

namespace App\Http\Requests\Messaging;

use App\Http\Requests\ApiFormRequest;
use App\Models\Conversation;
use Illuminate\Validation\Rule;

class StoreConversationRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in([Conversation::KIND_ANNOUNCEMENT, Conversation::KIND_GENERAL])],
            'name' => ['required', 'string', 'max:191'],
            'send_permission' => ['nullable', Rule::in([Conversation::SEND_ADMINS_ONLY, Conversation::SEND_ALL_MEMBERS])],
            'member_ids' => ['nullable', 'array'],
            'member_ids.*' => ['integer', 'exists:users,id'],
        ];
    }
}
