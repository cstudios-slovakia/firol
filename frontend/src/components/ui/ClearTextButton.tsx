import { X } from 'lucide-react';
import { cn } from '@/lib/cn';

/**
 * The small „X" that empties a free-text field in one tap (fix list of
 * 16. 11. 2026): texts travel with a fire-protection item from the last
 * protocol, and a note from last year is often no longer true.
 *
 * Rendered only while the field has text. Pressing it does not take focus
 * from the field — the pointer default is suppressed and focus is put back —
 * so the technician can type the new text straight away. Used by
 * {@link Textarea} and as the `rightSlot` of an `Input`.
 */
export function ClearTextButton({
  value,
  onClear,
  target,
  disabled,
  className,
}: {
  value: string;
  onClear: () => void;
  /** The field to keep focused. */
  target: React.RefObject<HTMLInputElement | HTMLTextAreaElement | null>;
  disabled?: boolean;
  className?: string;
}) {
  if (value === '' || disabled) return null;
  return (
    <button
      type="button"
      aria-label="Vymazať text"
      title="Vymazať text"
      // Keep the caret in the field: a press on the button must not blur it.
      onPointerDown={(e) => e.preventDefault()}
      onMouseDown={(e) => e.preventDefault()}
      onClick={() => {
        onClear();
        target.current?.focus();
      }}
      className={cn(
        'grid size-8 place-items-center rounded-lg text-ink-400 transition-all duration-200',
        'hover:bg-ink-100 hover:text-ink-700 active:scale-90',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-200',
        className,
      )}
    >
      <X className="size-4" />
    </button>
  );
}
