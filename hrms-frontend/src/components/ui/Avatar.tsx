import type { HTMLAttributes } from 'react';
import { cn } from '../../lib/utils';

export type AvatarSize = 'sm' | 'md' | 'lg';

export interface AvatarProps extends HTMLAttributes<HTMLDivElement> {
  initials: string;
  size?: AvatarSize;
}

const sizeStyles: Record<AvatarSize, string> = {
  sm: 'h-8 w-8 text-xs',
  md: 'h-10 w-10 text-sm',
  lg: 'h-12 w-12 text-base',
};

function formatInitials(value: string): string {
  return value.trim().slice(0, 2).toUpperCase();
}

export function Avatar({
  className,
  initials,
  size = 'md',
  ...props
}: AvatarProps) {
  return (
    <div
      role="img"
      aria-label={initials}
      className={cn(
        'inline-flex shrink-0 items-center justify-center rounded-full',
        'bg-slate-200 font-semibold tracking-wide text-slate-700',
        sizeStyles[size],
        className,
      )}
      {...props}
    >
      {formatInitials(initials)}
    </div>
  );
}
