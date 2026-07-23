import { NavLink } from 'react-router-dom';
import {
  CalendarDays,
  ChevronLeft,
  ChevronRight,
  ClipboardList,
  LayoutDashboard,
  LogOut,
  Users,
  UsersRound,
} from 'lucide-react';
import { Button } from '../ui/Button';
import { APP_CONFIG } from '../../config/app';
import { cn } from '../../lib/utils';

interface NavItem {
  label: string;
  to: string;
  icon: typeof Users;
}

const navItems: NavItem[] = [
  { label: 'Dashboard', to: '/admin/dashboard', icon: LayoutDashboard },
  { label: 'Users', to: '/admin/users', icon: Users },
  { label: 'Teams', to: '/admin/teams', icon: UsersRound },
  { label: 'Leave Types', to: '/admin/leave-types', icon: CalendarDays },
  { label: 'Reports', to: '/admin/reports', icon: ClipboardList },
];

export interface SidebarProps {
  isOpen: boolean;
  toggleSidebar: () => void;
  displayName: string;
  isLoading: boolean;
  isLoggingOut: boolean;
  onLogout: () => void;
}

function ProfileTextSkeleton({ className }: { className?: string }) {
  return <span className={cn('inline-block h-4 w-24 animate-pulse rounded bg-slate-200', className)} />;
}

export function Sidebar({
  isOpen,
  toggleSidebar,
  displayName,
  isLoading,
  isLoggingOut,
  onLogout,
}: SidebarProps) {
  return (
    <>
      <aside
        className={cn(
          'fixed inset-y-0 left-0 z-50 flex flex-col border-r border-slate-200 bg-white transition-all duration-300 ease-in-out md:z-40',
          isOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0',
          isOpen ? 'w-64' : 'w-64 md:w-20',
        )}
      >
        <div
          className={cn(
            'flex h-16 items-center border-b border-slate-200',
            isOpen ? 'gap-3 px-6' : 'justify-center px-2',
          )}
        >
          <img
            src="/logo.png"
            alt="MTS Logo"
            className="h-10 w-10 shrink-0 rounded-full object-contain"
          />
          <div
            className={cn(
              'min-w-0 whitespace-nowrap transition-opacity duration-300',
              isOpen ? 'opacity-100' : 'hidden opacity-0',
            )}
          >
            <div className="flex min-w-0 items-center gap-2">
              <p className="truncate text-sm font-semibold text-slate-800">{APP_CONFIG.name}</p>
              <span className="shrink-0 rounded-full bg-brand-100 px-2 py-0.5 text-[10px] font-semibold whitespace-nowrap text-brand-600">
                {APP_CONFIG.version}
              </span>
            </div>
            <p className="truncate text-xs text-slate-500">Admin Console</p>
          </div>
        </div>

        <div className={cn('border-b border-slate-200', isOpen ? 'px-3 py-2' : 'px-2 py-2')}>
          <button
            type="button"
            onClick={toggleSidebar}
            className={cn(
              'flex w-full items-center rounded-md px-3 py-2 text-sm font-medium text-slate-600 transition-colors hover:bg-brand-50 hover:text-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand',
              isOpen ? 'justify-end' : 'justify-center',
            )}
            aria-label={isOpen ? 'Collapse sidebar' : 'Expand sidebar'}
            aria-expanded={isOpen}
          >
            {isOpen ? (
              <ChevronLeft className="h-4 w-4 shrink-0" aria-hidden="true" />
            ) : (
              <ChevronRight className="h-4 w-4 shrink-0" aria-hidden="true" />
            )}
          </button>
        </div>

        <nav className="flex-1 space-y-1 px-3 py-4" aria-label="Admin navigation">
          {navItems.map((item) => {
            const Icon = item.icon;
            return (
              <NavLink
                key={item.to + item.label}
                to={item.to}
                end={item.to !== '/admin/reports'}
                title={isOpen ? undefined : item.label}
                className={({ isActive }) =>
                  cn(
                    'flex items-center gap-3 px-3 py-2.5 text-sm font-medium transition-colors',
                    isOpen ? '' : 'justify-center',
                    isActive
                      ? 'border-r-4 border-brand bg-brand-50 font-semibold text-brand'
                      : 'rounded-md text-slate-600 hover:bg-brand-50 hover:text-brand',
                  )
                }
              >
                <Icon className="h-4 w-4 shrink-0" aria-hidden="true" />
                <span
                  className={cn(
                    'whitespace-nowrap transition-opacity duration-300',
                    isOpen ? 'opacity-100' : 'hidden opacity-0',
                  )}
                >
                  {item.label}
                </span>
              </NavLink>
            );
          })}
        </nav>

        <div className={cn('border-t border-slate-200', isOpen ? 'p-4' : 'p-2')}>
          <div
            className={cn(
              'whitespace-nowrap transition-opacity duration-300',
              isOpen ? 'opacity-100' : 'hidden opacity-0',
            )}
          >
            <p className="text-xs text-slate-500">Signed in as</p>
            <p className="mt-0.5 truncate text-sm font-medium text-slate-800">
              {isLoading ? <ProfileTextSkeleton /> : displayName}
            </p>
          </div>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className={cn(
              'justify-start gap-2 px-2',
              isOpen ? 'mt-3 w-full' : 'mx-auto mt-0 w-auto justify-center',
            )}
            onClick={onLogout}
            disabled={isLoggingOut}
            title={isLoggingOut ? 'Signing out...' : 'Sign out'}
            aria-label={isLoggingOut ? 'Signing out...' : 'Sign out'}
          >
            <LogOut className="h-4 w-4 shrink-0" aria-hidden="true" />
            <span
              className={cn(
                'whitespace-nowrap transition-opacity duration-300',
                isOpen ? 'opacity-100' : 'hidden opacity-0',
              )}
            >
              {isLoggingOut ? 'Signing out...' : 'Sign out'}
            </span>
          </Button>
        </div>
      </aside>

      {isOpen ? (
        <div
          className="fixed inset-0 z-40 bg-black/50 md:hidden"
          onClick={toggleSidebar}
          aria-hidden="true"
        />
      ) : null}
    </>
  );
}
