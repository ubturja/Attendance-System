import { Link } from 'react-router-dom';
import { Button } from '../components/ui/Button';
import { APP_CONFIG } from '../config/app';

export default function NotFound() {
  return (
    <div className="flex min-h-screen items-center justify-center bg-gradient-to-br from-white to-brand-50 px-4 py-12">
      <div className="w-full max-w-md text-center">
        <p className="text-xs font-semibold uppercase tracking-wider text-brand">
          {APP_CONFIG.name}
        </p>
        <p className="mt-6 text-6xl font-semibold tracking-tight text-slate-800">404</p>
        <h1 className="mt-3 text-2xl font-semibold tracking-tight text-slate-800">
          Page Not Found
        </h1>
        <p className="mt-2 text-sm text-slate-500">
          The page you are looking for does not exist or has been moved.
        </p>
        <Link to="/" className="mt-8 inline-block">
          <Button size="lg">Go Back Home</Button>
        </Link>
      </div>
    </div>
  );
}
