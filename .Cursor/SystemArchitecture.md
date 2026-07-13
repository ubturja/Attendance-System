# System Architecture Document
# System: MTS Attendance HRMS

## Technology Stack
* **Backend:** PHP (Laravel) - RESTful API, Sanctum Auth, Eloquent ORM.
* **Database:** MySQL (Strictly adhering to ERD.md).
* **Frontend:** React.js - SPA, dynamic tables (Out of scope for backend phase).

## System Modules & Data Flow

### 1. Authentication & Middleware
* Laravel Sanctum provides token-based auth. 
* Route protection relies on custom `CheckRole` middleware (`Admin` vs `Employee`).

### 2. Team & User Management API
* Full CRUD for Admins. 
* System must block Team deletion if active users remain attached.

### 3. Dynamic Leave & Allocation API
* Admins can create/toggle dynamic `leave_types`.
* Admins assign/update decimal balances in `user_yearly_leave_records`.
* **Runtime Calculation:** `remaining_days` is NEVER stored in the DB. The API calculates `(assigned_days - taken_days)` on the fly and appends it to JSON responses.

### 4. Fractional Attendance Processing API
* Receives bulk attendance logs from the frontend.
* **Variant Mapping:** The backend intercepts variant codes (AO, OA, NO, ON) and queries the database using their parent IDs (A, N). 
* **Validation:** Rejects the transaction with `422 Unprocessable Entity` if calculated `remaining_days < 0` after deduction.

### 5. Pivot Reporting Engine (Daily, Monthly, Yearly)
* Generates Admin reports by querying `attendance_logs`.
* **Yearly Report Pivot:** Maps vertical rows from `user_yearly_leave_records` into a flattened, spreadsheet-style JSON object. Calculates `Annual Leave Remaining` and `Total Absences` dynamically.

### 6. CSV Import Engine (Data Seeding)
* An Artisan Command / API Endpoint designed to ingest legacy `.csv` files (e.g., "MTSI Attendance List 2026.xlsx - January.csv").
* Maps spreadsheet cells into explicit `attendance_logs` rows and sums up data into `user_yearly_leave_records`.