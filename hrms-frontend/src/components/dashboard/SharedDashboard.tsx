import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { useSearchParams } from 'react-router-dom';
import { CalendarDays, Loader2, Wallet } from 'lucide-react';
import { Alert } from '../ui/Alert';
import { Avatar } from '../ui/Avatar';
import { Button } from '../ui/Button';
import { Input } from '../ui/Input';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '../ui/Table';
import { TableErrorRow } from '../ui/TableErrorRow';
import api from '../../lib/api';
import { buildAttendanceOptions } from '../../lib/attendanceOptions';
import { getApiErrorMessage } from '../../lib/errors';
import { invalidateAttendanceRelatedQueries, queryKeys } from '../../lib/queryKeys';
import { cn } from '../../lib/utils';

interface ApiSuccessResponse<T> {
  success: true;
  message: string;
  data: T;
}

interface LeaveTypeRecord {
  id: number;
  leave_type_code: string;
  name: string;
  is_active: boolean;
}

interface ProfileLeaveType {
  id: number;
  leave_type_code: string;
  name: string;
  is_active: boolean;
}

interface ProfileYearlyLeaveRecord {
  id: number;
  user_id: number;
  leave_type_id: number;
  year: number;
  assigned_days: number;
  taken_days: number;
  remaining_days: number;
  leave_type: ProfileLeaveType | null;
}

interface ProfileAttendanceLog {
  id: number;
  user_id: number;
  team_id: number;
  date: string;
  submitted_code: string | null;
  leave_type_id: number | null;
  leave_type?: ProfileLeaveType | null;
}

interface ProfileTeamMember {
  id: number;
  name: string;
  team_id: number | null;
  job_title: 'Admin' | 'Employee';
  attendance_logs?: ProfileAttendanceLog[];
}

interface ProfileTeam {
  id: number;
  team_name: string;
  users: ProfileTeamMember[];
}

interface ProfileRecord {
  id: number;
  name: string;
  email: string;
  job_title: 'Admin' | 'Employee';
  team_id: number | null;
  team: ProfileTeam | null;
  attendance_date?: string;
  leave_balances?: ProfileYearlyLeaveRecord[];
  yearly_leave_records?: ProfileYearlyLeaveRecord[];
  total_absences?: number;
}

interface AttendanceRecordPayload {
  user_id: number;
  date: string;
  code: string;
}

interface SubmitAttendancePayload {
  records: AttendanceRecordPayload[];
}

interface AttendanceLogRecord {
  id: number;
  user_id: number;
  team_id: number;
  date: string;
  leave_type_id: number | null;
}

function formatBalanceDays(value: number): string {
  return Number(value).toFixed(1);
}

function isWorkFromHomeBalance(balance: ProfileYearlyLeaveRecord): boolean {
  const name = balance.leave_type?.name?.toLowerCase() ?? '';
  const code = balance.leave_type?.leave_type_code?.toUpperCase() ?? '';
  return name === 'work from home' || code === 'W';
}

function getLeaveBalances(profile: ProfileRecord | undefined): ProfileYearlyLeaveRecord[] {
  if (profile === undefined) {
    return [];
  }

  return profile.leave_balances ?? profile.yearly_leave_records ?? [];
}

function resolveMemberAttendanceCode(member: ProfileTeamMember): string {
  const log = member.attendance_logs?.[0];

  if (log === undefined) {
    return '';
  }

  const submitted = log.submitted_code?.trim();
  if (submitted !== undefined && submitted !== '') {
    return submitted.toUpperCase();
  }

  const leaveCode = log.leave_type?.leave_type_code?.trim();
  if (leaveCode !== undefined && leaveCode !== '') {
    return leaveCode.toUpperCase();
  }

  return '';
}

async function fetchActiveLeaveTypes(): Promise<LeaveTypeRecord[]> {
  const response = await api.get<ApiSuccessResponse<LeaveTypeRecord[]>>('/leave-types');
  return response.data.data;
}

async function fetchProfile(date: string): Promise<ProfileRecord> {
  const response = await api.get<ApiSuccessResponse<ProfileRecord>>('/profile', {
    params: { date },
  });
  return response.data.data;
}

