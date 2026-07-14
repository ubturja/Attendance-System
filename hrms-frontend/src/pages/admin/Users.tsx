import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { CalendarPlus, ChevronDown, Loader2, Pencil, Plus } from 'lucide-react';
import { Alert } from '../../components/ui/Alert';
import { Badge } from '../../components/ui/Badge';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { LeaveBalanceSlideOver } from '../../components/ui/LeaveBalanceSlideOver';
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
import {
  invalidateReportQueries,
  queryKeys,
} from '../../lib/queryKeys';
import { cn } from '../../lib/utils';

interface ApiSuccessResponse<T> {
  success: true;
  message: string;
  data: T;
}

type UserJobTitle = 'Admin' | 'Employee';

interface UserTeam {
  id: number;
  team_name: string;
  team_leader_id: number | null;
}

interface UserLeaveType {
  id: number;
  leave_type_code: string;
  name: string;
  is_active: boolean;
}

interface UserYearlyLeaveRecord {
  id: number;
  user_id: number;
  leave_type_id: number;
  year: number;
  assigned_days: number;
  taken_days: number;
  remaining_days: number;
  leave_type: UserLeaveType | null;
}

interface UserRecord {
  id: number;
  name: string;
  email: string;
  job_title: UserJobTitle;
  nationality: string | null;
  passport_number: string;
  phone_number: string | null;
  address: string | null;
  work_type: string | null;
  is_active: boolean;
  team_id: number | null;
  team: UserTeam | null;
  yearly_leave_records: UserYearlyLeaveRecord[];
}

interface TeamOption {
  id: number;
  team_name: string;
}

interface CreateUserPayload {
  name: string;
  email: string;
  password: string;
  job_title: UserJobTitle;
  passport_number: string;
  team_id: number | null;
}

interface UpdateUserPayload {
  job_title: UserJobTitle;
  team_id: number | null;
}

interface SaveUserVariables {
  userId?: number;
  payload: CreateUserPayload | UpdateUserPayload;
}

interface CreateUserFormState {
  name: string;
  email: string;
  password: string;
  role: UserJobTitle;
  passport_number: string;
  team_id: string;
}

interface LeaveTypeOption {
  id: number;
  leave_type_code: string;
  name: string;
  is_active: boolean;
}

interface AssignLeavePayload {
  leave_type_id: number;
  year: number;
  assigned_days: number;
}

interface AssignLeaveVariables {
  userId: number;
  payload: AssignLeavePayload;
}

interface AssignLeaveFormState {
  leave_type_id: string;
  year: string;
  assigned_days: string;
}

const EMPTY_CREATE_USER_FORM: CreateUserFormState = {
  name: '',
  email: '',
  password: '',
  role: 'Employee',
  passport_number: '',
  team_id: '',
};

function getCurrentYear(): number {
  return new Date().getFullYear();
}

function createEmptyAssignLeaveForm(): AssignLeaveFormState {
  return {
    leave_type_id: '',
    year: String(getCurrentYear()),
    assigned_days: '',
  };
}

async function fetchUsers(): Promise<UserRecord[]> {
  const response = await api.get<ApiSuccessResponse<UserRecord[]>>('/admin/users');
  return response.data.data;
}

async function fetchTeams(): Promise<TeamOption[]> {
  const response = await api.get<ApiSuccessResponse<TeamOption[]>>('/admin/teams');
  return response.data.data;
}

async function createUser(payload: CreateUserPayload): Promise<UserRecord> {
  const response = await api.post<ApiSuccessResponse<UserRecord>>('/admin/users', payload);
  return response.data.data;
}

async function updateUser(userId: number, payload: UpdateUserPayload): Promise<UserRecord> {
  const response = await api.put<ApiSuccessResponse<UserRecord>>(
    `/admin/users/${userId}`,
    payload,
  );
  return response.data.data;
}

async function saveUser({ userId, payload }: SaveUserVariables): Promise<UserRecord> {
  if (userId !== undefined) {
    return updateUser(userId, payload as UpdateUserPayload);
  }

  return createUser(payload as CreateUserPayload);
}

