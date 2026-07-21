import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Alert } from './Alert';
import { Button } from './Button';
import { Input } from './Input';
import { SlideOver } from './SlideOver';
import api from '../../lib/api';
import { getApiErrorMessage } from '../../lib/errors';
import {
  invalidateReportQueries,
  queryKeys,
} from '../../lib/queryKeys';

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

interface AssignLeavePayload {
  year: number;
  allocations: Array<{
    leave_type_id: number;
    assigned_days: number;
  }>;
}

interface AssignLeaveVariables {
  userId: number;
  payload: AssignLeavePayload;
}

export interface AssignLeaveUser {
  id: number;
  name: string;
}

export interface AssignLeaveSlideOverProps {
  isOpen: boolean;
  onClose: () => void;
  user: AssignLeaveUser | null;
}

function getCurrentYear(): number {
  return new Date().getFullYear();
}

async function fetchUserLeaveBalances(
  userId: number,
  year: number,
): Promise<UserBalancePayload> {
  const response = await api.get<ApiSuccessResponse<UserBalancePayload>>(
    `/admin/users/${userId}`,
    { params: { year } },
  );
  return response.data.data;
}

async function assignLeaveAllocations({
  userId,
  payload,
}: AssignLeaveVariables): Promise<void> {
  await api.put<ApiSuccessResponse<unknown>>(`/admin/leave-allocations/${userId}`, payload);
}

function isValidAllocationYear(year: number): boolean {
  return !Number.isNaN(year) && year >= 2000 && year <= 2100;
}

