import { useState } from 'react';
import { Menu } from 'lucide-react';
import { Outlet, useNavigate } from 'react-router-dom';
import { profileInitials, useCurrentProfile } from '../../hooks/useCurrentProfile';
import { Avatar } from '../ui/Avatar';
import { performLogout } from '../../lib/auth';
import { cn } from '../../lib/utils';
import { Sidebar } from './Sidebar';

function ProfileTextSkeleton({ className }: { className?: string }) {
  return <span className={cn('inline-block h-4 w-24 animate-pulse rounded bg-slate-200', className)} />;
}

export function AdminLayout() {
  const navigate = useNavigate();
  const [isLoggingOut, setIsLoggingOut] = useState(false);
  const [isSidebarOpen, setIsSidebarOpen] = useState(
    () => typeof window !== 'undefined' && window.matchMedia('(min-width: 768px)').matches,
  );
  const { data: profile, isLoading } = useCurrentProfile();

  const displayName = profile?.name ?? '';
  const displayRole = profile?.job_title ?? '';
  const avatarInitials = profile !== undefined ? profileInitials(profile.name) : '';

  // Offset only from md up so mobile content is full-bleed (ml/pl 0).
  const sidebarOffsetClass = cn(
    'transition-all duration-300 ease-in-out pl-0',
    isSidebarOpen ? 'md:pl-64' : 'md:pl-20',
  );

  async function handleLogout() {
    setIsLoggingOut(true);
    try {
      await performLogout();
      navigate('/login', { replace: true });
    } finally {
      setIsLoggingOut(false);
    }
  }

  return (
    <div className="min-h-screen bg-slate-100">
      <Sidebar
        isOpen={isSidebarOpen}
        toggleSidebar={() => setIsSidebarOpen(!isSidebarOpen)}
        displayName={displayName}
        isLoading={isLoading}
        isLoggingOut={isLoggingOut}
        onLogout={handleLogout}
      />

      <div className={cn('flex min-h-screen min-w-0 flex-col', sidebarOffsetClass)}>
        <header className="sticky top-0 z-10 flex h-16 w-full items-center justify-between border-b border-slate-200 bg-gradient-to-r from-white to-brand-50 px-4 sm:px-6">
          <button
            type="button"
            onClick={() => setIsSidebarOpen(!isSidebarOpen)}
            className="rounded-md p-2 text-slate-600 transition-colors hover:bg-brand-50 hover:text-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand md:hidden"
            aria-label={isSidebarOpen ? 'Close sidebar' : 'Open sidebar'}
            aria-expanded={isSidebarOpen}
          >
            <Menu className="h-5 w-5" aria-hidden="true" />
          </button>

          <div className="ml-auto flex shrink-0 items-center gap-3">
            <div className="hidden text-right sm:block">
              <p className="text-sm font-medium text-slate-800">
                {isLoading ? <ProfileTextSkeleton /> : displayName}
              </p>
              <p className="text-xs text-slate-500">
                {isLoading ? <ProfileTextSkeleton className="mt-1 h-3 w-16" /> : displayRole}
              </p>
            </div>
            {isLoading ? (
              <div className="h-8 w-8 animate-pulse rounded-full bg-slate-200" aria-hidden="true" />
            ) : (
              <Avatar
                initials={avatarInitials}
                size="sm"
                className="bg-brand text-white"
              />
            )}
          </div>
        </header>

        <main className="w-full min-w-0 flex-1 p-6">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