async function fetchAllocationLeaveTypes(): Promise<LeaveTypeOption[]> {
  const response = await api.get<ApiSuccessResponse<LeaveTypeOption[]>>('/leave-types', {
    params: { requires_allocation: true },
  });
  return response.data.data;
}

async function assignLeaveAllocation({ userId, payload }: AssignLeaveVariables): Promise<void> {
  await api.put<ApiSuccessResponse<unknown>>(`/admin/leave-allocations/${userId}`, payload);
}

function getMutationErrorMessage(error: unknown, fallback: string): string {
  return getApiErrorMessage(error, fallback);
}

function getTeamName(user: UserRecord): string {
  return user.team?.team_name ?? '—';
}

function createUserFormFromRecord(user: UserRecord): CreateUserFormState {
  return {
    name: user.name,
    email: user.email,
    password: '',
    role: user.job_title,
    passport_number: user.passport_number,
    team_id: user.team_id !== null ? String(user.team_id) : '',
  };
}

function parseTeamId(value: string): number | null {
  if (value.trim().length === 0) {
    return null;
  }

  const parsed = Number.parseInt(value, 10);
  return Number.isNaN(parsed) ? null : parsed;
}

function UsersTableSkeleton() {
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

export default function Users() {
  const queryClient = useQueryClient();
  const [panelOpen, setPanelOpen] = useState(false);
  const [editingUser, setEditingUser] = useState<UserRecord | null>(null);
  const [formState, setFormState] = useState<CreateUserFormState>(EMPTY_CREATE_USER_FORM);
  const [formError, setFormError] = useState<string | undefined>();

  const isEditMode = editingUser !== null;

  const [allocationUser, setAllocationUser] = useState<UserRecord | null>(null);
  const [allocationForm, setAllocationForm] = useState<AssignLeaveFormState>(
    createEmptyAssignLeaveForm,
  );
  const [allocationError, setAllocationError] = useState<string | undefined>();
  const [balanceModalUserId, setBalanceModalUserId] = useState<number | null>(null);

  const {
    data: users = [],
    isLoading,
    isError,
  } = useQuery({
    queryKey: queryKeys.users.admin,
    queryFn: fetchUsers,
  });

  const teamsQuery = useQuery({
    queryKey: queryKeys.teams.admin,
    queryFn: fetchTeams,
  });

  const leaveTypesQuery = useQuery({
    queryKey: queryKeys.leaveTypes.allocation,
    queryFn: fetchAllocationLeaveTypes,
  });

  const saveUserMutation = useMutation({
    mutationFn: saveUser,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.users.admin });
      void queryClient.invalidateQueries({ queryKey: queryKeys.teams.admin });
      invalidateReportQueries(queryClient);
      handleClosePanel();
    },
    onError: (error) => {
      setFormError(
        getMutationErrorMessage(
          error,
          isEditMode
            ? 'Unable to update user. Please try again.'
            : 'Unable to create user. Please try again.',
        ),
      );
    },
  });

  const assignLeaveMutation = useMutation({
    mutationFn: assignLeaveAllocation,
    onSuccess: (_data, variables) => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.users.admin });
      void queryClient.invalidateQueries({
        queryKey: ['admin', 'user', variables.userId, 'balances'],
      });
      invalidateReportQueries(queryClient);
      handleCloseAllocationPanel();
    },
    onError: (error) => {
      setAllocationError(
        getMutationErrorMessage(error, 'Unable to assign leave allocation. Please try again.'),
      );
    },
  });

  function handleOpenCreatePanel() {
    setEditingUser(null);
    setFormState(EMPTY_CREATE_USER_FORM);
    setFormError(undefined);
    setPanelOpen(true);
  }

  function handleOpenEditPanel(user: UserRecord) {
    setEditingUser(user);
    setFormState(createUserFormFromRecord(user));
    setFormError(undefined);
    setPanelOpen(true);
  }

  function handleClosePanel() {
    setPanelOpen(false);
    setEditingUser(null);
    setFormState(EMPTY_CREATE_USER_FORM);
    setFormError(undefined);
  }

  function handleSaveUser() {
    setFormError(undefined);

    if (editingUser !== null) {
      saveUserMutation.mutate({
        userId: editingUser.id,
        payload: {
          job_title: formState.role,
          team_id: parseTeamId(formState.team_id),
        },
      });
      return;
    }

    saveUserMutation.mutate({
      payload: {
        name: formState.name.trim(),
        email: formState.email.trim(),
        password: formState.password,
        job_title: formState.role,
        passport_number: formState.passport_number.trim(),
        team_id: parseTeamId(formState.team_id),
      },
    });
  }

  function handleOpenAllocationPanel(user: UserRecord) {
    setAllocationUser(user);
    setAllocationForm(createEmptyAssignLeaveForm());
    setAllocationError(undefined);
  }

  function handleCloseAllocationPanel() {
    setAllocationUser(null);
    setAllocationForm(createEmptyAssignLeaveForm());
    setAllocationError(undefined);
  }

  function handleAssignLeave() {
    if (allocationUser === null) {
      return;
    }

    setAllocationError(undefined);

    const leaveTypeId = Number.parseInt(allocationForm.leave_type_id, 10);
    const year = Number.parseInt(allocationForm.year, 10);
    const assignedDays = Number.parseFloat(allocationForm.assigned_days);

    if (Number.isNaN(leaveTypeId)) {
      setAllocationError('Please select a leave type.');
      return;
    }

    if (Number.isNaN(year)) {
      setAllocationError('Please enter a valid year.');
      return;
    }

    if (Number.isNaN(assignedDays) || assignedDays < 0) {
      setAllocationError('Assigned days must be zero or greater.');
      return;
    }

    assignLeaveMutation.mutate({
      userId: allocationUser.id,
      payload: {
        leave_type_id: leaveTypeId,
        year,
        assigned_days: assignedDays,
      },
    });
  }

  const isSaving = saveUserMutation.isPending;
  const isAssigning = assignLeaveMutation.isPending;
  const teamOptions = teamsQuery.data ?? [];
  const leaveTypeOptions = leaveTypesQuery.data ?? [];

  return (
    <div className="space-y-6">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight text-slate-800">
            User Management
          </h1>
          <p className="mt-1 text-sm text-slate-500">
            Create and manage employee accounts, roles, and team assignments.
          </p>
        </div>
        <Button type="button" variant="primary" size="md" onClick={handleOpenCreatePanel}>
          <Plus className="h-4 w-4" aria-hidden="true" />
          Add User
        </Button>
      </div>

      <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <Table>
          <TableHeader>
            <TableRow className="hover:bg-transparent">
              <TableHead>Name</TableHead>
              <TableHead>Email</TableHead>
              <TableHead>Role</TableHead>
              <TableHead>Team</TableHead>
              <TableHead>Leave Balance</TableHead>
              <TableHead>Status</TableHead>
              <TableHead className="w-24 text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          {isLoading ? (
            <UsersTableSkeleton />
          ) : (
            <TableBody>
              {isError ? (
                <TableErrorRow colSpan={7} />
              ) : users.length === 0 ? (
                <TableRow className="hover:bg-transparent">
                  <TableCell colSpan={7} className="py-8 text-center text-sm text-slate-500">
                    No users found.
                  </TableCell>
                </TableRow>
              ) : (
                users.map((user) => (
                  <TableRow key={user.id}>
                    <TableCell className="font-medium text-slate-900">{user.name}</TableCell>
                    <TableCell>{user.email}</TableCell>
                    <TableCell>
                      <Badge variant="neutral">{user.job_title}</Badge>
                    </TableCell>
                    <TableCell>{getTeamName(user)}</TableCell>
                    <TableCell>
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setBalanceModalUserId(user.id)}
                      >
                        Show Leave Balance
                      </Button>
                    </TableCell>
                    <TableCell>
                      <Badge variant={user.is_active ? 'active' : 'inactive'}>
                        {user.is_active ? 'Active' : 'Inactive'}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-right">
                      <div className="flex items-center justify-end gap-2">
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          className="h-8 w-8 px-0"
                          onClick={() => handleOpenEditPanel(user)}
                          aria-label={`Edit ${user.name}`}
                        >
                          <Pencil className="h-4 w-4" aria-hidden="true" />
                        </Button>
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          onClick={() => handleOpenAllocationPanel(user)}
                          aria-label={`Assign leave for ${user.name}`}
                        >
                          <CalendarPlus className="h-4 w-4" aria-hidden="true" />
                          Assign Leave
                        </Button>
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
        title={isEditMode ? 'Edit User' : 'Add New User'}
        description={
          isEditMode
            ? 'Update role and team assignment. Name, email, and passport cannot be changed after creation.'
            : 'Provision a new HRMS account with role and optional team assignment.'
        }
      >
        <div className="flex flex-1 flex-col gap-5 p-6">
          {teamsQuery.isError ? (
            <Alert variant="error">
              Failed to load teams. Team assignment is temporarily unavailable.
            </Alert>
          ) : null}

          {formError !== undefined ? (
            <Alert variant="error">{formError}</Alert>
          ) : null}

          <Input
            id="user-name"
            label="Name"
            placeholder="Full name"
            value={formState.name}
            disabled={isSaving || isEditMode}
            onChange={(event) => {
              setFormState((previous) => ({ ...previous, name: event.target.value }));
              if (formError !== undefined) {
                setFormError(undefined);
              }
            }}
          />
          <Input
            id="user-email"
            label="Email"
            type="email"
            placeholder="name@company.com"
            value={formState.email}
            disabled={isSaving || isEditMode}
            onChange={(event) => {
              setFormState((previous) => ({ ...previous, email: event.target.value }));
              if (formError !== undefined) {
                setFormError(undefined);
              }
            }}
          />
          {!isEditMode ? (
            <Input
              id="user-password"
              label="Password"
              type="password"
              placeholder="Minimum 8 characters"
              value={formState.password}
              disabled={isSaving}
              onChange={(event) => {
                setFormState((previous) => ({ ...previous, password: event.target.value }));
                if (formError !== undefined) {
                  setFormError(undefined);
                }
              }}
            />
          ) : null}
          <Input
            id="user-passport-number"
            label="Passport Number"
            placeholder="Unique passport identifier"
            value={formState.passport_number}
            disabled={isSaving || isEditMode}
            onChange={(event) => {
              setFormState((previous) => ({
                ...previous,
                passport_number: event.target.value,
              }));
              if (formError !== undefined) {
                setFormError(undefined);
              }
            }}
          />

          <div className="flex w-full flex-col gap-1.5">
            <label htmlFor="user-role" className="text-sm font-medium leading-none text-slate-700">
              Role
            </label>
            <div className="relative">
              <select
                id="user-role"
                value={formState.role}
                disabled={isSaving}
                onChange={(event) => {
                  setFormState((previous) => ({
                    ...previous,
                    role: event.target.value as UserJobTitle,
                  }));
                  if (formError !== undefined) {
                    setFormError(undefined);
                  }
                }}
                className={cn(
                  'h-10 w-full appearance-none rounded-md border border-slate-300 bg-white px-3 pr-9 text-sm text-slate-900',
                  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand',
                  'disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-400',
                )}
              >
                <option value="Employee">Employee</option>
                <option value="Admin">Admin</option>
              </select>
              <ChevronDown
                className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"
                aria-hidden="true"
              />
            </div>
          </div>

          <div className="flex w-full flex-col gap-1.5">
            <label htmlFor="user-team" className="text-sm font-medium leading-none text-slate-700">
              Team
            </label>
            <div className="relative">
              <select
                id="user-team"
                value={formState.team_id}
                disabled={isSaving || teamsQuery.isLoading}
                onChange={(event) => {
                  setFormState((previous) => ({ ...previous, team_id: event.target.value }));
                  if (formError !== undefined) {
                    setFormError(undefined);
                  }
                }}
                className={cn(
                  'h-10 w-full appearance-none rounded-md border border-slate-300 bg-white px-3 pr-9 text-sm text-slate-900',
                  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand',
                  'disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-400',
                )}
              >
                <option value="">No team assigned</option>
                {teamOptions.map((team) => (
                  <option key={team.id} value={String(team.id)}>
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

        <div className="mt-auto flex items-center justify-end gap-3 border-t border-slate-200 px-6 py-4">
          <Button type="button" variant="outline" onClick={handleClosePanel} disabled={isSaving}>
            Cancel
          </Button>
          <Button
            type="button"
            variant="primary"
            onClick={handleSaveUser}
            disabled={isSaving}
          >
            {isSaving
              ? isEditMode
                ? 'Saving...'
                : 'Creating...'
              : isEditMode
                ? 'Save Changes'
                : 'Create User'}
          </Button>
        </div>
      </SlideOver>

      <SlideOver
        isOpen={allocationUser !== null}
        onClose={handleCloseAllocationPanel}
        title="Assign Leave Allocation"
        description={
          allocationUser !== null
            ? `Set the yearly leave quota for ${allocationUser.name}.`
            : 'Set the yearly leave quota for this user.'
        }
      >
        <div className="flex flex-1 flex-col gap-5 p-6">
          {allocationError !== undefined ? (
            <Alert variant="error">{allocationError}</Alert>
          ) : null}

          <div className="flex w-full flex-col gap-1.5">
            <label
              htmlFor="allocation-leave-type"
              className="text-sm font-medium leading-none text-slate-700"
            >
              Leave Type
            </label>
            <div className="relative">
              <select
                id="allocation-leave-type"
                value={allocationForm.leave_type_id}
                disabled={isAssigning || leaveTypesQuery.isLoading}
                onChange={(event) => {
                  const value = event.target.value;
                  setAllocationForm((previous) => ({ ...previous, leave_type_id: value }));
                  if (allocationError !== undefined) {
                    setAllocationError(undefined);
                  }
                }}
                className={cn(
                  'h-10 w-full appearance-none rounded-md border border-slate-300 bg-white px-3 pr-9 text-sm text-slate-900',
                  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand',
                  'disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-400',
                )}
              >
                <option value="">Select a leave type</option>
                {leaveTypeOptions.map((leaveType) => (
                  <option key={leaveType.id} value={String(leaveType.id)}>
                    {leaveType.leave_type_code} — {leaveType.name}
                  </option>
                ))}
              </select>
              <ChevronDown
                className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"
                aria-hidden="true"
              />
            </div>
            {leaveTypesQuery.isError ? (
              <Alert variant="error" className="text-xs">
                Failed to load leave types. Please try again.
              </Alert>
            ) : null}
          </div>

          <Input
            id="allocation-year"
            label="Year"
            type="number"
            min={2000}
            max={2100}
            placeholder="e.g. 2026"
            value={allocationForm.year}
            disabled={isAssigning}
            onChange={(event) => {
              const value = event.target.value;
              setAllocationForm((previous) => ({ ...previous, year: value }));
              if (allocationError !== undefined) {
                setAllocationError(undefined);
              }
            }}
          />

          <Input
            id="allocation-assigned-days"
            label="Assigned Days"
            type="number"
            min={0}
            step={0.5}
            placeholder="e.g. 14.5"
            value={allocationForm.assigned_days}
            disabled={isAssigning}
            onChange={(event) => {
              const value = event.target.value;
              setAllocationForm((previous) => ({ ...previous, assigned_days: value }));
              if (allocationError !== undefined) {
                setAllocationError(undefined);
              }
            }}
          />
        </div>

        <div className="mt-auto flex items-center justify-end gap-3 border-t border-slate-200 px-6 py-4">
          <Button
            type="button"
            variant="outline"
            onClick={handleCloseAllocationPanel}
            disabled={isAssigning}
          >
            Cancel
          </Button>
          <Button
            type="button"
            variant="primary"
            onClick={handleAssignLeave}
            disabled={isAssigning}
          >
            {isAssigning ? 'Assigning...' : 'Assign Leave'}
          </Button>
        </div>
      </SlideOver>

      <LeaveBalanceSlideOver
        isOpen={balanceModalUserId !== null}
        userId={balanceModalUserId}
        onClose={() => setBalanceModalUserId(null)}
      />
    </div>
  );
}
