import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  addMonths,
  eachDayOfInterval,
  endOfMonth,
  endOfWeek,
  format,
  isSameDay,
  isSameMonth,
  isToday,
  startOfMonth,
  startOfWeek,
  subMonths,
} from 'date-fns';
import { useState } from 'react';
import { ChevronLeft, ChevronRight, ChevronDown, Loader2, Pencil, Plus, Trash2 } from 'lucide-react';
import { Alert } from '../../components/ui/Alert';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { SlideOver } from '../../components/ui/SlideOver';
import { useCurrentProfile } from '../../hooks/useCurrentProfile';
import {
  createHoliday,
  deleteHoliday,
  getHolidays,
  updateHoliday,
  type Holiday,
  type HolidayInput,
  type HolidayType,
} from '../../lib/api';
import { getApiErrorMessage } from '../../lib/errors';
import { queryKeys } from '../../lib/queryKeys';
import { cn } from '../../lib/utils';

const WEEKDAY_LABELS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as const;

const DEFAULT_HOLIDAY_TYPE: HolidayType = 'malaysia';

/** Parses an API date (`YYYY-MM-DD`) as a local calendar day. */
function parseHolidayDate(isoDate: string): Date {
  return new Date(`${isoDate.slice(0, 10)}T00:00:00`);
}

/** Formats an ISO date string (YYYY-MM-DD) for display without hardcoding. */
function formatHolidayDate(isoDate: string): string {
  const parsed = parseHolidayDate(isoDate);

  if (Number.isNaN(parsed.getTime())) {
    return isoDate;
  }

  return new Intl.DateTimeFormat(undefined, {
    weekday: 'short',
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  }).format(parsed);
}

/** Normalizes API dates to `YYYY-MM-DD` for `<input type="date">`. */
function toDateInputValue(isoDate: string): string {
  return isoDate.slice(0, 10);
}

function holidayPillClassName(type: HolidayType | undefined): string {
  if (type === 'hong_kong') {
    return 'bg-red-100 text-red-700';
  }

  return 'bg-brand-100 text-brand-600';
}

interface HolidayPillProps {
  holiday: Holiday;
  isAdmin: boolean;
  isBusy: boolean;
  onEdit: (holiday: Holiday) => void;
  onDelete: (holiday: Holiday) => void;
}

function HolidayPill({ holiday, isAdmin, isBusy, onEdit, onDelete }: HolidayPillProps) {
  return (
    <div className="group relative mt-1">
      <span
        title={`${holiday.name} (${holiday.type === 'hong_kong' ? 'Hong Kong' : 'Malaysia'})`}
        className={cn(
          'block truncate rounded-md px-2 py-1 text-xs font-semibold',
          holidayPillClassName(holiday.type),
        )}
      >
        {holiday.name}
      </span>

      {isAdmin ? (
        <div className="absolute right-0.5 top-0.5 hidden items-center gap-0.5 rounded bg-white/95 p-0.5 shadow-sm group-hover:flex">
          <button
            type="button"
            disabled={isBusy}
            aria-label={`Edit ${holiday.name}`}
            onClick={(event) => {
              event.stopPropagation();
              onEdit(holiday);
            }}
            className="rounded p-0.5 text-slate-600 hover:bg-brand-50 hover:text-brand disabled:opacity-50"
          >
            <Pencil className="h-3 w-3" aria-hidden="true" />
          </button>
          <button
            type="button"
            disabled={isBusy}
            aria-label={`Delete ${holiday.name}`}
            onClick={(event) => {
              event.stopPropagation();
              onDelete(holiday);
            }}
            className="rounded p-0.5 text-red-700 hover:bg-red-50 hover:text-red-800 disabled:opacity-50"
          >
            <Trash2 className="h-3 w-3" aria-hidden="true" />
          </button>
        </div>
      ) : null}
    </div>
  );
}

