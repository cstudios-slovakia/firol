import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

/**
 * Spája Tailwind triedy s podporou conditional / dedup-u.
 * Použitie: cn('p-2', isActive && 'bg-red-500', 'p-4')  // → 'bg-red-500 p-4'
 */
export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}
