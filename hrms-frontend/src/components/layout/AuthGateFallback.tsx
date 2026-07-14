import { Loader2 } from 'lucide-react';

/** Shown while ProtectedRoute verifies the Sanctum session against the API. */
export function AuthGateFallback() {
  return (
    <div
      className="flex min-h-screen items-center justify-center bg-slate-100"
      role="status"
      aria-live="polite"
      aria-label="Verifying session"
    >
      <div className="flex items-center gap-2 text-sm text-slate-600">
        <Loader2 className="h-5 w-5 animate-spin" aria-hidden="true" />
        Verifying session…
      </div>
    </div>
  );
}