export default function Calendar() {
  const queryClient = useQueryClient();
  const { data: profile } = useCurrentProfile();
  const isAdmin = profile?.job_title === 'Admin';

  const [currentDate, setCurrentDate] = useState(new Date());
  const [panelOpen, setPanelOpen] = useState(false);
  const [editingHoliday, setEditingHoliday] = useState<Holiday | null>(null);
  const [name, setName] = useState('');
  const [date, setDate] = useState('');
  const [description, setDescription] = useState('');
  const [type, setType] = useState<HolidayType>(DEFAULT_HOLIDAY_TYPE);
  const [formError, setFormError] = useState<string | undefined>();
  const [actionError, setActionError] = useState<string | undefined>();
  const [actionSuccess, setActionSuccess] = useState<string | undefined>();

  const isEditMode = editingHoliday !== null;

  function nextMonth() {
    setCurrentDate((prev) => addMonths(prev, 1));
  }

  function prevMonth() {
    setCurrentDate((prev) => subMonths(prev, 1));
  }

  const monthStart = startOfMonth(currentDate);
  const monthEnd = endOfMonth(monthStart);
  const startDate = startOfWeek(monthStart);
  const endDate = endOfWeek(monthEnd);
  const calendarDays = eachDayOfInterval({ start: startDate, end: endDate });

  const {
    data: holidays = [],
    isLoading,
    isError,
  } = useQuery({
    queryKey: queryKeys.holidays.all,
    queryFn: getHolidays,
  });

  function invalidateHolidays() {
    void queryClient.invalidateQueries({ queryKey: queryKeys.holidays.all });
    // Dashboard holiday lockdown + rolling RL balance depend on holiday rows.
    void queryClient.invalidateQueries({ queryKey: queryKeys.profile });
    void queryClient.invalidateQueries({ queryKey: ['user', 'replacement-balance'] });
  }

  function resetForm() {
    setEditingHoliday(null);
    setName('');
    setDate('');
    setDescription('');
    setType(DEFAULT_HOLIDAY_TYPE);
    setFormError(undefined);
  }

  function handleClosePanel() {
    setPanelOpen(false);
    resetForm();
  }

  function handleOpenCreatePanel() {
    resetForm();
    setActionError(undefined);
    setActionSuccess(undefined);
    setPanelOpen(true);
  }

  function handleOpenEditPanel(holiday: Holiday) {
    setEditingHoliday(holiday);
    setName(holiday.name);
    setDate(toDateInputValue(holiday.date));
    setDescription(holiday.description ?? '');
    setType(holiday.type ?? DEFAULT_HOLIDAY_TYPE);
    setFormError(undefined);
    setActionError(undefined);
    setActionSuccess(undefined);
    setPanelOpen(true);
  }

  const createHolidayMutation = useMutation({
    mutationFn: createHoliday,
    onSuccess: () => {
      setActionError(undefined);
      setActionSuccess('Holiday created successfully.');
      invalidateHolidays();
      handleClosePanel();
    },
    onError: (error) => {
      setFormError(getApiErrorMessage(error, 'Unable to create holiday. Please try again.'));
    },
  });

  const updateHolidayMutation = useMutation({
    mutationFn: ({ id, data }: { id: number; data: HolidayInput }) =>
      updateHoliday(id, data),
    onSuccess: () => {
      setActionError(undefined);
      setActionSuccess('Holiday updated successfully.');
      invalidateHolidays();
      handleClosePanel();
    },
    onError: (error) => {
      setFormError(getApiErrorMessage(error, 'Unable to update holiday. Please try again.'));
    },
  });

  const deleteHolidayMutation = useMutation({
    mutationFn: deleteHoliday,
    onSuccess: () => {
      setActionError(undefined);
      setActionSuccess('Holiday deleted successfully.');
      invalidateHolidays();
    },
    onError: (error) => {
      setActionSuccess(undefined);
      setActionError(getApiErrorMessage(error, 'Unable to delete holiday. Please try again.'));
    },
  });

  function handleSaveHoliday() {
    const trimmedName = name.trim();
    const trimmedDescription = description.trim();

    if (trimmedName.length === 0) {
      setFormError('Name is required.');
      return;
    }

    if (date.trim().length === 0) {
      setFormError('Date is required.');
      return;
    }

    setFormError(undefined);

    const payload: HolidayInput = {
      name: trimmedName,
      date: date.trim(),
      description: trimmedDescription.length > 0 ? trimmedDescription : null,
      type,
    };

    if (isEditMode && editingHoliday !== null) {
      updateHolidayMutation.mutate({ id: editingHoliday.id, data: payload });
      return;
    }

    createHolidayMutation.mutate(payload);
  }

  function handleDeleteHoliday(holiday: Holiday) {
    const confirmed = window.confirm(
      `Delete "${holiday.name}" on ${formatHolidayDate(holiday.date)}? This cannot be undone.`,
    );

    if (!confirmed) {
      return;
    }

    setActionError(undefined);
    setActionSuccess(undefined);
    deleteHolidayMutation.mutate(holiday.id);
  }

  const isSaving = createHolidayMutation.isPending || updateHolidayMutation.isPending;
  const isRowBusy = deleteHolidayMutation.isPending || isSaving;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight text-slate-800">Calendar</h1>
          <p className="mt-1 text-sm text-slate-500">
            Company holidays on the shared calendar.
          </p>
        </div>

        {isAdmin ? (
          <Button type="button" variant="primary" size="md" onClick={handleOpenCreatePanel}>
            <Plus className="h-4 w-4" aria-hidden="true" />
            Add Holiday
          </Button>
        ) : null}
      </div>

      {isAdmin && actionSuccess !== undefined ? (
        <Alert variant="success">{actionSuccess}</Alert>
      ) : null}

      {isAdmin && actionError !== undefined ? (
        <Alert variant="error">{actionError}</Alert>
      ) : null}

      {isError ? (
        <Alert variant="error">Failed to load holidays. Please try again.</Alert>
      ) : null}

      <div className="flex items-center justify-between gap-4">
        <Button
          type="button"
          variant="ghost"
          size="sm"
          aria-label="Previous month"
          onClick={prevMonth}
          className="px-2"
        >
          <ChevronLeft className="h-5 w-5" aria-hidden="true" />
        </Button>

        <h2 className="text-lg font-semibold text-slate-800">
          {format(currentDate, 'MMMM yyyy')}
        </h2>

        <Button
          type="button"
          variant="ghost"
          size="sm"
          aria-label="Next month"
          onClick={nextMonth}
          className="px-2"
        >
          <ChevronRight className="h-5 w-5" aria-hidden="true" />
        </Button>
      </div>

      <div className="relative">
        {isLoading ? (
          <div className="absolute inset-0 z-10 flex items-center justify-center gap-2 rounded-md bg-white/70 text-sm text-slate-500">
            <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
            Loading holidays...
          </div>
        ) : null}

        <div className="grid grid-cols-7">
          {WEEKDAY_LABELS.map((label) => (
            <div
              key={label}
              className="py-2 text-center text-sm font-medium text-slate-500"
            >
              {label}
            </div>
          ))}
        </div>

        <div className="grid grid-cols-7 auto-rows-fr gap-px border border-slate-200 bg-slate-200">
          {calendarDays.map((day) => {
            const inCurrentMonth = isSameMonth(day, monthStart);
            const dayIsToday = isToday(day);
            const dayHolidays = holidays.filter((holiday) =>
              isSameDay(parseHolidayDate(holiday.date), day),
            );

            return (
              <div
                key={day.toISOString()}
                className={cn(
                  'min-h-[120px] bg-white p-2',
                  !inCurrentMonth && 'bg-slate-50 text-slate-400',
                )}
              >
                <span
                  className={cn(
                    'inline-flex h-7 w-7 items-center justify-center text-sm font-medium',
                    dayIsToday && 'rounded-full bg-brand text-white',
                    !dayIsToday && inCurrentMonth && 'text-slate-800',
                  )}
                >
                  {format(day, 'd')}
                </span>

                {dayHolidays.map((holiday) => (
                  <HolidayPill
                    key={holiday.id}
                    holiday={holiday}
                    isAdmin={isAdmin}
                    isBusy={isRowBusy}
                    onEdit={handleOpenEditPanel}
                    onDelete={handleDeleteHoliday}
                  />
                ))}
              </div>
            );
          })}
        </div>
      </div>

      {isAdmin ? (
        <SlideOver
          isOpen={panelOpen}
          onClose={handleClosePanel}
          title={isEditMode ? 'Edit Holiday' : 'Add Holiday'}
          description={
            isEditMode
              ? 'Update the holiday name, date, type, or description.'
              : 'Add a company holiday to the calendar.'
          }
        >
          <div className="flex flex-1 flex-col gap-5 p-6">
            {formError !== undefined ? <Alert variant="error">{formError}</Alert> : null}

            <Input
              id="holiday-name"
              label="Name"
              placeholder="e.g. New Year's Day"
              value={name}
              disabled={isSaving}
              onChange={(event) => {
                setName(event.target.value);
                if (formError !== undefined) {
                  setFormError(undefined);
                }
              }}
            />

            <Input
              id="holiday-date"
              label="Date"
              type="date"
              value={date}
              disabled={isSaving}
              onChange={(event) => {
                setDate(event.target.value);
                if (formError !== undefined) {
                  setFormError(undefined);
                }
              }}
            />

            <div className="flex w-full flex-col gap-1.5">
              <label
                htmlFor="holiday-type"
                className="text-sm font-medium leading-none text-slate-700"
              >
                Holiday Type
              </label>
              <div className="relative">
                <select
                  id="holiday-type"
                  value={type}
                  disabled={isSaving}
                  onChange={(event) => {
                    setType(event.target.value as HolidayType);
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
                  <option value="malaysia">Malaysian Holiday</option>
                  <option value="hong_kong">Hong Kong Holiday</option>
                </select>
                <ChevronDown
                  className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"
                  aria-hidden="true"
                />
              </div>
            </div>

            <Input
              id="holiday-description"
              label="Description"
              placeholder="Optional notes"
              value={description}
              disabled={isSaving}
              onChange={(event) => {
                setDescription(event.target.value);
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
              onClick={handleSaveHoliday}
              disabled={isSaving}
            >
              {isSaving ? 'Saving...' : isEditMode ? 'Save Changes' : 'Save'}
            </Button>
          </div>
        </SlideOver>
      ) : null}
    </div>
  );
}
