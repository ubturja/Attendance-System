import { useMutation, useQuery, useQueryClient, type QueryClient } from '@tanstack/react-query';
import { ArchiveRestore, Loader2, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Alert } from './ui/Alert';
import { Badge } from './ui/Badge';
import { Button } from './ui/Button';
import { SlideOver } from './ui/SlideOver';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from './ui/Table';
import { TableErrorRow } from './ui/TableErrorRow';
import {
  deleteHoliday,
  getHolidays,
  restoreHoliday,
  type Holiday,
  type HolidayType,
} from '../lib/api';
import { getApiErrorMessage } from '../lib/errors';
import { queryKeys } from '../lib/queryKeys';

export interface HolidayListAdminProps {
  isOpen: boolean;
  onClose: () => void;
  /** Opens the existing create/edit SlideOver for an active holiday. */
  onEdit: (holiday: Holiday) => void;
}

function isHolidayDeleted(holiday: Holiday): boolean {
  return holiday.deleted_at != null && holiday.deleted_at !== '';
}

function formatHolidayDate(isoDate: string): string {
  const parsed = new Date(`${isoDate.slice(0, 10)}T00:00:00`);

  if (Number.isNaN(parsed.getTime())) {
    return isoDate;
  }

  return new Intl.DateTimeFormat(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  }).format(parsed);
}

function typeLabel(type: HolidayType | undefined): string {
  return type === 'hong_kong' ? 'HK' : 'MY';
}

function invalidateHolidayCaches(queryClient: QueryClient): void {
  void queryClient.invalidateQueries({ queryKey: queryKeys.holidays.all });
  void queryClient.invalidateQueries({ queryKey: queryKeys.profile });
}

/**
 * Admin SlideOver listing all holidays (including soft-deleted).
 * Active rows: Edit / Delete. Deleted rows: Restore only.
 */
