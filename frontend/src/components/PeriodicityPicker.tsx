import { useEffect, useState } from 'react';
import { Infinity as InfinityIcon, Repeat, SlidersHorizontal } from 'lucide-react';
import {
  PERIODICITY_NONE,
  RECOMMENDED_MONTHS,
  periodicityLabel,
  unitWord,
  validUntil,
  type Periodicity,
  type PeriodicityUnit,
} from '@/lib/periodicity';
import { cn } from '@/lib/cn';

/**
 * Choosing how often an úkon repeats — block 1 / chapter 5.
 *
 * Three ways to answer, in the order a technician reaches for them:
 *   - a chip, for the value the app recommends and most people take;
 *   - „vlastná", for a number and unit they type — days, weeks or months,
 *     because some firms breath-test weekly and others once a year;
 *   - „bez opakovania", for an úkon that does not recur at all.
 *
 * Every type offers all three. No period is ever presented as statutory: the
 * app calls its own suggestion „odporúčaná lehota" and nothing more, because
 * lehoty follow from the building, its environment and the operator's own
 * decision — the technician carries that judgement, and the wording has to say
 * so.
 */

const QUICK_UNITS: { unit: PeriodicityUnit; label: string }[] = [
  { unit: 'den', label: 'dni' },
  { unit: 'tyzden', label: 'týždne' },
  { unit: 'mesiac', label: 'mesiace' },
];

type Props = {
  /** Inspection type — decides which values are offered as chips. */
  type: string;
  value: Periodicity;
  onChange: (next: Periodicity) => void;
  /**
   * Execution date, when one has been entered. Used only to show what the
   * chosen period works out to — seeing "platí do 12. 8. 2027" catches a
   * mistyped unit far better than the number alone does.
   */
  executedOn?: string | null;
  disabled?: boolean;
};

export function PeriodicityPicker({ type, value, onChange, executedOn, disabled }: Props) {
  const recommended = RECOMMENDED_MONTHS[type] ?? [];
  const isNone = value.value === null || value.unit === null;
  const matchesChip =
    !isNone && value.unit === 'mesiac' && recommended.includes(value.value as number);

  // The custom row stays open once the user goes there, so switching units
  // mid-thought doesn't collapse the fields under them.
  const [customOpen, setCustomOpen] = useState(!isNone && !matchesChip);
  const [customValue, setCustomValue] = useState<string>(
    !isNone && !matchesChip ? String(value.value) : '',
  );
  const [customUnit, setCustomUnit] = useState<PeriodicityUnit>(
    !isNone && !matchesChip ? (value.unit as PeriodicityUnit) : 'mesiac',
  );

  // Follow the value when it changes from outside (history prefill in Step 1,
  // or loading a saved úkon) — but never fight the user while they type.
  useEffect(() => {
    if (isNone || matchesChip) return;
    setCustomOpen(true);
    setCustomValue(String(value.value));
    setCustomUnit(value.unit as PeriodicityUnit);
  }, [value.value, value.unit, isNone, matchesChip]);

  function pickChip(months: number) {
    setCustomOpen(false);
    onChange({ value: months, unit: 'mesiac' });
  }

  function pickNone() {
    setCustomOpen(false);
    onChange(PERIODICITY_NONE);
  }

  function commitCustom(raw: string, unit: PeriodicityUnit) {
    setCustomValue(raw);
    setCustomUnit(unit);
    const n = Number(raw);
    // An empty or nonsensical box is left alone rather than pushed upstream as
    // „bez opakovania" — clearing the field is a step in typing, not an answer.
    if (!raw || !Number.isFinite(n) || n < 1) return;
    onChange({ value: Math.floor(n), unit });
  }

  const due = validUntil(executedOn ?? null, value);

  return (
    <div className="flex flex-col gap-2.5">
      <div className="flex flex-wrap gap-2" role="group" aria-label="Periodicita">
        {recommended.map((months) => {
          const active = !customOpen && matchesChip && value.value === months;
          return (
            <button
              key={months}
              type="button"
              disabled={disabled}
              onClick={() => pickChip(months)}
              className={cn(chipClass, active ? chipActive : chipIdle)}
            >
              <Repeat className="size-4" />
              {months} {unitWord(months, 'mesiac')}
            </button>
          );
        })}

        <button
          type="button"
          disabled={disabled}
          onClick={() => {
            setCustomOpen(true);
            if (customValue) commitCustom(customValue, customUnit);
          }}
          aria-expanded={customOpen}
          className={cn(chipClass, customOpen ? chipActive : chipIdle)}
        >
          <SlidersHorizontal className="size-4" />
          Vlastná
        </button>

        <button
          type="button"
          disabled={disabled}
          onClick={pickNone}
          className={cn(chipClass, isNone && !customOpen ? chipActive : chipIdle)}
        >
          <InfinityIcon className="size-4" />
          Bez opakovania
        </button>
      </div>

      {customOpen && (
        <div className="flex animate-fade-up items-center gap-2">
          <input
            type="number"
            inputMode="numeric"
            min={1}
            disabled={disabled}
            value={customValue}
            onChange={(e) => commitCustom(e.target.value, customUnit)}
            aria-label="Počet"
            placeholder="napr. 18"
            className="h-11 w-24 rounded-xl border border-ink-200 bg-white px-3 text-sm text-ink-800 transition-colors hover:border-ink-300 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200"
          />
          <div className="flex gap-1.5">
            {QUICK_UNITS.map((u) => (
              <button
                key={u.unit}
                type="button"
                disabled={disabled}
                onClick={() => commitCustom(customValue, u.unit)}
                className={cn(
                  'h-11 rounded-xl border px-3 text-sm font-medium transition-colors duration-150',
                  customUnit === u.unit
                    ? 'border-firol-500 bg-firol-50 text-firol-700'
                    : 'border-ink-200 bg-white text-ink-700 hover:border-ink-300',
                )}
              >
                {u.label}
              </button>
            ))}
          </div>
        </div>
      )}

      <p className="text-xs text-ink-500">
        {isNone ? (
          <>Úkon sa neopakuje — v prehľadoch sa zobrazí „podľa potreby".</>
        ) : (
          <>
            Odporúčaná lehota, po vykonaní platí{' '}
            <span className="font-medium text-ink-700">{periodicityLabel(value)}</span>
            {due && (
              <>
                {' '}
                — teda do{' '}
                <span className="font-medium text-ink-700">
                  {new Date(`${due}T00:00:00`).toLocaleDateString('sk-SK')}
                </span>
              </>
            )}
            . Lehotu nastavuje technik podľa objektu a prostredia.
          </>
        )}
      </p>
    </div>
  );
}

const chipClass =
  'inline-flex h-11 items-center justify-center gap-1.5 rounded-xl border px-3.5 text-sm font-medium transition-all duration-150 active:scale-[0.98] disabled:cursor-default disabled:opacity-60';
const chipActive = 'border-firol-500 bg-firol-50 text-firol-700';
const chipIdle = 'border-ink-200 bg-white text-ink-700 hover:border-ink-300 hover:bg-ink-50';
