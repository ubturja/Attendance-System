<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Validates Admin attendance correction payloads for an existing log row.
 */
class UpdateAttendanceRequest extends ApiFormRequest
{
    /**
     * @return array<string, list<\Illuminate\Contracts\Validation\ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50'],
        ];
    }
}
