<?php

declare(strict_types=1);

namespace App\Http\Requests\Messaging;

use App\Http\Requests\ApiFormRequest;
use App\Models\Conversation;
use Illuminate\Validation\Rule;

class UpdateConversationRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:191'],
            'send_permission' => ['sometimes', Rule::in([Conversation::SEND_ADMINS_ONLY, Conversation::SEND_ALL_MEMBERS])],
        ];
    }
}
