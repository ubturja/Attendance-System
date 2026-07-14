import { useQuery } from '@tanstack/react-query';
import { Calendar, ChevronDown, Loader2 } from 'lucide-react';
import { useState } from 'react';
import { Alert } from '../../components/ui/Alert';
import api from '../../lib/api';
import { queryKeys } from '../../lib/queryKeys';
import { cn } from '../../lib/utils';

interface ApiSuccessResponse<T> {
  success: true;
  message: string;
  data: T;
}

interface YearlyReportPivotRow {
  user_id: number;
  user_name: string | null;
  team_name: string | null;
  annual_leave_remaining: number;
  total_absences: number;
  total_work_in_office: number;
  total_wfh: number;
  [key: `${string}_assigned`]: number | undefined;
  [key: `${string}_taken`]: number | undefined;
  [key: `${string}_remaining`]: number | undefined;
}

interface YearlyReportData {
  year: number;
  leave_type_columns: string[];
  rows: YearlyReportPivotRow[];
}

function getCurrentYear(): number {
  return new Date().getFullYear();
}

function formatDays(value: number | undefined): string {
  return (value ?? 0).toFixed(1);
}

function getPivotValue(row: YearlyReportPivotRow, code: string, suffix: 'assigned' | 'taken' | 'remaining'): number {
  const key = `${code}_${suffix}` as keyof YearlyReportPivotRow;
  const value = row[key];
  return typeof value === 'number' ? value : 0;
}

async function fetchYearlyReport(year: number): Promise<YearlyReportData> {
  const response = await api.get<ApiSuccessResponse<YearlyReportData>>('/reports/yearly', {
    params: { year },
  });
  return response.data.data;
}

const stickyUserHead = 'sticky left-0 z-30 bg-slate-50';
const stickyTeamHead = 'sticky left-[9rem] z-30 bg-slate-50';
const stickyUser = 'sticky left-0 z-20 bg-white group-hover:bg-slate-50/80';
const stickyTeam = 'sticky left-[9rem] z-20 bg-white group-hover:bg-slate-50/80';

const SUB_COLUMN_LABELS = ['Assigned', 'Taken', 'Remaining'] as const;

