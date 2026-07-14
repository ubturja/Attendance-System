import { useMutation } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { Alert } from '../../components/ui/Alert';
import api, {
  ADMIN_HOME_PATH,
  clearAuthSession,
  EMPLOYEE_DASHBOARD_PATH,
  setAuthToken,
  USER_ROLE_STORAGE_KEY,
} from '../../lib/api';
import { getApiErrorMessage } from '../../lib/errors';
import { queryClient } from '../../lib/queryClient';
import { type UserRole } from '../../components/layout/ProtectedRoute';

interface LoginCredentials {
  email: string;
  password: string;
}

interface LoginMutationVariables extends LoginCredentials {
  intendedRole: UserRole;
}

interface LoginUserData {
  token: string;
  token_type: string;
  job_title: UserRole;
}

interface LoginSuccessResponse {
  success: true;
  message: string;
  data: LoginUserData;
}

function getLoginErrorMessage(error: unknown): string {
  return getApiErrorMessage(error, 'Unable to sign in. Please try again.');
}

function getRoleMismatchMessage(intendedRole: UserRole): string {
  if (intendedRole === 'Admin') {
    return 'Unauthorized: You do not have Admin privileges.';
  }

  return 'Unauthorized: You do not have Employee privileges.';
}

async function loginRequest(credentials: LoginCredentials): Promise<LoginSuccessResponse> {
  const response = await api.post<LoginSuccessResponse>('/login', credentials);
  return response.data;
}

export default function Login() {
  const navigate = useNavigate();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [errorMessage, setErrorMessage] = useState<string | undefined>();

  const loginMutation = useMutation({
    mutationFn: ({ email, password }: LoginMutationVariables) =>
      loginRequest({ email, password }),
    onSuccess: (response, variables) => {
      // Redirect must use job_title from this response — never localStorage.
      const { token, job_title: role } = response.data;
      const { intendedRole } = variables;

      if (role !== intendedRole) {
        clearAuthSession();
        queryClient.clear();
        setErrorMessage(getRoleMismatchMessage(intendedRole));
        return;
      }

      // Drop any leftover cache from a previous session before entering the app.
      queryClient.clear();
      setAuthToken(token);
      localStorage.setItem(USER_ROLE_STORAGE_KEY, role);

      if (role === 'Admin') {
        navigate(ADMIN_HOME_PATH, { replace: true });
        return;
      }

      navigate(EMPLOYEE_DASHBOARD_PATH, { replace: true });
    },
    onError: (error) => {
      setErrorMessage(getLoginErrorMessage(error));
    },
  });

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    // Role is chosen via the Admin / Employee buttons — Enter alone must not login.
    event.preventDefault();
  }

  function handleLogin(intendedRole: UserRole) {
    setErrorMessage(undefined);
    loginMutation.mutate({ email, password, intendedRole });
  }

  const isPending = loginMutation.isPending;
  const pendingRole = isPending ? loginMutation.variables?.intendedRole : undefined;

  return (
    <div className="flex min-h-screen items-center justify-center bg-gradient-to-br from-white to-brand-50 px-4 py-12">
      <div className="w-full max-w-md rounded-lg border border-slate-200 bg-white p-8 shadow-sm">
        <div className="mb-8 text-center">
          <img
            src="/logo.png"
            alt="MTS Logo"
            className="mx-auto h-14 w-14 rounded-full object-contain"
          />
          <p className="mt-4 text-xs font-semibold uppercase tracking-wider text-brand">
            MTS Attendance
          </p>
          <h1 className="mt-2 text-2xl font-semibold tracking-tight text-slate-800">
            Sign in
          </h1>
          <p className="mt-2 text-sm text-slate-500">
            Enter your credentials and choose how you want to access the HRMS platform.
          </p>
        </div>

        <form className="flex flex-col gap-5" onSubmit={handleSubmit} noValidate>
          {errorMessage !== undefined ? (
            <Alert variant="error">{errorMessage}</Alert>
          ) : null}

          <Input
            id="login-email"
            label="Email"
            type="email"
            name="email"
            autoComplete="email"
            placeholder="name@company.com"
            value={email}
            onChange={(event) => {
              setEmail(event.target.value);
              if (errorMessage !== undefined) {
                setErrorMessage(undefined);
              }
            }}
            disabled={isPending}
          />

          <Input
            id="login-password"
            label="Password"
            type="password"
            name="password"
            autoComplete="current-password"
            placeholder="Enter your password"
            value={password}
            onChange={(event) => {
              setPassword(event.target.value);
              if (errorMessage !== undefined) {
                setErrorMessage(undefined);
              }
            }}
            disabled={isPending}
          />

          <div className="flex flex-col gap-3">
            <Button
              type="button"
              variant="primary"
              size="lg"
              className="w-full"
              disabled={isPending}
              onClick={() => handleLogin('Admin')}
            >
              {pendingRole === 'Admin' ? 'Logging in...' : 'Log In as Admin'}
            </Button>

            <Button
              type="button"
              variant="outline"
              size="lg"
              className="w-full"
              disabled={isPending}
              onClick={() => handleLogin('Employee')}
            >
              {pendingRole === 'Employee' ? 'Logging in...' : 'Log In as Employee'}
            </Button>
          </div>
        </form>
      </div>
    </div>
  );
}
