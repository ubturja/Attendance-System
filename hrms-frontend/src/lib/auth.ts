import api, { clearAuthSession } from './api';
import { queryClient } from './queryClient';

/**
 * Revokes the current Sanctum token on the server and hard-clears the client session.
 * Always removes auth_token + user_role and wipes the React Query cache so the next
 * login cannot inherit a stale profile or dashboard state.
 */
export async function performLogout(): Promise<void> {
  try {
    await api.post('/logout');
  } catch {
    // Client session must still be cleared when the token is already invalid.
  } finally {
    clearAuthSession();
    queryClient.clear();
  }
}
