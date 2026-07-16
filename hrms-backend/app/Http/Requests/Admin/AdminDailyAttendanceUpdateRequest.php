<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

/**
 * Validates Admin daily-report attendance upsert payloads.
 *
 * Accepts either `code` (SPA) or `status` (spec alias) for the attendance type.
 */
class AdminDailyAttendanceUpdateRequest extends ApiFormRequest
{
    /**
     * @return array<string, list<\Illuminate\Contracts\Validation\ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'date' => ['required', 'date'],
            'code' => ['required_without:status', 'nullable', 'string', 'max:50'],
            'status' => ['required_without:code', 'nullable', 'string', 'max:50'],
        ];
    }

    /**
     * Normalized attendance code from code or status.
     */
    public function attendanceCode(): string
    {
        $code = $this->validated('code') ?? $this->validated('status');

        return strtoupper(trim((string) $code));
    }
}
