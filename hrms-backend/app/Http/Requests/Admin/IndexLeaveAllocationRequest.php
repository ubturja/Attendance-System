<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

/**
 * Validates query parameters for fetching yearly leave balance records.
 */
class IndexLeaveAllocationRequest extends ApiFormRequest
{
    /**
     * Index is a GET endpoint — read filters from query string.
     */
    public function validationData(): array
    {
        return $this->query();
    }

    /**
     * Required scoping filters — balances are always fetched per user and calendar year.
     *
     * @return array<string, list<\Illuminate\Contracts\Validation\ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            // Employee whose balances are being retrieved.
            'user_id' => ['required', 'integer', 'exists:users,id'],

            // Calendar year boundary (MySQL YEAR — e.g., 2026).
            'year' => ['required', 'integer', 'digits:4', 'min:2000', 'max:2100'],
        ];
    }
}
