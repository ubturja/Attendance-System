<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use App\Models\User;
use Closure;

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
     * @return array<string, list<\Illuminate\Contracts\Validation\ValidationRule|string|Closure>>
     */
    public function rules(): array
    {
        return [
            // Soft-deleted (archived) users remain valid so Admins can fetch
            // historical leave allocations without a 422. Eloquent SoftDeletingScope
            // is bypassed via withTrashed() (Rule::exists has no withoutGlobalScope API).
            'user_id' => [
                'required',
                'integer',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! User::withTrashed()->whereKey($value)->exists()) {
                        $fail(__('validation.exists', ['attribute' => str_replace('_', ' ', $attribute)]));
                    }
                },
            ],

            // Calendar year boundary (MySQL YEAR — e.g., 2026).
            'year' => ['required', 'integer', 'digits:4', 'min:2000', 'max:2100'],
        ];
    }
}
