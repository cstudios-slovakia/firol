import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import {
  ArrowLeft, ChevronDown, FileText, Hash, PenLine, Plus, Printer, Signature,
} from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useIsReadOnly } from '@/auth/useIsReadOnly';
import {
  Book, bookPrintUrl, type BookEntry, type BookOffer, type BookScope, type BookView,
} from '@/api/book';
import { ApiError } from '@/lib/api';
import { BOOKS, BOOK_LABELS, isBook, type BookKey } from '@/lib/bookMapping';
import { offlineMessage } from '@/lib/offline';
import { cn } from '@/lib/cn';
import { useToast } from '@/lib/toast';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { DetailHeaderSkeleton, SkeletonList } from '@/components/ui/Skeleton';
import { formatDay } from '@/components/ClientArchive';
import {
  AdditionSheet, EntrySheet, SignSheet, type EntrySheetMode,
} from '@/components/book/BookSheets';

/** Offers older than this fold into one row, so a firm with years of history is not buried. */
const FOLD_OFFERS_AFTER_MONTHS = 12;

function plural(n: number, one: string, few: string, many: string): string {
  return `${n} ${n === 1 ? one : n >= 2 && n <= 4 ? few : many}`;
}

function olderThanCutoff(iso: string): boolean {
  const cutoff = new Date();
  cutoff.setMonth(cutoff.getMonth() - FOLD_OFFERS_AFTER_MONTHS);
  return iso < cutoff.toISOString().slice(0, 10);
}

type Sheet =
  | { type: 'entry'; mode: EntrySheetMode }
  | { type: 'addition'; entry: BookEntry }
  | { type: 'sign' };

/**
 * Požiarna kniha and kniha BOZP of one firm (spec 9). The book is a place at
 * the firm with a numbered row per entry; the app offers an entry for every
 * finished úkon and the technician adds it with one tap or edits it first.
 * Reads work offline; adding, dopísanie and signing need a connection.
 */
