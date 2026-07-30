import { useEffect, useId, useRef, useState } from 'react';
import { Input } from '@/components/ui/Input';
import { Inspections, type SuggestionField } from '@/api/inspections';
import { cn } from '@/lib/cn';
import { fold } from '@/lib/text';

type Props = {
  /** Which historical field to draw suggestions from. */
  field: SuggestionField;
  /** For `location`, floats the given facility's values to the top. */
  facilityId?: number;
  value: string;
  onChange: (value: string) => void;
  /**
   * Fixed options always offered (filtered by the current prefix), shown even
   * before the history lookup kicks in — e.g. the common PHP type list (2.4.3).
   */
  staticOptions?: string[];
  id?: string;
  placeholder?: string;
  required?: boolean;
  /** Forwarded from <Field> so the input picks up its error styling. */
  'aria-invalid'?: boolean | undefined;
  leftIcon?: React.ReactNode;
  autoComplete?: string;
};

/**
 * Text input with a suggestion dropdown fed by the account's own history
 * (change request 2.4.1) plus any fixed `staticOptions`. Suggestions appear
 * after 2 typed characters, or immediately on focus when static options exist.
 * Free text always stays possible — the dropdown only assists.
 */
export function AutocompleteInput({
  field,
  facilityId,
  value,
  onChange,
  staticOptions,
  id,
  placeholder,
  required,
  'aria-invalid': ariaInvalid,
  leftIcon,
  autoComplete,
}: Props) {
  const [history, setHistory] = useState<string[]>([]);
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(-1);
  const boxRef = useRef<HTMLDivElement | null>(null);
  const listId = useId();

  // Debounced history lookup once the user has typed enough to be specific.
  useEffect(() => {
    const q = value.trim();
    if (q.length < 2) {
      setHistory([]);
      return;
    }
    let cancelled = false;
    const t = setTimeout(() => {
      Inspections.suggestions(field, q, facilityId)
        .then((res) => { if (!cancelled) setHistory(res.suggestions); })
        .catch(() => { if (!cancelled) setHistory([]); });
    }, 200);
    return () => { cancelled = true; clearTimeout(t); };
  }, [value, field, facilityId]);

  // Close when focus/click leaves the widget.
  useEffect(() => {
    function onDocClick(e: MouseEvent) {
      if (boxRef.current && !boxRef.current.contains(e.target as Node)) setOpen(false);
    }
    document.addEventListener('mousedown', onDocClick);
    return () => document.removeEventListener('mousedown', onDocClick);
  }, []);

  // Matching ignores case and diacritics throughout: "gloria" offers "Glória",
  // "PE" offers "Peter". The backend folds the same way for the history lookup.
  const q = fold(value.trim());
  const staticMatches = (staticOptions ?? []).filter(
    (o) => q === '' || fold(o).includes(q),
  );
  // Static options first, then history values not already listed; drop an exact
  // match with the current value (nothing to suggest when it's already typed).
  // Spelling variants of one value collapse to the first (= most used) of them.
  const options: string[] = [];
  for (const o of [...staticMatches, ...history]) {
    const key = fold(o);
    if (!options.some((x) => fold(x) === key) && key !== q) {
      options.push(o);
    }
  }
  const showList = open && options.length > 0;

  function choose(v: string) {
    onChange(v);
    setOpen(false);
    setActive(-1);
  }

  function onKeyDown(e: React.KeyboardEvent<HTMLInputElement>) {
    if (!showList) return;
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setActive((i) => (i + 1) % options.length);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setActive((i) => (i <= 0 ? options.length - 1 : i - 1));
    } else if (e.key === 'Enter' && active >= 0) {
      e.preventDefault();
      choose(options[active]);
    } else if (e.key === 'Escape') {
      setOpen(false);
    }
  }

  return (
    <div ref={boxRef} className="relative">
      <Input
        id={id}
        value={value}
        onChange={(e) => { onChange(e.target.value); setOpen(true); setActive(-1); }}
        onFocus={() => setOpen(true)}
        onKeyDown={onKeyDown}
        role="combobox"
        aria-expanded={showList}
        aria-controls={listId}
        aria-autocomplete="list"
        autoComplete={autoComplete ?? 'off'}
        required={required}
        aria-invalid={ariaInvalid}
        leftIcon={leftIcon}
        placeholder={placeholder}
      />
      {showList && (
        <ul
          id={listId}
          role="listbox"
          className="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-xl border border-ink-200 bg-white py-1 shadow-[var(--shadow-soft)]"
        >
          {options.map((o, idx) => (
            <li key={o} role="option" aria-selected={idx === active}>
              <button
                type="button"
                // onMouseDown (not click) so it fires before the input blur.
                onMouseDown={(e) => { e.preventDefault(); choose(o); }}
                onMouseEnter={() => setActive(idx)}
                className={cn(
                  'block w-full px-3 py-2 text-left text-sm transition-colors',
                  idx === active ? 'bg-firol-50 text-firol-700' : 'text-ink-700 hover:bg-ink-50',
                )}
              >
                {o}
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

/** Common portable/mobile extinguisher types offered on the PHP type field (2.4.3). */
export const PHP_COMMON_TYPES = [
  'P1', 'P2', 'P4', 'P6', 'P9', 'P12',
  'CO2-2', 'CO2-5', 'V9', 'S5', 'S6',
];
