import { Navigate, useRoutes, type RouteObject } from 'react-router-dom';
import { AdminLayout } from '../components/layout/AdminLayout';
import { EmployeeLayout } from '../components/layout/EmployeeLayout';
import { ProtectedRoute } from '../components/layout/ProtectedRoute';
import DailyReport from '../pages/admin/DailyReport';
import LeaveTypes from '../pages/admin/LeaveTypes';
import MonthlyReport from '../pages/admin/MonthlyReport';
import Teams from '../pages/admin/Teams';
import Users from '../pages/admin/Users';
import YearlyReport from '../pages/admin/YearlyReport';
import Dashboard from '../pages/employee/Dashboard';
import Profile from '../pages/employee/Profile';
import Login from '../pages/auth/Login';

const routes: RouteObject[] = [
  {
    path: '/',
    element: <Navigate to="/login" replace />,
  },
  {
    path: '/login',
    element: <Login />,
  },
  {
    path: '/admin',
    element: (
      <ProtectedRoute allowedRoles={['Admin']}>
        <AdminLayout />
      </ProtectedRoute>
    ),
    children: [
      {
        index: true,
        element: <Navigate to="users" replace />,
      },
      {
        path: 'users',
        element: <Users />,
      },
      {
        path: 'teams',
        element: <Teams />,
      },
      {
        path: 'leave-types',
        element: <LeaveTypes />,
      },
      {
        path: 'reports',
        element: <YearlyReport />,
      },
      {
        path: 'reports/daily',
        element: <DailyReport />,
      },
      {
        path: 'reports/monthly',
        element: <MonthlyReport />,
      },
    ],
  },
  {
    path: '/employee',
    element: (
      <ProtectedRoute allowedRoles={['Employee', 'Admin']}>
        <EmployeeLayout />
      </ProtectedRoute>
    ),
    children: [
      {
        index: true,
        element: <Navigate to="dashboard" replace />,
      },
      {
        path: 'dashboard',
        element: <Dashboard />,
      },
      {
        path: 'profile',
        element: <Profile />,
      },
    ],
  },
];

export function AppRouter() {
  return useRoutes(routes);
}
