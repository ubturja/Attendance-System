import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Loader2, Plus } from 'lucide-react';
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
}

interface CreateLeaveTypePayload {
  leave_type_code: string;
  name: string;
}

interface UpdateLeaveTypePayload {
  is_active: boolean;
}

async function fetchLeaveTypes(): Promise<LeaveTypeRecord[]> {
  const response = await api.get<ApiSuccessResponse<LeaveTypeRecord[]>>('/admin/leave-types');
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

function getMutationErrorMessage(error: unknown, fallback: string): string {
  return getApiErrorMessage(error, fallback);
}

function LeaveTypesTableSkeleton() {
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

export default function LeaveTypes() {
  const queryClient = useQueryClient();
  const [panelOpen, setPanelOpen] = useState(false);
  const [newCode, setNewCode] = useState('');
  const [newName, setNewName] = useState('');
  const [formError, setFormError] = useState<string | undefined>();
  const [actionError, setActionError] = useState<string | undefined>();

  const {
    data: leaveTypes = [],
    isLoading,
    isError,
  } = useQuery({
    queryKey: queryKeys.leaveTypes.admin,
    queryFn: fetchLeaveTypes,
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
      is_active,
    }: {
      leaveTypeId: number;
      is_active: boolean;
    }) => updateLeaveTypeStatus(leaveTypeId, { is_active }),
    onSuccess: () => {
      setActionError(undefined);
      invalidateLeaveTypeQueries(queryClient);
    },
    onError: (error) => {
      setActionError(
        getMutationErrorMessage(error, 'Unable to update leave type status. Please try again.'),
      );
    },
  });

  function handleClosePanel() {
    setPanelOpen(false);
    setNewCode('');
    setNewName('');
    setFormError(undefined);
  }

  function handleCreateLeaveType() {
    setFormError(undefined);
    createLeaveTypeMutation.mutate({
      leave_type_code: newCode.trim(),
      name: newName.trim(),
    });
  }

  function toggleActive(leaveType: LeaveTypeRecord) {
    updateLeaveTypeMutation.mutate({
      leaveTypeId: leaveType.id,
      is_active: !leaveType.is_active,
    });
  }

  const isSaving = createLeaveTypeMutation.isPending;

  return (
    <div className="space-y-6">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight text-slate-900">
            Leave Types
          </h1>
          <p className="mt-1 text-sm text-slate-500">
            Manage dynamic leave codes used in attendance and yearly balances.
          </p>
        </div>
        <Button type="button" variant="primary" size="md" onClick={() => setPanelOpen(true)}>
          <Plus className="h-4 w-4" aria-hidden="true" />
          Add New Leave Type
        </Button>
      </div>

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
            </TableRow>
          </TableHeader>
          {isLoading ? (
            <LeaveTypesTableSkeleton />
          ) : (
            <TableBody>
              {isError ? (
                <TableErrorRow colSpan={3} />
              ) : leaveTypes.length === 0 ? (
                <TableRow className="hover:bg-transparent">
                  <TableCell colSpan={3} className="py-8 text-center text-sm text-slate-500">
                    No leave types found.
                  </TableCell>
                </TableRow>
              ) : (
                leaveTypes.map((row) => (
                  <TableRow key={row.id}>
                    <TableCell className="font-mono font-medium text-slate-900">
                      {row.leave_type_code}
                    </TableCell>
                    <TableCell>{row.name}</TableCell>
                    <TableCell>
                      <div className="flex items-center gap-3">
                        <button
                          type="button"
                          role="switch"
                          aria-checked={row.is_active}
                          aria-label={`Toggle ${row.name}`}
                          disabled={updateLeaveTypeMutation.isPending}
                          onClick={() => toggleActive(row)}
                          className={cn(
                            'relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors',
                            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400 focus-visible:ring-offset-2',
                            'disabled:cursor-not-allowed disabled:opacity-60',
                            row.is_active ? 'bg-emerald-500' : 'bg-slate-300',
                          )}
                        >
                          <span
                            className={cn(
                              'pointer-events-none inline-block h-5 w-5 translate-y-0.5 rounded-full bg-white shadow transition-transform',
                              row.is_active ? 'translate-x-5' : 'translate-x-0.5',
                            )}
                          />
                        </button>
                        <Badge variant={row.is_active ? 'active' : 'inactive'}>
                          {row.is_active ? 'Active' : 'Inactive'}
                        </Badge>
                      </div>
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
