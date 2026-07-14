<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

/**
 * Validates manual HR leave rollover parameters.
 *
 * Admin initiates year-end (or year-start) allocation carry-forward by specifying
 * which calendar year to copy FROM (source_year) and which year to seed (target_year).
 */
class StoreLeaveRolloverRequest extends ApiFormRequest
{
    /**
     * Strict year-boundary validation for the manual rollover workflow.
     *
     * @return array<string, list<\Illuminate\Contracts\Validation\ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            // Closing / reference year — existing allocations to copy (e.g., 2026).
            'source_year' => ['required', 'integer', 'digits:4', 'min:2000', 'max:2100'],

            // Upcoming year — fresh allocation rows to create (e.g., 2027).
            'target_year' => [
                'required',
                'integer',
                'digits:4',
                'min:2000',
                'max:2100',
                'gt:source_year',
            ],
        ];
    }
}
