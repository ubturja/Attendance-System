import { useMutation } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { Alert } from '../../components/ui/Alert';
import api, {
  ADMIN_HOME_PATH,
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

interface LoginUser {
  id: number;
  name: string;
  email: string;
  job_title: UserRole;
}

interface LoginUserData {
  token: string;
  token_type: string;
  user: LoginUser;
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

function isUserRole(value: string): value is UserRole {
  return value === 'Admin' || value === 'Employee';
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
    mutationFn: (credentials: LoginCredentials) => loginRequest(credentials),
    onSuccess: (response) => {
      const { token, user } = response.data;
      const userRole = user.job_title;

      if (!isUserRole(userRole)) {
        setErrorMessage('Unable to determine account role. Please contact an administrator.');
        return;
      }

      // Persist session before navigation so protected routes see a valid token/role.
      queryClient.clear();
      setAuthToken(token);
      localStorage.setItem(USER_ROLE_STORAGE_KEY, userRole);

      if (userRole === 'Admin') {
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
    event.preventDefault();
    setErrorMessage(undefined);
    loginMutation.mutate({ email, password });
  }

  const isPending = loginMutation.isPending;

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
            Enter your credentials to access the HRMS platform.
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

          <Button
            type="submit"
            size="lg"
            className="w-full bg-brand hover:bg-brand-600 text-white"
            disabled={isPending}
          >
            {isPending ? 'Logging in...' : 'Log In'}
          </Button>
        </form>
      </div>
    </div>
  );
}
