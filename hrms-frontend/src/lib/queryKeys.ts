import type { QueryClient } from '@tanstack/react-query';

/** Central React Query keys — keep invalidations consistent across Admin and Employee views. */
export const queryKeys = {
  leaveTypes: {
    admin: ['admin', 'leave-types'] as const,
    active: ['leave-types', 'active'] as const,
  },
  users: {
    admin: ['admin', 'users'] as const,
  },
  teams: {
    admin: ['admin', 'teams'] as const,
  },
  profile: ['profile'] as const,
  reports: {
    all: ['reports'] as const,
    yearly: (year: number) => ['reports', 'yearly', year] as const,
    daily: (date: string) => ['reports', 'daily', date] as const,
    monthly: (year: number, month: number) => ['reports', 'monthly', year, month] as const,
  },
} as const;

/** Refetch admin catalog and employee attendance dropdown after leave type mutations. */
export function invalidateLeaveTypeQueries(queryClient: QueryClient): void {
  void queryClient.invalidateQueries({ queryKey: queryKeys.leaveTypes.admin });
  void queryClient.invalidateQueries({ queryKey: queryKeys.leaveTypes.active });
}

/** Refetch yearly (and related) report matrices after balance or attendance changes. */
export function invalidateReportQueries(queryClient: QueryClient): void {
  void queryClient.invalidateQueries({ queryKey: queryKeys.reports.all });
}
