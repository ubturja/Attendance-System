import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { Loader2, MoreHorizontal, Plus, UserMinus, UserPlus } from 'lucide-react';
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
import { queryKeys } from '../../lib/queryKeys';
import { cn } from '../../lib/utils';

interface ApiSuccessResponse<T> {
  success: true;
  message: string;
  data: T;
}

interface TeamLeader {
  id: number;
  name: string;
}

interface TeamMember {
  id: number;
  name: string;
  email?: string;
  is_active: boolean;
  team_id?: number | null;
}

interface TeamRecord {
  id: number;
  team_name: string;
  team_leader_id: number | null;
  leader?: TeamLeader | null;
  team_leader?: TeamLeader | null;
  users: TeamMember[];
}

interface UserRecord {
  id: number;
  name: string;
  email: string;
  job_title: 'Admin' | 'Employee';
  is_active: boolean;
  team_id: number | null;
}

interface DraftRosterMember {
  id: number;
  name: string;
  email: string;
  is_active: boolean;
}

interface CreateTeamPayload {
  team_name: string;
}

interface UpdateTeamPayload {
  team_name: string;
  team_leader_id: number | null;
}

interface UpdateUserTeamPayload {
  team_id: number | null;
}

interface SaveEditTeamVariables {
  teamId: number;
  teamName: string;
  teamLeaderId: number | null;
  originalMemberIds: number[];
  draftMemberIds: number[];
}

interface CreateTeamFormState {
  name: string;
}

const EMPTY_CREATE_TEAM_FORM: CreateTeamFormState = {
  name: '',
};

async function fetchTeams(): Promise<TeamRecord[]> {
  const response = await api.get<ApiSuccessResponse<TeamRecord[]>>('/admin/teams');
  return response.data.data;
}

async function fetchUsers(): Promise<UserRecord[]> {
  const response = await api.get<ApiSuccessResponse<UserRecord[]>>('/admin/users');
  return response.data.data;
}

async function createTeam(payload: CreateTeamPayload): Promise<TeamRecord> {
  const response = await api.post<ApiSuccessResponse<TeamRecord>>('/admin/teams', payload);
  return response.data.data;
}

async function updateTeam(teamId: number, payload: UpdateTeamPayload): Promise<TeamRecord> {
  const response = await api.put<ApiSuccessResponse<TeamRecord>>(
    `/admin/teams/${teamId}`,
    payload,
  );
  return response.data.data;
}

async function updateUserTeam(
  userId: number,
  payload: UpdateUserTeamPayload,
): Promise<UserRecord> {
  const response = await api.put<ApiSuccessResponse<UserRecord>>(
    `/admin/users/${userId}`,
    payload,
  );
  return response.data.data;
}

async function deleteTeam(teamId: number): Promise<void> {
  await api.delete(`/admin/teams/${teamId}`);
}

async function saveEditTeamChanges({
  teamId,
  teamName,
  teamLeaderId,
  originalMemberIds,
  draftMemberIds,
}: SaveEditTeamVariables): Promise<void> {
  const originalSet = new Set(originalMemberIds);
  const draftSet = new Set(draftMemberIds);

  const memberIdsToAdd = draftMemberIds.filter((id) => !originalSet.has(id));
  const memberIdsToRemove = originalMemberIds.filter((id) => !draftSet.has(id));

  await updateTeam(teamId, {
    team_name: teamName,
    team_leader_id: teamLeaderId,
  });

  await Promise.all([
    ...memberIdsToAdd.map((userId) => updateUserTeam(userId, { team_id: teamId })),
    ...memberIdsToRemove.map((userId) => updateUserTeam(userId, { team_id: null })),
  ]);
}

function getTeamLeaderName(team: TeamRecord): string {
  return team.leader?.name ?? team.team_leader?.name ?? '—';
}

function getTeamStatus(team: TeamRecord): 'active' | 'inactive' {
  const hasActiveMembers = team.users.some((member) => member.is_active);
  return hasActiveMembers ? 'active' : 'inactive';
}

