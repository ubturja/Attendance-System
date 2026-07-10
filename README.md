# MTS Attendance HRMS

A digital Human Resource Management System (HRMS) that replaces spreadsheet-based attendance tracking. It supports dynamic leave types, fractional half-day deductions, team-scoped attendance entry, and admin reporting (daily, monthly, yearly).

The project is split into two applications:

| Directory | Role | Stack |
|-----------|------|-------|
| [`hrms-backend/`](hrms-backend/) | REST API | PHP 8.3, Laravel 13, MySQL, Laravel Sanctum |
| [`hrms-frontend/`](hrms-frontend/) | Single-page app | React 19, TypeScript, Vite 8, Tailwind CSS, TanStack Query |

---

## Table of Contents

- [Features](#features)
- [Architecture](#architecture)
- [Database Schema](#database-schema)
- [Prerequisites](#prerequisites)
- [Getting Started](#getting-started)
- [Environment Variables](#environment-variables)
- [Frontend ↔ Backend Connection](#frontend--backend-connection)
- [Authentication](#authentication)
- [API Reference](#api-reference)
- [Attendance & Leave Rules](#attendance--leave-rules)
- [Frontend Routes](#frontend-routes)
- [Testing](#testing)
- [Project Structure](#project-structure)
- [Documentation](#documentation)
- [Troubleshooting](#troubleshooting)

---

## Features

### Admin (HR)

- **User management** — Create users (no self-registration), edit profiles, assign teams and `work_type`. `name`, `email`, and `passport_number` are immutable after creation.
- **Team management** — Create teams, assign team leaders, move users between teams. Teams with active members cannot be deleted.
- **Leave types** — Add custom leave types dynamically (e.g. `A`, `S`, `MRG`). Toggle `is_active` without deleting history.
- **Leave allocation** — Assign decimal yearly quotas per employee and leave type. New hires are seeded with zero-balance rows for all active leave types.
- **Leave rollover** — Copy allocations from one year to the next (idempotent; skips users who already have target-year rows).
- **Reports** — Daily list (with admin override), monthly matrix (users × days), yearly pivot (assigned/taken per leave type with computed totals).
- **Attendance override** — Admins can correct daily attendance codes from the Daily Report screen.

### Employee

- **Profile** — Read-only view of own profile and leave balances.
- **Team visibility** — See only colleagues on the same `team_id`.
- **Attendance entry** — Submit daily attendance for self and all active team members via a dropdown grid.

---

## Architecture

```
┌─────────────────────┐         Bearer token          ┌─────────────────────┐
│   hrms-frontend     │  ───────────────────────────► │   hrms-backend      │
│   React SPA         │         JSON /api/*           │   Laravel REST API  │
│   localhost:5173    │                               │   localhost:8000    │
└─────────────────────┘                               └──────────┬──────────┘
                                                                 │
                                                                 ▼
                                                      ┌─────────────────────┐
                                                      │       MySQL         │
                                                      │      hrms_db        │
                                                      └─────────────────────┘
```

### Backend modules

| Module | Description |
|--------|-------------|
| **Authentication** | Sanctum personal access tokens; `CheckRole` middleware (`Admin` / `Employee`) |
| **Team & User CRUD** | Admin-only user and team management |
| **Dynamic Leave & Allocation** | Leave type catalog, yearly balance assignment, rollover |
| **Attendance Processing** | Bulk submission with variant mapping, balance validation, atomic transactions |
| **Reporting** | Daily, monthly matrix, yearly pivot endpoints |

### API response format

All endpoints return a consistent JSON envelope:

```json
{
  "success": true,
  "message": "Human-readable status message.",
  "data": { }
}
```

Error responses use the same shape with `"success": false` and appropriate HTTP status codes (`401`, `403`, `404`, `422`).

---

## Database Schema

Schema follows [`.Cursor/ERD.md`](.Cursor/ERD.md) as the source of truth.

| Table | Purpose |
|-------|---------|
| `teams` | Organizational units; optional `team_leader_id` |
| `users` | Admin and Employee accounts; `job_title` enum, optional `team_id` |
| `leave_types` | Dynamic leave catalog (`leave_type_code`, `name`, `is_active`) |
| `user_yearly_leave_records` | Per-user, per-type, per-year `assigned_days` / `taken_days` (DECIMAL 8,2) |
| `attendance_logs` | Daily attendance rows; snapshots `team_id`; stores `submitted_code` and resolved `leave_type_id` |
| `personal_access_tokens` | Sanctum Bearer tokens |

**Key rules:**

- `remaining_days` is **never stored** — computed at runtime as `assigned_days - taken_days`.
- `attendance_logs.team_id` is resolved server-side from the employee's current team assignment (not sent by the frontend).
- One attendance log per user per date (unique constraint on `user_id` + `date`).

---

## Prerequisites

| Tool | Version | Notes |
|------|---------|-------|
| **PHP** | 8.3+ | Required by Laravel 13 |
| **Composer** | 2.x | PHP dependency manager |
| **MySQL** | 8.x | Database server |
| **Node.js** | 20+ (LTS recommended) | Via [nvm](https://github.com/nvm-sh/nvm) if not installed globally |
| **npm** | 10+ | Bundled with Node.js |

---

## Getting Started

### 1. Database

Create the MySQL database:

```sql
CREATE DATABASE hrms_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 2. Backend setup

```bash
cd hrms-backend

cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve
```

The API will be available at **http://127.0.0.1:8000**.

Verify health: **http://127.0.0.1:8000/up**

### 3. Frontend setup

If `npm` is not found, load nvm first:

```bash
source ~/.zshrc
# or: export NVM_DIR="$HOME/.nvm" && . "$NVM_DIR/nvm.sh" && nvm use
```

Then:

```bash
cd hrms-frontend

cp .env.example .env
npm install
npm run dev
```

The app will be available at **http://localhost:5173**.

### 4. Create initial users

There is no self-registration. Create an Admin user directly in the database, or use Tinker:

```bash
cd hrms-backend
php artisan tinker
```

```php
use App\Models\User;
use Illuminate\Support\Facades\Hash;

User::create([
    'name' => 'HR Admin',
    'email' => 'admin@example.com',
    'password' => Hash::make('password'),
    'job_title' => 'Admin',
    'passport_number' => 'P12345678',
    'is_active' => true,
]);
```

For full end-to-end testing, also create teams, employees, leave types, and allocations via the Admin UI. See [`hrms-backend/QA_HAPPY_PATH.md`](hrms-backend/QA_HAPPY_PATH.md) for a step-by-step manual QA checklist.

---

## Environment Variables

### Backend (`hrms-backend/.env`)

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_URL` | `http://127.0.0.1:8000` | Laravel application URL |
| `FRONTEND_URL` | `http://localhost:5173` | React SPA origin for CORS |
| `CORS_ALLOWED_ORIGINS` | *(falls back to `FRONTEND_URL`)* | Comma-separated allowed origins |
| `DB_DATABASE` | `hrms_db` | MySQL database name |
| `DB_USERNAME` | `root` | MySQL username |
| `DB_PASSWORD` | *(empty)* | MySQL password |

See [`hrms-backend/.env.example`](hrms-backend/.env.example) for the full list.

### Frontend

| File | Variable | Value | Used when |
|------|----------|-------|-----------|
| `.env.development` | `VITE_API_BASE_URL` | `/api` | `npm run dev` — proxied to Laravel |
| `.env` | `VITE_API_BASE_URL` | `http://127.0.0.1:8000` | `npm run build` / `npm run preview` |

Copy from [`.env.example`](hrms-frontend/.env.example) if starting fresh.

---

## Frontend ↔ Backend Connection

### Development (`npm run dev`)

The Vite dev server proxies API requests so the browser stays same-origin:

```
Browser  →  http://localhost:5173/api/login
Vite proxy  →  http://127.0.0.1:8000/api/login
```

Configured in [`hrms-frontend/vite.config.js`](hrms-frontend/vite.config.js) and [`hrms-frontend/.env.development`](hrms-frontend/.env.development).

### Production build

The built SPA calls the Laravel API directly using the full URL from `VITE_API_BASE_URL`. CORS is configured in [`hrms-backend/config/cors.php`](hrms-backend/config/cors.php).

### API client

All HTTP calls go through a single Axios instance at [`hrms-frontend/src/lib/api.ts`](hrms-frontend/src/lib/api.ts):

- Base URL resolved from `VITE_API_BASE_URL` (always normalized to end in `/api`)
- `Authorization: Bearer <token>` attached from `localStorage`
- Global 401 → logout and redirect to `/login`
- Global 403 on admin/report routes → role reconciliation via `GET /profile`

---

## Authentication

Authentication uses **stateless Sanctum Bearer tokens** (not cookie-based SPA sessions).

```
POST /api/login          →  { token, token_type: "Bearer", job_title }
Authorization: Bearer …  →  all protected routes
POST /api/logout         →  revokes current token
GET  /api/profile        →  session gate + RBAC source of truth
```

| Role | Access |
|------|--------|
| **Admin** | All `/api/admin/*` and `/api/reports/*` routes |
| **Employee** | `/api/profile`, `/api/leave-types`, `/api/attendance` (team-scoped) |

Frontend token storage (`localStorage`):

- `auth_token` — Sanctum Bearer token
- `user_role` — cached `Admin` or `Employee` (synced from server on profile load)

---

## API Reference

Base URL: `http://127.0.0.1:8000/api`

### Public

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/login` | Authenticate and receive Bearer token |

### Authenticated (`auth:sanctum`)

| Method | Path | Role | Description |
|--------|------|------|-------------|
| `POST` | `/logout` | Any | Revoke current token |
| `GET` | `/profile` | Any | Own profile, team roster, leave balances |
| `GET` | `/leave-types` | Any | Active leave types (attendance dropdowns) |
| `POST` | `/attendance` | Any | Bulk attendance submission |
| `PUT` | `/attendance/{id}` | Admin | Override attendance code on existing log |

### Admin (`auth:sanctum` + `role:Admin`, prefix `/admin`)

| Method | Path | Description |
|--------|------|-------------|
| `GET/POST` | `/users` | List / create users |
| `GET/PUT/PATCH/DELETE` | `/users/{user}` | Show / update / deactivate user |
| `GET/POST` | `/teams` | List / create teams |
| `GET/PUT/PATCH/DELETE` | `/teams/{team}` | Show / update / delete team |
| `GET` | `/leave-types` | Full leave type catalog (including inactive) |
| `POST` | `/leave-types` | Create leave type |
| `PUT` | `/leave-types/{leaveType}` | Update (toggle `is_active`) |
| `GET` | `/leave-allocations` | List all allocations |
| `GET` | `/leave-allocations/{user}` | User's balance rows |
| `PUT` | `/leave-allocations/{user}` | Assign or adjust balances |
| `POST` | `/leave-rollover` | Copy allocations between years |

### Reports (`auth:sanctum` + `role:Admin`, prefix `/reports`)

| Method | Path | Query params | Description |
|--------|------|--------------|-------------|
| `GET` | `/daily` | `?date=YYYY-MM-DD` | Daily attendance list |
| `GET` | `/monthly` | `?year=&month=` | Monthly matrix |
| `GET` | `/yearly` | `?year=` | Yearly pivot summary |

---

## Attendance & Leave Rules

### Attendance codes

| Category | Codes | Balance impact |
|----------|-------|----------------|
| **Non-leave** | `W`, `O`, `X` | No deduction; `leave_type_id` stored as `NULL` |
| **Full-day leave** | `A`, `S`, `M`, `R`, `B`, `H`, `C`, `N`, custom codes | Deduct **1.0** day from matching leave type |
| **Half-day variants** | `AO`, `OA` → parent `A` | Deduct **0.5** from Annual Leave balance |
| **Half-day variants** | `NO`, `ON` → parent `N` | Deduct **0.5** from No Pay Leave balance |

Variant codes (`AO`, `OA`, etc.) exist only in the frontend — they are **not** rows in `leave_types`. The backend maps them via `AttendanceVariantMapper`.

### Validation

- Submission is **atomic** — if any record in a batch fails, the entire transaction rolls back.
- Employees may only submit attendance for users on their own team; Admins are unrestricted.
- `team_id` is resolved from the target employee's database record (never trusted from the client).
- Duplicate `(user_id, date)` submissions are rejected.
- Insufficient leave balance returns **422**; missing allocation returns **404**.

### Attendance payload (frontend → backend)

```json
{
  "records": [
    {
      "user_id": 5,
      "date": "2026-07-10",
      "code": "A"
    }
  ]
}
```

---

## Frontend Routes

| Path | Role | Page |
|------|------|------|
| `/login` | Public | Sign in |
| `/admin/users` | Admin | User management + leave allocation |
| `/admin/teams` | Admin | Team management |
| `/admin/leave-types` | Admin | Leave type catalog |
| `/admin/reports` | Admin | Yearly report |
| `/admin/reports/daily` | Admin | Daily report + override |
| `/admin/reports/monthly` | Admin | Monthly matrix |
| `/employee/dashboard` | Employee, Admin | Leave balances + team attendance |
| `/employee/profile` | Employee, Admin | Read-only profile |

---

## Testing

### Backend

```bash
cd hrms-backend
php artisan test
```

Feature tests include attendance security (team scoping, RBAC, duplicate rejection) in [`tests/Feature/AttendanceSecurityTest.php`](hrms-backend/tests/Feature/AttendanceSecurityTest.php).

### Frontend

```bash
cd hrms-frontend
npm run lint
npm run build    # verifies TypeScript compilation and production bundle
```

### Manual QA

Follow the step-by-step checklist in [`hrms-backend/QA_HAPPY_PATH.md`](hrms-backend/QA_HAPPY_PATH.md).

---

## Project Structure

```
MTS Attn.Sys./
├── README.md                 ← this file
├── .Cursor/                  ← architecture & product docs
│   ├── ERD.md
│   ├── info.md               ← PRD
│   ├── SystemArchitecture.md
│   └── HighLevelArchitecture.md
│
├── hrms-backend/
│   ├── app/
│   │   ├── Http/Controllers/Api/     ← REST controllers
│   │   ├── Http/Middleware/          ← CheckRole (RBAC)
│   │   ├── Http/Requests/            ← Form request validation
│   │   ├── Models/                   ← Eloquent models
│   │   └── Services/Attendance/      ← Variant mapping, validation
│   ├── config/cors.php               ← SPA CORS policy
│   ├── database/migrations/          ← MySQL schema
│   ├── routes/api.php                ← API route definitions
│   ├── tests/                        ← PHPUnit feature tests
│   └── QA_HAPPY_PATH.md              ← Manual QA checklist
│
└── hrms-frontend/
    ├── src/
    │   ├── lib/api.ts                ← Axios client + auth interceptors
    │   ├── pages/admin/              ← Admin screens
    │   ├── pages/employee/           ← Employee screens
    │   ├── pages/auth/               ← Login
    │   ├── components/               ← Layout + UI primitives
    │   └── routes/AppRouter.tsx      ← React Router config
    ├── vite.config.js                ← Dev server + API proxy
    ├── .env.development              ← Dev API config (proxy)
    └── .env.example                  ← Production API config template
```

---

## Documentation

| Document | Location | Contents |
|----------|----------|----------|
| **PRD** | [`.Cursor/info.md`](.Cursor/info.md) | Product requirements, roles, features |
| **ERD** | [`.Cursor/ERD.md`](.Cursor/ERD.md) | Database schema specification |
| **System Architecture** | [`.Cursor/SystemArchitecture.md`](.Cursor/SystemArchitecture.md) | Backend modules and data flow |
| **Business Logic** | [`.Cursor/HighLevelArchitecture.md`](.Cursor/HighLevelArchitecture.md) | RBAC, fractional deduction, reporting rules |
| **QA Happy Path** | [`hrms-backend/QA_HAPPY_PATH.md`](hrms-backend/QA_HAPPY_PATH.md) | End-to-end manual test checklist |

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| `command not found: npm` | Node.js not on PATH | Run `source ~/.zshrc` or `nvm use` |
| `command not found: php` | PHP not installed | Install PHP 8.3+ or use [Laravel Herd](https://herd.laravel.com/) |
| CORS errors in browser | Frontend calling API directly without proxy | Use `npm run dev` (proxy) or set `FRONTEND_URL` in backend `.env` |
| 401 on all API calls | Missing or expired token | Log in again at `/login` |
| 403 on admin pages | Logged in as Employee | Use an Admin account |
| 422 on attendance submit | Insufficient leave balance | Increase allocation via Admin → Users → Assign Leave |
| 404 on attendance submit | No allocation row for leave type | Assign leave for that employee and year |
| Empty team on dashboard | Employee has no `team_id` | Assign the user to a team via Admin → Users |
| `MRG` missing from dropdown | Leave type inactive | Toggle Active in Admin → Leave Types |

---

## License

Private / internal project — MTS Attendance System.