export function BookPage() {
  const { id: idStr } = useParams<{ id: string }>();
  const companyId = Number(idStr);
  const [params, setParams] = useSearchParams();
  const book: BookKey = isBook(params.get('book')) ? (params.get('book') as BookKey) : 'po';
  const facilityParam = Number(params.get('facility')) || null;

  const { csrfToken } = useAuth();
  const isReadOnly = useIsReadOnly();
  const toast = useToast();
  const [view, setView] = useState<BookView | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [sheet, setSheet] = useState<Sheet | null>(null);
  const [adding, setAdding] = useState<string | null>(null);
  const [showOldOffers, setShowOldOffers] = useState(false);
  const [showPrevious, setShowPrevious] = useState(false);
  const [switching, setSwitching] = useState(false);

  const load = useCallback(() => {
    return Book.show(companyId, book, facilityParam)
      .then((res) => {
        setView(res);
        setError(null);
      })
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : 'Knihu sa nepodarilo načítať.');
      });
  }, [companyId, book, facilityParam]);

  useEffect(() => {
    setView(null);
    void load();
  }, [load]);

  const recentOffers = useMemo(() => (view?.offers ?? []).filter((o) => !olderThanCutoff(o.date)), [view]);
  const oldOffers = useMemo(() => (view?.offers ?? []).filter((o) => olderThanCutoff(o.date)), [view]);

  function selectBook(next: BookKey) {
    const qs = new URLSearchParams(params);
    qs.set('book', next);
    setParams(qs, { replace: true });
  }

  function selectFacility(id: number) {
    const qs = new URLSearchParams(params);
    qs.set('facility', String(id));
    setParams(qs, { replace: true });
  }

  async function switchScope(next: BookScope) {
    if (!view || view.scope === next) return;
    setSwitching(true);
    try {
      await Book.setScope(companyId, next, csrfToken);
      toast.success(next === 'firma' ? 'Jedna kniha za celú firmu.' : 'Kniha za každú prevádzku.');
      await load();
    } catch (err) {
      toast.error(offlineMessage(err, 'Rozsah knihy sa nepodarilo zmeniť.'));
    } finally {
      setSwitching(false);
    }
  }

  async function addOffer(offer: BookOffer) {
    if (!view) return;
    setAdding(offer.key);
    try {
      await Book.createEntry(
        companyId,
        {
          book: view.book,
          facility_id: view.facility_id,
          source: 'ukon',
          date: offer.date,
          text: offer.text,
          protocol_numbers: offer.protocol_numbers,
          inspection_ids: offer.inspection_ids,
          training_ids: offer.training_ids,
        },
        csrfToken,
      );
      toast.success('Zapísané do knihy. Čaká na podpis vedúceho.');
      await load();
    } catch (err) {
      toast.error(offlineMessage(err, 'Zápis sa nepodarilo uložiť.'));
    } finally {
      setAdding(null);
    }
  }

  if (error && !view) {
    return (
      <div className="flex flex-col gap-4">
        <Link
          to={`/companies/${companyId}`}
          className="inline-flex items-center gap-1 self-start text-sm text-ink-500 hover:text-ink-700"
        >
          <ArrowLeft className="size-4" />
          Späť
        </Link>
        <Card className="px-4 py-3 text-sm text-status-bad">{error}</Card>
      </div>
    );
  }

  if (!view) {
    return (
      <div className="flex flex-col gap-5">
        <DetailHeaderSkeleton />
        <SkeletonList count={3} />
      </div>
    );
  }

  const canWrite = !isReadOnly && !view.company.archived;
  const hasLedger = view.scope === 'firma' || view.facility_id !== null;
  const currentFacility = view.facilities.find((f) => f.id === view.facility_id) ?? null;
  const offerCount = view.offers.length;

  return (
    <div className="flex flex-col gap-5">
      <Link
        to={`/companies/${view.company.id}`}
        className="inline-flex items-center gap-1 self-start text-sm text-ink-500 transition-colors hover:text-ink-700"
      >
        <ArrowLeft className="size-4" />
        {view.company.name}
      </Link>

      <header className="flex flex-col gap-3">
        <div className="flex items-center gap-2.5">
          <span
            aria-hidden
            className={cn('h-9 w-1.5 shrink-0 rounded-full', book === 'bozp' ? 'bg-[#3D7FC1]' : 'bg-[#E8433A]')}
          />
          <div className="min-w-0">
            <h1 className="text-xl font-semibold tracking-tight text-ink-900">{view.book_label}</h1>
            <p className="mt-0.5 text-sm text-ink-500">
              {view.scope === 'prevadzka' && currentFacility ? `${currentFacility.name} · ` : ''}
              {plural(view.entries.length, 'zápis', 'zápisy', 'zápisov')}
              {offerCount > 0 && ` · ${offerCount} na pridanie`}
            </p>
          </div>
        </div>

        <div role="radiogroup" aria-label="Kniha" className="grid grid-cols-2 gap-1 rounded-2xl bg-ink-100/80 p-1">
          {BOOKS.map((b) => (
            <button
              key={b}
              type="button"
              role="radio"
              aria-checked={book === b}
              onClick={() => selectBook(b)}
              className={cn(
                'inline-flex h-10 items-center justify-center rounded-xl px-3 text-sm font-medium transition-all duration-200',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300 active:scale-[0.98]',
                book === b ? 'bg-white text-ink-900 shadow-sm' : 'text-ink-600 hover:bg-white/60 hover:text-ink-800',
              )}
            >
              {BOOK_LABELS[b]}
            </button>
          ))}
        </div>
      </header>

      {view.company.archived && (
        <p className="rounded-2xl bg-ink-50 px-3 py-2.5 text-xs leading-relaxed text-ink-600">
          Firma je archivovaná — kniha sa dá čítať a tlačiť, ale nič sa do nej už nezapisuje.
        </p>
      )}

      <p className="rounded-2xl bg-ink-50 px-3.5 py-3 text-xs leading-relaxed text-ink-600">
        {book === 'po'
          ? 'Záznamy o dôležitých skutočnostiach podľa § 29 vyhlášky č. 121/2002 Z. z. — najmä o preventívnych prehliadkach, zistených nedostatkoch a ich odstránení.'
          : 'Vlastná kniha pre BOZP. Nie je to súčasť požiarnej knihy — tá je dokument podľa § 29 vyhlášky č. 121/2002 Z. z. a je čisto pre ochranu pred požiarmi. Mechanika je tá istá.'}
      </p>

      {view.can_switch_scope && (
        <section className="flex animate-fade-up flex-col gap-3">
          <Card className="flex flex-col gap-2.5 px-4 py-3.5">
            <p className="text-xs font-semibold uppercase tracking-wide text-ink-500">Rozsah knihy</p>
            <div role="radiogroup" aria-label="Rozsah knihy" className="grid grid-cols-2 gap-1 rounded-2xl bg-ink-100/80 p-1">
              {(['firma', 'prevadzka'] as BookScope[]).map((s) => (
                <button
                  key={s}
                  type="button"
                  role="radio"
                  aria-checked={view.scope === s}
                  disabled={!canWrite || switching}
                  onClick={() => void switchScope(s)}
                  className={cn(
                    'inline-flex min-h-10 items-center justify-center rounded-xl px-2 text-center text-sm font-medium leading-tight transition-all duration-200',
                    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300 active:scale-[0.98] disabled:pointer-events-none',
                    view.scope === s
                      ? 'bg-white text-ink-900 shadow-sm'
                      : 'text-ink-600 hover:bg-white/60 hover:text-ink-800 disabled:opacity-60',
                  )}
                >
                  {s === 'firma' ? 'Jedna za firmu' : 'Za každú prevádzku'}
                </button>
              ))}
            </div>
            <p className="text-xs leading-relaxed text-ink-500">
              {view.scope === 'firma'
                ? 'Jedna kniha na všetky prevádzky. Keď ten istý úkon urobíte v ten istý deň na viacerých, appka ponúkne jeden zápis za všetky.'
                : 'Každá prevádzka má vlastnú knihu a vlastné číslovanie. § 29 to pripúšťa — nižšie organizačné jednotky môžu mať samostatné knihy.'}
            </p>
          </Card>

          {view.scope === 'prevadzka' && (
            <div className="-mx-1 flex gap-1.5 overflow-x-auto px-1 pb-1 [&::-webkit-scrollbar]:hidden">
              {view.facilities.map((f) => (
                <button
                  key={f.id}
                  type="button"
                  aria-pressed={f.id === view.facility_id}
                  onClick={() => selectFacility(f.id)}
                  className={cn(
                    'shrink-0 rounded-full px-3.5 py-1.5 text-xs font-medium transition-[background-color,color,transform] duration-200 active:scale-[0.97]',
                    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300',
                    f.id === view.facility_id ? 'bg-firol-500 text-white' : 'bg-ink-100 text-ink-600 hover:bg-ink-200',
                  )}
                >
                  {f.name}
                </button>
              ))}
            </div>
          )}
        </section>
      )}

      {!hasLedger && (
        <p className="rounded-2xl bg-ink-50 px-3 py-3 text-sm text-ink-600">
          Kniha je vedená po prevádzkach — najprv pridajte prevádzku.
        </p>
      )}

      {hasLedger && (
        <>
          {/* Appka ponúka pridať */}
          <section className="flex animate-fade-up flex-col gap-2.5">
            <h2 className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-ink-400">
              Appka ponúka pridať
              {offerCount > 0 && <Badge tone="brand">{offerCount}</Badge>}
            </h2>

            {offerCount === 0 ? (
              <p className="rounded-2xl border border-dashed border-ink-200 bg-white px-3.5 py-3 text-xs leading-relaxed text-ink-500">
                Všetky hotové úkony tejto firmy už sú v knihe. Nové sa tu ponúknu samé, keď ich dokončíte.
              </p>
            ) : (
              <>
                {recentOffers.map((o) => (
                  <OfferCard
                    key={o.key}
                    offer={o}
                    canWrite={canWrite}
                    busy={adding === o.key}
                    onAdd={() => void addOffer(o)}
                    onEdit={() => setSheet({ type: 'entry', mode: { kind: 'offer', offer: o } })}
                  />
                ))}

                {oldOffers.length > 0 && (
                  <>
                    <button
                      type="button"
                      aria-expanded={showOldOffers}
                      onClick={() => setShowOldOffers((v) => !v)}
                      className="flex min-h-11 items-center justify-between gap-2 rounded-2xl border border-ink-100 bg-white px-4 text-sm font-medium text-ink-700 transition-all duration-200 hover:bg-ink-50 active:scale-[0.99] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300"
                    >
                      <span>Staršie než 12 mesiacov · {oldOffers.length}</span>
                      <ChevronDown className={cn('size-4 text-ink-400 transition-transform duration-200', showOldOffers && 'rotate-180')} />
                    </button>
                    {showOldOffers &&
                      oldOffers.map((o) => (
                        <div key={o.key} className="animate-fade-up">
                          <OfferCard
                            offer={o}
                            canWrite={canWrite}
                            busy={adding === o.key}
                            onAdd={() => void addOffer(o)}
                            onEdit={() => setSheet({ type: 'entry', mode: { kind: 'offer', offer: o } })}
                          />
                        </div>
                      ))}
                  </>
                )}
              </>
            )}
          </section>

          {/* Zápisy */}
          <section className="flex animate-fade-up flex-col gap-2.5">
            <h2 className="text-xs font-semibold uppercase tracking-wider text-ink-400">Zápisy</h2>
            {view.entries.length === 0 ? (
              <p className="rounded-2xl border border-dashed border-ink-200 bg-white px-3.5 py-3 text-xs leading-relaxed text-ink-500">
                Kniha je ešte prázdna. Pridajte prvý zápis z ponuky vyššie alebo napíšte vlastný.
              </p>
            ) : (
              view.entries.map((e) => (
                <EntryCard
                  key={e.id}
                  entry={e}
                  book={view.book}
                  companyId={view.company.id}
                  canWrite={canWrite}
                  onAddition={() => setSheet({ type: 'addition', entry: e })}
                />
              ))
            )}
          </section>

          {/* Pridať zápis ručne */}
          {canWrite && (
            <section className="flex animate-fade-up flex-col gap-2">
              <h2 className="text-xs font-semibold uppercase tracking-wider text-ink-400">Pridať zápis ručne</h2>
              <Button
                variant="secondary"
                leftIcon={<PenLine className="size-4" />}
                onClick={() => setSheet({ type: 'entry', mode: { kind: 'own' } })}
              >
                Vlastný zápis
              </Button>
              <Button
                variant="secondary"
                leftIcon={<Hash className="size-4" />}
                onClick={() => setSheet({ type: 'entry', mode: { kind: 'number' } })}
              >
                Podľa čísla protokolu
              </Button>
              <p className="text-xs leading-relaxed text-ink-500">
                Zápis podľa čísla funguje aj pre úkon, ktorý v appke vôbec nie je — zadáte číslo protokolu a appka zloží vetu.
              </p>
            </section>
          )}

          {/* Podpisy */}
          <section className="flex animate-fade-up flex-col gap-2.5">
            <h2 className="text-xs font-semibold uppercase tracking-wider text-ink-400">Podpisy</h2>
            <Card className="flex flex-col gap-3 px-4 py-3.5">
              <div className="flex items-center gap-3">
                <span
                  className={cn(
                    'grid size-10 shrink-0 place-items-center rounded-2xl',
                    view.unsigned_count > 0
                      ? 'bg-[var(--color-status-warn-bg)] text-[var(--color-status-warn)]'
                      : 'bg-[var(--color-status-ok-bg)] text-[var(--color-status-ok)]',
                  )}
                >
                  <Signature className="size-5" />
                </span>
                <div className="min-w-0">
                  <p className="text-sm font-semibold text-ink-900">
                    {view.unsigned_count === 0
                      ? view.entries.length === 0
                        ? 'Zatiaľ nie je čo podpisovať'
                        : 'Všetko podpísané'
                      : view.unsigned_count === 1
                        ? '1 zápis nie je podpísaný'
                        : view.unsigned_count < 5
                          ? `${view.unsigned_count} zápisy nie sú podpísané`
                          : `${view.unsigned_count} zápisov nie je podpísaných`}
                  </p>
                  <p className="text-xs text-ink-500">podpisuje vedúci zamestnanec na strane klienta</p>
                </div>
              </div>
              <p className="text-xs leading-relaxed text-ink-500">
                Podpisy fungujú ako doteraz — zápis sa vytlačí a dá podpísať. Appka vedie len evidenciu, podpísaný papier musí byť u klienta.
              </p>
              {view.unsigned_count > 0 && (
                <Button
                  variant="secondary"
                  leftIcon={<Printer className="size-4" />}
                  onClick={() => setSheet({ type: 'sign' })}
                >
                  Vytlačiť nepodpísané ({view.unsigned_count})
                </Button>
              )}
            </Card>
          </section>
        </>
      )}

      {view.previous_scope_entries.length > 0 && (
        <section className="flex flex-col gap-2.5">
          <button
            type="button"
            aria-expanded={showPrevious}
            onClick={() => setShowPrevious((v) => !v)}
            className="flex min-h-11 items-center justify-between gap-2 rounded-2xl border border-ink-100 bg-white px-4 text-left text-sm font-medium text-ink-700 transition-all duration-200 hover:bg-ink-50 active:scale-[0.99] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300"
          >
            <span>Zápisy z predchádzajúceho rozsahu · {view.previous_scope_entries.length}</span>
            <ChevronDown className={cn('size-4 shrink-0 text-ink-400 transition-transform duration-200', showPrevious && 'rotate-180')} />
          </button>
          {showPrevious && (
            <>
              <p className="text-xs leading-relaxed text-ink-500">
                Tieto zápisy ostali v knihe, v ktorej vznikli ({view.scope === 'firma' ? 'knihy jednotlivých prevádzok' : 'kniha celej firmy'}), a nemenia sa.
              </p>
              {view.previous_scope_entries.map((e) => (
                <div key={e.id} className="animate-fade-up">
                  <EntryCard
                    entry={e}
                    book={view.book}
                    companyId={view.company.id}
                    canWrite={canWrite}
                    onAddition={() => setSheet({ type: 'addition', entry: e })}
                  />
                </div>
              ))}
            </>
          )}
        </section>
      )}

      <p className="rounded-2xl bg-ink-50 px-3.5 py-3 text-xs leading-relaxed text-ink-600">
        Zákonná veta („v zmysle zákona č. 314/2001 Z. z. …“) sa do zápisu <strong>nedopĺňa sama</strong> — technik si ju zapne, keď ju tam chce. Titulnú stranu knihy si robí technik sám — je na nej napísané, pre ktoré prevádzky kniha platí. Appka len vie, kam ktorý zápis patrí.
      </p>

      {sheet?.type === 'entry' && (
        <EntrySheet
          view={view}
          mode={sheet.mode}
          onClose={() => setSheet(null)}
          onSaved={() => {
            setSheet(null);
            void load();
          }}
        />
      )}
      {sheet?.type === 'addition' && (
        <AdditionSheet
          entry={sheet.entry}
          onClose={() => setSheet(null)}
          onSaved={() => {
            setSheet(null);
            void load();
          }}
        />
      )}
      {sheet?.type === 'sign' && (
        <SignSheet
          view={view}
          onClose={() => setSheet(null)}
          onMarked={() => {
            setSheet(null);
            void load();
          }}
        />
      )}
    </div>
  );
}

