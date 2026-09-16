import { History } from 'lucide-react';
import {
  PASS_FAIL_LABELS,
  PHP_STATUS_LABELS,
  type InspectionType,
} from '@/api/inspections';
import { cn } from '@/lib/cn';

/**
 * What a carried-over item scored last time — block 1 / chapter 12.
 *
 * "Pri každej prenesenej položke sa zobrazí, aký mala minule stav." It is the
 * difference between retyping forty extinguishers and glancing down a list to
 * see which one was on tlaková skúška a year ago. Display only: the value is
 * never a default for this year's stav, and no protocol template prints it.
 *
 * The badge disappears as soon as the technician records a result, so a row
 * never shows two verdicts at once.
 */
export const PREVIOUS_STATUS_KEY = 'previous_status';

/** The value, or null when this item was entered fresh rather than carried. */
export function previousStatusOf(fields: Record<string, unknown>): string | null {
  const raw = fields[PREVIOUS_STATUS_KEY];
  return typeof raw === 'string' && raw !== '' ? raw : null;
}

/** Slovak label for a stored code, using the type's own číselník. */
function labelFor(type: InspectionType, code: string): string {
  if (type === 'php') {
    return PHP_STATUS_LABELS[code as keyof typeof PHP_STATUS_LABELS] ?? code;
  }
  return PASS_FAIL_LABELS[code as keyof typeof PASS_FAIL_LABELS] ?? code;
}

export function PreviousStatusBadge({
  type,
  fields,
  className,
}: {
  type: InspectionType;
  fields: Record<string, unknown>;
  className?: string;
}) {
  const previous = previousStatusOf(fields);
  if (!previous) return null;

  return (
    <span
      title="Stav z predchádzajúcej kontroly — tento rok ho zadaj nanovo."
      className={cn(
        'inline-flex items-center gap-1 rounded-full bg-ink-100 px-2 py-0.5 text-[11px] font-medium text-ink-500',
        className,
      )}
    >
      <History className="size-3" />
      minule: {labelFor(type, previous)}
    </span>
  );
}
