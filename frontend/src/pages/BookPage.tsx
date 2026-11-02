import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import {
  ArrowLeft, FileText, Plus, Printer, Signature,
} from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useIsReadOnly } from '@/auth/useIsReadOnly';
import {
  Book, bookPrintUrl, type BookEntry, type BookScope, type BookView,
} from '@/api/book';
import { ApiError } from '@/lib/api';
import { isBytoveDruzstvo } from '@/lib/clientKind';
import { offlineMessage } from '@/lib/offline';
import { cn } from '@/lib/cn';
import { useRevalidateOnReturn } from '@/lib/useRevalidateOnReturn';
import { useToast } from '@/lib/toast';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { DetailHeaderSkeleton, SkeletonList } from '@/components/ui/Skeleton';
import { formatDay } from '@/components/ClientArchive';
import { AdditionSheet, SignSheet } from '@/components/book/BookSheets';
import { NewBookEntryModal } from '@/components/book/NewBookEntryModal';

function plural(n: number, one: string, few: string, many: string): string {
  return `${n} ${n === 1 ? one : n >= 2 && n <= 4 ? few : many}`;
}

type Sheet =
  | { type: 'addition'; entry: BookEntry }
  | { type: 'sign' };

/**
 * Požiarna kniha of one firm (spec 9, 02.11 overhaul).
 * The book is strictly Požiarna kniha; BOZP has no book ledger (decision D4).
 * The book screen features a unified pull modal (+ Nový zápis do požiarnej knihy)
 * where the technician selects unbooked activities, writes custom notes or defects,
 * toggles optional statutory sentences, previews live, and prints single entries
 * or the full ledger.
 */