function OfferCard({
  offer,
  canWrite,
  busy,
  onAdd,
  onEdit,
}: {
  offer: BookOffer;
  canWrite: boolean;
  busy: boolean;
  onAdd: () => void;
  onEdit: () => void;
}) {
  return (
    <Card className="flex flex-col gap-3 border-[#D8CBE8] bg-[#F7F3FC] px-4 py-3.5">
      <div className="flex items-start gap-3">
        <span className="grid size-9 shrink-0 place-items-center rounded-2xl bg-[#EBE1F7] text-[#5B3E8C]">
          <Plus className="size-4" />
        </span>
        <div className="min-w-0 flex-1">
          <p className="text-sm font-semibold text-ink-900">{offer.title}</p>
          <p className="mt-0.5 text-xs text-ink-500">
            {offer.merged > 1 && <strong className="font-semibold text-[#5B3E8C]">{offer.merged} prevádzok naraz · </strong>}
            {offer.merged === 1 && offer.facilities.length > 0 && `${offer.facilities[0]} · `}
            {formatDay(offer.date)}
            {offer.merged === 1 && offer.protocol_numbers[0] && ` · ${offer.protocol_numbers[0]}`}
          </p>
          <p className="mt-2 text-[13px] leading-relaxed text-ink-600">{offer.text}</p>
        </div>
      </div>
      {canWrite && (
        <div className="flex justify-end gap-2">
          <Button variant="secondary" size="sm" disabled={busy} onClick={onEdit}>
            Upraviť a pridať
          </Button>
          <Button size="sm" loading={busy} onClick={onAdd}>
            Pridať do knihy
          </Button>
        </div>
      )}
    </Card>
  );
}

