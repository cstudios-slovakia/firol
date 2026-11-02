import { useMemo, useState } from 'react';
import { Check, Printer } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import {
  Book,
  bookPrintUrl,
  type BookEntry,
  type BookOffer,
  type BookScope,
  type BookView,
} from '@/api/book';
import { formatDay } from '@/components/ClientArchive';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { isBytoveDruzstvo } from '@/lib/clientKind';
import { cn } from '@/lib/cn';
import { todayIso } from '@/lib/dates';
import { offlineMessage } from '@/lib/offline';
import { useToast } from '@/lib/toast';

function plural(n: number, one: string, few: string, many: string): string {
  if (n === 1) return one;
  if (n >= 2 && n <= 4) return few;
  return many;
}

const TEXTAREA_CLASS =
  'w-full rounded-xl border border-ink-200 bg-white px-3 py-2.5 text-sm text-ink-800 placeholder:text-ink-400 ' +
  'transition-colors duration-150 hover:border-ink-300 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200';

export function NewBookEntryModal({
  view,
  onClose,
  onSaved,
  onSwitchScope,
  onSelectFacility,
}: {
  view: BookView;
  onClose: () => void;
  onSaved: (entry: BookEntry, openPrint: boolean) => void;
  onSwitchScope?: (scope: BookScope) => Promise<void>;
  onSelectFacility?: (facilityId: number) => void;
}) {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const isBd = isBytoveDruzstvo(view.company.client_type);

  // Group unbooked offers by title (control type)
  const groupedOffers = useMemo(() => {
    const groups: { title: string; items: BookOffer[] }[] = [];
    const map = new Map<string, BookOffer[]>();

    for (const o of view.offers) {
      const list = map.get(o.title) ?? [];
      list.push(o);
      map.set(o.title, list);
    }

    for (const [title, items] of map.entries()) {
      // Newest first
      items.sort((a, b) => (b.date || '').localeCompare(a.date || ''));
      groups.push({ title, items });
    }

    return groups;
  }, [view.offers]);

  // Initial selection: pre-check the latest (first) item in each group
  const [selectedKeys, setSelectedKeys] = useState<Set<string>>(() => {
    const initial = new Set<string>();
    for (const g of groupedOffers) {
      const latest = g.items.find((x) => x.is_latest) ?? g.items[0];
      if (latest) {
        initial.add(latest.key);
      }
    }
    return initial;
  });

  const [vlastnyText, setVlastnyText] = useState('');
  const [legalSentence, setLegalSentence] = useState(false);
  const [defectsEdited, setDefectsEdited] = useState(false);
  const [defectsText, setDefectsText] = useState(() => {
    // If any preselected offer carries defects_text, pre-fill it
    for (const g of groupedOffers) {
      const latest = g.items.find((x) => x.is_latest) ?? g.items[0];
      if (latest && selectedKeys.has(latest.key) && latest.defects_text) {
        return latest.defects_text;
      }
    }
    return '';
  });
  const [noDefects, setNoDefects] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [switchingScope, setSwitchingScope] = useState(false);

  // Selected offers in order
  const selectedOffers = useMemo(() => {
    return view.offers.filter((o) => selectedKeys.has(o.key));
  }, [view.offers, selectedKeys]);

  const carriesDefects = useMemo(() => {
    return selectedOffers.some((o) => o.has_defects) || defectsText.trim().length > 0;
  }, [selectedOffers, defectsText]);

  // Toggle selection of an offer
  function toggleOffer(offer: BookOffer) {
    setSelectedKeys((prev) => {
      const next = new Set(prev);
      if (next.has(offer.key)) {
        next.delete(offer.key);
      } else {
        next.add(offer.key);
        // If defectsText wasn't manually edited and this offer has defect text, auto-populate
        if (!defectsEdited && !defectsText.trim() && offer.defects_text) {
          setDefectsText(offer.defects_text);
        }
      }
      return next;
    });
  }

  // Compose live preview text
  const composedText = useMemo(() => {
    let t = '';
    if (selectedOffers.length === 1) {
      t = selectedOffers[0].text;
    } else if (selectedOffers.length > 1) {
      const lines = selectedOffers.map((x) => {
        const kde = x.facilities.length > 0 ? x.facilities.join(', ') : '';
        const cislo = x.protocol_numbers.length > 0 ? `, protokol č. ${x.protocol_numbers.join(', ')}` : '';
        return `· ${formatDay(x.date)} — ${x.title}${kde ? `, ${kde}` : ''}${cislo}`;
      });
      t = `Do knihy sa zapisujú tieto vykonané úkony:\n${lines.join('\n')}`;
    }

    const vlastny = vlastnyText.trim();
    if (vlastny) {
      t = t ? `${t}\n${vlastny}` : vlastny;
    }

    if (legalSentence && t) {
      t = `${view.legal_sentence} ${t}`;
    }

    return t;
  }, [selectedOffers, vlastnyText, legalSentence, view.legal_sentence]);

  const nextSeq = useMemo(() => {
    return (view.entries.reduce((max, e) => Math.max(max, e.seq), 0) || 0) + 1;
  }, [view.entries]);

  const currentFacility = useMemo(() => {
    return view.facilities.find((f) => f.id === view.facility_id) ?? null;
  }, [view.facilities, view.facility_id]);

  async function handleSwitchScope(scope: BookScope) {
    if (!onSwitchScope || switchingScope || view.scope === scope) return;
    setSwitchingScope(true);
    try {
      await onSwitchScope(scope);
    } finally {
      setSwitchingScope(false);
    }
  }

  async function handleSubmit(openPrint: boolean) {
    setError(null);
    const textToSave = composedText.trim();
    if (!textToSave) {
      setError('Označte aspoň jeden úkon alebo napíšte text zápisu.');
      return;
    }

    const allNumbers = selectedOffers.flatMap((o) => o.protocol_numbers);
    const allInspectionIds = selectedOffers.flatMap((o) => o.inspection_ids);
    const allTrainingIds = selectedOffers.flatMap((o) => o.training_ids);

    const newestDate =
      selectedOffers.length > 0
        ? [...selectedOffers.map((o) => o.date)].sort().slice(-1)[0]
        : todayIso();

    const source =
      selectedOffers.length > 1
        ? 'hromadny'
        : selectedOffers.length === 1
          ? selectedOffers[0].kind === 'termin'
            ? 'termin'
            : 'ukon'
          : 'vlastny';

    setSaving(true);
    try {
      const res = await Book.createEntry(
        view.company.id,
        {
          book: 'po',
          facility_id: view.facility_id,
          source,
          date: newestDate,
          text: textToSave,
          legal_sentence: legalSentence,
          no_defects_statement: !carriesDefects && noDefects,
          defects_text: defectsText.trim() || undefined,
          protocol_numbers: allNumbers,
          inspection_ids: allInspectionIds,
          training_ids: allTrainingIds,
        },
        csrfToken,
      );

      if (openPrint) {
        window.open(
          bookPrintUrl(view.company.id, 'po', {
            entryId: res.entry.id,
            facilityId: res.entry.facility_id,
            mode: 'entry',
          }),
          '_blank',
          'noopener',
        );
        toast.success('Zapísané do požiarnej knihy. Otváram protokol na tlač...');
      } else {
        toast.success('Zapísané do požiarnej knihy. Čaká na podpis vedúceho.');
      }

      onSaved(res.entry, openPrint);
    } catch (err) {
      setError(offlineMessage(err, 'Zápis sa nepodarilo uložiť.'));
      setSaving(false);
    }
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title="Nový zápis do požiarnej knihy"
      description={`Zápis č. ${nextSeq}${view.scope === 'prevadzka' && currentFacility ? ` · ${currentFacility.name}` : ''}`}
      maxWidthClassName="max-w-2xl"
      dismissible={!saving}
    >
      <div className="flex flex-col gap-5">
        {/* Scope switch if multi-facility */}
        {view.can_switch_scope && onSwitchScope && (
          <div className="flex flex-col gap-2 rounded-2xl border border-ink-100 bg-ink-50/70 p-3">
            <span className="text-xs font-semibold uppercase tracking-wider text-ink-500">Rozsah knihy</span>
            <div role="radiogroup" aria-label="Rozsah knihy" className="grid grid-cols-2 gap-1 rounded-xl bg-ink-100 p-1">
              <button
                type="button"
                role="radio"
                aria-checked={view.scope === 'firma'}
                disabled={saving || switchingScope}
                onClick={() => void handleSwitchScope('firma')}
                className={cn(
                  'inline-flex min-h-9 items-center justify-center rounded-lg px-2 text-center text-xs font-medium transition-all duration-150',
                  view.scope === 'firma' ? 'bg-white text-ink-900 shadow-sm' : 'text-ink-600 hover:text-ink-900',
                )}
              >
                {isBd ? 'Spoločná kniha za celý subjekt' : 'Jedna za celú firmu'}
              </button>
              <button
                type="button"
                role="radio"
                aria-checked={view.scope === 'prevadzka'}
                disabled={saving || switchingScope}
                onClick={() => void handleSwitchScope('prevadzka')}
                className={cn(
                  'inline-flex min-h-9 items-center justify-center rounded-lg px-2 text-center text-xs font-medium transition-all duration-150',
                  view.scope === 'prevadzka' ? 'bg-white text-ink-900 shadow-sm' : 'text-ink-600 hover:text-ink-900',
                )}
              >
                {isBd ? 'Samostatne za každý dom' : 'Za každú prevádzku'}
              </button>
            </div>
            {view.scope === 'prevadzka' && onSelectFacility && (
              <div className="mt-1 flex flex-wrap gap-1">
                {view.facilities.map((f) => (
                  <button
                    key={f.id}
                    type="button"
                    onClick={() => onSelectFacility(f.id)}
                    className={cn(
                      'rounded-full px-2.5 py-1 text-xs font-medium transition-colors',
                      f.id === view.facility_id
                        ? 'bg-firol-500 text-white'
                        : 'bg-white text-ink-600 hover:bg-ink-100 border border-ink-200',
                    )}
                  >
                    {f.name}
                  </button>
                ))}
              </div>
            )}
          </div>
        )}

        {/* Unbooked activities list */}
        <div className="flex flex-col gap-2.5">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold uppercase tracking-wider text-ink-500">Čo má ísť do zápisu</span>
            <span className="text-xs text-ink-400">
              {groupedOffers.length > 0 && 'Z každého druhu je predznačený posledný'}
            </span>
          </div>

          {groupedOffers.length === 0 ? (
            <p className="rounded-2xl border border-dashed border-ink-200 bg-ink-50/50 px-4 py-3 text-xs leading-relaxed text-ink-600">
              Všetky hotové úkony tejto firmy už v knihe sú. Napíšte zápis vlastnými slovami.
            </p>
          ) : (
            <div className="flex max-h-72 flex-col gap-3 overflow-y-auto pr-1">
              {groupedOffers.map((g) => (
                <div key={g.title} className="flex flex-col gap-1.5">
                  <div className="flex items-center gap-1.5 text-xs font-medium text-ink-700">
                    <span>{g.title}</span>
                    {g.items.length > 1 && <span className="text-ink-400">· {g.items.length}</span>}
                  </div>
                  <div className="flex flex-col gap-1.5">
                    {g.items.map((offer, idx) => {
                      const isSelected = selectedKeys.has(offer.key);
                      const isLatest = idx === 0 || offer.is_latest;
                      return (
                        <button
                          key={offer.key}
                          type="button"
                          onClick={() => toggleOffer(offer)}
                          className={cn(
                            'flex w-full items-start gap-3 rounded-xl border p-2.5 text-left transition-all duration-150',
                            isSelected
                              ? 'border-firol-300 bg-firol-50/40 text-ink-900 shadow-xs'
                              : 'border-ink-200 bg-white text-ink-700 hover:border-ink-300 hover:bg-ink-50/50',
                          )}
                        >
                          <span
                            className={cn(
                              'mt-0.5 grid size-4 shrink-0 place-items-center rounded-sm border transition-colors',
                              isSelected
                                ? 'border-firol-600 bg-firol-600 text-white'
                                : 'border-ink-300 bg-white',
                            )}
                          >
                            {isSelected && <Check className="size-3 stroke-[3]" />}
                          </span>
                          <span className="min-w-0 flex-1">
                            <span className="flex flex-wrap items-center gap-1.5 text-xs font-semibold text-ink-900">
                              <span>{formatDay(offer.date)}</span>
                              {offer.protocol_numbers.length > 0 && (
                                <span className="text-ink-600">· {offer.protocol_numbers.join(', ')}</span>
                              )}
                              {isLatest && <Badge tone="ok">posledný</Badge>}
                            </span>
                            <span className="mt-0.5 block truncate text-[11px] text-ink-500">
                              {offer.merged > 1
                                ? `${offer.merged} prevádzok naraz · ${offer.facilities.join(', ')}`
                                : offer.facilities.join(', ')}
                            </span>
                          </span>
                        </button>
                      );
                    })}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Vlastný text */}
        <div className="flex flex-col gap-1.5">
          <label htmlFor="nz-vlastny-text" className="text-xs font-semibold uppercase tracking-wider text-ink-500">
            Vlastný text
          </label>
          <p className="text-xs text-ink-500">Nepovinné, pripojí sa za vybrané úkony.</p>
          <textarea
            id="nz-vlastny-text"
            rows={3}
            value={vlastnyText}
            placeholder="Vykonaný cvičný požiarny poplach…"
            onChange={(e) => setVlastnyText(e.target.value)}
            className={TEXTAREA_CLASS}
          />
        </div>

        {/* Zistené závady a nedostatky */}
        <div className="flex flex-col gap-1.5">
          <label htmlFor="nz-defects-text" className="text-xs font-semibold uppercase tracking-wider text-ink-500">
            Zistené závady a nedostatky
          </label>
          <p className="text-xs text-ink-500">
            Nepovinné. Keď nič nevyplníte, do protokolu sa vytlačí, že závady neboli zistené.
          </p>
          <textarea
            id="nz-defects-text"
            rows={2}
            value={defectsText}
            placeholder="Pri kontrole neboli zistené…"
            onChange={(e) => {
              setDefectsEdited(true);
              setDefectsText(e.target.value);
            }}
            className={TEXTAREA_CLASS}
          />
        </div>

        {/* Checkboxes: Pridať zákonnú vetu & Vyhlásenie o nedostatkoch */}
        <div className="flex flex-col gap-2">
          {/* Pridať zákonnú vetu */}
          <label
            className={cn(
              'flex cursor-pointer items-start gap-3 rounded-xl border p-2.5 transition-colors duration-150',
              legalSentence ? 'border-firol-300 bg-firol-50/30' : 'border-ink-200 bg-white hover:border-ink-300',
            )}
          >
            <input
              type="checkbox"
              checked={legalSentence}
              onChange={(e) => setLegalSentence(e.target.checked)}
              className="mt-0.5 size-4 shrink-0 accent-[var(--color-firol-500)]"
            />
            <span className="min-w-0">
              <span className="block text-xs font-semibold text-ink-900">Pridať zákonnú vetu</span>
              <span className="mt-0.5 block text-xs leading-relaxed text-ink-500">
                Doplní vetu o zákone a vyhláške pred text zápisu.
              </span>
            </span>
          </label>

          {/* Vyhlásenie o nedostatkoch */}
          <label
            className={cn(
              'flex items-start gap-3 rounded-xl border p-2.5 transition-colors duration-150',
              carriesDefects
                ? 'cursor-not-allowed border-ink-100 bg-ink-50/50 opacity-60'
                : noDefects
                  ? 'cursor-pointer border-status-ok bg-[var(--color-status-ok-bg)]'
                  : 'cursor-pointer border-ink-200 bg-white hover:border-ink-300',
            )}
          >
            <input
              type="checkbox"
              checked={!carriesDefects && noDefects}
              disabled={carriesDefects}
              onChange={(e) => setNoDefects(e.target.checked)}
              className="mt-0.5 size-4 shrink-0 accent-[var(--color-firol-500)]"
            />
            <span className="min-w-0">
              <span className="block text-xs font-semibold text-ink-900">{view.no_defects_sentence}</span>
              <span className="mt-0.5 block text-xs leading-relaxed text-ink-500">
                {carriesDefects
                  ? 'Zápis nesie nedostatky, preto sa vyhlásenie nedá zaškrtnúť.'
                  : 'Vytlačí sa v sekcii ZISTENIA namiesto zistených nedostatkov.'}
              </span>
            </span>
          </label>
        </div>

        {/* Live preview */}
        <div className="flex flex-col gap-1.5">
          <span className="text-xs font-semibold uppercase tracking-wider text-ink-500">Takto bude zápis vyzerať</span>
          <div className="rounded-xl border border-ink-200 bg-ink-50/60 p-3 font-sans text-xs leading-relaxed text-ink-800 whitespace-pre-wrap min-h-16">
            {composedText ? composedText : <span className="text-ink-400">— zatiaľ prázdny —</span>}
          </div>
          {selectedOffers.length > 0 && (
            <p className="text-xs text-ink-500">
              {selectedOffers.length} {plural(selectedOffers.length, 'úkon', 'úkony', 'úkonov')} v jednom zápise.
            </p>
          )}
        </div>

        {error && (
          <p role="alert" className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-xs text-[var(--color-status-bad)]">
            {error}
          </p>
        )}

        {/* Modal buttons */}
        <div className="flex flex-wrap items-center justify-end gap-2 pt-2 border-t border-ink-100">
          <Button type="button" variant="ghost" size="sm" onClick={onClose} disabled={saving}>
            Zrušiť
          </Button>
          <Button
            type="button"
            variant="secondary"
            size="sm"
            loading={saving}
            leftIcon={<Printer className="size-3.5" />}
            onClick={() => void handleSubmit(true)}
          >
            Zapísať a vytlačiť záznam
          </Button>
          <Button
            type="button"
            size="sm"
            loading={saving}
            onClick={() => void handleSubmit(false)}
          >
            Zapísať do požiarnej knihy
          </Button>
        </div>
      </div>
    </Dialog>
  );
}