function TeamsTableSkeleton() {
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

export default function Teams() {
  const queryClient = useQueryClient();
  const [panelOpen, setPanelOpen] = useState(false);
  const [editingTeamId, setEditingTeamId] = useState<number | null>(null);
  const [formState, setFormState] = useState<CreateTeamFormState>(EMPTY_CREATE_TEAM_FORM);
  const [formError, setFormError] = useState<string | undefined>();
  const [rosterError, setRosterError] = useState<string | undefined>();
  const [rosterSuccess, setRosterSuccess] = useState<string | undefined>();
  const [memberSearch, setMemberSearch] = useState('');
  const [selectedUserIds, setSelectedUserIds] = useState<number[]>([]);
  /** Draft roster — only persisted when Save Changes is clicked. */
  const [draftMemberIds, setDraftMemberIds] = useState<number[]>([]);
  const [originalMemberIds, setOriginalMemberIds] = useState<number[]>([]);
  const [teamLeaderId, setTeamLeaderId] = useState<number | null>(null);

  const isEditMode = editingTeamId !== null;

  const {
    data: teams = [],
    isLoading,
    isError,
  } = useQuery({
    queryKey: queryKeys.teams.admin,
    queryFn: fetchTeams,
  });

  const { data: allUsers = [] } = useQuery({
    queryKey: queryKeys.users.admin,
    queryFn: fetchUsers,
    enabled: panelOpen && isEditMode,
  });

  const editingTeam = useMemo(() => {
    if (editingTeamId === null) {
      return null;
    }

    return teams.find((team) => team.id === editingTeamId) ?? null;
  }, [editingTeamId, teams]);

  const usersById = useMemo(() => {
    const map = new Map<number, UserRecord | TeamMember>();

    for (const user of allUsers) {
      map.set(user.id, user);
    }

    for (const member of editingTeam?.users ?? []) {
      if (!map.has(member.id)) {
        map.set(member.id, member);
      }
    }

    return map;
  }, [allUsers, editingTeam]);

  const draftRosterMembers = useMemo((): DraftRosterMember[] => {
    return draftMemberIds
      .map((id) => {
        const user = usersById.get(id);
        if (user === undefined) {
          return null;
        }

        return {
          id: user.id,
          name: user.name,
          email: 'email' in user && typeof user.email === 'string' ? user.email : '',
          is_active: user.is_active,
        };
      })
      .filter((member): member is DraftRosterMember => member !== null)
      .sort((a, b) => a.name.localeCompare(b.name));
  }, [draftMemberIds, usersById]);

  // Leader must remain a current roster member — clear if they were removed from the draft.
  const effectiveTeamLeaderId =
    teamLeaderId !== null && draftMemberIds.includes(teamLeaderId) ? teamLeaderId : null;

  // Delete guard uses persisted server roster (not unsaved draft removes).
  const hasPersistedMembers = (editingTeam?.users.length ?? 0) > 0;

  // Available = unassigned on server, or currently on this team but staged for removal.
  // Never show employees assigned to a different team.
  const availableUsers = useMemo(() => {
    if (editingTeamId === null) {
      return [];
    }

    const query = memberSearch.trim().toLowerCase();
    const draftSet = new Set(draftMemberIds);

    return allUsers
      .filter((user) => !draftSet.has(user.id))
      .filter((user) => user.team_id === null || user.team_id === editingTeamId)
      .filter((user) => {
        if (query.length === 0) {
          return true;
        }

        return (
          user.name.toLowerCase().includes(query) ||
          user.email.toLowerCase().includes(query)
        );
      })
      .sort((a, b) => a.name.localeCompare(b.name));
  }, [allUsers, draftMemberIds, editingTeamId, memberSearch]);

  function invalidateTeamAndUserQueries(): void {
    void queryClient.invalidateQueries({ queryKey: queryKeys.teams.admin });
    void queryClient.invalidateQueries({ queryKey: queryKeys.users.admin });
  }

  const createTeamMutation = useMutation({
    mutationFn: (payload: CreateTeamPayload) => createTeam(payload),
    onSuccess: () => {
      invalidateTeamAndUserQueries();
      handleClosePanel();
    },
    onError: (error) => {
      setFormError(
        getApiErrorMessage(error, 'Unable to create team. Please try again.'),
      );
    },
  });

  const saveEditTeamMutation = useMutation({
    mutationFn: saveEditTeamChanges,
    onSuccess: () => {
      invalidateTeamAndUserQueries();
      setRosterError(undefined);
      setFormError(undefined);
      setRosterSuccess('Team changes saved successfully.');
      setSelectedUserIds([]);
      setMemberSearch('');
      // Keep panel open; sync draft baseline after refetch via next open or update originals.
      setOriginalMemberIds(draftMemberIds);
    },
    onError: (error) => {
      setRosterSuccess(undefined);
      setFormError(
        getApiErrorMessage(error, 'Unable to save team changes. Please try again.'),
      );
    },
  });

  const deleteTeamMutation = useMutation({
    mutationFn: deleteTeam,
    onSuccess: () => {
      invalidateTeamAndUserQueries();
      handleClosePanel();
    },
    onError: (error) => {
      setRosterSuccess(undefined);
      setRosterError(
        getApiErrorMessage(error, 'Unable to delete team. Please try again.'),
      );
    },
  });

  function handleOpenCreatePanel() {
    setEditingTeamId(null);
    setFormState(EMPTY_CREATE_TEAM_FORM);
    setFormError(undefined);
    setRosterError(undefined);
    setRosterSuccess(undefined);
    setMemberSearch('');
    setSelectedUserIds([]);
    setDraftMemberIds([]);
    setOriginalMemberIds([]);
    setTeamLeaderId(null);
    setPanelOpen(true);
  }

  function handleOpenEditPanel(team: TeamRecord) {
    const memberIds = team.users.map((member) => member.id);
    setEditingTeamId(team.id);
    setFormState({ name: team.team_name });
    setFormError(undefined);
    setRosterError(undefined);
    setRosterSuccess(undefined);
    setMemberSearch('');
    setSelectedUserIds([]);
    setDraftMemberIds(memberIds);
    setOriginalMemberIds(memberIds);
    setTeamLeaderId(team.team_leader_id);
    setPanelOpen(true);
  }

  function handleClosePanel() {
    setPanelOpen(false);
    setEditingTeamId(null);
    setFormState(EMPTY_CREATE_TEAM_FORM);
    setFormError(undefined);
    setRosterError(undefined);
    setRosterSuccess(undefined);
    setMemberSearch('');
    setSelectedUserIds([]);
    setDraftMemberIds([]);
    setOriginalMemberIds([]);
    setTeamLeaderId(null);
  }

  function handleSaveTeam() {
    setFormError(undefined);
    setRosterError(undefined);
    setRosterSuccess(undefined);

    const trimmedName = formState.name.trim();
    if (trimmedName.length === 0) {
      setFormError('Team name is required.');
      return;
    }

    if (!isEditMode) {
      createTeamMutation.mutate({ team_name: trimmedName });
      return;
    }

    if (editingTeamId === null) {
      return;
    }

    saveEditTeamMutation.mutate({
      teamId: editingTeamId,
      teamName: trimmedName,
      teamLeaderId: effectiveTeamLeaderId,
      originalMemberIds,
      draftMemberIds,
    });
  }

  function handleToggleUserSelection(userId: number) {
    setSelectedUserIds((previous) => {
      if (previous.includes(userId)) {
        return previous.filter((id) => id !== userId);
      }

      return [...previous, userId];
    });

    if (rosterError !== undefined) {
      setRosterError(undefined);
    }
  }

  /** Stage selected unassigned users into the draft roster (not persisted until Save). */
  function handleAddSelectedMembers() {
    if (selectedUserIds.length === 0) {
      setRosterError('Select at least one unassigned employee to add.');
      return;
    }

    setDraftMemberIds((previous) => {
      const next = new Set(previous);
      for (const id of selectedUserIds) {
        next.add(id);
      }
      return Array.from(next);
    });
    setSelectedUserIds([]);
    setMemberSearch('');
    setRosterError(undefined);
    setRosterSuccess(undefined);
  }

  /** Stage removal locally — database is unchanged until Save Changes. */
  function handleRemoveMember(userId: number) {
    setDraftMemberIds((previous) => previous.filter((id) => id !== userId));
    setTeamLeaderId((previous) => (previous === userId ? null : previous));
    setRosterError(undefined);
    setRosterSuccess(undefined);
  }

  function handleDeleteTeam() {
    if (editingTeamId === null || hasPersistedMembers) {
      return;
    }

    setRosterError(undefined);
    setRosterSuccess(undefined);
    deleteTeamMutation.mutate(editingTeamId);
  }

  const isSaving = createTeamMutation.isPending || saveEditTeamMutation.isPending;
  const isPanelBusy = isSaving || deleteTeamMutation.isPending;

  return (
    <div className="space-y-6">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight text-slate-800">
            Team Management
          </h1>
          <p className="mt-1 text-sm text-slate-500">
            Create teams, manage members, and track active membership.
          </p>
        </div>
        <Button type="button" variant="primary" size="md" onClick={handleOpenCreatePanel}>
          <Plus className="h-4 w-4" aria-hidden="true" />
          Create Team
        </Button>
      </div>

      <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <Table>
          <TableHeader>
            <TableRow className="hover:bg-transparent">
              <TableHead>Team Name</TableHead>
              <TableHead>Team Leader</TableHead>
              <TableHead>Members</TableHead>
              <TableHead>Status</TableHead>
              <TableHead className="w-24 text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          {isLoading ? (
            <TeamsTableSkeleton />
          ) : (
            <TableBody>
              {isError ? (
                <TableErrorRow colSpan={5} />
              ) : teams.length === 0 ? (
                <TableRow className="hover:bg-transparent">
                  <TableCell colSpan={5} className="py-8 text-center text-sm text-slate-500">
                    No teams found.
                  </TableCell>
                </TableRow>
              ) : (
                teams.map((team) => {
                  const status = getTeamStatus(team);

                  return (
                    <TableRow key={team.id}>
                      <TableCell className="font-medium text-slate-900">
                        {team.team_name}
                      </TableCell>
                      <TableCell>{getTeamLeaderName(team)}</TableCell>
                      <TableCell>{team.users.length}</TableCell>
                      <TableCell>
                        <Badge variant={status === 'active' ? 'active' : 'inactive'}>
                          {status === 'active' ? 'Active' : 'Inactive'}
                        </Badge>
                      </TableCell>
                      <TableCell className="text-right">
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          className="h-8 w-8 px-0"
                          aria-label={`Edit ${team.team_name}`}
                          onClick={() => handleOpenEditPanel(team)}
                        >
                          <MoreHorizontal className="h-4 w-4" aria-hidden="true" />
                        </Button>
                      </TableCell>
                    </TableRow>
                  );
                })
              )}
            </TableBody>
          )}
        </Table>
      </div>

      <SlideOver
        isOpen={panelOpen}
        onClose={handleClosePanel}
        title={isEditMode ? 'Edit Team' : 'Create New Team'}
        description={
          isEditMode
            ? 'Roster edits are draft until you click Save Changes. Closing discards unsaved changes.'
            : 'Add a new organizational team to assign employees.'
        }
      >
        <div className="flex flex-1 flex-col gap-5 p-6">
          {formError !== undefined ? (
            <Alert variant="error">{formError}</Alert>
          ) : null}

          {rosterError !== undefined ? (
            <Alert variant="error">{rosterError}</Alert>
          ) : null}

          {rosterSuccess !== undefined ? (
            <Alert variant="success">{rosterSuccess}</Alert>
          ) : null}

          <Input
            id="team-name"
            label="Team Name"
            placeholder="e.g. Alpha Team"
            value={formState.name}
            disabled={isPanelBusy}
            onChange={(event) => {
              setFormState({ name: event.target.value });
              if (formError !== undefined) {
                setFormError(undefined);
              }
              if (rosterSuccess !== undefined) {
                setRosterSuccess(undefined);
              }
            }}
          />

          {isEditMode ? (
            <div className="flex w-full flex-col gap-1.5">
              <label
                htmlFor="team-leader"
                className="text-sm font-medium leading-none text-slate-700"
              >
                Team Leader
              </label>
              <select
                id="team-leader"
                value={effectiveTeamLeaderId === null ? '' : String(effectiveTeamLeaderId)}
                disabled={isPanelBusy || draftRosterMembers.length === 0}
                onChange={(event) => {
                  const nextValue = event.target.value;
                  setTeamLeaderId(nextValue === '' ? null : Number(nextValue));
                  if (rosterSuccess !== undefined) {
                    setRosterSuccess(undefined);
                  }
                }}
                className={cn(
                  'h-10 w-full rounded-md border border-slate-300 bg-white px-3 text-sm text-slate-900',
                  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-0',
                  'disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-400',
                )}
              >
                <option value="">-- No Leader Assigned --</option>
                {draftRosterMembers.map((member) => (
                  <option key={member.id} value={member.id}>
                    {member.name}
                  </option>
                ))}
              </select>
              {draftRosterMembers.length === 0 ? (
                <p className="text-xs text-slate-500">
                  Add members to the roster before assigning a team leader.
                </p>
              ) : null}
            </div>
          ) : null}

          {isEditMode ? (
            <div className="space-y-4 border-t border-slate-200 pt-5">
              <div>
                <h3 className="text-sm font-semibold text-slate-900">Team Roster</h3>
                <p className="mt-1 text-sm text-slate-500">
                  Add or remove members here. Changes apply only after Save Changes.
                </p>
              </div>

              <div className="rounded-md border border-slate-200">
                {draftRosterMembers.length === 0 ? (
                  <p className="px-4 py-6 text-center text-sm text-slate-500">
                    No members in the draft roster.
                  </p>
                ) : (
                  <ul className="divide-y divide-slate-200">
                    {draftRosterMembers.map((member) => (
                      <li
                        key={member.id}
                        className="flex items-center justify-between gap-3 px-4 py-3"
                      >
                        <div className="min-w-0">
                          <p className="truncate text-sm font-medium text-slate-900">
                            {member.name}
                          </p>
                          {member.email.length > 0 ? (
                            <p className="truncate text-xs text-slate-500">{member.email}</p>
                          ) : null}
                          <div className="mt-1">
                            <Badge variant={member.is_active ? 'active' : 'inactive'}>
                              {member.is_active ? 'Active' : 'Inactive'}
                            </Badge>
                          </div>
                        </div>
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          disabled={isPanelBusy}
                          onClick={() => handleRemoveMember(member.id)}
                        >
                          <UserMinus className="h-3.5 w-3.5" aria-hidden="true" />
                          Remove
                        </Button>
                      </li>
                    ))}
                  </ul>
                )}
              </div>

              <div className="space-y-3 rounded-md border border-slate-200 bg-slate-50 p-4">
                <div>
                  <h4 className="text-sm font-semibold text-slate-900">Add Members</h4>
                  <p className="mt-1 text-xs text-slate-500">
                    Only unassigned employees can be selected. Staging is local until Save Changes.
                  </p>
                </div>
                <Input
                  id="member-search"
                  label="Search unassigned employees"
                  placeholder="Search by name or email"
                  value={memberSearch}
                  disabled={isPanelBusy}
                  onChange={(event) => {
                    setMemberSearch(event.target.value);
                    if (rosterError !== undefined) {
                      setRosterError(undefined);
                    }
                  }}
                />
                <div className="overflow-hidden rounded-md border border-slate-200 bg-white">
                  <div className="max-h-48 overflow-y-auto">
                    {availableUsers.length === 0 ? (
                      <p className="px-4 py-6 text-center text-sm text-slate-500">
                        No unassigned employees available.
                      </p>
                    ) : (
                      <ul className="divide-y divide-slate-200">
                        {availableUsers.map((user) => {
                          const isChecked = selectedUserIds.includes(user.id);
                          const checkboxId = `add-member-${user.id}`;

                          return (
                            <li key={user.id}>
                              <label
                                htmlFor={checkboxId}
                                className={cn(
                                  'flex cursor-pointer items-start gap-3 px-4 py-3',
                                  'hover:bg-brand-50',
                                  isPanelBusy && 'cursor-not-allowed opacity-60',
                                )}
                              >
                                <input
                                  id={checkboxId}
                                  type="checkbox"
                  className="mt-1 h-4 w-4 rounded border-slate-300 text-brand focus:ring-brand"
                                  checked={isChecked}
                                  disabled={isPanelBusy}
                                  onChange={() => handleToggleUserSelection(user.id)}
                                />
                                <span className="min-w-0">
                                  <span className="block truncate text-sm font-medium text-slate-900">
                                    {user.name}
                                  </span>
                                  <span className="block truncate text-xs text-slate-500">
                                    {user.email}
                                  </span>
                                </span>
                              </label>
                            </li>
                          );
                        })}
                      </ul>
                    )}
                  </div>
                </div>
                <p className="text-xs text-slate-500">
                  {selectedUserIds.length === 0
                    ? 'No employees selected.'
                    : `${selectedUserIds.length} selected`}
                </p>
                <Button
                  type="button"
                  variant="primary"
                  size="md"
                  className="w-full"
                  disabled={isPanelBusy || selectedUserIds.length === 0}
                  onClick={handleAddSelectedMembers}
                >
                  <UserPlus className="h-4 w-4" aria-hidden="true" />
                  Add Selected Employees
                </Button>
              </div>

              <div className="border-t border-slate-200 pt-4">
                <div className="group relative">
                  <Button
                    type="button"
                    variant="outline"
                    className={cn(
                      'w-full border-red-200 text-red-700 hover:bg-red-50',
                      hasPersistedMembers && 'cursor-not-allowed opacity-50 hover:bg-white',
                    )}
                    disabled={isPanelBusy || hasPersistedMembers}
                    title={
                      hasPersistedMembers
                        ? 'Cannot delete a team with active members.'
                        : 'Delete this empty team'
                    }
                    onClick={handleDeleteTeam}
                  >
                    {deleteTeamMutation.isPending ? 'Deleting...' : 'Delete Team'}
                  </Button>
                  {hasPersistedMembers ? (
                    <p className="mt-2 text-xs text-slate-500">
                      Cannot delete a team with active members. Save removals first if the
                      roster should be emptied.
                    </p>
                  ) : (
                    <p className="mt-2 text-xs text-slate-500">
                      This team has no members and can be safely deleted.
                    </p>
                  )}
                </div>
              </div>
            </div>
          ) : null}
        </div>

        <div className="mt-auto flex items-center justify-end gap-3 border-t border-slate-200 px-6 py-4">
          <Button type="button" variant="outline" onClick={handleClosePanel} disabled={isPanelBusy}>
            {isEditMode ? 'Close' : 'Cancel'}
          </Button>
          <Button
            type="button"
            variant="primary"
            onClick={handleSaveTeam}
            disabled={isPanelBusy || formState.name.trim().length === 0}
          >
            {isSaving
              ? isEditMode
                ? 'Saving...'
                : 'Creating...'
              : isEditMode
                ? 'Save Changes'
                : 'Create Team'}
          </Button>
        </div>
      </SlideOver>
    </div>
  );
}