function EntryCard({
  entry,
  book,
  companyId,
  canWrite,
  onAddition,
}: {
  entry: BookEntry;
  book: BookKey;
  companyId: number;
  canWrite: boolean;
  onAddition: () => void;
}) {
  return (
    <Card className="flex flex-col gap-3 px-4 py-3.5">
      <div className="flex items-start gap-3">
        <span
          className={cn(
            'grid size-9 shrink-0 place-items-center rounded-xl text-[13px] font-bold tabular-nums',
            book === 'bozp' ? 'bg-[#EEF3F9] text-[#3D7FC1]' : 'bg-firol-50 text-firol-600',
          )}
        >
          {entry.seq}
        </span>
        <div className="min-w-0 flex-1">
          <p className="text-xs text-ink-500">
            {formatDay(entry.entry_date)}
            {entry.protocol_numbers.length > 0 && ` · ${entry.protocol_numbers.join(', ')}`}
            {entry.facility_name && ` · ${entry.facility_name}`}
          </p>
          <p className="mt-1 whitespace-pre-wrap break-words text-[13.5px] leading-relaxed text-ink-800">{entry.text}</p>

          {entry.no_defects_statement && (
            <p className="mt-2 rounded-xl bg-[var(--color-status-ok-bg)] px-2.5 py-2 text-xs leading-relaxed text-[var(--color-status-ok)]">
              <strong className="font-semibold tracking-wide">ZISTENIA</strong> — V čase kontroly na kontrolovaných pracoviskách neboli zistené žiadne nedostatky
            </p>
          )}

          {entry.additions.map((a) => (
            <p key={a.id} className="mt-2 border-l-2 border-ink-200 pl-2.5 text-xs leading-relaxed text-ink-600">
              <span className="font-semibold text-ink-700">Dopísané {formatDay(a.created_at)}:</span> {a.text}
            </p>
          ))}

          <div className="mt-2.5 flex flex-wrap gap-1.5">
            <Badge tone="ok">zápis vykonal technik</Badge>
            {entry.signed_manager ? (
              <Badge tone="ok">podpísal vedúci</Badge>
            ) : (
              <Badge tone="warn">čaká na podpis vedúceho</Badge>
            )}
          </div>
        </div>
      </div>
      <div className="flex justify-end gap-2">
        {canWrite && (
          <Button variant="secondary" size="sm" leftIcon={<FileText className="size-3.5" />} onClick={onAddition}>
            Dopísať
          </Button>
        )}
        <a
          href={bookPrintUrl(companyId, book, { entryId: entry.id, facilityId: entry.facility_id })}
          target="_blank"
          rel="noopener noreferrer"
          className="inline-flex h-9 items-center justify-center gap-1.5 rounded-xl border border-ink-200 bg-white px-3 text-sm font-medium text-ink-800 transition-[background-color,box-shadow,transform] duration-200 hover:border-ink-300 hover:bg-ink-50 active:scale-[0.99] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300"
        >
          <Printer className="size-3.5" />
          Tlačiť
        </a>
      </div>
    </Card>
  );
}
