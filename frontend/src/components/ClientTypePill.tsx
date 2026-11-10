import { Building, Building2 } from 'lucide-react';
import { clientNouns, isBytoveDruzstvo } from '@/lib/clientKind';
import { cn } from '@/lib/cn';

/**
 * Small pill naming the kind of client: Firma (red tint) or Bytové družstvo
 * (blue tint). Always visible, next to the client's title.
 */
export function ClientTypePill({
  clientType,
  className,
}: {
  clientType: string | null | undefined;
  className?: string;
}) {
  const bytove = isBytoveDruzstvo(clientType);
  const Icon = bytove ? Building2 : Building;
  return (
    <span
      className={cn(
        'inline-flex shrink-0 items-center gap-1 rounded-full border px-2 py-0.5 text-[10px] font-medium',
        bytove
          ? 'border-blue-200/70 bg-blue-50 text-blue-700'
          : 'border-firol-200/70 bg-firol-50 text-firol-700',
        className,
      )}
    >
      <Icon className="size-3" />
      {clientNouns(clientType).singular}
    </span>
  );
}
