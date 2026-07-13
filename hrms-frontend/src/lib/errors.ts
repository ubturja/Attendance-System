import { isAxiosError } from 'axios';

export interface ApiErrorPayload {
  success?: false;
  message?: string;
  /** Laravel validation errors (`string[]`) or domain context objects. */
  errors?: Record<string, string[] | string | number | boolean | null>;
}

const NETWORK_ERROR_MESSAGE =
  'Unable to reach the server. Please check your connection and try again.';

const SERVER_ERROR_MESSAGE =
  'The server encountered an error. Please try again later.';

/**
 * Extracts the first Laravel validation message when `errors` contains string arrays.
 * Ignores non-validation context objects (e.g. attendance 422 metadata).
 */
function extractValidationMessage(
  errors: Record<string, string[] | string | number | boolean | null>,
): string | undefined {
  for (const value of Object.values(errors)) {
    if (Array.isArray(value) && typeof value[0] === 'string' && value[0].length > 0) {
      return value[0];
    }
  }

  return undefined;
}

/**
 * Maps Axios / network failures into a user-facing message.
 * Handles 422 validation payloads, domain rule violations, 5xx responses, and offline errors.
 */
export function getApiErrorMessage(error: unknown, fallback: string): string {
  if (!isAxiosError<ApiErrorPayload>(error)) {
    return fallback;
  }

  if (error.code === 'ERR_NETWORK' || error.response === undefined) {
    return NETWORK_ERROR_MESSAGE;
  }

  const payload = error.response.data;
  const status = error.response.status;

  if (payload?.errors !== undefined) {
    const validationMessage = extractValidationMessage(payload.errors);
    if (validationMessage !== undefined) {
      return validationMessage;
    }
  }

  if (payload?.message !== undefined && payload.message.trim().length > 0) {
    return payload.message;
  }

  if (status >= 500) {
    return SERVER_ERROR_MESSAGE;
  }

  return fallback;
}

export { NETWORK_ERROR_MESSAGE, SERVER_ERROR_MESSAGE };
