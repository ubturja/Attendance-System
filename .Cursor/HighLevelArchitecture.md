# High Level Architecture & Business Logic
# System: MTS Attendance HRMS

## Role-Based Access Control (RBAC)
* **Admin (HR):** Full access. Creates users, manages teams, adds leave types, allocates yearly balances, views/edits reports, and handles CSV imports.
* **Employee:** Restricted. Can only view their own profile, see members of their assigned team, and submit attendance for themselves and their specific team members. Zero access to system reports.

## Dynamic Dropdown Logic
* Frontend attendance dropdowns are populated dynamically via a `GET /api/leave-types` endpoint (`WHERE is_active = true`).
* Standard non-leave codes: `W` (Work from Home), `O` (Work in Office), `X` (OFF/CC). These bypass leave validation and insert `NULL` into `leave_type_id`.

## Fractional Deduction & Variant Mapping Logic
Because of morning/afternoon leave tracking, the system uses DECIMAL(8,2).
* **1.0 Day Deduction Codes:** `A` (Annual), `S` (Sick), `M`, `R`, `B`, `H`, `C`, `N` (No Pay), `MRG`.
* **0.5 Day Deduction Codes (Variants):** * `AO` (Annual Morning) -> Deducts 0.5 from `A` balance.
  * `OA` (Annual Afternoon) -> Deducts 0.5 from `A` balance.
  * `NO` (No Pay Morning) -> Deducts 0.5 from `N` balance.
  * `ON` (No Pay Afternoon) -> Deducts 0.5 from `N` balance.
* **Execution:** Before saving an attendance log, the backend controller maps variants to parent codes, checks if `Remaining Days >= 0.5`, and updates the parent record in `user_yearly_leave_records`.

## Yearly Report Generation Rules
The frontend expects a flattened JSON structure matching the legacy Excel sheets. The API must group by User, output assigned/taken totals for each specific leave type, and calculate:
1. `Annual Leave Remaining = (Assigned Annual - Taken Annual)`
2. `Total Absences = Sum(All Leave Taken)`