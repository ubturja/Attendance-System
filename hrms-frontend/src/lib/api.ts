import axios, {
  AxiosHeaders,
  type AxiosError,
  type AxiosInstance,
  type InternalAxiosRequestConfig,
} from 'axios';

/** localStorage key for the Sanctum Bearer token issued by `POST /api/login`. */
export const AUTH_TOKEN_STORAGE_KEY = 'auth_token' as const;

/** localStorage key for the authenticated user's `job_title` (Admin | Employee). */
export const USER_ROLE_STORAGE_KEY = 'user_role' as const;

export const LOGIN_PATH = '/login' as const;
export const EMPLOYEE_DASHBOARD_PATH = '/employee/dashboard' as const;
export const ADMIN_HOME_PATH = '/admin/dashboard' as const;

type SessionUserRole = 'Admin' | 'Employee';

interface ProfileRolePayload {
  job_title: SessionUserRole;
}

interface ApiSuccessResponse<T> {
  success: true;
  message: string;
  data: T;
}

const DEFAULT_API_ORIGIN = 'http://127.0.0.1:8000' as const;

const JSON_HEADERS = {
  Accept: 'application/json',
  'Content-Type': 'application/json',
} as const;

/**
 * Builds the Laravel API base URL from `VITE_API_BASE_URL`.
 * Accepts an origin (`http://localhost:8000`) or a full API prefix (`http://localhost:8000/api`).
 * Falls back to localhost only in development; production builds log a critical warning when unset.
 */
function resolveApiBaseUrl(): string {
  const configuredOrigin = import.meta.env.VITE_API_BASE_URL?.trim();

  if (configuredOrigin) {
    // Relative path for Vite dev proxy (e.g. "/api").
    if (configuredOrigin.startsWith('/')) {
      return configuredOrigin.replace(/\/+$/, '') || '/api';
    }

    const normalizedOrigin = configuredOrigin.replace(/\/+$/, '');

    return normalizedOrigin.endsWith('/api')
      ? normalizedOrigin
      : `${normalizedOrigin}/api`;
  }

  if (import.meta.env.PROD) {
    console.error(
      'CRITICAL: VITE_API_BASE_URL is not defined in environment variables! Falling back to localhost — API calls will fail in production.',
    );
  }

  return `${DEFAULT_API_ORIGIN}/api`;
}

/** Returns the persisted Sanctum token, or `null` when absent or blank. */
export function getAuthToken(): string | null {
  const token = localStorage.getItem(AUTH_TOKEN_STORAGE_KEY);

  if (token === null || token.trim().length === 0) {
    return null;
  }

  return token;
}

/** Persists the Sanctum token returned by the login endpoint. */
export function setAuthToken(token: string): void {
  localStorage.setItem(AUTH_TOKEN_STORAGE_KEY, token);
}

/** Removes the persisted Sanctum token. */
export function clearAuthToken(): void {
  localStorage.removeItem(AUTH_TOKEN_STORAGE_KEY);
}

/** Clears all client-side auth session keys. */
export function clearAuthSession(): void {
  localStorage.removeItem(AUTH_TOKEN_STORAGE_KEY);
  localStorage.removeItem(USER_ROLE_STORAGE_KEY);
}

function syncStoredUserRole(role: SessionUserRole): void {
  localStorage.setItem(USER_ROLE_STORAGE_KEY, role);
}

function attachBearerToken(config: InternalAxiosRequestConfig): InternalAxiosRequestConfig {
  const token = getAuthToken();

  if (token === null) {
    return config;
  }

  const headers = AxiosHeaders.from(config.headers);
  headers.set('Authorization', `Bearer ${token}`);
  config.headers = headers;

  return config;
}

function redirectToLogin(): void {
  if (window.location.pathname === LOGIN_PATH) {
    return;
  }

  window.location.href = LOGIN_PATH;
}

function redirectToRoleHome(role: SessionUserRole): void {
  window.location.href = role === 'Admin' ? ADMIN_HOME_PATH : EMPLOYEE_DASHBOARD_PATH;
}

function isUnauthorizedError(error: AxiosError): boolean {
  return error.response?.status === 401;
}

function isForbiddenError(error: AxiosError): boolean {
  return error.response?.status === 403;
}

function isLogoutRequest(error: AxiosError): boolean {
  const requestUrl = error.config?.url ?? '';
  return requestUrl.includes('/logout');
}

