import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Calendar, ChevronDown, Loader2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Alert } from '../../components/ui/Alert';
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
import {
  buildAttendanceOptions,
  type AttendanceOption,
  type LeaveTypeOptionSource,
} from '../../lib/attendanceOptions';
import { getApiErrorMessage } from '../../lib/errors';
import { invalidateReportQueries, queryKeys } from '../../lib/queryKeys';
import { cn } from '../../lib/utils';

interface ApiSuccessResponse<T> {
  success: true;
  message: string;
  data: T;
}

interface DailyReportRecord {
  id: number | null;
  user_id: number;
  user_name: string | null;
  team_id: number | null;
  team_name: string | null;
  date: string;
  submitted_code: string | null;
  leave_type_code: string | null;
  leave_type_name: string | null;
  updated_by?: number | null;
  updated_at?: string | null;
  updated_by_name?: string | null;
}

interface DailyReportData {
  date: string;
  records: DailyReportRecord[];
}

interface TeamOption {
  id: number;
  team_name: string;
}

interface UpdateDailyAttendancePayload {
  userId: number;
  date: string;
  code: string;
}

function getTodayDateString(): string {
  const now = new Date();
  const year = now.getFullYear();
  const month = String(now.getMonth() + 1).padStart(2, '0');
  const day = String(now.getDate()).padStart(2, '0');

  return `${year}-${month}-${day}`;
}

function resolveDisplayCode(record: DailyReportRecord): string {
  return record.submitted_code ?? record.leave_type_code ?? '';
}

function formatAuditTimestamp(value: string | null | undefined): string {
  if (value === null || value === undefined || value.trim() === '') {
    return '';
  }

  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) {
    return value;
  }

  return parsed.toLocaleString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

async function fetchDailyReport(date: string, teamId: string): Promise<DailyReportData> {
  const response = await api.get<ApiSuccessResponse<DailyReportData>>('/admin/reports/daily', {
    params: {
      date,
      ...(teamId !== '' ? { team_id: teamId } : {}),
    },
  });
  return response.data.data;
}

async function fetchTeams(): Promise<TeamOption[]> {
  const response = await api.get<ApiSuccessResponse<TeamOption[]>>('/admin/teams');
  return response.data.data;
}

async function fetchAdminReportLeaveTypes(): Promise<LeaveTypeOptionSource[]> {
  const response = await api.get<ApiSuccessResponse<LeaveTypeOptionSource[]>>(
    '/admin/leave-types',
    { params: { status: 'all' } },
  );
  return response.data.data;
}

async function updateDailyAttendance({
  userId,
  date,
  code,
}: UpdateDailyAttendancePayload): Promise<void> {
  await api.post('/admin/reports/daily/update', {
    user_id: userId,
    date,
    code,
  });
}

function DailyReportTableSkeleton() {
  return (
    <TableBody>
      <TableRow className="hover:bg-transparent">
        <TableCell colSpan={3}>
          <div className="flex items-center justify-center gap-2 py-8 text-sm text-slate-500">
            <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
            Loading...
          </div>
        </TableCell>
      </TableRow>
    </TableBody>
  );
}

interface AttendanceTypeCellProps {
  record: DailyReportRecord;
  options: AttendanceOption[];
  isSaving: boolean;
  onCodeChange: (record: DailyReportRecord, code: string) => void;
}

