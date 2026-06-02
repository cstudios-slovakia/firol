import {
  useEffect, useId, useLayoutEffect, useMemo, useRef, useState,
} from 'react';
import { Check, ChevronDown, Search } from 'lucide-react';
import { cn } from '@/lib/cn';
import { useDelayedMount } from '@/lib/useDelayedMount';

export type SelectOption = {
  value: string;
  label: string;
  /** Secondary line shown next to the label, e.g. IČO, certification id. */
  description?: string;
  disabled?: boolean;
};

type SelectProps = {
  id?: string;
  /** Current value as string. Use empty string for "no selection". */
  value: string;
  onChange: (value: string) => void;
  options: SelectOption[];
  placeholder?: string;
  leftIcon?: React.ReactNode;
  disabled?: boolean;
  invalid?: boolean;
  'aria-invalid'?: boolean | undefined;
  className?: string;
  /** When set, shown in the closed trigger if no option matches the value. */
  emptyLabel?: string;
  /**
   * Optional sticky element rendered at the top of the open listbox
   * (e.g. a "+ Pridať novú firmu" button). The slot receives a
   * `closeDropdown` callback so the trigger can close the popover
   * before opening a follow-up modal.
   */
  headerSlot?: (ctx: { closeDropdown: () => void }) => React.ReactNode;
  /** Adds a search/filter input inside the dropdown. Ignores diacritics. */
  searchable?: boolean;
};

