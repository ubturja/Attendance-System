import api from './api';
import { USER_ROLE_STORAGE_KEY } from './api';

interface ApiSuccessResponse<T> {
  success: true;
  message: string;
  data: T;
}

/** Authoritative job title from `users.job_title` — used for RBAC after Sanctum auth. */
export type SessionUserRole = 'Admin' | 'Employee';

export interface SessionProfileTeam {
  id: number;
  team_name: string;
}

/** Profile payload returned by `GET /api/profile` — server source of truth for RBAC. */
export interface SessionProfile {
  id: number;
  name: string;
  email: string;
  job_title: SessionUserRole;
  team_id: number | null;
  team: SessionProfileTeam | null;
}

/** Fetches the authenticated user's profile — source of truth for client-side RBAC. */
export async function fetchSessionProfile(): Promise<SessionProfile> {
  const response = await api.get<ApiSuccessResponse<SessionProfile>>('/profile');
  return response.data.data;
}

/** Keeps persisted role aligned with the server after a verified profile load. */
export function syncStoredUserRole(role: SessionUserRole): void {
  localStorage.setItem(USER_ROLE_STORAGE_KEY, role);
}