async function submitAttendance(
  payload: SubmitAttendancePayload,
): Promise<AttendanceLogRecord[]> {
  const response = await api.post<ApiSuccessResponse<AttendanceLogRecord[]>>(
    '/attendance',
    payload,
  );
  return response.data.data;
}

function getTodayDateString(): string {
  const now = new Date();
  const year = now.getFullYear();
  const month = String(now.getMonth() + 1).padStart(2, '0');
  const day = String(now.getDate()).padStart(2, '0');

  return `${year}-${month}-${day}`;
}

function createSelectionsFromMembers(members: ProfileTeamMember[]): Record<number, string> {
  return Object.fromEntries(
    members.map((member) => [member.id, resolveMemberAttendanceCode(member)]),
  );
}

function memberInitials(name: string): string {
  return name
    .split(/\s+/)
    .map((part) => part[0] ?? '')
    .join('')
    .slice(0, 2);
}

function getMemberRoleLabel(member: ProfileTeamMember, currentUserId: number | undefined): string {
  if (currentUserId !== undefined && member.id === currentUserId) {
    return 'Self';
  }

  return 'Colleague';
}

function getSubmitErrorMessage(error: unknown): string {
  return getApiErrorMessage(
    error,
    'Unable to submit attendance. Please try again.',
  );
}

function TeamAttendanceTableSkeleton() {
  return (
    <TableBody>
      <TableRow className="hover:bg-transparent">
        <TableCell colSpan={3}>
          <div className="flex items-center justify-center gap-2 py-10 text-sm text-slate-500">
            <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
            Loading team attendance...
          </div>
        </TableCell>
      </TableRow>
    </TableBody>
  );
}