export function BookPage() {
  const { id: idStr } = useParams<{ id: string }>();
  const companyId = Number(idStr);
  const [params, setParams] = useSearchParams();
  const facilityParam = Number(params.get('facility')) || null;

  const { csrfToken } = useAuth();
  const isReadOnly = useIsReadOnly();
  const toast = useToast();
  const [view, setView] = useState<BookView | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [sheet, setSheet] = useState<Sheet | null>(null);
  const [novyZapisOpen, setNovyZapisOpen] = useState(false);
  const [switching, setSwitching] = useState(false);

  const load = useCallback(() => {
    return Book.show(companyId, 'po', facilityParam)
      .then((res) => {
        setView(res);
        setError(null);
      })
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : 'Knihu sa nepodarilo načítať.');
      });
  }, [companyId, facilityParam]);

  useEffect(() => {
    setView(null);
    void load();
  }, [load]);

  const shownKey = useRef('');
  shownKey.current = `${companyId}|po|${facilityParam ?? ''}`;
  useRevalidateOnReturn(() => {
    const key = shownKey.current;
    return Book.show(companyId, 'po', facilityParam)
      .then((res) => {
        if (shownKey.current === key) setView(res);
      })
      .catch(() => undefined);
  });

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
      const isBd = isBytoveDruzstvo(view.company.client_type);
      toast.success(
        next === 'firma'
          ? (isBd ? 'Spoločná kniha za celý subjekt.' : 'Jedna kniha za celú firmu.')
          : (isBd ? 'Samostatná kniha za každý dom.' : 'Kniha za každú prevádzku.')
      );
      await load();
    } catch (err) {
      toast.error(offlineMessage(err, 'Rozsah knihy sa nepodarilo zmeniť.'));
    } finally {
      setSwitching(false);
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
  const isBd = isBytoveDruzstvo(view.company.client_type);
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
            className="h-9 w-1.5 shrink-0 rounded-full bg-[#E8433A]"
          />
          <div className="min-w-0">
            <h1 className="text-xl font-semibold tracking-tight text-ink-900">Požiarna kniha</h1>
            <p className="mt-0.5 text-sm text-ink-500">
              {view.scope === 'prevadzka' && currentFacility ? `${currentFacility.name} · ` : ''}
              {plural(view.entries.length, 'zápis', 'zápisy', 'zápisov')}
              {offerCount > 0 && ` · ${offerCount} na pridanie`}
            </p>
          </div>
        </div>
      </header>

      {view.company.archived && (
        <p className="rounded-2xl bg-ink-50 px-3 py-2.5 text-xs leading-relaxed text-ink-600">
          Firma je archivovaná — kniha sa dá čítať a tlačiť, ale nič sa do nej už nezapisuje.
        </p>
      )}

      <div className="flex flex-col gap-2 rounded-2xl bg-ink-50 px-3.5 py-3 text-xs leading-relaxed text-ink-600">
        <p>Záznamy o dôležitých skutočnostiach — prehliadky, nedostatky, ich odstránenie.</p>
        <p className="text-ink-500">
          Kniha je <strong>len požiarna</strong>. Preventívna prehliadka BOZP je samostatný záznam z prehliadky, nie kniha.
        </p>
      </div>

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
                  {s === 'firma'
                    ? (isBd ? 'Spoločná kniha za celý subjekt' : 'Jedna za celú firmu')
                    : (isBd ? 'Samostatne za každý dom' : 'Za každú prevádzku')}
                </button>
              ))}
            </div>
            <p className="text-xs leading-relaxed text-ink-500">
              {view.scope === 'firma'
                ? (isBd
                  ? 'Spoločná kniha za všetky bytové domy. Keď ten istý úkon urobíte v ten istý deň na viacerých domoch, appka ponúkne jeden zápis za všetky.'
                  : 'Jedna kniha na všetky prevádzky. Keď ten istý úkon urobíte v ten istý deň na viacerých prevádzkach, appka ponúkne jeden zápis za všetky.')
                : (isBd
                  ? 'Každý bytový dom má vlastnú knihu a vlastné číslovanie.'
                  : 'Každá prevádzka má vlastnú knihu a vlastné číslovanie.')}
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
          {/* Nový zápis pull card */}
          <section className="flex animate-fade-up flex-col gap-2.5">
            <Card className="flex flex-col gap-3 border-firol-200 bg-firol-50/20 px-4 py-4">
              <div>
                <h2 className="text-base font-semibold text-ink-900">Nový zápis</h2>
                <p className="mt-1 text-xs leading-relaxed text-ink-600">
                  {offerCount > 0
                    ? `Appka ponúkne posledné kontroly ako hotové odseky — čaká ${offerCount} ${plural(offerCount, 'úkon', 'úkony', 'úkonov')}.`
                    : 'Všetky úkony už v knihe sú — zápis napíšete vlastnými slovami.'}
                </p>
              </div>

              {canWrite && (
                <Button
                  id="btn-novy-zapis"
                  className="self-start"
                  leftIcon={<Plus className="size-4" />}
                  onClick={() => setNovyZapisOpen(true)}
                >
                  + Nový zápis do požiarnej knihy
                </Button>
              )}

              <p className="text-xs text-ink-400">
                Úkon, ktorý v appke nie je, zapíšete vlastnými slovami priamo v zápise.
              </p>
            </Card>
          </section>

          {/* Zápisy */}
          <section className="flex animate-fade-up flex-col gap-2.5">
            <h2 className="text-xs font-semibold uppercase tracking-wider text-ink-400">Zápisy</h2>
            {view.entries.length === 0 ? (
              <p className="rounded-2xl border border-dashed border-ink-200 bg-white px-3.5 py-4 text-xs leading-relaxed text-ink-500">
                Kniha je ešte prázdna. Pridajte prvý zápis tlačidlom vyššie.
              </p>
            ) : (
              view.entries.map((e) => (
                <EntryCard
                  key={e.id}
                  entry={e}
                  companyId={view.company.id}
                  canWrite={canWrite}
                  onAddition={() => setSheet({ type: 'addition', entry: e })}
                />
              ))
            )}
          </section>

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
                  Vytlačiť hárok nepovinných podpisov ({view.unsigned_count})
                </Button>
              )}
            </Card>
          </section>

          {/* Vytlačiť celú knihu */}
          <section className="flex animate-fade-up flex-col gap-2.5">
            <h2 className="text-xs font-semibold uppercase tracking-wider text-ink-400">Vytlačiť celú knihu</h2>
            <Card className="flex flex-col gap-3 px-4 py-3.5">
              <p className="text-xs leading-relaxed text-ink-600">
                Všetky zápisy za sebou v jednom dokumente — to, čo odovzdáte klientovi alebo ukážete pri kontrole.
              </p>
              <a
                href={
                  view.entries.length > 0
                    ? bookPrintUrl(view.company.id, 'po', { facilityId: view.facility_id, mode: 'all' })
                    : '#'
                }
                target={view.entries.length > 0 ? '_blank' : undefined}
                rel="noopener noreferrer"
                onClick={(e) => {
                  if (view.entries.length === 0) {
                    e.preventDefault();
                    toast.error('Kniha je zatiaľ prázdna.');
                  }
                }}
                className={cn(
                  'inline-flex h-10 items-center justify-center gap-2 rounded-xl border border-ink-200 bg-white px-4 text-sm font-medium text-ink-800 transition-all duration-200',
                  view.entries.length > 0
                    ? 'hover:border-ink-300 hover:bg-ink-50 active:scale-[0.99] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300'
                    : 'opacity-50 cursor-not-allowed',
                )}
              >
                <Printer className="size-4" />
                Vytlačiť celú knihu
              </a>
            </Card>
          </section>
        </>
      )}

      <p className="rounded-2xl bg-ink-50 px-3.5 py-3 text-xs leading-relaxed text-ink-600">
        Zákonná veta sa do zápisu <strong>nedopĺňa sama</strong> — technik si ju zapne, keď ju tam chce. Titulnú stranu knihy si robí technik sám.
      </p>

      {/* Unified pull modal */}
      {novyZapisOpen && (
        <NewBookEntryModal
          view={view}
          onClose={() => setNovyZapisOpen(false)}
          onSaved={() => {
            setNovyZapisOpen(false);
            void load();
          }}
          onSwitchScope={switchScope}
          onSelectFacility={selectFacility}
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

function EntryCard({
  entry,
  companyId,
  canWrite,
  onAddition,
}: {
  entry: BookEntry;
  companyId: number;
  canWrite: boolean;
  onAddition: () => void;
}) {
  return (
    <Card className="flex flex-col gap-3 px-4 py-3.5">
      <div className="flex items-start gap-3">
        <span
          className="grid size-9 shrink-0 place-items-center rounded-xl bg-firol-50 text-firol-600 text-[13px] font-bold tabular-nums"
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

          {entry.defects_text && (
            <div className="mt-2 rounded-xl bg-ink-50 p-2.5 text-xs text-ink-700">
              <strong className="block font-semibold text-ink-800 mb-0.5">Zistené závady a nedostatky:</strong>
              <p className="whitespace-pre-wrap">{entry.defects_text}</p>
            </div>
          )}

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
          href={bookPrintUrl(companyId, 'po', { entryId: entry.id, facilityId: entry.facility_id, mode: 'entry' })}
          target="_blank"
          rel="noopener noreferrer"
          className="inline-flex h-9 items-center justify-center gap-1.5 rounded-xl border border-ink-200 bg-white px-3 text-sm font-medium text-ink-800 transition-[background-color,box-shadow,transform] duration-200 hover:border-ink-300 hover:bg-ink-50 active:scale-[0.99] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300"
        >
          <Printer className="size-3.5" />
          Tlač
        </a>
      </div>
    </Card>
  );
}
