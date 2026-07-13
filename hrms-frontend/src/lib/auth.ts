import api, { clearAuthSession } from './api';

/**
 * Revokes the current Sanctum token on the server and clears the client session.
 * Client session is always cleared even when the API call fails (expired token, network).
 */
export async function performLogout(): Promise<void> {
  try {
    await api.post('/logout');
  } catch {
    // Client session must still be cleared when the token is already invalid.
  } finally {
    clearAuthSession();
  }
}
