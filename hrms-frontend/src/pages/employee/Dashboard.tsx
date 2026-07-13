import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { CalendarDays, Clock3, Loader2, Wallet } from 'lucide-react';
import { Alert } from '../../components/ui/Alert';
import { Avatar } from '../../components/ui/Avatar';
import { Button } from '../../components/ui/Button';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '../../components/ui/Table';
import { TableErrorRow } from '../../components/ui/TableErrorRow';
import api from '../../lib/api';
import { buildAttendanceOptions } from '../../lib/attendanceOptions';
import { getApiErrorMessage } from '../../lib/errors';
import { invalidateReportQueries, queryKeys } from '../../lib/queryKeys';
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

interface ProfileTeamMember {
  id: number;
  name: string;
  team_id: number | null;
  job_title: 'Admin' | 'Employee';
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
  yearly_leave_records: ProfileYearlyLeaveRecord[];
}

interface DashboardStatCard {
  title: string;
  value: string;
  unit: string;
  icon: typeof Wallet;
  accent: string;
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

const DEFAULT_ATTENDANCE_CODE = 'O';

function formatBalanceDays(value: number | null): string {
  return value !== null ? value.toFixed(1) : '—';
}

function getRemainingByLeaveCode(
  records: ProfileYearlyLeaveRecord[],
  leaveTypeCode: string,
): number | null {
  const record = records.find(
    (entry) => entry.leave_type?.leave_type_code === leaveTypeCode,
  );

  if (record === undefined) {
    return null;
  }

  return record.remaining_days;
}

function getTotalAbsences(records: ProfileYearlyLeaveRecord[]): number {
  return records.reduce((total, record) => total + record.taken_days, 0);
}

function buildDashboardStatCards(records: ProfileYearlyLeaveRecord[]): DashboardStatCard[] {
  const annualLeaveRemaining = getRemainingByLeaveCode(records, 'A');
  const noPayLeaveRemaining = getRemainingByLeaveCode(records, 'N');
  const totalAbsences = getTotalAbsences(records);

  return [
    {
      title: 'Annual Leave Remaining',
      value: formatBalanceDays(annualLeaveRemaining),
      unit: 'Days Remaining',
      icon: Wallet,
      accent: 'text-emerald-700 bg-emerald-50',
    },
    {
      title: 'No Pay Remaining',
      value: formatBalanceDays(noPayLeaveRemaining),
      unit: 'Days Remaining',
      icon: Clock3,
      accent: 'text-violet-700 bg-violet-50',
    },
    {
      title: 'Total Absences',
      value: totalAbsences.toFixed(1),
      unit: 'Days Taken',
      icon: CalendarDays,
      accent: 'text-sky-700 bg-sky-50',
    },
  ];
}

async function fetchActiveLeaveTypes(): Promise<LeaveTypeRecord[]> {
  const response = await api.get<ApiSuccessResponse<LeaveTypeRecord[]>>('/leave-types');
  return response.data.data;
}

async function fetchProfile(): Promise<ProfileRecord> {
  const response = await api.get<ApiSuccessResponse<ProfileRecord>>('/profile');
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

function createDefaultSelections(memberIds: number[]): Record<number, string> {
  return Object.fromEntries(memberIds.map((memberId) => [memberId, DEFAULT_ATTENDANCE_CODE]));
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

export default function Dashboard() {
  const queryClient = useQueryClient();
  const [selections, setSelections] = useState<Record<number, string>>({});
  const [submitError, setSubmitError] = useState<string | undefined>();
  const [submitSuccess, setSubmitSuccess] = useState<string | undefined>();

  const leaveTypesQuery = useQuery({
    queryKey: queryKeys.leaveTypes.active,
    queryFn: fetchActiveLeaveTypes,
    staleTime: 0,
  });

  const profileQuery = useQuery({
    queryKey: queryKeys.profile,
    queryFn: fetchProfile,
  });

  const teamMembers = profileQuery.data?.team?.users ?? [];
  const teamName = profileQuery.data?.team?.team_name ?? 'your team';
  const currentUserId = profileQuery.data?.id;

  const attendanceOptions = useMemo(
    () => buildAttendanceOptions(leaveTypesQuery.data ?? []),
    [leaveTypesQuery.data],
  );

  const statCards = useMemo(
    () => buildDashboardStatCards(profileQuery.data?.yearly_leave_records ?? []),
    [profileQuery.data?.yearly_leave_records],
  );

  const isProfileLoading = profileQuery.isLoading;

  useEffect(() => {
    if (teamMembers.length === 0) {
      return;
    }

    setSelections((previousSelections) => {
      const nextSelections = { ...previousSelections };

      for (const member of teamMembers) {
        if (nextSelections[member.id] === undefined) {
          nextSelections[member.id] = DEFAULT_ATTENDANCE_CODE;
        }
      }

      return nextSelections;
    });
  }, [teamMembers]);

  const submitAttendanceMutation = useMutation({
    mutationFn: submitAttendance,
    onSuccess: () => {
      setSubmitSuccess('Attendance submitted successfully.');
      setSubmitError(undefined);
      setSelections(createDefaultSelections(teamMembers.map((member) => member.id)));
      void queryClient.invalidateQueries({ queryKey: queryKeys.profile });
      invalidateReportQueries(queryClient);
    },
    onError: (error) => {
      setSubmitSuccess(undefined);
      setSubmitError(getSubmitErrorMessage(error));
    },
  });

  const isPageLoading = leaveTypesQuery.isLoading || profileQuery.isLoading;
  const isPageError = leaveTypesQuery.isError || profileQuery.isError;
  const isSubmitting = submitAttendanceMutation.isPending;

  function handleCodeChange(memberId: number, value: string) {
    setSelections((previousSelections) => ({ ...previousSelections, [memberId]: value }));
    setSubmitError(undefined);
    setSubmitSuccess(undefined);
  }

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSubmitError(undefined);
    setSubmitSuccess(undefined);

    const attendanceDate = getTodayDateString();
    const records: AttendanceRecordPayload[] = teamMembers.map((member) => ({
      user_id: member.id,
      date: attendanceDate,
      code: selections[member.id] ?? DEFAULT_ATTENDANCE_CODE,
    }));

    submitAttendanceMutation.mutate({ records });
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight text-slate-900">Dashboard</h1>
        <p className="mt-1 text-sm text-slate-500">
          View your leave balances and mark attendance for your team.
        </p>
      </div>

      <div className="grid gap-4 sm:grid-cols-3">
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
          statCards.map((card) => {
            const Icon = card.icon;
            return (
              <div
                key={card.title}
                className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm"
              >
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <p className="text-sm font-medium text-slate-500">{card.title}</p>
                    <p className="mt-2 text-3xl font-semibold tracking-tight text-slate-900">
                      {card.value}
                    </p>
                    <p className="mt-1 text-sm text-slate-500">{card.unit}</p>
                  </div>
                  <div
                    className={cn(
                      'flex h-10 w-10 items-center justify-center rounded-md',
                      card.accent,
                    )}
                  >
                    <Icon className="h-5 w-5" aria-hidden="true" />
                  </div>
                </div>
              </div>
            );
          })
        )}
      </div>

      <form
        onSubmit={handleSubmit}
        className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm"
      >
        <div className="border-b border-slate-200 px-5 py-4">
          <h2 className="text-base font-semibold text-slate-900">Team Attendance Submission</h2>
          <p className="mt-1 text-sm text-slate-500">
            Select an attendance code for yourself and each {teamName} member.
          </p>
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
                          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400',
                          'disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-400',
                        )}
                        value={selections[member.id] ?? DEFAULT_ATTENDANCE_CODE}
                        onChange={(event) => handleCodeChange(member.id, event.target.value)}
                        disabled={isSubmitting || attendanceOptions.length === 0}
                        aria-label={`Attendance code for ${member.name}`}
                      >
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
            className="h-12 w-full text-base"
            disabled={isPageLoading || isPageError || teamMembers.length === 0 || isSubmitting}
          >
            {isSubmitting ? 'Submitting...' : 'Submit Attendance'}
          </Button>
        </div>
      </form>
    </div>
  );
}
