# Entity Relationship Diagram (ERD) Specification
# System: MTS Attendance HRMS

This document outlines the finalized relational database schema. Use this strictly as the source of truth for Laravel migrations, constraints, and Eloquent relationships.

## Entity Tables

### 1. users
| Key | Field | Type | Attributes |
| :--- | :--- | :--- | :--- |
| **PK** | `id` | BIGINT | Unsigned, Auto-increment |
| | `name` | VARCHAR(255) | |
| **UK** | `email` | VARCHAR(191) | Unique |
| | `password` | VARCHAR(255) | Hashed |
| | `job_title` | ENUM | ('Admin', 'Employee') |
| | `nationality` | TEXT | Nullable |
| **UK** | `passport_number` | VARCHAR(100) | Unique |
| | `phone_number` | VARCHAR(50) | Nullable |
| | `address` | TEXT | Nullable |
| | `work_type` | VARCHAR(100) | Nullable |
| | `is_active` | BOOLEAN | Default: true |
| **FK** | `team_id` | BIGINT | Unsigned, Nullable |

### 2. teams
| Key | Field | Type | Attributes |
| :--- | :--- | :--- | :--- |
| **PK** | `id` | BIGINT | Unsigned, Auto-increment |
| **UK** | `team_name` | VARCHAR(191) | Unique |
| **FK** | `team_leader_id` | BIGINT | Unsigned, Nullable |

### 3. leave_types
Stores dynamic leave configurations managed by the Admin.
| Key | Field | Type | Attributes |
| :--- | :--- | :--- | :--- |
| **PK** | `id` | BIGINT | Unsigned, Auto-increment |
| **UK** | `leave_type_code` | VARCHAR(50) | Unique (e.g., A, S, M, N) |
| | `name` | VARCHAR(191) | (e.g., Annual Leave) |
| | `is_active` | BOOLEAN | Default: true |

### 4. user_yearly_leave_records
Normalized table storing balance allocations per user, per leave type, per year.
| Key | Field | Type | Attributes |
| :--- | :--- | :--- | :--- |
| **PK** | `id` | BIGINT | Unsigned, Auto-increment |
| **FK** | `user_id` | BIGINT | Unsigned |
| **FK** | `leave_type_id` | BIGINT | Unsigned |
| | `year` | YEAR | (e.g., 2026) |
| | `assigned_days` | DECIMAL(8,2) | Handles 0.5 fractional days |
| | `taken_days` | DECIMAL(8,2) | Handles 0.5 fractional days |

### 5. attendance_logs
| Key | Field | Type | Attributes |
| :--- | :--- | :--- | :--- |
| **PK** | `id` | BIGINT | Unsigned, Auto-increment |
| **FK** | `user_id` | BIGINT | Unsigned |
| **FK** | `team_id` | BIGINT | Unsigned |
| | `date` | DATE | |
| **FK** | `leave_type_id` | BIGINT | Unsigned, Nullable (NULL for 'W' or 'O' codes) |

---

## Entity Relationships
* **teams to users (1:M):** `teams.id` $\rightarrow$ `users.team_id`. Nullable on delete.
* **users to teams (1:1 Leader):** `users.id` $\rightarrow$ `teams.team_leader_id`.
* **leave_types to user_yearly_leave_records (1:M):** `leave_types.id` $\rightarrow$ `user_yearly_leave_records.leave_type_id`.
* **users to user_yearly_leave_records (1:M):** `users.id` $\rightarrow$ `user_yearly_leave_records.user_id`.
* **leave_types to attendance_logs (1:M):** `leave_types.id` $\rightarrow$ `attendance_logs.leave_type_id`.
* **teams to attendance_logs (1:M):** `teams.id` $\rightarrow$ `attendance_logs.team_id`. Preserves historical accuracy if users switch teams.
* **users to attendance_logs (1:M):** `users.id` $\rightarrow$ `attendance_logs.user_id`.