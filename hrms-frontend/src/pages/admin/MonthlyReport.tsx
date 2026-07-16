import { useQuery } from '@tanstack/react-query';
import { Calendar, ChevronDown, Download, Loader2 } from 'lucide-react';
import { useSearchParams } from 'react-router-dom';
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
import { queryKeys } from '../../lib/queryKeys';
import { cn } from '../../lib/utils';

interface ApiSuccessResponse<T> {
  success: true;
  message: string;
  data: T;
}

interface MonthlyReportTotals {
  annual: number;
  sick: number;
  other: number;
}

interface MonthlyReportRow {
  user_id: number;
  user_name: string;
  team_name: string | null;
  daily_records: Record<string, string | null>;
  totals: MonthlyReportTotals;
  total_work_in_office: number;
  total_wfh: number;
}

interface MonthlyReportData {
  year: number;
  month: number;
  days_in_month: number;
  rows: MonthlyReportRow[];
}

interface TeamOption {
  id: number;
  team_name: string;
}

interface CalendarDay {
  date: number;
  dayName: string;
}

const DAY_NAMES = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as const;

const SUMMARY_HEADERS = [
  'Total Annual',
  'Total Sick',
  'Total Other',
  'Total WFH',
  'Total Office',
] as const;

const stickyNameHead =
  'sticky left-0 z-30 bg-slate-50 border-r border-slate-200 shadow-[2px_0_4px_-2px_rgba(0,0,0,0.08)]';
const stickyNameCell =
  'sticky left-0 z-10 bg-white border-r border-slate-200 shadow-[2px_0_4px_-2px_rgba(0,0,0,0.08)] group-hover:bg-brand-50/80';

function getCurrentYearMonth(): { year: number; month: number } {
  const now = new Date();
  return {
    year: now.getFullYear(),
    month: now.getMonth() + 1,
  };
}

function formatMonthInputValue(year: number, month: number): string {
  return `${year}-${String(month).padStart(2, '0')}`;
}

function parseMonthInputValue(value: string): { year: number; month: number } {
  const [yearPart, monthPart] = value.split('-');
  return {
    year: Number.parseInt(yearPart ?? '', 10),
    month: Number.parseInt(monthPart ?? '', 10),
  };
}

/** Build Mon/Tue/… + day-of-month headers for the selected calendar month. */
function getDaysInMonth(year: number, month: number): CalendarDay[] {
  const daysInMonth = new Date(year, month, 0).getDate();
  const days: CalendarDay[] = [];

  for (let date = 1; date <= daysInMonth; date += 1) {
    const weekday = new Date(year, month - 1, date).getDay();
    days.push({
      date,
      dayName: DAY_NAMES[weekday] ?? '',
    });
  }

  return days;
}

/** Resolve a day's attendance code from the backend daily_records map. */
function getDailyCode(
  dailyRecords: Record<string, string | null>,
  dayOfMonth: number,
): string {
  const code = dailyRecords[String(dayOfMonth)];

  if (code === null || code === undefined || code === '') {
    return '—';
  }

  return code;
}

function isWeekend(dayName: string): boolean {
  return dayName === 'Sat' || dayName === 'Sun';
}

/**
 * Color-code attendance statuses for the calendar grid.
 * Leave / absence codes stand out in red; office / WFH stay neutral.
 */
function getStatusColor(code: string): string {
  const normalized = code.toUpperCase();

  if (normalized === '—' || normalized === '') {
    return 'text-slate-300 font-normal';
  }

  // Present (office) or WFH — neutral dark gray
  if (normalized === 'O' || normalized === 'W') {
    return 'text-slate-700 font-medium';
  }

  // Absence (X) and leave codes (A, AL, S, N, AO, OA, …)
  return 'text-red-600 font-bold';
}

const dayCellBase =
  'min-w-[40px] w-[40px] max-w-[40px] p-1 text-center tabular-nums border border-slate-100';
