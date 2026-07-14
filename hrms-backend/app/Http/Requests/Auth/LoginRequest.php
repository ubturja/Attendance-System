<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Validates inbound credentials for the Sanctum token login endpoint.
 *
 * Isolates input rules from controller logic so authentication concerns
 * remain testable and Mass Assignment safe at the request boundary.
 */
class LoginRequest extends FormRequest
{
    /**
     * Login is a public endpoint — authorization is deferred to credential verification.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Strict validation rules aligned with ERD column constraints.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // Must match users.email (VARCHAR 191, unique login identifier).
            'email' => ['required', 'string', 'email', 'max:191'],

            // Plaintext password submitted by the client; hashed comparison occurs in the controller.
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Standardized 422 payload for predictable frontend error handling.
     *
     * Laravel's default validation envelope differs from our API contract;
     * this override ensures all auth validation failures share one shape.
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
