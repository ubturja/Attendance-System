import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { ArchiveRestore, Loader2, Plus, Trash2 } from 'lucide-react';
import { Alert } from '../../components/ui/Alert';
import { Badge } from '../../components/ui/Badge';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { SlideOver } from '../../components/ui/SlideOver';
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
import { getApiErrorMessage } from '../../lib/errors';
import { invalidateLeaveTypeQueries, queryKeys } from '../../lib/queryKeys';
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
  is_quota_based: boolean;
}

interface CreateLeaveTypePayload {
  leave_type_code: string;
  name: string;
  is_quota_based: boolean;
}

interface UpdateLeaveTypePayload {
  is_active?: boolean;
  is_quota_based?: boolean;
}

async function fetchLeaveTypes(isArchived: boolean): Promise<LeaveTypeRecord[]> {
  const response = await api.get<ApiSuccessResponse<LeaveTypeRecord[]>>('/admin/leave-types', {
    params: isArchived ? { status: 'archived' } : undefined,
  });
  return response.data.data;
}

async function createLeaveType(payload: CreateLeaveTypePayload): Promise<LeaveTypeRecord> {
  const response = await api.post<ApiSuccessResponse<LeaveTypeRecord>>(
    '/admin/leave-types',
    payload,
  );
  return response.data.data;
}

async function updateLeaveTypeStatus(
  leaveTypeId: number,
  payload: UpdateLeaveTypePayload,
): Promise<LeaveTypeRecord> {
  const response = await api.put<ApiSuccessResponse<LeaveTypeRecord>>(
    `/admin/leave-types/${leaveTypeId}`,
    payload,
  );
  return response.data.data;
}

async function deleteLeaveType(leaveTypeId: number): Promise<void> {
  await api.delete(`/admin/leave-types/${leaveTypeId}`);
}

async function restoreLeaveType(leaveTypeId: number): Promise<LeaveTypeRecord> {
  const response = await api.patch<ApiSuccessResponse<LeaveTypeRecord>>(
    `/admin/leave-types/${leaveTypeId}/restore`,
  );
  return response.data.data;
}

function getMutationErrorMessage(error: unknown, fallback: string): string {
  return getApiErrorMessage(error, fallback);
}

function LeaveTypesTableSkeleton() {
  return (
    <TableBody>
      <TableRow className="hover:bg-transparent">
        <TableCell colSpan={5}>
          <div className="flex items-center justify-center gap-2 py-8 text-sm text-slate-500">
            <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
            Loading...
          </div>
        </TableCell>
      </TableRow>
    </TableBody>
  );
}

