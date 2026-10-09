import { Building2, CalendarDays, Lock, Warehouse } from 'lucide-react';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { Skeleton } from '@/components/ui/Skeleton';

/**
 * Company, prevádzka and date of a návšteva, shown on Step 1 of an úkon that
 * belongs to one — chapter 9. They were entered once when the visit started,
 * so here they are locked display rows: the technician sees what the úkon is
 * recorded under, but cannot drift away from the visit's own values.
 */
export function VisitSharedFields({
  companyName,
  facilityName,
  date,
  dateLabel,
}: {
  companyName: string;
  facilityName: string;
  date: string;
  dateLabel: string;
}) {
  return (
    <div className="flex flex-col gap-4 rounded-2xl border border-ink-100 bg-ink-50/60 p-3.5">
      <p className="flex items-center gap-1.5 text-xs text-ink-500">
        <Lock className="size-3.5 shrink-0 text-ink-400" />
        Spoločné pre celú návštevu
      </p>
      <LockedField label="Spoločnosť" value={companyName} icon={<Building2 className="size-4" />} />
      <LockedField label="Prevádzka" value={facilityName} icon={<Warehouse className="size-4" />} />
      <LockedField
        label={dateLabel}
        value={new Date(`${date}T00:00:00`).toLocaleDateString('sk-SK')}
        icon={<CalendarDays className="size-4" />}
      />
    </div>
  );
}

function LockedField({ label, value, icon }: { label: string; value: string; icon: React.ReactNode }) {
  return (
    <Field label={label}>
      {(p) => (
        <Input
          id={p.id}
          value={value}
          readOnly
          disabled
          leftIcon={icon}
          rightSlot={<Lock className="mr-2 size-3.5 text-ink-400" aria-hidden />}
          className="truncate disabled:text-ink-700"
        />
      )}
    </Field>
  );
}

/** Placeholder for the locked rows while the visit is still loading. */
export function VisitSharedFieldsSkeleton() {
  return (
    <div className="flex flex-col gap-4 rounded-2xl border border-ink-100 bg-ink-50/60 p-3.5" aria-busy>
      <Skeleton className="h-3.5 w-44" />
      {[0, 1, 2].map((i) => (
        <div key={i} className="flex flex-col gap-1.5">
          <Skeleton className="h-3 w-24" />
          <Skeleton className="h-11 w-full rounded-xl" />
        </div>
      ))}
    </div>
  );
}
