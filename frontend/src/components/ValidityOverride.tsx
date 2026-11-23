import { CalendarCheck, PencilLine, RotateCcw } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { periodicityLabel, validUntil, type Periodicity } from '@/lib/periodicity';

/**
 * „Prepísať platnosť rukou" — spec 8.1. A training's recommended periodicity
 * is only a suggestion, so the date it runs out can be written by hand. While
 * an override is set it replaces date + periodicity in the calendar, the časová
 * os, Dnes, the client notices and the kniha BOZP, and the UI says so
 * („prepísané rukou").
 *
 * Sits under the PeriodicityPicker on the new / edit training forms.
 */
type Props = {
  /** Date of the training, when entered — the override defaults to the computed term. */
  date: string | null;
  periodicity: Periodicity;
  /** YYYY-MM-DD, or null for „no override". */
  value: string | null;
  onChange: (next: string | null) => void;
  disabled?: boolean;
};

const fmt = (iso: string) => new Date(`${iso}T00:00:00`).toLocaleDateString('sk-SK');

export function ValidityOverride({ date, periodicity, value, onChange, disabled }: Props) {
  const computed = validUntil(date, periodicity);
  const overridden = value !== null;

  return (
    <div className="flex flex-col gap-2.5 rounded-2xl border border-ink-100 bg-ink-50/60 p-3.5">
      <div className="flex items-start gap-2.5">
        <span className="mt-0.5 grid size-7 shrink-0 place-items-center rounded-xl bg-white text-ink-500">
          <CalendarCheck className="size-4" />
        </span>
        <div className="min-w-0 flex-1 text-sm text-ink-700">
          {overridden ? (
            <>
              <p className="flex flex-wrap items-center gap-x-2 gap-y-1">
                <span>
                  Platí do <span className="font-semibold text-ink-900">{fmt(value)}</span>
                </span>
                <Badge tone="warn">prepísané rukou</Badge>
              </p>
              {computed && (
                <p className="mt-0.5 text-xs text-ink-500">
                  Podľa periodicity ({periodicityLabel(periodicity)}) by to bolo do {fmt(computed)}.
                </p>
              )}
            </>
          ) : computed ? (
            <p>
              Odporúčaná periodicita{' '}
              <span className="font-semibold text-ink-900">{periodicityLabel(periodicity)}</span> — ďalšie do{' '}
              <span className="font-semibold text-ink-900">{fmt(computed)}</span>.
              <span className="text-ink-500"> Je odporúčaná, nie zákonná.</span>
            </p>
          ) : (
            <p className="text-ink-600">
              Bez opakovania — školenie nemá termín ďalšieho. Platnosť sa dá určiť rukou.
            </p>
          )}
        </div>
      </div>

      {overridden && (
        <div className="flex animate-fade-up flex-col gap-2 sm:flex-row sm:items-center">
          <div className="min-w-0 flex-1">
            <Input
              type="date"
              aria-label="Platnosť do"
              disabled={disabled}
              value={value}
              onChange={(e) => onChange(e.target.value || null)}
            />
          </div>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            disabled={disabled}
            onClick={() => onChange(null)}
            leftIcon={<RotateCcw className="size-4" />}
          >
            Zrušiť prepis
          </Button>
        </div>
      )}

      {!overridden && (
        <Button
          type="button"
          variant="secondary"
          size="sm"
          className="self-start"
          disabled={disabled}
          onClick={() => onChange(computed ?? date ?? new Date().toISOString().slice(0, 10))}
          leftIcon={<PencilLine className="size-4" />}
        >
          Prepísať platnosť rukou
        </Button>
      )}
    </div>
  );
}
