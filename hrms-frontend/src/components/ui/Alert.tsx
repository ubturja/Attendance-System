import { AlertCircle, CheckCircle2, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '../../lib/utils';

export type AlertVariant = 'error' | 'success' | 'warning';

const VARIANT_CONFIG: Record<
  AlertVariant,
  { containerClass: string; Icon: LucideIcon; role: 'alert' | 'status' }
> = {
  error: {
    containerClass: 'border-red-200 bg-red-50 text-red-800',
    Icon: AlertCircle,
    role: 'alert',
  },
  success: {
    containerClass: 'border-emerald-200 bg-emerald-50 text-emerald-900',
    Icon: CheckCircle2,
    role: 'status',
  },
  warning: {
    containerClass: 'border-amber-200 bg-amber-50 text-amber-900',
    Icon: AlertCircle,
    role: 'alert',
  },
};

export interface AlertProps {
  variant: AlertVariant;
  children: ReactNode;
  className?: string;
}

export function Alert({ variant, children, className }: AlertProps) {
  const { containerClass, Icon, role } = VARIANT_CONFIG[variant];

  return (
    <div
      role={role}
      className={cn(
        'flex items-start gap-3 rounded-md border px-4 py-3 text-sm',
        containerClass,
        className,
      )}
    >
      <Icon className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
      <p className="min-w-0 flex-1">{children}</p>
    </div>
  );
}
