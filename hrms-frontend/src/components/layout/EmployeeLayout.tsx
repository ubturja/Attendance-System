import { useState } from 'react';
import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import { LayoutDashboard, LogOut, Menu, UserRound } from 'lucide-react';
import { profileInitials, useCurrentProfile } from '../../hooks/useCurrentProfile';
import { Avatar } from '../ui/Avatar';
import { Button } from '../ui/Button';
import { APP_CONFIG } from '../../config/app';
import { performLogout } from '../../lib/auth';
import { cn } from '../../lib/utils';

interface NavItem {
  label: string;
  to: string;
  end?: boolean;
  icon: typeof LayoutDashboard;
}

const navItems: NavItem[] = [
  { label: 'Dashboard', to: '/employee', end: true, icon: LayoutDashboard },
  { label: 'Profile', to: '/employee/profile', end: true, icon: UserRound },
];

function ProfileTextSkeleton({ className }: { className?: string }) {
  return <span className={cn('inline-block h-4 w-24 animate-pulse rounded bg-slate-200', className)} />;
}

export function EmployeeLayout() {
  const navigate = useNavigate();
  const [isLoggingOut, setIsLoggingOut] = useState(false);
  const [isSidebarOpen, setIsSidebarOpen] = useState(
    () => typeof window !== 'undefined' && window.matchMedia('(min-width: 768px)').matches,
  );
  const { data: profile, isLoading } = useCurrentProfile();

  const displayName = profile?.name ?? '';
  const displayRole = profile?.job_title ?? '';
  const teamName = profile?.team?.team_name ?? '—';
  const avatarInitials = profile !== undefined ? profileInitials(profile.name) : '';

  function toggleSidebar() {
    setIsSidebarOpen((open) => !open);
  }

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
      <aside
        className={cn(
          'fixed inset-y-0 left-0 z-50 flex w-64 flex-col border-r border-slate-200 bg-white transition-all duration-300 ease-in-out md:z-40',
          isSidebarOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0',
        )}
      >
        <div className="flex h-16 items-center gap-3 border-b border-slate-200 px-6">
          <img
            src="/logo.png"
            alt="MTS Logo"
            className="h-10 w-10 shrink-0 rounded-full object-contain"
          />
          <div className="min-w-0">
            <div className="flex min-w-0 items-center gap-2">
              <p className="truncate text-sm font-semibold text-slate-800">{APP_CONFIG.name}</p>
              <span className="shrink-0 rounded-full bg-brand-100 px-2 py-0.5 text-[10px] font-semibold whitespace-nowrap text-brand-600">
                {APP_CONFIG.version}
              </span>
            </div>
            <p className="truncate text-xs text-slate-500">Employee Portal</p>
          </div>
        </div>

        <nav className="flex-1 space-y-1 px-3 py-4" aria-label="Employee navigation">
          {navItems.map((item) => {
            const Icon = item.icon;
            return (
              <NavLink
                key={item.to}
                to={item.to}
                end={item.end}
                className={({ isActive }) =>
                  cn(
                    'flex items-center gap-3 px-3 py-2.5 text-sm font-medium transition-colors',
                    isActive
                      ? 'border-r-4 border-brand bg-brand-50 font-semibold text-brand'
                      : 'rounded-md text-slate-600 hover:bg-brand-50 hover:text-brand',
                  )
                }
              >
                <Icon className="h-4 w-4 shrink-0" aria-hidden="true" />
                {item.label}
              </NavLink>
            );
          })}
        </nav>

        <div className="border-t border-slate-200 p-4">
          <p className="text-xs text-slate-500">Signed in as</p>
          <p className="mt-0.5 truncate text-sm font-medium text-slate-800">
            {isLoading ? <ProfileTextSkeleton /> : displayName}
          </p>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className="mt-3 w-full justify-start gap-2 px-2"
            onClick={handleLogout}
            disabled={isLoggingOut}
          >
            <LogOut className="h-4 w-4 shrink-0" aria-hidden="true" />
            {isLoggingOut ? 'Signing out...' : 'Sign out'}
          </Button>
        </div>
      </aside>

      {isSidebarOpen ? (
        <div
          className="fixed inset-0 z-40 bg-black/50 md:hidden"
          onClick={toggleSidebar}
          aria-hidden="true"
        />
      ) : null}

      <div className="flex min-h-screen min-w-0 flex-col pl-0 transition-all duration-300 ease-in-out md:pl-64">
        <header className="sticky top-0 z-10 flex h-16 w-full items-center justify-between border-b border-slate-200 bg-gradient-to-r from-white to-brand-50 px-4 sm:px-6">
          <div className="flex min-w-0 items-center gap-3">
            <button
              type="button"
              onClick={toggleSidebar}
              className="rounded-md p-2 text-slate-600 transition-colors hover:bg-brand-50 hover:text-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand md:hidden"
              aria-label={isSidebarOpen ? 'Close sidebar' : 'Open sidebar'}
              aria-expanded={isSidebarOpen}
            >
              <Menu className="h-5 w-5" aria-hidden="true" />
            </button>

            <div className="min-w-0">
              <p className="text-sm font-medium text-slate-800">Team Attendance</p>
              <p className="text-xs text-slate-500">
                {isLoading ? <ProfileTextSkeleton className="mt-1 h-3 w-20" /> : teamName}
              </p>
            </div>
          </div>

          <div className="flex shrink-0 items-center gap-3">
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
