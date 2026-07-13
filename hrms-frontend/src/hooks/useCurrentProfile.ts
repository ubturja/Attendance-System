import { useQuery } from '@tanstack/react-query';
import { queryKeys } from '../lib/queryKeys';
import { fetchSessionProfile, type SessionProfile } from '../lib/sessionProfile';

export type ProfileJobTitle = SessionProfile['job_title'];
export type CurrentUserProfile = SessionProfile;

export const PROFILE_QUERY_KEY = queryKeys.profile;

export function profileInitials(name: string): string {
  const trimmed = name.trim();

  if (trimmed.length === 0) {
    return '';
  }

  return trimmed
    .split(/\s+/)
    .map((part) => part[0] ?? '')
    .join('')
    .slice(0, 2)
    .toUpperCase();
}

export function useCurrentProfile() {
  return useQuery({
    queryKey: PROFILE_QUERY_KEY,
    queryFn: fetchSessionProfile,
  });
}
