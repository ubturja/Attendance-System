# Product Requirements Document (PRD)
# Product: MTS Attendance System

## 1. Overview
A digital HR Management System (HRMS) designed to replace spreadsheet-based attendance tracking. It handles dynamic leave management, fractional day deductions, and automated report generation.

## 2. User Roles & Functionality

### Admin (HR)
* **Account Management:** Create users (no self-registration). Edit profiles, set `work_type`. (Name, Passport, Email cannot be changed after creation).
* **Team Management:** Create teams, assign Team Leaders (status only), move users. Cannot delete a team if active users exist.
* **Leave Management:** Add custom leave types dynamically. Toggle `is_active` status. Assign yearly leave quotas (decimals) to employees.
* **Reporting:** View/Edit Daily Reports. View Monthly and Yearly Reports.
* **Data Import:** Import legacy `.csv` files for historical data ingestion.

### Employee
* **Profile:** Read-only access to own profile (`GET /api/profile`). 
* **Team Visibility:** Can only see colleagues assigned to the same `team_id`.
* **Attendance Entry:** Can mark daily attendance for themselves AND other members of their specific team using a dropdown menu. 

## 3. Core Features

### Fractional Leave Tracking
To accommodate half-days, leave allocations are tracked in decimals (e.g., 14.5 days).
* `AO` / `OA` deduct 0.5 days from the Annual Leave (A) balance.
* `NO` / `ON` deduct 0.5 days from the No Pay Leave (N) balance.

### Report Formats (Admin View)
* **Daily Report:** List of all users, sorted by Team Name, showing Date and Attendance Type. Admin has a dropdown to override mistakes.
* **Monthly Report:** Matrix of Users vs Days of the Month (1-31). Shows total Annual, Sick, and Other leaves at the end of the row.
* **Yearly Report:** Excel-style summary matrix. Columns for every leave category showing assigned/taken values. Calculates `Annual Leave Remaining` and `Total Absences` at runtime.

### Data Validation
* The system must strictly block users from submitting a leave type if their calculated `Remaining Balance` for that category is exactly 0.