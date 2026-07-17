import { useQuery } from '@tanstack/react-query';
import { Loader2 } from 'lucide-react';
import { Alert } from './Alert';
import { Button } from './Button';
import { SlideOver } from './SlideOver';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from './Table';
import api from '../../lib/api';
import { queryKeys } from '../../lib/queryKeys';

interface ApiSuccessResponse<T> {
  success: true;
  message: string;
  data: T;
}

interface LeaveTypeSummary {
  id: number;
  leave_type_code: string;
  name: string;
}

interface YearlyLeaveBalanceRecord {
  id?: number;
  leave_type_id: number;
  year: number;
  assigned_days: number;
  taken_days: number;
  remaining_days: number;
  leave_type: LeaveTypeSummary | null;
}

interface UserBalancePayload {
  id: number;
  name: string;
  yearly_leave_records: YearlyLeaveBalanceRecord[];
}

export interface LeaveBalanceSlideOverProps {
  isOpen: boolean;
  onClose: () => void;
  userId: number | null;
}

async function fetchUserLeaveBalances(userId: number): Promise<UserBalancePayload> {
  const response = await api.get<ApiSuccessResponse<UserBalancePayload>>(
    `/admin/users/${userId}`,
  );
  return response.data.data;
}

function formatDays(value: number): string {
  return value.toFixed(1);
}

export function LeaveBalanceSlideOver({
  isOpen,
  onClose,
  userId,
}: LeaveBalanceSlideOverProps) {
  const {
    data: user,
    isLoading,
    isError,
    isFetching,
  } = useQuery({
    queryKey: queryKeys.users.balances(userId as number),
    queryFn: () => fetchUserLeaveBalances(userId as number),
    enabled: isOpen && userId !== null,
  });

  const records = user?.yearly_leave_records ?? [];
  const title =
    user !== undefined
      ? `Leave Balances for ${user.name}`
      : 'Leave Balances';

  return (
    <SlideOver
      isOpen={isOpen}
      onClose={onClose}
      title={title}
      description="Assigned, taken, and remaining days by leave type."
    >
      <div className="flex flex-1 flex-col gap-5 p-6">
        {isLoading || (isFetching && user === undefined) ? (
          <div className="flex items-center justify-center gap-2 py-12 text-sm text-slate-500">
            <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
            Loading leave balances...
          </div>
        ) : null}

        {isError ? (
          <Alert variant="error">
            Failed to load leave balances. Please try again.
          </Alert>
        ) : null}

        {!isLoading && !isError && user !== undefined ? (
          <div className="overflow-hidden rounded-lg border border-slate-200">
            <Table>
              <TableHeader>
                <TableRow className="hover:bg-transparent">
                  <TableHead>Leave Type</TableHead>
                  <TableHead className="text-right">Assigned</TableHead>
                  <TableHead className="text-right">Taken</TableHead>
                  <TableHead className="text-right">Remaining</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {records.map((record) => {
                  const leaveName =
                    record.leave_type?.name ??
                    record.leave_type?.leave_type_code ??
                    '—';
                  const leaveCode = record.leave_type?.leave_type_code;
                  const rowKey =
                    record.id ??
                    record.leave_type_id ??
                    record.leave_type?.id ??
                    leaveCode ??
                    leaveName;

                  return (
                    <TableRow key={rowKey}>
                      <TableCell>
                        <div className="min-w-0">
                          <p className="font-medium text-slate-900">{leaveName}</p>
                          <p className="mt-0.5 text-xs text-slate-500">
                            {leaveCode !== undefined ? `${leaveCode} · ` : ''}
                            {record.year}
                          </p>
                        </div>
                      </TableCell>
                      <TableCell className="text-right tabular-nums text-slate-700">
                        {formatDays(record.assigned_days)}
                      </TableCell>
                      <TableCell className="text-right tabular-nums text-slate-700">
                        {formatDays(record.taken_days)}
                      </TableCell>
                      <TableCell className="text-right font-semibold tabular-nums text-slate-900">
                        {formatDays(record.remaining_days)}
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </div>
        ) : null}
      </div>

      <div className="mt-auto flex items-center justify-end gap-3 border-t border-slate-200 px-6 py-4">
        <Button type="button" variant="outline" onClick={onClose}>
          Close
        </Button>
      </div>
    </SlideOver>
  );
}