const weekendBand = 'bg-yellow-50';
const weekendBandHead = 'bg-yellow-50/90';

async function fetchMonthlyReport(
  year: number,
  month: number,
  teamId: string,
): Promise<MonthlyReportData> {
  const response = await api.get<ApiSuccessResponse<MonthlyReportData>>('/reports/monthly', {
    params: {
      year,
      month,
      ...(teamId !== '' ? { team_id: teamId } : {}),
    },
  });
  return response.data.data;
}

async function fetchTeams(): Promise<TeamOption[]> {
  const response = await api.get<ApiSuccessResponse<TeamOption[]>>('/admin/teams');
  return response.data.data;
}

export default function MonthlyReport() {
  const [searchParams, setSearchParams] = useSearchParams();
  const defaults = getCurrentYearMonth();

  const teamId = searchParams.get('team_id') || '';
  const yearParam = searchParams.get('year') || String(defaults.year);
  const monthParam = searchParams.get('month') || String(defaults.month);

  const parsedYear = Number.parseInt(yearParam, 10);
  const parsedMonth = Number.parseInt(monthParam, 10);
  const reportYear = Number.isNaN(parsedYear) ? defaults.year : parsedYear;
  const reportMonth = Number.isNaN(parsedMonth) ? defaults.month : parsedMonth;

  const calendarDays = getDaysInMonth(reportYear, reportMonth);
  const columnCount = 1 + calendarDays.length + SUMMARY_HEADERS.length;

  const {
    data: report,
    isLoading,
    isError,
  } = useQuery({
    queryKey: queryKeys.reports.monthly(reportYear, reportMonth, teamId),
    queryFn: () => fetchMonthlyReport(reportYear, reportMonth, teamId),
  });

  const { data: teams = [] } = useQuery({
    queryKey: queryKeys.teams.admin,
    queryFn: fetchTeams,
  });

  const selectedTeam = teams.find((team) => String(team.id) === teamId);
  const emptyStateMessage = selectedTeam
    ? `No member assigned in ${selectedTeam.team_name}`
    : 'No members found.';

  const rows = report?.rows ?? [];

  function updateSearchParams(updates: Record<string, string>): void {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev);
      for (const [key, value] of Object.entries(updates)) {
        if (value === '') {
          next.delete(key);
        } else {
          next.set(key, value);
        }
      }
      return next;
    });
  }

  function handleMonthChange(value: string): void {
    const { year, month } = parseMonthInputValue(value);
    if (!Number.isNaN(year) && !Number.isNaN(month)) {
      updateSearchParams({
        year: String(year),
        month: String(month),
      });
    }
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight text-slate-800">
          Monthly Report
        </h1>
        <p className="mt-1 text-sm text-slate-500">
          Monthly attendance calendar by employee with leave and work-day totals.
        </p>
      </div>

      <div className="flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
        <div className="flex min-w-[12rem] flex-col gap-1.5">
          <label className="text-xs font-medium text-slate-600" htmlFor="monthly-report-month">
            Month
          </label>
          <div className="relative">
            <Calendar
              className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"
              aria-hidden="true"
            />
            <input
              id="monthly-report-month"
              type="month"
              value={formatMonthInputValue(reportYear, reportMonth)}
              onChange={(event) => handleMonthChange(event.target.value)}
              className={cn(
                'h-10 w-full rounded-md border border-slate-300 bg-white pl-10 pr-3 text-sm text-slate-900',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand',
              )}
            />
          </div>
        </div>

        <div className="flex min-w-[12rem] flex-col gap-1.5">
          <label className="text-xs font-medium text-slate-600" htmlFor="monthly-report-team">
            Team
          </label>
          <div className="relative">
            <select
              id="monthly-report-team"
              value={teamId}
              onChange={(event) => updateSearchParams({ team_id: event.target.value })}
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
        {/* Table wraps content in overflow-x-auto for horizontal scroll on 31+ day columns */}
        <Table className="min-w-max">
          <TableHeader>
            {/* Row 1: day names + summary labels */}
            <TableRow className="hover:bg-transparent">
              <TableHead
                className={cn(
                  stickyNameHead,
                  'min-w-[10rem] whitespace-nowrap px-3 text-left normal-case',
                )}
              >
                Name
              </TableHead>
              {calendarDays.map((day) => (
                <TableHead
                  key={`day-name-${day.date}`}
                  className={cn(
                    dayCellBase,
                    'h-11 normal-case tracking-normal',
                    isWeekend(day.dayName) && weekendBandHead,
                  )}
                >
                  {day.dayName}
                </TableHead>
              ))}
              {SUMMARY_HEADERS.map((label) => (
                <TableHead
                  key={label}
                  rowSpan={2}
                  className="min-w-[4.5rem] whitespace-nowrap border-l border-slate-200 px-2 text-center align-middle normal-case tracking-normal"
                >
                  {label}
                </TableHead>
              ))}
            </TableRow>

            {/* Row 2: day-of-month numbers */}
            <TableRow className="hover:bg-transparent">
              <TableHead
                aria-hidden="true"
                className={cn(stickyNameHead, 'h-9 min-w-[10rem] border-t border-slate-200 p-0')}
              />
              {calendarDays.map((day) => (
                <TableHead
                  key={`day-num-${day.date}`}
                  className={cn(
                    dayCellBase,
                    'h-9 border-t border-slate-200 normal-case tracking-normal',
                    isWeekend(day.dayName) && weekendBandHead,
                  )}
                >
                  {day.date}
                </TableHead>
              ))}
            </TableRow>
          </TableHeader>

          {isLoading ? (
            <TableBody>
              <TableRow className="hover:bg-transparent">
                <TableCell colSpan={columnCount}>
                  <div className="flex items-center justify-center gap-2 py-8 text-sm text-slate-500">
                    <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
                    Loading...
                  </div>
                </TableCell>
              </TableRow>
            </TableBody>
          ) : (
            <TableBody>
              {isError ? (
                <TableErrorRow colSpan={columnCount} />
              ) : !rows || rows.length === 0 ? (
                <TableRow>
                  <TableCell
                    colSpan={columnCount}
                    className="bg-slate-50 py-12 text-center text-sm font-medium text-slate-500"
                  >
                    {emptyStateMessage}
                  </TableCell>
                </TableRow>
              ) : (
                rows.map((row) => (
                  <TableRow key={row.user_id} className="group">
                    {/* Sticky name column */}
                    <TableCell
                      className={cn(
                        stickyNameCell,
                        'min-w-[10rem] whitespace-nowrap px-3 font-medium text-slate-900',
                      )}
                    >
                      {row.user_name}
                    </TableCell>

                    {/* Day cells — same calendarDays array as the header */}
                    {calendarDays.map((day) => {
                      const code = getDailyCode(row.daily_records, day.date);

                      return (
                        <TableCell
                          key={`${row.user_id}-day-${day.date}`}
                          className={cn(
                            dayCellBase,
                            getStatusColor(code),
                            isWeekend(day.dayName) && weekendBand,
                            isWeekend(day.dayName) && 'group-hover:bg-yellow-100/80',
                          )}
                        >
                          {code}
                        </TableCell>
                      );
                    })}

                    {/* Summary totals from backend payload */}
                    <TableCell className="border-l border-slate-200 px-2 text-center tabular-nums">
                      {row.totals.annual}
                    </TableCell>
                    <TableCell className="px-2 text-center tabular-nums">
                      {row.totals.sick}
                    </TableCell>
                    <TableCell className="px-2 text-center tabular-nums">
                      {row.totals.other}
                    </TableCell>
                    <TableCell className="px-2 text-center tabular-nums">
                      {row.total_wfh}
                    </TableCell>
                    <TableCell className="px-2 text-center tabular-nums">
                      {row.total_work_in_office}
                    </TableCell>
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
