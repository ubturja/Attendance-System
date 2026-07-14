import { useQuery } from '@tanstack/react-query';
import { Loader2 } from 'lucide-react';
import { Alert } from '../../components/ui/Alert';
import { Avatar } from '../../components/ui/Avatar';
import api from '../../lib/api';

interface ApiSuccessResponse<T> {
  success: true;
  message: string;
  data: T;
}

type UserJobTitle = 'Admin' | 'Employee';

interface ProfileTeam {
  id: number;
  team_name: string;
}

interface ProfileRecord {
  id: number;
  name: string;
  email: string;
  job_title: UserJobTitle;
  team_id: number | null;
  team: ProfileTeam | null;
}

interface ProfileField {
  label: string;
  value: string;
}

const PROFILE_QUERY_KEY = ['profile'] as const;

async function fetchProfile(): Promise<ProfileRecord> {
  const response = await api.get<ApiSuccessResponse<ProfileRecord>>('/profile');
  return response.data.data;
}

function profileInitials(name: string): string {
  return name
    .split(/\s+/)
    .map((part) => part[0] ?? '')
    .join('')
    .slice(0, 2)
    .toUpperCase();
}

function buildProfileFields(profile: ProfileRecord): ProfileField[] {
  return [
    { label: 'Full Name', value: profile.name },
    { label: 'Email', value: profile.email },
    { label: 'Role', value: profile.job_title },
    { label: 'Team', value: profile.team?.team_name ?? '—' },
  ];
}

export default function Profile() {
  const {
    data: profile,
    isLoading,
    isError,
  } = useQuery({
    queryKey: PROFILE_QUERY_KEY,
    queryFn: fetchProfile,
  });

  const profileFields = profile !== undefined ? buildProfileFields(profile) : [];

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight text-slate-900">Profile</h1>
        <p className="mt-1 text-sm text-slate-500">
          Read-only view of your account details.
        </p>
      </div>

      <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        {isLoading ? (
          <div className="flex items-center justify-center gap-2 py-16 text-sm text-slate-500">
            <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
            Loading profile...
          </div>
        ) : isError ? (
          <Alert variant="error" className="m-6">
            Failed to load data. Please try again.
          </Alert>
        ) : profile === undefined ? null : (
          <>
            <div className="flex items-center gap-4 border-b border-slate-200 px-6 py-5">
              <Avatar
                initials={profileInitials(profile.name)}
                size="lg"
                className="bg-slate-800 text-white"
              />
              <div className="min-w-0">
                <p className="truncate text-lg font-semibold text-slate-900">{profile.name}</p>
                <p className="truncate text-sm text-slate-500">{profile.email}</p>
              </div>
            </div>

            <dl className="divide-y divide-slate-100">
              {profileFields.map((field) => (
                <div
                  key={field.label}
                  className="grid grid-cols-1 gap-1 px-6 py-4 sm:grid-cols-3 sm:gap-4"
                >
                  <dt className="text-sm font-medium text-slate-500">{field.label}</dt>
                  <dd className="text-sm font-medium text-slate-900 sm:col-span-2">
                    {field.value}
                  </dd>
                </div>
              ))}
            </dl>
          </>
        )}
      </div>
    </div>
  );
}