export function SharedDashboard() {
  const queryClient = useQueryClient();
  const [searchParams, setSearchParams] = useSearchParams();
  const todayDate = getTodayDateString();
  const selectedDate = searchParams.get('date') || todayDate;
  const isViewingToday = selectedDate === todayDate;
  const isNotToday = !isViewingToday;

  const [selections, setSelections] = useState<Record<number, string>>({});
  const [selectionsDirty, setSelectionsDirty] = useState(false);
  const [submitError, setSubmitError] = useState<string | undefined>();
  const [submitSuccess, setSubmitSuccess] = useState<string | undefined>();
  const lastSeededDateRef = useRef<string | null>(null);

  const leaveTypesQuery = useQuery({
    queryKey: queryKeys.leaveTypes.active,
    queryFn: fetchActiveLeaveTypes,
    staleTime: 0,
  });

  const profileQuery = useQuery({
    queryKey: queryKeys.profileByDate(selectedDate),
    queryFn: () => fetchProfile(selectedDate),
    // Roster rarely changes mid-session; avoid focus refetch wiping local dropdown edits.
    refetchOnWindowFocus: false,
  });

  const teamMembers = profileQuery.data?.team?.users ?? [];
  const teamName = profileQuery.data?.team?.team_name ?? 'your team';
  const currentUserId = profileQuery.data?.id;
  const hasExistingAttendance = teamMembers.some(
    (member) => (member.attendance_logs?.length ?? 0) > 0,
  );

  const attendanceOptions = useMemo(
    () => buildAttendanceOptions(leaveTypesQuery.data ?? []),
    [leaveTypesQuery.data],
  );

  const displayBalances = useMemo(() => {
    const balances = getLeaveBalances(profileQuery.data);
    return balances.filter((balance) => !isWorkFromHomeBalance(balance));
  }, [profileQuery.data]);

  const totalAbsences = profileQuery.data?.total_absences ?? 0;

  const isProfileLoading = profileQuery.isLoading;

  // Seed from the server roster on first load / date change only — never overwrite dirty edits.
  useEffect(() => {
    if (teamMembers.length === 0) {
      return;
    }

    const dateChanged = lastSeededDateRef.current !== selectedDate;

    if (!dateChanged && selectionsDirty) {
      return;
    }

    setSelections(createSelectionsFromMembers(teamMembers));
    lastSeededDateRef.current = selectedDate;

    if (dateChanged) {
      setSelectionsDirty(false);
    }
  }, [teamMembers, selectedDate, selectionsDirty]);

  const submitAttendanceMutation = useMutation({
    mutationFn: submitAttendance,
    onSuccess: () => {
      setSubmitSuccess(
        hasExistingAttendance
          ? 'Attendance updated successfully.'
          : 'Attendance submitted successfully.',
      );
      setSubmitError(undefined);
      // Allow reseed from the refreshed server snapshot after a successful save.
      setSelectionsDirty(false);
      // Refresh dashboard balances/attendance, all reports, and admin leave grids.
      invalidateAttendanceRelatedQueries(queryClient);
    },
    onError: (error) => {
      setSubmitSuccess(undefined);
      setSubmitError(getSubmitErrorMessage(error));
    },
  });

  const isPageLoading = leaveTypesQuery.isLoading || profileQuery.isLoading;
  const isPageError = leaveTypesQuery.isError || profileQuery.isError;
  const isSubmitting = submitAttendanceMutation.isPending;
  const areLeaveTypesLoading = leaveTypesQuery.isLoading;
  const hasAnySelection = teamMembers.some((member) => {
    const code = selections[member.id];
    return code !== undefined && code !== '';
  });
  const canSubmitAttendance =
    isViewingToday &&
    !isPageLoading &&
    !isPageError &&
    teamMembers.length > 0 &&
    hasAnySelection;

  function setSelectedDate(date: string): void {
    setSearchParams((previous) => {
      const next = new URLSearchParams(previous);
      if (date === '' || date === todayDate) {
        next.delete('date');
      } else {
        next.set('date', date);
      }
      return next;
    });
    setSubmitError(undefined);
    setSubmitSuccess(undefined);
  }

  function handleCodeChange(memberId: number, value: string) {
    setSelectionsDirty(true);
    setSelections((previousSelections) => ({ ...previousSelections, [memberId]: value }));
    setSubmitError(undefined);
    setSubmitSuccess(undefined);
  }

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSubmitError(undefined);
    setSubmitSuccess(undefined);

    if (!isViewingToday) {
      setSubmitError(
        'You can only submit or update attendance for today. Contact your Admin for past changes.',
      );
      return;
    }

    const records: AttendanceRecordPayload[] = teamMembers
      .filter((member) => {
        const code = selections[member.id];
        return code !== undefined && code !== '';
      })
      .map((member) => ({
        user_id: member.id,
        date: todayDate,
        code: selections[member.id],
      }));

    submitAttendanceMutation.mutate({ records });
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight text-slate-800">Dashboard</h1>
        <p className="mt-1 text-sm text-slate-500">
          View your leave balances and mark attendance for your team.
        </p>
      </div>

      <div className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
        {isProfileLoading ? (
          <div className="col-span-full flex items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white py-10 text-sm text-slate-500 shadow-sm">
            <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
            Loading leave balances...
          </div>
        ) : profileQuery.isError ? (
          <Alert variant="error" className="col-span-full">
            Failed to load data. Please try again.
          </Alert>
        ) : (
          <>
            {displayBalances.map((leave) => (
              <div
                key={leave.id}
                className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm"
              >
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="truncate text-sm font-medium text-slate-800">
                      {leave.leave_type?.name ?? 'Leave'}
                    </p>
                    <p className="mt-2 text-xs text-slate-500">
                      Taken:{' '}
                      <span className="text-slate-600">
                        {formatBalanceDays(leave.taken_days)}
                      </span>
                      <span className="mx-1.5 text-slate-300">|</span>
                      Remaining:{' '}
                      <span className="font-semibold text-slate-900">
                        {formatBalanceDays(leave.remaining_days)}
                      </span>
                    </p>
                  </div>
                  <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-brand-50 text-brand-600">
                    <Wallet className="h-4 w-4" aria-hidden="true" />
                  </div>
                </div>
              </div>
            ))}
            <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <p className="text-sm font-medium text-slate-800">Total Absences</p>
                  <p className="mt-2 text-xl font-semibold tracking-tight text-slate-900">
                    {formatBalanceDays(totalAbsences)}{' '}
                    <span className="text-sm font-medium text-slate-500">Days</span>
                  </p>
                </div>
                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-sky-50 text-sky-700">
                  <CalendarDays className="h-4 w-4" aria-hidden="true" />
                </div>
              </div>
            </div>
          </>
        )}
      </div>

      <form
        onSubmit={handleSubmit}
        className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm"
      >
        <div className="flex flex-col gap-4 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-start sm:justify-between">
          <div className="min-w-0">
            <h2 className="text-base font-semibold text-slate-900">Team Attendance Submission</h2>
            <p className="mt-1 text-sm text-slate-500">
              {isViewingToday
                ? `Select an attendance code for yourself and each ${teamName} member.`
                : `Viewing attendance for ${selectedDate}. Past days are read-only — contact your Admin for changes.`}
            </p>
          </div>
          <div className="w-full shrink-0 sm:w-44">
            <Input
              type="date"
              label="Date"
              value={selectedDate}
              max={todayDate}
              onChange={(event) => setSelectedDate(event.target.value)}
              aria-label="Attendance date"
            />
            {isNotToday ? (
              <p className="mt-1.5 text-sm text-red-500">
                Contact your Admin to update past attendances.
              </p>
            ) : null}
          </div>
        </div>

        {submitSuccess !== undefined ? (
          <Alert variant="success" className="mx-5 mt-4">
            {submitSuccess}
          </Alert>
        ) : null}

        {submitError !== undefined ? (
          <Alert variant="error" className="mx-5 mt-4">
            {submitError}
          </Alert>
        ) : null}

        <Table>
          <TableHeader>
            <TableRow className="hover:bg-transparent">
              <TableHead>Team Member</TableHead>
              <TableHead>Role</TableHead>
              <TableHead className="w-64">Attendance Code</TableHead>
            </TableRow>
          </TableHeader>
          {isPageLoading ? (
            <TeamAttendanceTableSkeleton />
          ) : (
            <TableBody>
              {isPageError ? (
                <TableErrorRow colSpan={3} />
              ) : teamMembers.length === 0 ? (
                <TableRow className="hover:bg-transparent">
                  <TableCell colSpan={3} className="py-10 text-center text-sm text-slate-500">
                    No team members available for attendance submission.
                  </TableCell>
                </TableRow>
              ) : (
                teamMembers.map((member) => (
                  <TableRow key={member.id}>
                    <TableCell>
                      <div className="flex items-center gap-3">
                        <Avatar initials={memberInitials(member.name)} size="sm" />
                        <span className="font-medium text-slate-900">{member.name}</span>
                      </div>
                    </TableCell>
                    <TableCell className="text-slate-500">
                      {getMemberRoleLabel(member, currentUserId)}
                    </TableCell>
                    <TableCell>
                      <select
                        className={cn(
                          'h-10 w-full rounded-md border border-slate-300 bg-white px-3 text-sm text-slate-900',
                          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand',
                          'disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-400',
                        )}
                        value={selections[member.id] ?? ''}
                        onChange={(event) => handleCodeChange(member.id, event.target.value)}
                        disabled={isNotToday || isSubmitting || areLeaveTypesLoading}
                        aria-label={`Attendance code for ${member.name}`}
                      >
                        <option value="" disabled hidden>
                          Mark Attendance
                        </option>
                        {attendanceOptions.map((option) => (
                          <option key={option.value} value={option.value}>
                            {option.label}
                          </option>
                        ))}
                      </select>
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          )}
        </Table>

        <div className="border-t border-slate-200 p-5">
          <Button
            type="submit"
            variant="primary"
            size="lg"
            className="h-12 w-full text-base disabled:bg-slate-400 disabled:cursor-not-allowed"
            disabled={isNotToday || !canSubmitAttendance || isSubmitting}
          >
            {isSubmitting
              ? hasExistingAttendance
                ? 'Updating...'
                : 'Submitting...'
              : isNotToday
                ? 'Past Attendance is Read-Only'
                : hasExistingAttendance
                  ? 'Update Attendance'
                  : 'Submit Attendance'}
          </Button>
        </div>
      </form>
    </div>
  );
}