export function AssignLeaveSlideOver({
  isOpen,
  onClose,
  user,
}: AssignLeaveSlideOverProps) {
  const queryClient = useQueryClient();
  const [year, setYear] = useState(getCurrentYear);
  const [allocations, setAllocations] = useState<Record<number, number | ''>>({});
  const [formError, setFormError] = useState<string | undefined>();

  const yearIsValid = isValidAllocationYear(year);

  const balancesQuery = useQuery({
    queryKey:
      user !== null && yearIsValid
        ? queryKeys.users.balances(user.id, year)
        : ['admin', 'user', 'idle', 'balances'],
    queryFn: () => fetchUserLeaveBalances(user!.id, year),
    enabled: isOpen && user !== null && yearIsValid,
  });

  const leaveBalanceRows = balancesQuery.data?.yearly_leave_records ?? [];

  // Prefill assigned-days from the year-scoped zero-merged backend balance sheet.
  useEffect(() => {
    if (!isOpen || balancesQuery.data === undefined) {
      return;
    }

    const records = balancesQuery.data.yearly_leave_records;
    const nextAllocations: Record<number, number | ''> = {};

    for (const record of records) {
      nextAllocations[record.leave_type_id] = record.assigned_days;
    }

    setAllocations(nextAllocations);
  }, [isOpen, balancesQuery.data]);

  // Reset local form state whenever the panel closes or the target user changes.
  useEffect(() => {
    if (!isOpen) {
      setYear(getCurrentYear());
      setAllocations({});
      setFormError(undefined);
    }
  }, [isOpen, user?.id]);

  const assignLeaveMutation = useMutation({
    mutationFn: assignLeaveAllocations,
    onSuccess: (_data, variables) => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.users.admin });
      void queryClient.invalidateQueries({
        queryKey: ['admin', 'user', variables.userId, 'balances'],
      });
      invalidateReportQueries(queryClient);
      onClose();
    },
    onError: (error) => {
      setFormError(
        getApiErrorMessage(error, 'Unable to assign leave allocation. Please try again.'),
      );
    },
  });

  function handleYearChange(value: string): void {
    const parsed = Number.parseInt(value, 10);
    setYear(Number.isNaN(parsed) ? Number.NaN : parsed);
    // Drop Year N prefill immediately so it cannot leak into Year N+1 while refetching.
    setAllocations({});
    if (formError !== undefined) {
      setFormError(undefined);
    }
  }

  function handleAllocationChange(leaveTypeId: number, value: string): void {
    // Whole days only — block decimals at the UI layer (DB still supports 0.5 taken_days).
    if (value.includes('.')) {
      return;
    }

    if (value === '') {
      setAllocations((previous) => ({
        ...previous,
        [leaveTypeId]: '',
      }));
    } else {
      const parsed = Math.floor(Number(value));
      if (Number.isNaN(parsed)) {
        return;
      }
      setAllocations((previous) => ({
        ...previous,
        [leaveTypeId]: parsed,
      }));
    }

    if (formError !== undefined) {
      setFormError(undefined);
    }
  }

  function handleSubmit(): void {
    if (user === null) {
      return;
    }

    setFormError(undefined);

    if (Number.isNaN(year) || year < 2000 || year > 2100) {
      setFormError('Please enter a valid year between 2000 and 2100.');
      return;
    }

    const payloadAllocations: AssignLeavePayload['allocations'] = [];

    for (const [id, days] of Object.entries(allocations)) {
      const assignedDays = days === '' ? Number.NaN : Number(days);

      if (
        Number.isNaN(assignedDays) ||
        assignedDays < 0 ||
        !Number.isInteger(assignedDays)
      ) {
        setFormError(
          'Assigned days must be a whole number (0 or greater) for every leave type.',
        );
        return;
      }

      payloadAllocations.push({
        leave_type_id: Number(id),
        assigned_days: assignedDays,
      });
    }

    if (payloadAllocations.length === 0) {
      setFormError('No leave types available to assign.');
      return;
    }

    assignLeaveMutation.mutate({
      userId: user.id,
      payload: {
        year,
        allocations: payloadAllocations,
      },
    });
  }

  const isAssigning = assignLeaveMutation.isPending;
  const isFormLoading = balancesQuery.isLoading || balancesQuery.isFetching;

  return (
    <SlideOver
      isOpen={isOpen}
      onClose={onClose}
      title="Assign Leave Allocation"
      description={
        user !== null
          ? `Set yearly leave quotas for ${user.name}.`
          : 'Set yearly leave quotas for this user.'
      }
    >
      <div className="flex flex-1 flex-col gap-5 p-6">
        {formError !== undefined ? <Alert variant="error">{formError}</Alert> : null}

        <Input
          id="allocation-year"
          label="Year"
          type="number"
          min={2000}
          max={2100}
          placeholder="e.g. 2026"
          value={Number.isNaN(year) ? '' : year}
          disabled={isAssigning}
          onChange={(event) => handleYearChange(event.target.value)}
        />

        <div className="flex flex-col gap-3">
          <div className="flex items-center justify-between gap-2">
            <p className="text-sm font-medium text-slate-700">Leave type quotas</p>
            {isFormLoading ? (
              <span className="inline-flex items-center gap-1.5 text-xs text-slate-500">
                <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden="true" />
                Loading...
              </span>
            ) : null}
          </div>

          {balancesQuery.isError ? (
            <Alert variant="error">Failed to load leave balances. Please try again.</Alert>
          ) : null}

          {!balancesQuery.isError && leaveBalanceRows.length === 0 && !isFormLoading ? (
            <p className="text-sm text-slate-500">No active leave types found.</p>
          ) : null}

          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            {leaveBalanceRows.map((record) => {
              const leaveTypeId = record.leave_type_id;
              const leaveName =
                record.leave_type?.name ??
                record.leave_type?.leave_type_code ??
                '—';
              const leaveCode = record.leave_type?.leave_type_code;
              const inputId = `allocation-days-${leaveTypeId}`;
              const value = allocations[leaveTypeId] ?? '';
              const rowKey = record.id ?? leaveTypeId;

              return (
                <div
                  key={rowKey}
                  className="flex min-w-0 items-center gap-3 rounded-lg border border-slate-200 bg-white px-4 py-3"
                >
                  <div className="min-w-0 flex-1">
                    <label
                      htmlFor={inputId}
                      className="block truncate text-sm font-medium text-slate-900"
                    >
                      {leaveName}
                    </label>
                    {leaveCode !== undefined ? (
                      <p className="mt-0.5 truncate text-xs text-slate-500">{leaveCode}</p>
                    ) : null}
                  </div>
                  <div className="w-24 shrink-0 sm:w-28">
                    <Input
                      id={inputId}
                      type="number"
                      min={0}
                      step={1}
                      aria-label={`Assigned days for ${leaveName}`}
                      value={value}
                      disabled={isAssigning || isFormLoading}
                      onChange={(event) =>
                        handleAllocationChange(leaveTypeId, event.target.value)
                      }
                    />
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      </div>

      <div className="mt-auto flex items-center justify-end gap-3 border-t border-slate-200 px-6 py-4">
        <Button type="button" variant="outline" onClick={onClose} disabled={isAssigning}>
          Cancel
        </Button>
        <Button
          type="button"
          variant="primary"
          onClick={handleSubmit}
          disabled={isAssigning || isFormLoading || leaveBalanceRows.length === 0}
        >
          {isAssigning ? 'Assigning...' : 'Assign Leave'}
        </Button>
      </div>
    </SlideOver>
  );
}
