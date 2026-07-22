import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import {
  ArchiveRestore,
  CalendarPlus,
  ChevronDown,
  Eye,
  Loader2,
  Pencil,
  Plus,
  Search,
} from 'lucide-react';
import { Alert } from '../../components/ui/Alert';
import { Badge } from '../../components/ui/Badge';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { AssignLeaveSlideOver } from '../../components/ui/AssignLeaveSlideOver';
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
  deleted_at?: string | null;
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
  is_active: boolean;
  password?: string;
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
  is_active: boolean;
}

const EMPTY_CREATE_USER_FORM: CreateUserFormState = {
  name: '',
  email: '',
  password: '',
  role: 'Employee',
  passport_number: '',
  team_id: '',
  is_active: true,
};

async function fetchUsers(isArchived: boolean, search: string): Promise<UserRecord[]> {
  const params: Record<string, string> = {};
  if (isArchived) {
    params.status = 'archived';
  }
  if (search !== '') {
    params.search = search;
  }

  const response = await api.get<ApiSuccessResponse<UserRecord[]>>('/admin/users', {
    params: Object.keys(params).length > 0 ? params : undefined,
  });
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

async function deleteUser(userId: number): Promise<void> {
  await api.delete(`/admin/users/${userId}`);
}

async function restoreUser(userId: number): Promise<UserRecord> {
  const response = await api.patch<ApiSuccessResponse<UserRecord>>(
    `/admin/users/${userId}/restore`,
  );
  return response.data.data;
}

function getMutationErrorMessage(error: unknown, fallback: string): string {
  return getApiErrorMessage(error, fallback);
}

function isUserArchived(user: UserRecord | null): boolean {
  return user !== null && user.deleted_at != null && user.deleted_at !== '';
}

function formatAuditDate(value: string | null | undefined): string {
  if (value === null || value === undefined || value.trim().length === 0) {
    return '—';
  }

  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) {
    return value;
  }

  return parsed.toLocaleString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
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
    is_active: user.is_active,
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
  const [searchParams, setSearchParams] = useSearchParams();
  const searchQuery = searchParams.get('search') || '';
  const isArchived = searchParams.get('status') === 'archived';

  const [panelOpen, setPanelOpen] = useState(false);
  const [editingUser, setEditingUser] = useState<UserRecord | null>(null);
  const [formState, setFormState] = useState<CreateUserFormState>(EMPTY_CREATE_USER_FORM);
  const [formError, setFormError] = useState<string | undefined>();
  const [showPassword, setShowPassword] = useState(false);

  const isEditMode = editingUser !== null;
  const isViewOnly = isEditMode && isUserArchived(editingUser);

  const [allocationUser, setAllocationUser] = useState<UserRecord | null>(null);
  const [balanceModalUserId, setBalanceModalUserId] = useState<number | null>(null);

  const {
    data: users = [],
    isLoading,
    isError,
  } = useQuery({
    queryKey: queryKeys.users.list(isArchived, searchQuery),
    queryFn: () => fetchUsers(isArchived, searchQuery),
  });

  const teamsQuery = useQuery({
    queryKey: queryKeys.teams.admin,
    queryFn: fetchTeams,
  });

  function handleSearchChange(value: string): void {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev);
      if (value === '') {
        next.delete('search');
      } else {
        next.set('search', value);
      }
      return next;
    });
  }

  function handleArchiveViewChange(archived: boolean): void {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev);
      if (archived) {
        next.set('status', 'archived');
      } else {
        next.delete('status');
      }
      return next;
    });
  }

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

  const deleteUserMutation = useMutation({
    mutationFn: deleteUser,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.users.admin });
      void queryClient.invalidateQueries({ queryKey: queryKeys.teams.admin });
      invalidateReportQueries(queryClient);
      handleClosePanel();
    },
    onError: (error) => {
      setFormError(
        getMutationErrorMessage(error, 'Unable to offboard user. Please try again.'),
      );
    },
  });

  const restoreUserMutation = useMutation({
    mutationFn: restoreUser,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.users.admin });
      void queryClient.invalidateQueries({ queryKey: queryKeys.teams.admin });
      invalidateReportQueries(queryClient);
      handleClosePanel();
    },
    onError: (error) => {
      setFormError(
        getMutationErrorMessage(error, 'Unable to restore user. Please try again.'),
      );
    },
  });

  function handleOpenCreatePanel() {
    setEditingUser(null);
    setFormState(EMPTY_CREATE_USER_FORM);
    setFormError(undefined);
    setShowPassword(false);
    setPanelOpen(true);
  }

  function handleOpenEditPanel(user: UserRecord) {
    setEditingUser(user);
    setFormState(createUserFormFromRecord(user));
    setFormError(undefined);
    setShowPassword(false);
    setPanelOpen(true);
  }

  function handleClosePanel() {
    setPanelOpen(false);
    setEditingUser(null);
    setFormState(EMPTY_CREATE_USER_FORM);
    setFormError(undefined);
    setShowPassword(false);
  }

  function handleSaveUser() {
    setFormError(undefined);

    if (editingUser !== null) {
      const trimmedPassword = formState.password.trim();
      if (trimmedPassword.length > 0 && trimmedPassword.length < 8) {
        setFormError('New password must be at least 8 characters.');
        return;
      }

      const payload: UpdateUserPayload = {
        job_title: formState.role,
        team_id: parseTeamId(formState.team_id),
        is_active: formState.is_active,
      };

      if (trimmedPassword.length > 0) {
        payload.password = trimmedPassword;
      }

      saveUserMutation.mutate({
        userId: editingUser.id,
        payload,
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

  function handleOffboardUser() {
    if (editingUser === null || isViewOnly) {
      return;
    }

    setFormError(undefined);
    deleteUserMutation.mutate(editingUser.id);
  }

  function handleRestoreUser() {
    if (editingUser === null || !isViewOnly) {
      return;
    }

    setFormError(undefined);
    restoreUserMutation.mutate(editingUser.id);
  }

  function handleOpenAllocationPanel(user: UserRecord) {
    setAllocationUser(user);
  }

  function handleCloseAllocationPanel() {
    setAllocationUser(null);
  }

  const isSaving = saveUserMutation.isPending;
  const isPanelBusy =
    isSaving || deleteUserMutation.isPending || restoreUserMutation.isPending;
  const teamOptions = teamsQuery.data ?? [];

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

      <div className="flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
        <div className="relative min-w-[16rem] flex-1">
          <Search
            className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"
            aria-hidden="true"
          />
          <input
            id="users-search"
            type="search"
            value={searchQuery}
            placeholder="Search by name or email"
            aria-label="Search users by name or email"
            onChange={(event) => handleSearchChange(event.target.value)}
            className={cn(
              'h-10 w-full rounded-md border border-slate-300 bg-white pl-10 pr-3 text-sm text-slate-900',
              'placeholder:text-slate-400',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand',
            )}
          />
        </div>
        <div
          className="inline-flex shrink-0 overflow-hidden rounded-md border border-slate-300"
          role="group"
          aria-label="User list filter"
        >
          <button
            type="button"
            onClick={() => handleArchiveViewChange(false)}
            className={cn(
              'h-10 px-3 text-sm font-medium transition-colors',
              !isArchived
                ? 'bg-brand text-white'
                : 'bg-white text-slate-600 hover:bg-brand-50 hover:text-brand',
            )}
          >
            Current Users
          </button>
          <button
            type="button"
            onClick={() => handleArchiveViewChange(true)}
            className={cn(
              'h-10 border-l border-slate-300 px-3 text-sm font-medium transition-colors',
              isArchived
                ? 'bg-brand text-white'
                : 'bg-white text-slate-600 hover:bg-brand-50 hover:text-brand',
            )}
          >
            Previous Users
          </button>
        </div>
      </div>

      <div className="w-full min-w-0 overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
        <Table className="min-w-max">
          <TableHeader>
            <TableRow className="hover:bg-transparent">
              <TableHead>Name</TableHead>
              <TableHead>Email</TableHead>
              <TableHead>Role</TableHead>
              <TableHead>Team</TableHead>
              <TableHead>Leave Balance</TableHead>
              <TableHead>Status</TableHead>
              <TableHead className="w-24 text-center">Actions</TableHead>
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
                    {searchQuery !== ''
                      ? 'No users match your search.'
                      : isArchived
                        ? 'No previous users found.'
                        : 'No users found.'}
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
                      {isArchived ? (
                        <Badge variant="inactive">Archived</Badge>
                      ) : (
                        <Badge variant={user.is_active ? 'active' : 'warning'}>
                          {user.is_active ? 'Active' : 'Temp Inactive'}
                        </Badge>
                      )}
                    </TableCell>
                    <TableCell className="text-right">
                      <div className="flex items-center justify-end gap-2">
                        {isArchived ? (
                          <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => handleOpenEditPanel(user)}
                            aria-label={`View details for ${user.name}`}
                          >
                            View Details
                          </Button>
                        ) : (
                          <>
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
                          </>
                        )}
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
        title={
          isViewOnly ? 'User Audit Log' : isEditMode ? 'Edit User' : 'Add New User'
        }
        description={
          isViewOnly
            ? 'Read-only profile for this offboarded employee.'
            : isEditMode
              ? 'Update role and team assignment. Name, email, and passport cannot be changed after creation.'
              : 'Provision a new HRMS account with role and optional team assignment.'
        }
      >
        <div className="flex flex-1 flex-col gap-5 p-6">
          {isViewOnly && editingUser?.deleted_at != null ? (
            <Alert variant="warning">
              This user was offboarded on {formatAuditDate(editingUser.deleted_at)}.
            </Alert>
          ) : null}

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
            disabled={isPanelBusy || isEditMode}
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
            disabled={isPanelBusy || isEditMode}
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
              disabled={isPanelBusy}
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
            disabled={isPanelBusy || isEditMode}
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
                disabled={isPanelBusy || isViewOnly}
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

          {isEditMode ? (
            <div className="space-y-1.5">
              <div>
                <p className="text-sm font-medium leading-none text-slate-700">
                  Reset Password (Optional)
                </p>
                <p className="mt-1 text-xs text-slate-500">
                  Leave blank to keep the current password.
                </p>
              </div>
              <div className="relative">
                <Input
                  id="user-reset-password"
                  type={showPassword ? 'text' : 'password'}
                  placeholder="Set new password (min. 8 characters)"
                  value={formState.password}
                  disabled={isPanelBusy || isViewOnly}
                  autoComplete="new-password"
                  className="pr-10"
                  onChange={(event) => {
                    setFormState((previous) => ({
                      ...previous,
                      password: event.target.value,
                    }));
                    if (formError !== undefined) {
                      setFormError(undefined);
                    }
                  }}
                />
                <button
                  type="button"
                  disabled={isPanelBusy || isViewOnly}
                  className={cn(
                    'absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400',
                    'hover:text-slate-600',
                    'disabled:cursor-not-allowed disabled:opacity-50',
                    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand',
                  )}
                  aria-label="Hold to show password"
                  onMouseDown={() => setShowPassword(true)}
                  onMouseUp={() => setShowPassword(false)}
                  onMouseLeave={() => setShowPassword(false)}
                  onTouchStart={() => setShowPassword(true)}
                  onTouchEnd={() => setShowPassword(false)}
                >
                  <Eye className="h-4 w-4" aria-hidden="true" />
                </button>
              </div>
            </div>
          ) : null}

          <div className="flex w-full flex-col gap-1.5">
            <label htmlFor="user-team" className="text-sm font-medium leading-none text-slate-700">
              Team
            </label>
            <div className="relative">
              <select
                id="user-team"
                value={formState.team_id}
                disabled={isPanelBusy || isViewOnly || teamsQuery.isLoading}
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

          {isEditMode ? (
            <div className="flex w-full flex-col gap-1.5">
              <label
                htmlFor="user-account-status"
                className="text-sm font-medium leading-none text-slate-700"
              >
                Account Status
              </label>
              <div className="relative">
                <select
                  id="user-account-status"
                  value={formState.is_active ? 'active' : 'inactive'}
                  disabled={isPanelBusy || isViewOnly}
                  onChange={(event) => {
                    setFormState((previous) => ({
                      ...previous,
                      is_active: event.target.value === 'active',
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
                  <option value="active">Active</option>
                  <option value="inactive">Temporarily Inactive</option>
                </select>
                <ChevronDown
                  className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"
                  aria-hidden="true"
                />
              </div>
              <p className="text-xs text-slate-500">
                Temporarily inactive users cannot sign in until reactivated.
              </p>
            </div>
          ) : null}

          {isEditMode ? (
            <div className="border-t border-slate-200 pt-4">
              {isViewOnly ? (
                <>
                  <Button
                    type="button"
                    variant="outline"
                    className="w-full border-brand-100 text-brand-600 hover:bg-brand-50 hover:text-brand"
                    disabled={isPanelBusy}
                    onClick={handleRestoreUser}
                  >
                    <ArchiveRestore className="h-4 w-4" aria-hidden="true" />
                    {restoreUserMutation.isPending ? 'Restoring...' : 'Restore User'}
                  </Button>
                  <p className="mt-2 text-xs text-slate-500">
                    Restoring clears the offboard flag so this account can sign in and be edited
                    again.
                  </p>
                </>
              ) : (
                <>
                  <Button
                    type="button"
                    variant="outline"
                    className="w-full border-red-200 text-red-700 hover:bg-red-50"
                    disabled={isPanelBusy}
                    onClick={handleOffboardUser}
                  >
                    {deleteUserMutation.isPending
                      ? 'Offboarding...'
                      : 'Offboard / Delete User'}
                  </Button>
                  <p className="mt-2 text-xs text-slate-500">
                    Soft-deletes this account. Historical attendance and leave records are kept.
                  </p>
                </>
              )}
            </div>
          ) : null}
        </div>

        <div className="mt-auto flex items-center justify-end gap-3 border-t border-slate-200 px-6 py-4">
          <Button
            type="button"
            variant="outline"
            onClick={handleClosePanel}
            disabled={isPanelBusy}
          >
            {isEditMode ? 'Close' : 'Cancel'}
          </Button>
          {!isViewOnly ? (
            <Button
              type="button"
              variant="primary"
              onClick={handleSaveUser}
              disabled={isPanelBusy}
            >
              {isSaving
                ? isEditMode
                  ? 'Saving...'
                  : 'Creating...'
                : isEditMode
                  ? 'Save Changes'
                  : 'Create User'}
            </Button>
          ) : null}
        </div>
      </SlideOver>

      <AssignLeaveSlideOver
        isOpen={allocationUser !== null}
        user={allocationUser}
        onClose={handleCloseAllocationPanel}
      />

      <LeaveBalanceSlideOver
        isOpen={balanceModalUserId !== null}
        userId={balanceModalUserId}
        onClose={() => setBalanceModalUserId(null)}
      />
    </div>
  );
}
