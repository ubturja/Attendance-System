export interface LeaveTypeOptionSource {
  leave_type_code: string;
  name: string;
}

export interface AttendanceOption {
  value: string;
  label: string;
}

/** Non-leave attendance codes always available in the employee submission dropdown. */
const BASE_ATTENDANCE_OPTIONS: AttendanceOption[] = [
  { value: 'O', label: 'Present' },
  { value: 'W', label: 'Work from Home' },
  { value: 'X', label: 'OFF / CC' },
];

/**
 * Builds attendance dropdown options from active leave types returned by `GET /leave-types`.
 * Half-day variants (AO/OA → A, NO/ON → N) are appended when the parent code is active.
 */
export function buildAttendanceOptions(leaveTypes: LeaveTypeOptionSource[]): AttendanceOption[] {
  const options: AttendanceOption[] = [...BASE_ATTENDANCE_OPTIONS];
  const activeCodes = new Set(leaveTypes.map((leaveType) => leaveType.leave_type_code));

  for (const leaveType of leaveTypes) {
    options.push({
      value: leaveType.leave_type_code,
      label: `${leaveType.leave_type_code} — ${leaveType.name}`,
    });
  }

  if (activeCodes.has('A')) {
    options.push({ value: 'AO', label: 'AO — Annual (Morning, 0.5 day)' });
    options.push({ value: 'OA', label: 'OA — Annual (Afternoon, 0.5 day)' });
  }

  if (activeCodes.has('N')) {
    options.push({ value: 'NO', label: 'NO — No Pay (Morning, 0.5 day)' });
    options.push({ value: 'ON', label: 'ON — No Pay (Afternoon, 0.5 day)' });
  }

  return options;
}
