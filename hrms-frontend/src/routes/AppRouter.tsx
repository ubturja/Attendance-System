import { Navigate, useRoutes, type RouteObject } from 'react-router-dom';
import { AdminLayout } from '../components/layout/AdminLayout';
import { EmployeeLayout } from '../components/layout/EmployeeLayout';
import { ProtectedRoute } from '../components/layout/ProtectedRoute';
import AdminDashboard from '../pages/admin/Dashboard';
import LeaveTypes from '../pages/admin/LeaveTypes';
import Reports from '../pages/admin/Reports';
import Teams from '../pages/admin/Teams';
import Users from '../pages/admin/Users';
import Dashboard from '../pages/employee/Dashboard';
import Profile from '../pages/employee/Profile';
import Login from '../pages/auth/Login';
import NotFound from '../pages/NotFound';

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
        element: <Navigate to="/admin/dashboard" replace />,
      },
      {
        path: 'dashboard',
        element: <AdminDashboard />,
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
        element: <Reports />,
      },
      {
        path: 'reports/daily',
        element: <Navigate to="/admin/reports?tab=daily" replace />,
      },
      {
        path: 'reports/monthly',
        element: <Navigate to="/admin/reports?tab=monthly" replace />,
      },
    ],
  },
  {
    path: '/employee',
    element: (
      <ProtectedRoute allowedRoles={['Employee']}>
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
  {
    path: '*',
    element: <NotFound />,
  },
];

export function AppRouter() {
  return useRoutes(routes);
}
