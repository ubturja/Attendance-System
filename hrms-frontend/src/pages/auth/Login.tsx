import { useMutation } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { Alert } from '../../components/ui/Alert';
import api, { setAuthToken } from '../../lib/api';
import { getApiErrorMessage } from '../../lib/errors';
import {
  USER_ROLE_STORAGE_KEY,
  type UserRole,
} from '../../components/layout/ProtectedRoute';

interface LoginCredentials {
  email: string;
  password: string;
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
    mutationFn: loginRequest,
    onSuccess: (response) => {
      const { token, job_title: role } = response.data;

      setAuthToken(token);
      localStorage.setItem(USER_ROLE_STORAGE_KEY, role);

      if (role === 'Admin') {
        navigate('/admin/users', { replace: true });
        return;
      }

      navigate('/employee/dashboard', { replace: true });
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

  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-100 px-4 py-12">
      <div className="w-full max-w-md rounded-lg border border-slate-200 bg-white p-8 shadow-sm">
        <div className="mb-8 text-center">
          <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">
            MTS Attendance
          </p>
          <h1 className="mt-2 text-2xl font-semibold tracking-tight text-slate-900">
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
            disabled={loginMutation.isPending}
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
            disabled={loginMutation.isPending}
          />

          <Button
            type="submit"
            variant="primary"
            size="lg"
            className="w-full"
            disabled={loginMutation.isPending}
          >
            {loginMutation.isPending ? 'Logging in...' : 'Sign in'}
          </Button>
        </form>
      </div>
    </div>
  );
}
