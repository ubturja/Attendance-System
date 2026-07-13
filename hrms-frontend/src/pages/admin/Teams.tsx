import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Loader2, MoreHorizontal, Plus } from 'lucide-react';
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
  is_active: boolean;
}

interface TeamRecord {
  id: number;
  team_name: string;
  team_leader_id: number | null;
  team_leader: TeamLeader | null;
  users: TeamMember[];
}

interface CreateTeamPayload {
  team_name: string;
}

interface SaveTeamVariables {
  teamId?: number;
  payload: CreateTeamPayload;
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

async function createTeam(payload: CreateTeamPayload): Promise<TeamRecord> {
  const response = await api.post<ApiSuccessResponse<TeamRecord>>('/admin/teams', payload);
  return response.data.data;
}

async function updateTeam(teamId: number, payload: CreateTeamPayload): Promise<TeamRecord> {
  const response = await api.put<ApiSuccessResponse<TeamRecord>>(
    `/admin/teams/${teamId}`,
    payload,
  );
  return response.data.data;
}

async function saveTeam({ teamId, payload }: SaveTeamVariables): Promise<TeamRecord> {
  if (teamId !== undefined) {
    return updateTeam(teamId, payload);
  }

  return createTeam(payload);
}

function getTeamLeaderName(team: TeamRecord): string {
  return team.team_leader?.name ?? '—';
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
  const [editingTeam, setEditingTeam] = useState<TeamRecord | null>(null);
  const [formState, setFormState] = useState<CreateTeamFormState>(EMPTY_CREATE_TEAM_FORM);
  const [formError, setFormError] = useState<string | undefined>();

  const isEditMode = editingTeam !== null;

  const {
    data: teams = [],
    isLoading,
    isError,
  } = useQuery({
    queryKey: queryKeys.teams.admin,
    queryFn: fetchTeams,
  });

  const saveTeamMutation = useMutation({
    mutationFn: saveTeam,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.teams.admin });
      handleClosePanel();
    },
    onError: (error) => {
      setFormError(
        getApiErrorMessage(
          error,
          isEditMode
            ? 'Unable to update team. Please try again.'
            : 'Unable to create team. Please try again.',
        ),
      );
    },
  });

  function handleOpenCreatePanel() {
    setEditingTeam(null);
    setFormState(EMPTY_CREATE_TEAM_FORM);
    setFormError(undefined);
    setPanelOpen(true);
  }

  function handleOpenEditPanel(team: TeamRecord) {
    setEditingTeam(team);
    setFormState({ name: team.team_name });
    setFormError(undefined);
    setPanelOpen(true);
  }

  function handleClosePanel() {
    setPanelOpen(false);
    setEditingTeam(null);
    setFormState(EMPTY_CREATE_TEAM_FORM);
    setFormError(undefined);
  }

  function handleSaveTeam() {
    setFormError(undefined);
    saveTeamMutation.mutate({
      teamId: editingTeam?.id,
      payload: {
        team_name: formState.name.trim(),
      },
    });
  }

  const isSaving = saveTeamMutation.isPending;

  return (
    <div className="space-y-6">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight text-slate-900">
            Team Management
          </h1>
          <p className="mt-1 text-sm text-slate-500">
            Create teams, assign leaders, and track active membership.
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
            ? 'Update the team name or leader assignment.'
            : 'Add a new organizational team to assign employees.'
        }
      >
        <div className="flex flex-1 flex-col gap-5 p-6">
          {formError !== undefined ? (
            <Alert variant="error">{formError}</Alert>
          ) : null}

          <Input
            id="team-name"
            label="Team Name"
            placeholder="e.g. Alpha Team"
            value={formState.name}
            disabled={isSaving}
            onChange={(event) => {
              setFormState({ name: event.target.value });
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
            onClick={handleSaveTeam}
            disabled={isSaving}
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
