import type { QueryClient } from '@tanstack/react-query';

/** Central React Query keys — keep invalidations consistent across Admin and Employee views. */
export const queryKeys = {
  leaveTypes: {
    admin: ['admin', 'leave-types'] as const,
    active: ['leave-types', 'active'] as const,
    /** Quota leave types for allocation UI (`is_quota_based=true`). */
    allocation: ['leave-types', 'is_quota_based', true] as const,
  },
  users: {
    admin: ['admin', 'users'] as const,
    list: (isArchived: boolean, search: string) =>
      ['admin', 'users', 'list', isArchived ? 'archived' : 'current', search] as const,
    /** Broad prefix for all per-user admin balance queries (any user/year). */
    balancesRoot: ['admin', 'user'] as const,
    balances: (userId: number, year: number) =>
      ['admin', 'user', userId, 'balances', year] as const,
  },
  teams: {
    admin: ['admin', 'teams'] as const,
    list: (isArchived: boolean, search: string) =>
      ['admin', 'teams', 'list', isArchived ? 'archived' : 'current', search] as const,
  },
  profile: ['profile'] as const,
  /** Dashboard attendance view scoped by calendar date (`GET /profile?date=`). */
  profileByDate: (date: string) => ['profile', 'by-date', date] as const,
  holidays: {
    all: ['holidays'] as const,
    /** Admin list including soft-deleted rows (`GET /holidays?include_trashed=true`). */
    admin: ['holidays', 'admin'] as const,
  },
  reports: {
    all: ['reports'] as const,
    yearly: (year: number, teamId: string) =>
      ['reports', 'yearly', year, teamId] as const,
    daily: (date: string, teamId: string) =>
      ['reports', 'daily', date, teamId] as const,
    monthly: (year: number, month: number, teamId: string) =>
      ['reports', 'monthly', year, month, teamId] as const,
  },
} as const;

/** Live-refresh interval for open report grids (cross-session attendance updates). */
export const REPORT_REFETCH_INTERVAL_MS = 3_000;

/** Refetch admin catalog and employee attendance dropdown after leave type mutations. */
export function invalidateLeaveTypeQueries(queryClient: QueryClient): void {
  void queryClient.invalidateQueries({ queryKey: queryKeys.leaveTypes.admin });
  void queryClient.invalidateQueries({ queryKey: queryKeys.leaveTypes.active });
  void queryClient.invalidateQueries({ queryKey: queryKeys.leaveTypes.allocation });
}

/**
 * Invalidate every cache that depends on attendance or leave balances.
 *
 * Prefix matches cover profile (+ by-date), all reports, and admin user balances.
 */
export function invalidateAttendanceRelatedQueries(queryClient: QueryClient): void {
  void queryClient.invalidateQueries({ queryKey: queryKeys.profile });
  void queryClient.invalidateQueries({ queryKey: queryKeys.reports.all });
  void queryClient.invalidateQueries({ queryKey: queryKeys.users.balancesRoot });
}

/** Alias used by leave-allocation and user mutations — same as {@link invalidateAttendanceRelatedQueries}. */
export function invalidateReportQueries(queryClient: QueryClient): void {
  invalidateAttendanceRelatedQueries(queryClient);
}