function AttendanceTypeCell({
  record,
  options,
  isSaving,
  onCodeChange,
}: AttendanceTypeCellProps) {
  const displayCode = resolveDisplayCode(record);
  const selectValue = displayCode !== '' ? displayCode : '';
  const hasAudit =
    record.updated_by != null &&
    record.updated_by_name != null &&
    record.updated_by_name.trim() !== '';

  return (
    <TableCell>
      <div className="space-y-1.5">
        <div className="relative inline-flex min-w-[12rem] max-w-full items-center">
          <select
            value={selectValue}
            disabled={isSaving}
            onChange={(event) => onCodeChange(record, event.target.value)}
            aria-label={`Attendance type for ${record.user_name ?? 'employee'}`}
            className={cn(
              'h-9 w-full appearance-none rounded-md border border-slate-300 bg-white px-3 pr-9 text-sm text-slate-900',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand',
              'disabled:cursor-wait disabled:bg-slate-50 disabled:text-slate-500',
            )}
          >
            <option value="" disabled>
              Select attendance type
            </option>
            {options.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
          <ChevronDown
            className="pointer-events-none absolute right-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"
            aria-hidden="true"
          />
          {isSaving ? (
            <Loader2
              className="absolute -right-6 top-1/2 h-4 w-4 -translate-y-1/2 animate-spin text-slate-400"
              aria-label="Saving attendance"
            />
          ) : null}
        </div>
        {hasAudit ? (
          <p className="text-[11px] leading-snug text-slate-400">
            Last edited by {record.updated_by_name} on{' '}
            {formatAuditTimestamp(record.updated_at)}
          </p>
        ) : null}
      </div>
    </TableCell>
  );
}

export default function DailyReport() {
  const queryClient = useQueryClient();
  const [searchParams, setSearchParams] = useSearchParams();
  const [savingUserId, setSavingUserId] = useState<number | null>(null);
  const [overrideError, setOverrideError] = useState<string | undefined>();

  const teamId = searchParams.get('team_id') || '';
  const date = searchParams.get('date') || getTodayDateString();

  const {
    data: report,
    isLoading,
    isError,
  } = useQuery({
    queryKey: queryKeys.reports.daily(date, teamId),
    queryFn: () => fetchDailyReport(date, teamId),
  });

  const { data: teams = [] } = useQuery({
    queryKey: queryKeys.teams.admin,
    queryFn: fetchTeams,
  });

  const selectedTeam = teams.find((team) => String(team.id) === teamId);
  const emptyStateMessage = selectedTeam
    ? `No member assigned in ${selectedTeam.team_name}`
    : 'No members found.';

  const leaveTypesQuery = useQuery({
    queryKey: [...queryKeys.leaveTypes.admin, 'report-options', 'all'],
    queryFn: fetchAdminReportLeaveTypes,
  });

  const attendanceOptions = useMemo(
    () => buildAttendanceOptions(leaveTypesQuery.data ?? []),
    [leaveTypesQuery.data],
  );

  const updateAttendanceMutation = useMutation({
    mutationFn: updateDailyAttendance,
    onMutate: ({ userId }) => {
      setSavingUserId(userId);
      setOverrideError(undefined);
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['reports', 'daily'] });
      invalidateReportQueries(queryClient);
    },
    onError: (error) => {
      setOverrideError(
        getApiErrorMessage(error, 'Failed to update attendance. Please try again.'),
      );
    },
    onSettled: () => {
      setSavingUserId(null);
    },
  });

  const records = report?.records ?? [];

  function updateSearchParam(key: string, value: string): void {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev);
      // Keep reports tab in the URL when nested under /admin/reports?tab=daily.
      if (!next.get('tab')) {
        next.set('tab', 'daily');
      }
      if (value === '') {
        next.delete(key);
      } else {
        next.set(key, value);
      }
      return next;
    });
  }

  function handleCodeChange(record: DailyReportRecord, code: string): void {
    if (code === '') {
      return;
    }

    const currentCode = resolveDisplayCode(record);
    if (code === currentCode) {
      return;
    }

    updateAttendanceMutation.mutate({
      userId: record.user_id,
      date,
      code,
    });
  }

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-lg font-semibold tracking-tight text-slate-800">Daily Report</h2>
        <p className="mt-1 text-sm text-slate-500">
          Inline-edit attendance types for each employee on the selected date.
        </p>
      </div>

      {overrideError !== undefined ? <Alert variant="error">{overrideError}</Alert> : null}

      <div className="flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
        <div className="flex min-w-[12rem] flex-col gap-1.5">
          <label className="text-xs font-medium text-slate-600" htmlFor="daily-report-date">
            Date
          </label>
          <div className="relative">
            <Calendar
              className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"
              aria-hidden="true"
            />
            <input
              id="daily-report-date"
              type="date"
              value={date}
              onChange={(event) => updateSearchParam('date', event.target.value)}
              className={cn(
                'h-10 w-full rounded-md border border-slate-300 bg-white pl-10 pr-3 text-sm text-slate-900',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand',
              )}
            />
          </div>
        </div>

        <div className="flex min-w-[12rem] flex-col gap-1.5">
          <label className="text-xs font-medium text-slate-600" htmlFor="daily-report-team">
            Team
          </label>
          <div className="relative">
            <select
              id="daily-report-team"
              value={teamId}
              onChange={(event) => updateSearchParam('team_id', event.target.value)}
              className={cn(
                'h-10 w-full appearance-none rounded-md border border-slate-300 bg-white px-3 pr-9 text-sm text-slate-700',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand',
              )}
            >
              <option value="">All teams</option>
              {teams.map((team) => (
                <option key={team.id} value={team.id}>
                  {team.team_name}
                </option>
              ))}
            </select>
            <ChevronDown
              className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"
              aria-hidden="true"
            />
          </div>
        </div>
      </div>

      <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <Table>
          <TableHeader>
            <TableRow className="hover:bg-transparent">
              <TableHead>Name</TableHead>
              <TableHead>Team Name</TableHead>
              <TableHead className="min-w-[14rem]">Attendance Type</TableHead>
            </TableRow>
          </TableHeader>
          {isLoading ? (
            <DailyReportTableSkeleton />
          ) : (
            <TableBody>
              {isError ? (
                <TableErrorRow colSpan={3} />
              ) : records.length === 0 ? (
                <TableRow>
                  <TableCell
                    colSpan={3}
                    className="bg-slate-50 py-12 text-center text-sm font-medium text-slate-500"
                  >
                    {emptyStateMessage}
                  </TableCell>
                </TableRow>
              ) : (
                records.map((row) => (
                  <TableRow key={`user-${row.user_id}`}>
                    <TableCell className="font-medium text-slate-900">
                      {row.user_name ?? '—'}
                    </TableCell>
                    <TableCell>{row.team_name ?? '—'}</TableCell>
                    <AttendanceTypeCell
                      record={row}
                      options={attendanceOptions}
                      isSaving={savingUserId === row.user_id}
                      onCodeChange={handleCodeChange}
                    />
                  </TableRow>
                ))
              )}
            </TableBody>
          )}
        </Table>
      </div>
    </div>
  );
}
