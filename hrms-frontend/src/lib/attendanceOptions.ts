export interface LeaveTypeOptionSource {
  leave_type_code: string;
  name: string;
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
 * Builds attendance dropdown options from active leave types (`GET /leave-types`).
 * Annual (A) and No Pay (N) expand to include morning/afternoon half-day variants.
 * Work from Home (W) is included only when present in the active leave-types payload.
 */
export function buildAttendanceOptions(leaveTypes: LeaveTypeOptionSource[]): AttendanceOption[] {
  const options: AttendanceOption[] = [...BASE_ATTENDANCE_OPTIONS];

  for (const leaveType of leaveTypes) {
    const code = leaveType.leave_type_code.toUpperCase();

    if (code === 'W') {
      options.push({ label: 'Work from Home', value: 'W' });
      continue;
    }

    if (code === 'A') {
      options.push(
        { label: 'Annual Leave (Full Day)', value: 'A' },
        { label: 'Annual Leave (Morning)', value: 'AO' },
        { label: 'Annual Leave (Afternoon)', value: 'OA' },
      );
      continue;
    }

    if (code === 'N') {
      options.push(
        { label: 'No Pay Leave (Full Day)', value: 'N' },
        { label: 'No Pay Leave (Morning)', value: 'NO' },
        { label: 'No Pay Leave (Afternoon)', value: 'ON' },
      );
      continue;
    }

    options.push({
      label: `${leaveType.name} (Full Day)`,
      value: leaveType.leave_type_code,
    });
  }

  return options;
}
