import { useQuery } from '@tanstack/react-query';
import { Calendar, ChevronDown, Download, Loader2 } from 'lucide-react';
import { useState } from 'react';
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
  days: Record<string, string | null>;
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

async function fetchMonthlyReport(year: number, month: number): Promise<MonthlyReportData> {
  const response = await api.get<ApiSuccessResponse<MonthlyReportData>>('/reports/monthly', {
    params: { year, month },
  });
  return response.data.data;
}

function MonthlyReportTableSkeleton() {
  return (
    <TableBody>
      <TableRow className="hover:bg-transparent">
        <TableCell colSpan={7}>
          <div className="flex items-center justify-center gap-2 py-8 text-sm text-slate-500">
            <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
            Loading...
          </div>
        </TableCell>
      </TableRow>
    </TableBody>
  );
}

export default function MonthlyReport() {
  const initialPeriod = getCurrentYearMonth();
  const [reportYear, setReportYear] = useState(initialPeriod.year);
  const [reportMonth, setReportMonth] = useState(initialPeriod.month);

  const {
    data: report,
    isLoading,
    isError,
  } = useQuery({
    queryKey: ['reports', 'monthly', reportYear, reportMonth] as const,
    queryFn: () => fetchMonthlyReport(reportYear, reportMonth),
  });

  const rows = report?.rows ?? [];

  function handleMonthChange(value: string) {
    const { year, month } = parseMonthInputValue(value);
    if (!Number.isNaN(year) && !Number.isNaN(month)) {
      setReportYear(year);
      setReportMonth(month);
    }
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight text-slate-800">
          Monthly Report
        </h1>
        <p className="mt-1 text-sm text-slate-500">
          Monthly leave totals by employee (Annual, Sick, Other).
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
              <TableHead>Annual Leave</TableHead>
              <TableHead>Sick Leave</TableHead>
              <TableHead>Other Leave</TableHead>
              <TableHead>Total WFH</TableHead>
              <TableHead>Total Office Days</TableHead>
            </TableRow>
          </TableHeader>
          {isLoading ? (
            <MonthlyReportTableSkeleton />
          ) : (
            <TableBody>
              {isError ? (
                <TableErrorRow colSpan={7} />
              ) : rows.length === 0 ? (
                <TableRow className="hover:bg-transparent">
                  <TableCell colSpan={7} className="py-8 text-center text-sm text-slate-500">
                    No monthly report data found for this period.
                  </TableCell>
                </TableRow>
              ) : (
                rows.map((row) => (
                  <TableRow key={row.user_id}>
                    <TableCell className="font-medium text-slate-900">{row.user_name}</TableCell>
                    <TableCell>{row.team_name ?? '—'}</TableCell>
                    <TableCell className="tabular-nums">{row.totals.annual}</TableCell>
                    <TableCell className="tabular-nums">{row.totals.sick}</TableCell>
                    <TableCell className="tabular-nums">{row.totals.other}</TableCell>
                    <TableCell className="tabular-nums">{row.total_wfh}</TableCell>
                    <TableCell className="tabular-nums">{row.total_work_in_office}</TableCell>
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
