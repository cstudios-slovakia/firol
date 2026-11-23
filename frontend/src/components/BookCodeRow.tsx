import { useRef, useState } from 'react';
import { flushSync } from 'react-dom';
import { BookMarked, Check, Lock, Pencil, X } from 'lucide-react';
import { Companies, type Company } from '@/api/companies';
import { ApiError } from '@/lib/api';
import { offlineMessage } from '@/lib/offline';
import { useAuth } from '@/auth/AuthContext';
import { useToast } from '@/lib/toast';
import { Button } from '@/components/ui/Button';
import { cn } from '@/lib/cn';
import { clientNouns } from '@/lib/clientKind';

/** Letters BookNumber::letters() maps by hand; the rest lose their marks below. */
const EXTRA: Record<string, string> = {
  ß: 'SS', Æ: 'AE', æ: 'AE', Œ: 'OE', œ: 'OE', Ø: 'O', ø: 'O', Ł: 'L', ł: 'L', Đ: 'D', đ: 'D', Þ: 'TH', þ: 'TH',
};

/**
 * What the user types, as the server reads names (BookNumber::letters): „šta"
 * → „STA". Nothing is dropped — a fifth character or a symbol stays, and the
 * server's message says what is wrong (round 6: the field silently cut „KOVOX"
 * to „KOVO" and „šta" to „TA").
 */
function transliterate(raw: string): string {
  return raw
    .replace(/[ßÆæŒœØøŁłĐđÞþ]/g, (c) => EXTRA[c] ?? c)
    .normalize('NFD')
    .replace(/\p{M}/gu, '')
    .toUpperCase();
}

/**
 * „Kód pre čísla zápisov do požiarnej knihy" on the firma's detail (customer's
 * answer 3 of 18. 11. 2026): the entries of a firma are numbered PK-<KÓD>-NNN
 * in a series of its own. The code is proposed from the name; the user may
 * change it (2–4 letters or digits) until the firma's first entry is issued,
 * then it is shown read-only.
 */
export function BookCodeRow({
  company,
  canEdit,
  onSaved,
}: {
  company: Company;
  canEdit: boolean;
  onSaved: (company: Company) => void;
}) {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const stored = company.book_code ?? null;
  const suggested = company.book_code_suggested ?? null;
  const locked = company.book_code_locked === true;
  const nouns = clientNouns(company.client_type);
  const [editing, setEditing] = useState(false);
  const [value, setValue] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);

  // A row cached before the field existed shows nothing rather than „chýba".
  if (company.book_code === undefined) return null;

  const shown = stored ?? suggested;

  function startEdit() {
    // The input is rendered and selected before this handler returns (round 7):
    // a select() delayed to the next frame swallowed the first keys typed
    // right after „Zmeniť".
    flushSync(() => {
      setValue(shown ?? '');
      setError(null);
      setEditing(true);
    });
    inputRef.current?.focus();
    inputRef.current?.select();
  }

  async function save() {
    // The server checks the shape and says what is wrong, in the client's words.
    const code = value.trim();
    setSaving(true);
    try {
      const res = await Companies.updateBookCode(company.id, code, csrfToken);
      onSaved(res.company);
      setEditing(false);
      toast.success('Kód uložený');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : offlineMessage(err, 'Kód sa nepodarilo uložiť.'));
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="flex items-start gap-3 py-2.5" id="company-book-code">
      <span className="grid size-6 shrink-0 place-items-center text-ink-400">
        <BookMarked className="size-4" />
      </span>
      <div className="min-w-0 flex-1">
        <dt className="text-[10px] font-semibold uppercase tracking-wider text-ink-400">
          Kód pre čísla zápisov do požiarnej knihy
        </dt>
        {editing ? (
          <dd className="mt-1 flex flex-col gap-2 animate-fade-in">
            <div className="flex items-center gap-2">
              <span className="text-sm text-ink-500">PK-</span>
              <input
                ref={inputRef}
                id="company-book-code-input"
                value={value}
                autoCapitalize="characters"
                autoComplete="off"
                aria-label={`Kód ${nouns.cases.gen}`}
                aria-invalid={error !== null}
                onChange={(e) => {
                  setValue(transliterate(e.target.value));
                  setError(null);
                }}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') { e.preventDefault(); void save(); }
                  if (e.key === 'Escape') setEditing(false);
                }}
                className={cn(
                  'h-10 w-24 rounded-xl border border-ink-200 bg-white px-3 text-sm font-semibold uppercase tracking-wider text-ink-800',
                  'transition-colors duration-150 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200',
                  error && 'border-status-bad',
                )}
              />
              <span className="text-sm text-ink-500">-001</span>
            </div>
            {error && <p className="text-xs text-status-bad">{error}</p>}
            <div className="flex gap-2">
              <Button type="button" size="sm" loading={saving} leftIcon={<Check className="size-4" />} onClick={() => void save()}>
                Uložiť kód
              </Button>
              <Button type="button" size="sm" variant="ghost" disabled={saving} leftIcon={<X className="size-4" />} onClick={() => setEditing(false)}>
                Zrušiť
              </Button>
            </div>
            <p className="text-xs text-ink-500">
              2 až 4 písmená alebo číslice. Kód sa dá zmeniť, kým nie je vystavený prvý zápis do knihy.
            </p>
          </dd>
        ) : (
          <dd className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-ink-800">
            {shown ? (
              <span className="font-semibold tracking-wider" data-book-code={shown}>{shown}</span>
            ) : (
              <span className="text-status-bad">chýba — doplň ho pred prvým zápisom</span>
            )}
            {shown && <span className="text-xs text-ink-500">zápisy PK-{shown}-001, PK-{shown}-002, …</span>}
            {!stored && suggested && <span className="text-xs text-ink-400">(navrhnutý)</span>}
            {locked ? (
              <span className="inline-flex items-center gap-1 text-xs text-ink-500" title={`${nouns.singular} už má vystavený zápis — kód sa nedá zmeniť.`}>
                <Lock className="size-3" /> uzamknutý
              </span>
            ) : (
              canEdit && (
                <button
                  type="button"
                  id="company-book-code-edit"
                  onClick={startEdit}
                  className="inline-flex items-center gap-1 rounded-lg px-1.5 py-0.5 text-xs font-medium text-firol-600 transition-all duration-200 hover:bg-firol-50 active:scale-95"
                >
                  <Pencil className="size-3" /> Zmeniť
                </button>
              )
            )}
          </dd>
        )}
      </div>
    </div>
  );
}