export default function LeaveTypes() {
  const queryClient = useQueryClient();
  const [searchParams, setSearchParams] = useSearchParams();
  const isArchived = searchParams.get('status') === 'archived';
  const [panelOpen, setPanelOpen] = useState(false);
  const [newCode, setNewCode] = useState('');
  const [newName, setNewName] = useState('');
  const [newIsQuotaBased, setNewIsQuotaBased] = useState(true);
  const [formError, setFormError] = useState<string | undefined>();
  const [actionError, setActionError] = useState<string | undefined>();
  const [actionSuccess, setActionSuccess] = useState<string | undefined>();

  const {
    data: leaveTypes = [],
    isLoading,
    isError,
  } = useQuery({
    queryKey: [...queryKeys.leaveTypes.admin, isArchived ? 'archived' : 'current'],
    queryFn: () => fetchLeaveTypes(isArchived),
  });

  const createLeaveTypeMutation = useMutation({
    mutationFn: createLeaveType,
    onSuccess: () => {
      invalidateLeaveTypeQueries(queryClient);
      handleClosePanel();
    },
    onError: (error) => {
      setFormError(
        getMutationErrorMessage(error, 'Unable to save leave type. Please try again.'),
      );
    },
  });

  const updateLeaveTypeMutation = useMutation({
    mutationFn: ({
      leaveTypeId,
      payload,
    }: {
      leaveTypeId: number;
      payload: UpdateLeaveTypePayload;
    }) => updateLeaveTypeStatus(leaveTypeId, payload),
    onSuccess: () => {
      setActionError(undefined);
      setActionSuccess(undefined);
      invalidateLeaveTypeQueries(queryClient);
    },
    onError: (error) => {
      setActionSuccess(undefined);
      setActionError(
        getMutationErrorMessage(error, 'Unable to update leave type. Please try again.'),
      );
    },
  });

  const deleteLeaveTypeMutation = useMutation({
    mutationFn: deleteLeaveType,
    onSuccess: () => {
      setActionError(undefined);
      setActionSuccess('Leave Type successfully archived.');
      invalidateLeaveTypeQueries(queryClient);
    },
    onError: (error) => {
      setActionSuccess(undefined);
      setActionError(
        getMutationErrorMessage(error, 'Unable to archive leave type. Please try again.'),
      );
    },
  });

  const restoreLeaveTypeMutation = useMutation({
    mutationFn: restoreLeaveType,
    onSuccess: () => {
      setActionError(undefined);
      setActionSuccess('Leave Type restored.');
      invalidateLeaveTypeQueries(queryClient);
    },
    onError: (error) => {
      setActionSuccess(undefined);
      setActionError(
        getMutationErrorMessage(error, 'Unable to restore leave type. Please try again.'),
      );
    },
  });

  function handleClosePanel() {
    setPanelOpen(false);
    setNewCode('');
    setNewName('');
    setNewIsQuotaBased(true);
    setFormError(undefined);
  }

  function handleCreateLeaveType() {
    setFormError(undefined);
    createLeaveTypeMutation.mutate({
      leave_type_code: newCode.trim(),
      name: newName.trim(),
      is_quota_based: newIsQuotaBased,
    });
  }

  function toggleActive(leaveType: LeaveTypeRecord) {
    setActionError(undefined);
    setActionSuccess(undefined);
    updateLeaveTypeMutation.mutate({
      leaveTypeId: leaveType.id,
      payload: { is_active: !leaveType.is_active },
    });
  }

  function toggleQuotaBased(leaveType: LeaveTypeRecord) {
    setActionError(undefined);
    setActionSuccess(undefined);
    updateLeaveTypeMutation.mutate({
      leaveTypeId: leaveType.id,
      payload: { is_quota_based: !leaveType.is_quota_based },
    });
  }

  function handleDeleteLeaveType(leaveType: LeaveTypeRecord) {
    setActionError(undefined);
    setActionSuccess(undefined);
    deleteLeaveTypeMutation.mutate(leaveType.id);
  }

  function handleRestoreLeaveType(leaveType: LeaveTypeRecord) {
    setActionError(undefined);
    setActionSuccess(undefined);
    restoreLeaveTypeMutation.mutate(leaveType.id);
  }

  function handleToggleArchiveView() {
    setActionError(undefined);
    setActionSuccess(undefined);

    if (isArchived) {
      setSearchParams({});
      return;
    }

    setSearchParams({ status: 'archived' });
  }

  const isSaving = createLeaveTypeMutation.isPending;
  const togglingLeaveTypeId = updateLeaveTypeMutation.isPending
    ? updateLeaveTypeMutation.variables?.leaveTypeId
    : undefined;
  const deletingLeaveTypeId = deleteLeaveTypeMutation.isPending
    ? deleteLeaveTypeMutation.variables
    : undefined;
  const restoringLeaveTypeId = restoreLeaveTypeMutation.isPending
    ? restoreLeaveTypeMutation.variables
    : undefined;
  const isRowBusy =
    updateLeaveTypeMutation.isPending ||
    deleteLeaveTypeMutation.isPending ||
    restoreLeaveTypeMutation.isPending;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight text-slate-800">
            Leave Types
          </h1>
          <p className="mt-1 text-sm text-slate-500">
            {isArchived
              ? 'Archived leave codes retained for historical reports. Restore to use them again.'
              : 'Manage dynamic leave codes used in attendance and yearly balances.'}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button type="button" variant="outline" size="md" onClick={handleToggleArchiveView}>
            {isArchived ? 'Back to Active' : 'View Archived'}
          </Button>
          {!isArchived ? (
            <Button type="button" variant="primary" size="md" onClick={() => setPanelOpen(true)}>
              <Plus className="h-4 w-4" aria-hidden="true" />
              Add New Leave Type
            </Button>
          ) : null}
        </div>
      </div>

      {actionSuccess !== undefined ? (
        <Alert variant="success">{actionSuccess}</Alert>
      ) : null}

      {actionError !== undefined ? (
        <Alert variant="error">{actionError}</Alert>
      ) : null}

      <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <Table>
          <TableHeader>
            <TableRow className="hover:bg-transparent">
              <TableHead>Leave Code</TableHead>
              <TableHead>Name</TableHead>
              <TableHead className="w-40">Status</TableHead>
              <TableHead className="w-40">Quota Based</TableHead>
              <TableHead className="w-28">
                <span className="sr-only">Actions</span>
              </TableHead>
            </TableRow>
          </TableHeader>
          {isLoading ? (
            <LeaveTypesTableSkeleton />
          ) : (
            <TableBody>
              {isError ? (
                <TableErrorRow colSpan={5} />
              ) : leaveTypes.length === 0 ? (
                <TableRow className="hover:bg-transparent">
                  <TableCell colSpan={5} className="py-8 text-center text-sm text-slate-500">
                    {isArchived ? 'No archived leave types found.' : 'No leave types found.'}
                  </TableCell>
                </TableRow>
              ) : (
                leaveTypes.map((row) => (
                  <TableRow key={row.id}>
                    <TableCell className="font-mono font-medium text-slate-900">
                      {row.leave_type_code}
                    </TableCell>
                    <TableCell>{row.name}</TableCell>
                    <TableCell className="align-middle">
                      {isArchived ? (
                        <Badge variant="inactive">Archived</Badge>
                      ) : (
                        <div className="flex items-center gap-3">
                          <button
                            type="button"
                            role="switch"
                            aria-checked={row.is_active}
                            aria-label={`Toggle ${row.name} ${row.is_active ? 'off' : 'on'}`}
                            disabled={isRowBusy}
                            onClick={() => toggleActive(row)}
                            className={cn(
                              'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full p-0.5 transition-colors',
                              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2',
                              'disabled:cursor-not-allowed disabled:opacity-60',
                              row.is_active ? 'bg-brand' : 'bg-slate-300',
                            )}
                          >
                            <span
                              className={cn(
                                'pointer-events-none block h-5 w-5 shrink-0 rounded-full bg-white shadow transition-transform',
                                row.is_active ? 'translate-x-5' : 'translate-x-0',
                                togglingLeaveTypeId === row.id && 'opacity-70',
                              )}
                            />
                          </button>
                          <Badge variant={row.is_active ? 'active' : 'inactive'}>
                            {togglingLeaveTypeId === row.id
                              ? 'Saving...'
                              : row.is_active
                                ? 'Active'
                                : 'Inactive'}
                          </Badge>
                        </div>
                      )}
                    </TableCell>
                    <TableCell className="align-middle">
                      {isArchived ? (
                        <span className="text-sm text-slate-500">
                          {row.is_quota_based ? 'Yes' : 'No'}
                        </span>
                      ) : (
                        <div className="flex items-center gap-3">
                          <button
                            type="button"
                            role="switch"
                            aria-checked={row.is_quota_based}
                            aria-label={`Toggle ${row.name} quota based ${row.is_quota_based ? 'off' : 'on'}`}
                            disabled={isRowBusy}
                            onClick={() => toggleQuotaBased(row)}
                            className={cn(
                              'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full p-0.5 transition-colors',
                              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2',
                              'disabled:cursor-not-allowed disabled:opacity-60',
                              row.is_quota_based ? 'bg-brand' : 'bg-slate-300',
                            )}
                          >
                            <span
                              className={cn(
                                'pointer-events-none block h-5 w-5 shrink-0 rounded-full bg-white shadow transition-transform',
                                row.is_quota_based ? 'translate-x-5' : 'translate-x-0',
                                togglingLeaveTypeId === row.id && 'opacity-70',
                              )}
                            />
                          </button>
                          <span className="text-sm text-slate-600">
                            {row.is_quota_based ? 'Yes' : 'No'}
                          </span>
                        </div>
                      )}
                    </TableCell>
                    <TableCell className="align-middle">
                      {isArchived ? (
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          disabled={isRowBusy}
                          aria-label={`Restore ${row.name}`}
                          onClick={() => handleRestoreLeaveType(row)}
                          className="text-brand-600 hover:bg-brand-50 hover:text-brand"
                        >
                          <ArchiveRestore className="h-4 w-4" aria-hidden="true" />
                          {restoringLeaveTypeId === row.id ? 'Restoring...' : 'Restore'}
                        </Button>
                      ) : (
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          disabled={isRowBusy}
                          aria-label={`Delete ${row.name}`}
                          onClick={() => handleDeleteLeaveType(row)}
                          className="text-red-700 hover:bg-red-50 hover:text-red-800"
                        >
                          <Trash2 className="h-4 w-4" aria-hidden="true" />
                          {deletingLeaveTypeId === row.id ? 'Archiving...' : 'Delete'}
                        </Button>
                      )}
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          )}
        </Table>
      </div>

      <SlideOver
        isOpen={panelOpen}
        onClose={handleClosePanel}
        title="Add New Leave Type"
        description="Define a leave code and display name for attendance dropdowns."
      >
        <div className="flex flex-1 flex-col gap-5 p-6">
          {formError !== undefined ? (
            <Alert variant="error">{formError}</Alert>
          ) : null}

          <Input
            id="leave-type-code"
            label="Leave Code"
            placeholder="e.g. A, S, N"
            value={newCode}
            disabled={isSaving}
            onChange={(event) => {
              setNewCode(event.target.value);
              if (formError !== undefined) {
                setFormError(undefined);
              }
            }}
          />
          <Input
            id="leave-type-name"
            label="Name"
            placeholder="e.g. Annual Leave"
            value={newName}
            disabled={isSaving}
            onChange={(event) => {
              setNewName(event.target.value);
              if (formError !== undefined) {
                setFormError(undefined);
              }
            }}
          />

          <label
            htmlFor="leave-type-quota-based"
            className="flex cursor-pointer items-start gap-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-3"
          >
            <input
              id="leave-type-quota-based"
              type="checkbox"
              className="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand focus:ring-brand"
              checked={newIsQuotaBased}
              disabled={isSaving}
              onChange={(event) => setNewIsQuotaBased(event.target.checked)}
            />
            <span className="flex flex-col gap-0.5">
              <span className="text-sm font-medium text-slate-800">Is Quota Based?</span>
              <span className="text-xs text-slate-500">
                When enabled, this type appears in Assign Leave and yearly balances.
                Turn off for attendance-only statuses like Work from Home.
              </span>
            </span>
          </label>
        </div>

        <div className="mt-auto flex items-center justify-end gap-3 border-t border-slate-200 px-6 py-4">
          <Button type="button" variant="outline" onClick={handleClosePanel} disabled={isSaving}>
            Cancel
          </Button>
          <Button
            type="button"
            variant="primary"
            onClick={handleCreateLeaveType}
            disabled={isSaving}
          >
            {isSaving ? 'Saving...' : 'Save'}
          </Button>
        </div>
      </SlideOver>
    </div>
  );
}
