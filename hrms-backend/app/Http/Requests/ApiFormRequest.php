<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Base form request for HRMS API endpoints.
 *
 * Normalizes validation failure responses to the project-wide JSON envelope.
 * Admin route authorization is enforced by auth:sanctum + role:Admin middleware.
 */
abstract class ApiFormRequest extends FormRequest
{
    /**
     * Admin CRUD endpoints rely on middleware for RBAC — request-level authorize is permissive.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Standardized 422 payload for predictable frontend error handling.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