function isPrivilegedApiRequest(error: AxiosError): boolean {
  const requestUrl = error.config?.url ?? '';
  return requestUrl.includes('/admin/') || requestUrl.includes('/reports/');
}

/**
 * Dedicated client for 403 reconciliation — bypasses response interceptors to
 * prevent recursive handler invocation when syncing role from `GET /profile`.
 */
const profileClient = axios.create({
  baseURL: resolveApiBaseUrl(),
  headers: AxiosHeaders.from(JSON_HEADERS),
});

let isReconcilingForbidden = false;

async function syncRoleFromProfile(): Promise<SessionUserRole | null> {
  const token = getAuthToken();

  if (token === null) {
    return null;
  }

  const response = await profileClient.get<ApiSuccessResponse<ProfileRolePayload>>('/profile', {
    headers: {
      Authorization: `Bearer ${token}`,
    },
  });

  const jobTitle = response.data.data.job_title;

  if (jobTitle === 'Admin' || jobTitle === 'Employee') {
    syncStoredUserRole(jobTitle);
    return jobTitle;
  }

  return null;
}

async function handleForbiddenAccess(): Promise<void> {
  if (isReconcilingForbidden) {
    return;
  }

  isReconcilingForbidden = true;

  try {
    const role = await syncRoleFromProfile();

    if (role !== null) {
      redirectToRoleHome(role);
      return;
    }

    clearAuthSession();
    redirectToLogin();
  } catch {
    clearAuthSession();
    redirectToLogin();
  } finally {
    isReconcilingForbidden = false;
  }
}

export const api: AxiosInstance = axios.create({
  baseURL: resolveApiBaseUrl(),
  headers: AxiosHeaders.from(JSON_HEADERS),
});

api.interceptors.request.use(
  (config: InternalAxiosRequestConfig) => attachBearerToken(config),
  (error: AxiosError) => Promise.reject(error),
);

api.interceptors.response.use(
  (response) => response,
  (error: AxiosError) => {
    if (isUnauthorizedError(error)) {
      clearAuthSession();

      if (!isLogoutRequest(error)) {
        redirectToLogin();
      }
    } else if (isForbiddenError(error) && isPrivilegedApiRequest(error) && getAuthToken() !== null) {
      void handleForbiddenAccess();
    }

    return Promise.reject(error);
  },
);

/** Company holiday calendar entry returned by `GET|POST|PUT /api/holidays`. */
export type HolidayType = 'malaysia' | 'hong_kong';

export interface Holiday {
  id: number;
  name: string;
  date: string;
  description?: string;
  type: HolidayType;
  /** Present when soft-deleted; `null`/absent for active holidays. */
  deleted_at?: string | null;
}

/** Payload for creating or updating a holiday (Admin). */
export type HolidayInput = {
  name: string;
  date: string;
  description?: string | null;
  type: HolidayType;
};

/**
 * Fetches holidays ordered by date (`GET /api/holidays`).
 * Pass `includeTrashed: true` (Admin) to include soft-deleted rows via `?include_trashed=true`.
 */
export async function getHolidays(includeTrashed?: boolean): Promise<Holiday[]> {
  const response = await api.get<ApiSuccessResponse<Holiday[]>>('/holidays', {
    params: includeTrashed === true ? { include_trashed: 'true' } : undefined,
  });
  return response.data.data;
}

/** Creates a holiday entry (`POST /api/holidays`, Admin only). */
export async function createHoliday(data: HolidayInput): Promise<Holiday> {
  const response = await api.post<ApiSuccessResponse<Holiday>>('/holidays', data);
  return response.data.data;
}

/** Updates a holiday entry (`PUT /api/holidays/{id}`, Admin only). */
export async function updateHoliday(
  id: number,
  data: Partial<HolidayInput>,
): Promise<Holiday> {
  const response = await api.put<ApiSuccessResponse<Holiday>>(`/holidays/${id}`, data);
  return response.data.data;
}

/** Soft-deletes a holiday entry (`DELETE /api/holidays/{id}`, Admin only). */
export async function deleteHoliday(id: number): Promise<void> {
  await api.delete<ApiSuccessResponse<null>>(`/holidays/${id}`);
}

/** Restores a soft-deleted holiday (`POST /api/holidays/{id}/restore`, Admin only). */
export async function restoreHoliday(id: number): Promise<Holiday> {
  const response = await api.post<ApiSuccessResponse<Holiday>>(`/holidays/${id}/restore`);
  return response.data.data;
}

export default api;
