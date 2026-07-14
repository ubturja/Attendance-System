<?php

declare(strict_types=1);

namespace App\Http\Requests\Reports;

use App\Http\Requests\ApiFormRequest;

/**
 * Validates query parameters for the monthly attendance matrix report.
 */
class MonthlyReportRequest extends ApiFormRequest
{
    public function validationData(): array
    {
        return $this->query();
    }

    /**
     * @return array<string, list<\Illuminate\Contracts\Validation\ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'digits:4', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
        ];
    }
}