function stripDiacritics(s: string) {
  return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

/**
 * Fully custom dropdown — no native <select> involved. Renders a styled
 * trigger that toggles a popover panel of options. Keyboard accessible
 * (arrows + enter + escape + type-ahead), closes on click-outside or
 * focus-out.
 *
 * The native element gives us nicer mobile pickers but uglier desktop
 * UI; we trade that off here in favour of a single look across both,
 * matching the rest of the form controls. If we want to bring back the
 * mobile native sheet later, we can swap in a media-query-gated
 * <select> at the leaf — the consumer API doesn't need to change.
 */
export function Select({
  id,
  value,
  onChange,
  options,
  placeholder = '— vyber —',
  leftIcon,
  disabled,
  invalid,
  className,
  emptyLabel,
  headerSlot,
  searchable,
  'aria-invalid': ariaInvalid,
}: SelectProps) {
  const triggerId = useId();
  const listboxId = useId();
  const finalId = id ?? triggerId;

  const wrapperRef = useRef<HTMLDivElement | null>(null);
  const triggerRef = useRef<HTMLButtonElement | null>(null);
  const listRef = useRef<HTMLUListElement | null>(null);
  const searchRef = useRef<HTMLInputElement | null>(null);

  const [open, setOpen] = useState(false);
  const { mounted, entered } = useDelayedMount(open, 160);
  const [activeIndex, setActiveIndex] = useState<number>(-1);
  const [searchQuery, setSearchQuery] = useState('');

  // Type-ahead buffer: a few characters typed in quick succession jump
  // to the first option whose label starts with that prefix.
  const typeaheadRef = useRef<{ buffer: string; timer: number | null }>({
    buffer: '',
    timer: null,
  });

  const selectedIndex = useMemo(
    () => options.findIndex((o) => o.value === value),
    [options, value],
  );
  const selectedOption = selectedIndex >= 0 ? options[selectedIndex] : null;

  // When searchable, options are filtered by the query (diacritics-insensitive).
  // Each entry keeps its originalIndex so keys stay stable.
  const filteredOptions = useMemo(() => {
    const mapped = options.map((o, i) => ({ option: o, originalIndex: i }));
    if (!searchable || !searchQuery.trim()) return mapped;
    const norm = stripDiacritics(searchQuery.trim());
    return mapped.filter(
      ({ option }) =>
        stripDiacritics(option.label).includes(norm) ||
        (option.description && stripDiacritics(option.description).includes(norm)),
    );
  }, [options, searchable, searchQuery]);

  // When opening, focus the currently selected option (or the first enabled one).
  // When closing, reset search and active index.
  useLayoutEffect(() => {
    if (!open) {
      setActiveIndex(-1);
      setSearchQuery('');
      return;
    }
    // filteredOptions == full list here (searchQuery was just reset to '')
    const filteredSelectedIdx = filteredOptions.findIndex(({ option }) => option.value === value);
    const start =
      filteredSelectedIdx >= 0
        ? filteredSelectedIdx
        : filteredOptions.findIndex(({ option }) => !option.disabled);
    setActiveIndex(start);
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  // When the search query changes, reset active index to the first result.
  useEffect(() => {
    if (!open) return;
    const first = filteredOptions.findIndex(({ option }) => !option.disabled);
    setActiveIndex(first);
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchQuery]);

  // Focus the search input (if searchable) or the listbox once it has entered.
  useEffect(() => {
    if (entered) {
      if (searchable) {
        searchRef.current?.focus();
      } else {
        listRef.current?.focus();
      }
    }
  }, [entered, searchable]);

  // Click-outside / focus-outside closes the panel.
  useEffect(() => {
    if (!open) return;
    const onDocPointerDown = (e: PointerEvent) => {
      const wrapper = wrapperRef.current;
      if (wrapper && !wrapper.contains(e.target as Node)) {
        setOpen(false);
      }
    };
    document.addEventListener('pointerdown', onDocPointerDown);
    return () => document.removeEventListener('pointerdown', onDocPointerDown);
  }, [open]);

  // Scroll the active option into view when it changes (keyboard nav).
  useEffect(() => {
    if (!open || activeIndex < 0) return;
    const list = listRef.current;
    if (!list) return;
    const node = list.querySelector<HTMLElement>(`[data-index="${activeIndex}"]`);
    node?.scrollIntoView({ block: 'nearest' });
  }, [open, activeIndex]);

  function moveActive(delta: number) {
    if (filteredOptions.length === 0) return;
    let next = activeIndex < 0 ? (delta > 0 ? -1 : filteredOptions.length) : activeIndex;
    for (let step = 0; step < filteredOptions.length; step++) {
      next = (next + delta + filteredOptions.length) % filteredOptions.length;
      if (!filteredOptions[next].option.disabled) {
        setActiveIndex(next);
        return;
      }
    }
  }

  function commit(filteredIdx: number) {
    const item = filteredOptions[filteredIdx];
    if (!item || item.option.disabled) return;
    onChange(item.option.value);
    setOpen(false);
    triggerRef.current?.focus();
  }

  function handleTypeahead(char: string) {
    const ta = typeaheadRef.current;
    ta.buffer += char.toLowerCase();
    if (ta.timer !== null) {
      window.clearTimeout(ta.timer);
    }
    ta.timer = window.setTimeout(() => {
      ta.buffer = '';
      ta.timer = null;
    }, 600);

    const found = filteredOptions.findIndex(
      ({ option }) => !option.disabled && option.label.toLowerCase().startsWith(ta.buffer),
    );
    if (found >= 0) {
      setActiveIndex(found);
    }
  }

  function onTriggerKeyDown(e: React.KeyboardEvent<HTMLButtonElement>) {
    if (disabled) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      setOpen(true);
      return;
    }
    if (e.key.length === 1 && !e.metaKey && !e.ctrlKey && !e.altKey) {
      // Open on type-ahead start so the user sees the matching option.
      setOpen(true);
      handleTypeahead(e.key);
    }
  }

  function onListKeyDown(e: React.KeyboardEvent<HTMLUListElement>) {
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      moveActive(1);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      moveActive(-1);
    } else if (e.key === 'Home') {
      e.preventDefault();
      const first = filteredOptions.findIndex(({ option }) => !option.disabled);
      if (first >= 0) setActiveIndex(first);
    } else if (e.key === 'End') {
      e.preventDefault();
      for (let i = filteredOptions.length - 1; i >= 0; i--) {
        if (!filteredOptions[i].option.disabled) {
          setActiveIndex(i);
          break;
        }
      }
    } else if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      if (activeIndex >= 0) commit(activeIndex);
    } else if (e.key === 'Escape' || e.key === 'Tab') {
      // Tab needs to actually move focus forward, so don't preventDefault.
      if (e.key === 'Escape') e.preventDefault();
      setOpen(false);
      triggerRef.current?.focus();
    } else if (e.key.length === 1 && !e.metaKey && !e.ctrlKey && !e.altKey) {
      handleTypeahead(e.key);
    }
  }

  function onSearchKeyDown(e: React.KeyboardEvent<HTMLInputElement>) {
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      moveActive(1);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      moveActive(-1);
    } else if (e.key === 'Enter') {
      e.preventDefault();
      if (activeIndex >= 0) commit(activeIndex);
    } else if (e.key === 'Escape') {
      e.preventDefault();
      setOpen(false);
      triggerRef.current?.focus();
    }
  }

  const triggerLabel = selectedOption
    ? selectedOption.label
    : (emptyLabel ?? placeholder);

  return (
    <div ref={wrapperRef} className={cn('relative', className)}>
      <button
        ref={triggerRef}
        id={finalId}
        type="button"
        role="combobox"
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-controls={listboxId}
        aria-invalid={ariaInvalid}
        disabled={disabled}
        onClick={() => !disabled && setOpen((v) => !v)}
        onKeyDown={onTriggerKeyDown}
        className={cn(
          'flex h-11 w-full items-center gap-2 rounded-xl border bg-white pl-3 pr-3 text-left text-sm transition-colors duration-150',
          (invalid || ariaInvalid === true)
            ? 'border-status-bad focus:border-status-bad focus:ring-[hsl(0_75%_90%)]'
            : 'border-ink-200 hover:border-ink-300 focus:border-firol-400 focus:ring-firol-200',
          'focus:outline-none focus:ring-2',
          disabled
            ? 'cursor-not-allowed bg-ink-50 text-ink-400'
            : selectedOption ? 'text-ink-800' : 'text-ink-400',
        )}
      >
        {leftIcon && (
          <span className={cn('shrink-0', selectedOption ? 'text-ink-400' : 'text-ink-300')}>
            {leftIcon}
          </span>
        )}
        <span className="min-w-0 flex-1 truncate">{triggerLabel}</span>
        <ChevronDown
          className={cn(
            'size-4 shrink-0 text-ink-400 transition-transform duration-200',
            open && 'rotate-180',
          )}
        />
      </button>

      {mounted && !disabled && (
        <ul
          ref={listRef}
          id={listboxId}
          role="listbox"
          tabIndex={-1}
          aria-activedescendant={
            activeIndex >= 0 ? `${listboxId}-opt-${activeIndex}` : undefined
          }
          onKeyDown={onListKeyDown}
          className={cn(
            'absolute left-0 right-0 top-full z-30 mt-1.5 max-h-72 overflow-auto rounded-2xl border border-ink-100 bg-white py-1 shadow-[var(--shadow-lift)] focus:outline-none',
            // Scale + opacity entry from the trigger edge for a natural
            // "popping out" feel.
            'origin-top transition-[opacity,transform] duration-150 ease-out',
            entered ? 'scale-100 opacity-100' : 'scale-95 opacity-0',
          )}
        >
          {(headerSlot || searchable) && (
            <li
              role="presentation"
              className="sticky top-0 z-10 -mt-1 mb-1 border-b border-ink-100 bg-white px-1 pb-1.5 pt-1"
            >
              {headerSlot && headerSlot({ closeDropdown: () => setOpen(false) })}
              {searchable && (
                <div className={cn('relative', headerSlot && 'mt-1')}>
                  <Search className="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-ink-400" />
                  <input
                    ref={searchRef}
                    type="text"
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    onKeyDown={onSearchKeyDown}
                    placeholder="Hľadať..."
                    className="h-8 w-full rounded-lg border border-ink-200 bg-ink-50 pl-7 pr-2 text-sm text-ink-800 placeholder:text-ink-400 focus:border-firol-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-firol-200"
                  />
                </div>
              )}
            </li>
          )}
          {filteredOptions.length === 0 && (
            <li className="px-3 py-2 text-sm text-ink-400">
              {searchQuery.trim() ? '— žiadne výsledky —' : '— žiadne možnosti —'}
            </li>
          )}
          {filteredOptions.map(({ option: opt, originalIndex }, idx) => {
            const isSelected = opt.value === value;
            const isActive = idx === activeIndex;
            return (
              <li
                key={`${opt.value}-${originalIndex}`}
                id={`${listboxId}-opt-${idx}`}
                data-index={idx}
                role="option"
                aria-selected={isSelected}
                aria-disabled={opt.disabled || undefined}
                onMouseEnter={() => !opt.disabled && setActiveIndex(idx)}
                onClick={() => commit(idx)}
                className={cn(
                  'flex cursor-pointer items-start gap-2 px-3 py-2 text-sm',
                  opt.disabled && 'pointer-events-none text-ink-300',
                  !opt.disabled && isActive && 'bg-firol-50 text-firol-800',
                  !opt.disabled && !isActive && isSelected && 'text-firol-700',
                  !opt.disabled && !isActive && !isSelected && 'text-ink-700',
                )}
              >
                <Check
                  className={cn(
                    'mt-0.5 size-4 shrink-0 transition-opacity',
                    isSelected ? 'opacity-100 text-firol-600' : 'opacity-0',
                  )}
                />
                <span className="min-w-0 flex-1">
                  <span className="block truncate">{opt.label}</span>
                  {opt.description && (
                    <span className={cn(
                      'block truncate text-xs',
                      isActive ? 'text-firol-600' : 'text-ink-500',
                    )}>
                      {opt.description}
                    </span>
                  )}
                </span>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
