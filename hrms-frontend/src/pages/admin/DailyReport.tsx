import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Calendar, ChevronDown, Download, Loader2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Alert } from '../../components/ui/Alert';
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
import { useCurrentProfile } from '../../hooks/useCurrentProfile';
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
}

interface DailyReportData {
  date: string;
  records: DailyReportRecord[];
}

interface TeamOption {
  id: number;
  team_name: string;
}

interface UpdateAttendancePayload {
  attendanceLogId: number;
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

async function fetchDailyReport(date: string, teamId: string): Promise<DailyReportData> {
  const response = await api.get<ApiSuccessResponse<DailyReportData>>('/reports/daily', {
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

async function fetchActiveLeaveTypes(): Promise<LeaveTypeOptionSource[]> {
  const response = await api.get<ApiSuccessResponse<LeaveTypeOptionSource[]>>('/leave-types');
  return response.data.data;
}

async function updateAttendanceRecord({
  attendanceLogId,
  code,
}: UpdateAttendancePayload): Promise<void> {
  await api.put(`/attendance/${attendanceLogId}`, { code });
}

function DailyReportTableSkeleton() {
  return (
    <TableBody>
      <TableRow className="hover:bg-transparent">
        <TableCell colSpan={4}>
          <div className="flex items-center justify-center gap-2 py-8 text-sm text-slate-500">
            <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
            Loading...
          </div>
        </TableCell>
      </TableRow>
    </TableBody>
  );
}

interface AttendanceCodeCellProps {
  record: DailyReportRecord;
  options: AttendanceOption[];
  isAdmin: boolean;
  isSaving: boolean;
  onCodeChange: (record: DailyReportRecord, code: string) => void;
}

function AttendanceCodeCell({
  record,
  options,
  isAdmin,
  isSaving,
  onCodeChange,
}: AttendanceCodeCellProps) {
  const displayCode = resolveDisplayCode(record);
  const hasAttendanceLog = record.id !== null;

  if (!isAdmin || !hasAttendanceLog) {
    return (
      <TableCell className="font-medium tabular-nums text-slate-900">
        {displayCode !== '' ? displayCode : '—'}
      </TableCell>
    );
  }

  return (
    <TableCell>
      <div className="relative inline-flex min-w-[10rem] items-center">
        <select
          value={displayCode !== '' ? displayCode : 'O'}
          disabled={isSaving}
          onChange={(event) => onCodeChange(record, event.target.value)}
          aria-label={`Attendance code for ${record.user_name ?? 'employee'}`}
          className={cn(
            'h-9 w-full appearance-none rounded-md border border-slate-300 bg-white px-3 pr-9 text-sm text-slate-900',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand',
            'disabled:cursor-wait disabled:bg-slate-50 disabled:text-slate-500',
          )}
        >
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
            aria-label="Saving attendance correction"
          />
        ) : null}
      </div>
    </TableCell>
  );
}

export default function DailyReport() {
  const queryClient = useQueryClient();
  const [searchParams, setSearchParams] = useSearchParams();
  const [savingRowId, setSavingRowId] = useState<number | null>(null);
  const [overrideError, setOverrideError] = useState<string | undefined>();

  const teamId = searchParams.get('team_id') || '';
  const reportDate = searchParams.get('date') || getTodayDateString();

  const { data: profile } = useCurrentProfile();
  const isAdmin = profile?.job_title === 'Admin';

  const {
    data: report,
    isLoading,
    isError,
  } = useQuery({
    queryKey: queryKeys.reports.daily(reportDate, teamId),
    queryFn: () => fetchDailyReport(reportDate, teamId),
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
    queryKey: queryKeys.leaveTypes.active,
    queryFn: fetchActiveLeaveTypes,
    enabled: isAdmin,
  });

  const attendanceOptions = useMemo(
    () => buildAttendanceOptions(leaveTypesQuery.data ?? []),
    [leaveTypesQuery.data],
  );

  const updateAttendanceMutation = useMutation({
    mutationFn: updateAttendanceRecord,
    onMutate: ({ attendanceLogId }) => {
      setSavingRowId(attendanceLogId);
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
      setSavingRowId(null);
    },
  });

  const records = report?.records ?? [];

  function updateSearchParam(key: string, value: string): void {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev);
      if (value === '') {
        next.delete(key);
      } else {
        next.set(key, value);
      }
      return next;
    });
  }

  function handleCodeChange(record: DailyReportRecord, code: string) {
    const currentCode = resolveDisplayCode(record);

    if (record.id === null || code === currentCode) {
      return;
    }

    updateAttendanceMutation.mutate({
      attendanceLogId: record.id,
      code,
    });
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight text-slate-800">
          Daily Report
        </h1>
        <p className="mt-1 text-sm text-slate-500">
          Day-by-day attendance codes by employee and team.
          {isAdmin ? ' Select a code to apply an admin override.' : null}
        </p>
      </div>

      {overrideError ? <Alert variant="error">{overrideError}</Alert> : null}

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
              value={reportDate}
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

        <div className="ml-auto">
          <Button
            type="button"
            variant="outline"
            size="md"
            disabled
            title="Feature coming soon"
          >
            <Download className="h-4 w-4" aria-hidden="true" />
            Export to CSV
          </Button>
        </div>
      </div>

      <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <Table>
          <TableHeader>
            <TableRow className="hover:bg-transparent">
              <TableHead>User Name</TableHead>
              <TableHead>Team</TableHead>
              <TableHead>Date</TableHead>
              <TableHead>Attendance Code</TableHead>
            </TableRow>
          </TableHeader>
          {isLoading ? (
            <DailyReportTableSkeleton />
          ) : (
            <TableBody>
              {isError ? (
                <TableErrorRow colSpan={4} />
              ) : !records || records.length === 0 ? (
                <TableRow>
                  <TableCell
                    colSpan={100}
                    className="bg-slate-50 py-12 text-center text-sm font-medium text-slate-500"
                  >
                    {emptyStateMessage}
                  </TableCell>
                </TableRow>
              ) : (
                records.map((row) => (
                  <TableRow key={row.id ?? `user-${row.user_id}`}>
                    <TableCell className="font-medium text-slate-900">
                      {row.user_name ?? '—'}
                    </TableCell>
                    <TableCell>{row.team_name ?? '—'}</TableCell>
                    <TableCell className="tabular-nums">{row.date}</TableCell>
                    <AttendanceCodeCell
                      record={row}
                      options={attendanceOptions}
                      isAdmin={isAdmin}
                      isSaving={row.id !== null && savingRowId === row.id}
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
