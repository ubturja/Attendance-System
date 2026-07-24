<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use App\Models\Holiday;
use Illuminate\Validation\Rule;

/**
 * Validates Admin holiday update payloads (partial updates allowed).
 */
class UpdateHolidayRequest extends ApiFormRequest
{
    /**
     * Partial update rules for holiday name, date, description, and type.
     *
     * @return array<string, list<\Illuminate\Contracts\Validation\ValidationRule|string>>
     */
    public function rules(): array
    {
        /** @var Holiday|null $holiday */
        $holiday = $this->route('holiday');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:191'],
            'date' => [
                'sometimes',
                'required',
                'date',
                Rule::unique('holidays', 'date')->ignore($holiday),
            ],
            'description' => ['nullable', 'string', 'max:191'],
            'type' => ['sometimes', 'required', 'in:malaysia,hong_kong'],
        ];
    }
}