export function HolidayListAdmin({ isOpen, onClose, onEdit }: HolidayListAdminProps) {
  const queryClient = useQueryClient();
  const [actionError, setActionError] = useState<string | undefined>();
  const [actionSuccess, setActionSuccess] = useState<string | undefined>();

  const {
    data: holidays = [],
    isLoading,
    isError,
  } = useQuery({
    queryKey: queryKeys.holidays.admin,
    queryFn: () => getHolidays(true),
    enabled: isOpen,
  });

  const deleteMutation = useMutation({
    mutationFn: deleteHoliday,
    onSuccess: () => {
      setActionError(undefined);
      setActionSuccess('Holiday deleted successfully.');
      invalidateHolidayCaches(queryClient);
    },
    onError: (error) => {
      setActionSuccess(undefined);
      setActionError(getApiErrorMessage(error, 'Unable to delete holiday. Please try again.'));
    },
  });

  const restoreMutation = useMutation({
    mutationFn: restoreHoliday,
    onSuccess: () => {
      setActionError(undefined);
      setActionSuccess('Holiday restored.');
      invalidateHolidayCaches(queryClient);
    },
    onError: (error) => {
      setActionSuccess(undefined);
      setActionError(getApiErrorMessage(error, 'Unable to restore holiday. Please try again.'));
    },
  });

  const isRowBusy = deleteMutation.isPending || restoreMutation.isPending;
  const busyHolidayId = deleteMutation.isPending
    ? deleteMutation.variables
    : restoreMutation.isPending
      ? restoreMutation.variables
      : null;

  function handleClose() {
    setActionError(undefined);
    setActionSuccess(undefined);
    onClose();
  }

  function handleDelete(holiday: Holiday) {
    const confirmed = window.confirm(
      `Delete "${holiday.name}" on ${formatHolidayDate(holiday.date)}? You can restore it later from this list.`,
    );

    if (!confirmed) {
      return;
    }

    setActionError(undefined);
    setActionSuccess(undefined);
    deleteMutation.mutate(holiday.id);
  }

  function handleRestore(holiday: Holiday) {
    setActionError(undefined);
    setActionSuccess(undefined);
    restoreMutation.mutate(holiday.id);
  }

  function handleEdit(holiday: Holiday) {
    onEdit(holiday);
    handleClose();
  }

  return (
    <SlideOver
      isOpen={isOpen}
      onClose={handleClose}
      title="All Holidays"
      description="Manage active and deleted company holidays. Deleted holidays can be restored."
    >
      <div className="flex flex-1 flex-col gap-4 p-6">
        {actionSuccess !== undefined ? (
          <Alert variant="success">{actionSuccess}</Alert>
        ) : null}

        {actionError !== undefined ? <Alert variant="error">{actionError}</Alert> : null}

        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          <Table>
            <TableHeader>
              <TableRow className="hover:bg-transparent">
                <TableHead>Date</TableHead>
                <TableHead>Name</TableHead>
                <TableHead className="w-20">Type</TableHead>
                <TableHead className="w-28">Status</TableHead>
                <TableHead className="w-40">
                  <span className="sr-only">Actions</span>
                </TableHead>
              </TableRow>
            </TableHeader>
            {isLoading ? (
              <TableBody>
                <TableRow className="hover:bg-transparent">
                  <TableCell colSpan={5} className="py-10 text-center text-sm text-slate-500">
                    <span className="inline-flex items-center gap-2">
                      <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
                      Loading holidays...
                    </span>
                  </TableCell>
                </TableRow>
              </TableBody>
            ) : (
              <TableBody>
                {isError ? (
                  <TableErrorRow
                    colSpan={5}
                    message="Failed to load holidays. Please try again."
                  />
                ) : holidays.length === 0 ? (
                  <TableRow className="hover:bg-transparent">
                    <TableCell colSpan={5} className="py-8 text-center text-sm text-slate-500">
                      No holidays found.
                    </TableCell>
                  </TableRow>
                ) : (
                  holidays.map((holiday) => {
                    const deleted = isHolidayDeleted(holiday);
                    const rowBusy = isRowBusy && busyHolidayId === holiday.id;

                    return (
                      <TableRow key={holiday.id}>
                        <TableCell className="whitespace-nowrap font-medium text-slate-900">
                          {formatHolidayDate(holiday.date)}
                        </TableCell>
                        <TableCell>
                          <div className="min-w-0">
                            <p className="truncate font-medium text-slate-800">{holiday.name}</p>
                            {holiday.description ? (
                              <p className="mt-0.5 truncate text-xs text-slate-500">
                                {holiday.description}
                              </p>
                            ) : null}
                          </div>
                        </TableCell>
                        <TableCell>
                          <Badge variant={holiday.type === 'hong_kong' ? 'warning' : 'neutral'}>
                            {typeLabel(holiday.type)}
                          </Badge>
                        </TableCell>
                        <TableCell>
                          {deleted ? (
                            <Badge variant="inactive">Deleted</Badge>
                          ) : (
                            <Badge variant="active">Active</Badge>
                          )}
                        </TableCell>
                        <TableCell>
                          {deleted ? (
                            <Button
                              type="button"
                              variant="ghost"
                              size="sm"
                              disabled={isRowBusy}
                              aria-label={`Restore ${holiday.name}`}
                              onClick={() => handleRestore(holiday)}
                              className="text-brand-600 hover:bg-brand-50 hover:text-brand"
                            >
                              <ArchiveRestore className="h-4 w-4" aria-hidden="true" />
                              {rowBusy && restoreMutation.isPending ? 'Restoring...' : 'Restore'}
                            </Button>
                          ) : (
                            <div className="flex items-center gap-1">
                              <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                disabled={isRowBusy}
                                aria-label={`Edit ${holiday.name}`}
                                onClick={() => handleEdit(holiday)}
                                className="text-slate-600 hover:bg-brand-50 hover:text-brand"
                              >
                                <Pencil className="h-4 w-4" aria-hidden="true" />
                                Edit
                              </Button>
                              <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                disabled={isRowBusy}
                                aria-label={`Delete ${holiday.name}`}
                                onClick={() => handleDelete(holiday)}
                                className="text-red-700 hover:bg-red-50 hover:text-red-800"
                              >
                                <Trash2 className="h-4 w-4" aria-hidden="true" />
                                {rowBusy && deleteMutation.isPending ? 'Deleting...' : 'Delete'}
                              </Button>
                            </div>
                          )}
                        </TableCell>
                      </TableRow>
                    );
                  })
                )}
              </TableBody>
            )}
          </Table>
        </div>
      </div>
    </SlideOver>
  );
}
