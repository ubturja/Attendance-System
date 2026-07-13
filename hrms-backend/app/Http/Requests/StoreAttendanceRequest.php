<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Validates bulk attendance submission payloads.
 *
 * Each record represents one user's daily attendance entry submitted by the
 * frontend grid (Employee team entry or Admin daily report override).
 */
class StoreAttendanceRequest extends ApiFormRequest
{
    /**
     * Bulk attendance array validation rules.
     *
     * @return array<string, list<\Illuminate\Contracts\Validation\ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            // Entire payload is an array of daily attendance rows processed atomically.
            'records' => ['required', 'array', 'min:1'],

            // Employee whose attendance is being recorded (ERD attendance_logs.user_id).
            'records.*.user_id' => ['required', 'integer', 'exists:users,id'],

            // Calendar date of the attendance event.
            'records.*.date' => ['required', 'date'],

            // Frontend attendance code (A, AO, W, etc.) — mapped server-side by AttendanceVariantMapper.
            'records.*.code' => ['required', 'string', 'max:50'],
        ];
    }
}
