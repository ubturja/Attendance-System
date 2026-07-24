<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

/**
 * Validates Admin holiday creation payloads.
 */
class StoreHolidayRequest extends ApiFormRequest
{
    /**
     * Creation rules aligned with the holidays table schema.
     *
     * @return array<string, list<\Illuminate\Contracts\Validation\ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'date' => ['required', 'date', 'unique:holidays,date'],
            'description' => ['nullable', 'string', 'max:191'],
            'type' => ['required', 'in:malaysia,hong_kong'],
        ];
    }
}
