<?php

declare(strict_types=1);

namespace App\Http\Requests\Reports;

use App\Http\Requests\ApiFormRequest;

/**
 * Validates query parameters for the daily attendance report endpoint.
 */
class DailyReportRequest extends ApiFormRequest
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
            'date' => ['required', 'date'],
        ];
    }
}
