export interface LeaveTypeOptionSource {
  leave_type_code: string;
  name: string;
  is_active?: boolean;
  deleted_at?: string | null;
}

export interface AttendanceOption {
  label: string;
  value: string;
}

/**
 * Hardcoded base codes that are NOT managed in leave_types.
 * Work from Home (`W`) is seeded in the DB and added dynamically when active.
 */
const BASE_ATTENDANCE_OPTIONS: AttendanceOption[] = [
  { label: 'Present', value: 'O' },
  { label: 'OFF / CC', value: 'X' },
];

/**
 * Builds attendance dropdown options from the supplied leave-type catalog.
 * Annual (A) and No Pay (N) expand to include morning/afternoon half-day variants.
 * Work from Home (W) is included only when present in the payload. Admin report
 * catalogs may include inactive/archived types; those options are visibly labelled.
 */
export function buildAttendanceOptions(leaveTypes: LeaveTypeOptionSource[]): AttendanceOption[] {
  const options: AttendanceOption[] = [...BASE_ATTENDANCE_OPTIONS];

  for (const leaveType of leaveTypes) {
    const code = leaveType.leave_type_code.toUpperCase();
    const legacySuffix = leaveType.deleted_at
      ? ' (Archived)'
      : leaveType.is_active === false
        ? ' (Inactive)'
        : '';

    if (code === 'W') {
      options.push({ label: `Work from Home${legacySuffix}`, value: 'W' });
      continue;
    }

    if (code === 'A') {
      options.push(
        { label: `Annual Leave (Full Day)${legacySuffix}`, value: 'A' },
        { label: `Annual Leave (Morning)${legacySuffix}`, value: 'AO' },
        { label: `Annual Leave (Afternoon)${legacySuffix}`, value: 'OA' },
      );
      continue;
    }

    if (code === 'N') {
      options.push(
        { label: `No Pay Leave (Full Day)${legacySuffix}`, value: 'N' },
        { label: `No Pay Leave (Morning)${legacySuffix}`, value: 'NO' },
        { label: `No Pay Leave (Afternoon)${legacySuffix}`, value: 'ON' },
      );
      continue;
    }

    options.push({
      label: `${leaveType.name} (Full Day)${legacySuffix}`,
      value: leaveType.leave_type_code,
    });
  }

  return options;
}