export default function YearlyReport() {
  const [reportYear, setReportYear] = useState(getCurrentYear);

  const {
    data: report,
    isLoading,
    isError,
  } = useQuery({
    queryKey: queryKeys.reports.yearly(reportYear),
    queryFn: () => fetchYearlyReport(reportYear),
  });

  const leaveTypeColumns = report?.leave_type_columns ?? [];
  const rows = report?.rows ?? [];

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight text-slate-900">
          Yearly Report
        </h1>
        <p className="mt-1 text-sm text-slate-500">
          Excel-style yearly leave balance matrix (assigned / taken / remaining).
        </p>
      </div>

      <div className="flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
        <div className="flex min-w-[10rem] flex-col gap-1.5">
          <label className="text-xs font-medium text-slate-600" htmlFor="report-year">
            Year
          </label>
          <div className="relative">
            <Calendar
              className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"
              aria-hidden="true"
            />
            <input
              id="report-year"
              type="number"
              min={2000}
              max={2100}
              value={reportYear}
              onChange={(event) => {
                const parsed = Number.parseInt(event.target.value, 10);
                if (!Number.isNaN(parsed)) {
                  setReportYear(parsed);
                }
              }}
              className={cn(
                'h-10 w-full rounded-md border border-slate-300 bg-white pl-10 pr-3 text-sm text-slate-900',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400',
              )}
            />
          </div>
        </div>

        <div className="flex min-w-[12rem] flex-col gap-1.5">
          <label className="text-xs font-medium text-slate-600" htmlFor="report-team">
            Team
          </label>
          <div className="relative">
            <select
              id="report-team"
              disabled
              title="Feature coming soon"
              className={cn(
                'h-10 w-full appearance-none rounded-md border border-slate-300 bg-white px-3 pr-9 text-sm text-slate-700',
                'disabled:cursor-not-allowed disabled:bg-slate-50',
              )}
              defaultValue=""
            >
              <option value="">All teams</option>
            </select>
            <ChevronDown
              className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"
              aria-hidden="true"
            />
          </div>
        </div>
      </div>

      <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <div className="w-full overflow-x-auto">
          {isLoading ? (
            <div className="flex items-center justify-center gap-2 py-16 text-sm text-slate-500">
              <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
              Loading...
            </div>
          ) : isError ? (
            <div className="p-6">
              <Alert variant="error">Failed to load data. Please try again.</Alert>
            </div>
          ) : (
            <table className="w-full min-w-[64rem] border-collapse text-sm">
              <thead>
                <tr className="border-b border-slate-200 bg-slate-50">
                  <th
                    rowSpan={2}
                    className={cn(
                      stickyUserHead,
                      'h-11 min-w-[9rem] border-r border-slate-200 px-4 text-left align-middle',
                      'text-xs font-semibold uppercase tracking-wide text-slate-500',
                    )}
                  >
                    User Name
                  </th>
                  <th
                    rowSpan={2}
                    className={cn(
                      stickyTeamHead,
                      'h-11 min-w-[8rem] border-r border-slate-200 px-4 text-left align-middle',
                      'text-xs font-semibold uppercase tracking-wide text-slate-500',
                    )}
                  >
                    Team
                  </th>
                  {leaveTypeColumns.map((code) => (
                    <th
                      key={code}
                      colSpan={3}
                      className="border-b border-r border-slate-200 px-4 py-2 text-center text-xs font-semibold uppercase tracking-wide text-slate-600"
                    >
                      {code} Leave
                    </th>
                  ))}
                  <th
                    rowSpan={2}
                    className={cn(
                      'h-11 min-w-[7rem] border-r border-slate-200 bg-emerald-50 px-4 text-center align-middle',
                      'text-xs font-semibold uppercase tracking-wide text-emerald-900',
                    )}
                  >
                    Annual Remaining
                  </th>
                  <th
                    rowSpan={2}
                    className={cn(
                      'h-11 min-w-[7rem] border-r border-slate-200 bg-amber-50 px-4 text-center align-middle',
                      'text-xs font-semibold uppercase tracking-wide text-amber-900',
                    )}
                  >
                    Total Absences
                  </th>
                  <th
                    rowSpan={2}
                    className={cn(
                      'h-11 min-w-[7rem] border-r border-slate-200 bg-sky-50 px-4 text-center align-middle',
                      'text-xs font-semibold uppercase tracking-wide text-sky-900',
                    )}
                  >
                    Total WFH Days
                  </th>
                  <th
                    rowSpan={2}
                    className={cn(
                      'h-11 min-w-[7rem] bg-slate-100 px-4 text-center align-middle',
                      'text-xs font-semibold uppercase tracking-wide text-slate-700',
                    )}
                  >
                    Total Office Days
                  </th>
                </tr>
                <tr className="border-b border-slate-200 bg-slate-50">
                  {leaveTypeColumns.flatMap((code) =>
                    SUB_COLUMN_LABELS.map((label, labelIndex) => (
                      <th
                        key={`${code}-${label}`}
                        className={cn(
                          'h-9 px-3 text-center text-xs font-semibold uppercase tracking-wide text-slate-500',
                          labelIndex === 2 ? 'border-r border-slate-200' : '',
                        )}
                      >
                        {label}
                      </th>
                    )),
                  )}
                </tr>
              </thead>
              <tbody>
                {rows.length === 0 ? (
                  <tr className="hover:bg-transparent">
                    <td
                      colSpan={6 + leaveTypeColumns.length * 3}
                      className="py-10 text-center text-sm text-slate-500"
                    >
                      No yearly report data found for this year.
                    </td>
                  </tr>
                ) : (
                  rows.map((row) => (
                    <tr
                      key={row.user_id}
                      className="group border-b border-slate-200 transition-colors hover:bg-slate-50/80"
                    >
                      <td
                        className={cn(
                          stickyUser,
                          'border-r border-slate-200 px-4 py-3 font-medium text-slate-900',
                        )}
                      >
                        {row.user_name ?? '—'}
                      </td>
                      <td
                        className={cn(
                          stickyTeam,
                          'border-r border-slate-200 px-4 py-3 text-slate-700',
                        )}
                      >
                        {row.team_name ?? '—'}
                      </td>
                      {leaveTypeColumns.flatMap((code) => [
                        <td
                          key={`${row.user_id}-${code}-assigned`}
                          className="px-3 py-3 text-center tabular-nums text-slate-700"
                        >
                          {formatDays(getPivotValue(row, code, 'assigned'))}
                        </td>,
                        <td
                          key={`${row.user_id}-${code}-taken`}
                          className="px-3 py-3 text-center tabular-nums text-slate-700"
                        >
                          {formatDays(getPivotValue(row, code, 'taken'))}
                        </td>,
                        <td
                          key={`${row.user_id}-${code}-remaining`}
                          className="border-r border-slate-200 px-3 py-3 text-center tabular-nums font-medium text-slate-900"
                        >
                          {formatDays(getPivotValue(row, code, 'remaining'))}
                        </td>,
                      ])}
                      <td className="border-r border-slate-200 bg-emerald-50/80 px-4 py-3 text-center font-semibold tabular-nums text-emerald-950">
                        {formatDays(row.annual_leave_remaining)}
                      </td>
                      <td className="border-r border-slate-200 bg-amber-50/80 px-4 py-3 text-center font-semibold tabular-nums text-amber-950">
                        {formatDays(row.total_absences)}
                      </td>
                      <td className="border-r border-slate-200 bg-sky-50/80 px-4 py-3 text-center font-semibold tabular-nums text-sky-950">
                        {row.total_wfh ?? 0}
                      </td>
                      <td className="bg-slate-100/80 px-4 py-3 text-center font-semibold tabular-nums text-slate-900">
                        {row.total_work_in_office ?? 0}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          )}
        </div>
      </div>
    </div>
  );
}
