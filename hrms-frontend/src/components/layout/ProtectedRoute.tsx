import { useQuery } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import { useEffect, type ReactNode } from 'react';
import { Navigate } from 'react-router-dom';
import {
  clearAuthSession,
  ADMIN_HOME_PATH,
  EMPLOYEE_DASHBOARD_PATH,
  getAuthToken,
  LOGIN_PATH,
  USER_ROLE_STORAGE_KEY,
} from '../../lib/api';
import { queryKeys } from '../../lib/queryKeys';
import {
  fetchSessionProfile,
  syncStoredUserRole,
  type SessionUserRole,
} from '../../lib/sessionProfile';
import { AuthGateFallback } from './AuthGateFallback';

export type UserRole = SessionUserRole;

export { USER_ROLE_STORAGE_KEY };

/** HTTP statuses that mean the Sanctum session is truly invalid — clear local auth. */
const SESSION_INVALID_STATUSES = new Set([401, 419]);

export interface ProtectedRouteProps {
  children: ReactNode;
  /** When set, the server-verified role must be included in this list. */
  allowedRoles?: readonly UserRole[];
  /**
   * Redirect when the session is missing or cannot be verified.
   * Defaults to `/login`.
   */
  unauthenticatedRedirect?: string;
  /**
   * Redirect when authenticated but the verified role is not permitted.
   * Defaults to a role-aware destination.
   */
  unauthorizedRedirect?: string;
}

/**
 * Resolves the redirect target for an authenticated user whose verified role
 * is not allowed on the requested route.
 */
export function resolveUnauthorizedRedirect(userRole: UserRole): string {
  if (userRole === 'Admin') {
    return ADMIN_HOME_PATH;
  }

  if (userRole === 'Employee') {
    return EMPLOYEE_DASHBOARD_PATH;
  }

  return LOGIN_PATH;
}

function getHttpStatus(error: unknown): number | undefined {
  if (!isAxiosError(error)) {
    return undefined;
  }

  return error.response?.status;
}

/** Retry transient network / 5xx failures; never retry definitive auth failures. */
function shouldRetrySessionFetch(failureCount: number, error: unknown): boolean {
  const status = getHttpStatus(error);

  if (status !== undefined && SESSION_INVALID_STATUSES.has(status)) {
    return false;
  }

  return failureCount < 2;
}

export function ProtectedRoute({
  children,
  allowedRoles,
  unauthenticatedRedirect = LOGIN_PATH,
  unauthorizedRedirect,
}: ProtectedRouteProps) {
  const token = getAuthToken();

  const sessionQuery = useQuery({
    queryKey: queryKeys.profile,
    queryFn: fetchSessionProfile,
    enabled: token !== null,
    retry: shouldRetrySessionFetch,
    staleTime: 60_000,
  });

  if (token === null) {
    return <Navigate to={unauthenticatedRedirect} replace />;
  }

  if (sessionQuery.isPending) {
    return <AuthGateFallback />;
  }

  if (sessionQuery.isError || sessionQuery.data === undefined) {
    const status = getHttpStatus(sessionQuery.error);

    // Only nuke the session on real auth/CSRF failures — not 5xx or offline blips.
    if (status !== undefined && SESSION_INVALID_STATUSES.has(status)) {
      clearAuthSession();
      return <Navigate to={unauthenticatedRedirect} replace />;
    }

    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-100">
        <div className="text-red-500">Network error. Please refresh the page.</div>
      </div>
    );
  }

  const verifiedRole = sessionQuery.data.job_title;

  return (
    <VerifiedRoleGate
      verifiedRole={verifiedRole}
      allowedRoles={allowedRoles}
      unauthorizedRedirect={unauthorizedRedirect}
    >
      {children}
    </VerifiedRoleGate>
  );
}

interface VerifiedRoleGateProps {
  children: ReactNode;
  verifiedRole: UserRole;
  allowedRoles?: readonly UserRole[];
  unauthorizedRedirect?: string;
}

/**
 * Inner gate — syncs storage to the server role, then enforces route ACLs.
 * Separated so the role sync effect runs only after a successful profile fetch.
 */
function VerifiedRoleGate({
  children,
  verifiedRole,
  allowedRoles,
  unauthorizedRedirect,
}: VerifiedRoleGateProps) {
  useEffect(() => {
    syncStoredUserRole(verifiedRole);
  }, [verifiedRole]);

  const isRoleRestricted =
    allowedRoles !== undefined && allowedRoles.length > 0;
  const isRolePermitted =
    !isRoleRestricted || allowedRoles.includes(verifiedRole);

  if (!isRolePermitted) {
    const destination =
      unauthorizedRedirect ?? resolveUnauthorizedRedirect(verifiedRole);
    return <Navigate to={destination} replace />;
  }

  return children;
}
