/**
 * „Čo to je" — picks a nedostatok from the číselník (spec 6.3 points 2–3,
 * Phase 4). Self-contained and props-driven: the prehliadka's defect form
 * (Phase 7a) owns the draft and only reacts to `onPick`.
 *
 *   1. „Najčastejšie" — the technician's own top 5 for the current scope
 *      (renders only once there is usage data).
 *   2. Search over title + oblasť + popis + predpis ("dvere", "garáž", "478").
 *   3. The list, filtered by the prehliadka's scope: a protipožiarna
 *      prehliadka hides BOZP-only items, a BOZP one hides požiarne-only items.
 *   4. „+ Vlastný nedostatok" — a sheet that adds an item to the account's
 *      číselník (default lehota „do 30 dní"), and ✎ / 🗑 on those own items.
 *
 * Picking only reports the catalogue row. Copying its texts into the defect
 * is the caller's job, so a defect never references live catalogue text.
 */
import { useId, useMemo, useState } from 'react';
import { Pencil, Search, Trash2, Plus } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import {
  DefectCatalog,
  SCOPE_LABEL,
  useDefectCatalog,
  type DefectCatalogItem,
  type DefectCatalogPayload,
  type DefectScope,
} from '@/api/defectCatalog';
import { OfflineQueuedError } from '@/lib/api';
import { mintTempId } from '@/lib/tempId';
import { useConfirm } from '@/lib/confirm';
import { useToast } from '@/lib/toast';
import { fold } from '@/lib/text';
import { cn } from '@/lib/cn';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { Skeleton } from '@/components/ui/Skeleton';

/** Which records the prehliadka produces (step 1 „Akú prehliadku robím"). */
export type DefectPickerScope = { po: boolean; bozp: boolean };

export type DefectPickerProps = {
  scope: DefectPickerScope;
  /** Catalogue id of the item already chosen, if any — shows it collapsed with „Zmeniť". */
  selectedId?: number | null;
  onPick: (item: DefectCatalogItem) => void;
  /** „Zmeniť" on the collapsed chosen item. */
  onClear?: () => void;
  /** An own item was edited — the caller may refresh the texts of a defect built from it. */
  onItemUpdated?: (item: DefectCatalogItem) => void;
  /** An own item was removed from the číselník — the caller drops it if it was the chosen one. */
  onItemRemoved?: (id: number) => void;
  /** Scope given to a new own item; defaults to what `scope` implies. */
  newItemScope?: DefectScope;
  className?: string;
};

const TOP_COUNT = 5;
/** Shown when the technician leaves popis / opatrenie empty (prototype `vlastnyNedostatok`). */
const EMPTY_TEXT = 'Doplní technik.';

/** PO hides `bo`, BOZP hides `po`, both shows everything (prototype `scrNedostatok`). */
function isVisible(item: DefectCatalogItem, scope: DefectPickerScope): boolean {
  if (scope.po && !scope.bozp) return item.scope !== 'bo';
  if (scope.bozp && !scope.po) return item.scope !== 'po';
  return true;
}

function scopeFor(scope: DefectPickerScope): DefectScope {
  if (scope.po && !scope.bozp) return 'po';
  if (scope.bozp && !scope.po) return 'bo';
  return 'ob';
}

function itemsLabel(n: number): string {
  if (n === 1) return '1 položka';
  if (n >= 2 && n <= 4) return `${n} položky`;
  return `${n} položiek`;
}

/** Title, oblasť, popis and predpis folded into one comparison string. */
function searchKey(item: DefectCatalogItem): string {
  return fold([item.title, item.area, item.description, item.regulation].join(' '));
}

export function DefectPicker({
  scope,
  selectedId = null,
  onPick,
  onClear,
  onItemUpdated,
  onItemRemoved,
  newItemScope,
  className,
}: DefectPickerProps) {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const searchId = useId();
  const { items, usage, loading, error } = useDefectCatalog();

  const [query, setQuery] = useState('');
  const [sheet, setSheet] = useState<{ mode: 'create' } | { mode: 'edit'; item: DefectCatalogItem } | null>(
    null,
  );

  const visible = useMemo(() => items.filter((it) => isVisible(it, scope)), [items, scope]);
  const keys = useMemo(() => new Map(visible.map((it) => [it.id, searchKey(it)])), [visible]);

  const top = useMemo(() => {
    const byId = new Map(visible.map((it) => [it.id, it]));
    return usage
      .map((u) => ({ item: byId.get(u.catalog_id), count: u.count }))
      .filter((u): u is { item: DefectCatalogItem; count: number } => u.item !== undefined)
      .slice(0, TOP_COUNT);
  }, [usage, visible]);

  const filtered = useMemo(() => {
    const tokens = fold(query).split(/\s+/).filter(Boolean);
    if (tokens.length === 0) return visible;
    return visible.filter((it) => {
      const key = keys.get(it.id) ?? '';
      return tokens.every((t) => key.includes(t));
    });
  }, [query, visible, keys]);

  const selected = selectedId !== null ? items.find((it) => it.id === selectedId) ?? null : null;

  async function remove(item: DefectCatalogItem) {
    const ok = await confirm({
      title: 'Odstrániť z číselníka?',
      description: `${item.title} — odstránenie ho zmaže vo vašom číselníku pri všetkých klientoch. Už zapísané nedostatky sa nemenia, text je v nich uložený.`,
      confirmLabel: 'Odstrániť',
    });
    if (!ok) return;
    try {
      await DefectCatalog.remove(item, csrfToken);
      toast.success('Odstránené z číselníka — aj pri ostatných klientoch.');
      onItemRemoved?.(item.id);
    } catch (err) {
      if (err instanceof OfflineQueuedError) {
        toast.success('Odstránenie sa uloží, keď budete online.');
        onItemRemoved?.(item.id);
        return;
      }
      toast.error(err instanceof Error ? err.message : 'Odstránenie sa nepodarilo.');
    }
  }

  return (
    <section className={cn('flex flex-col', className)} aria-label="Čo to je">
      <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-500">Čo to je</h3>

      {selected ? (
        <div className="animate-fade-in flex items-start gap-3 rounded-2xl border border-ink-200 bg-white px-4 py-3">
          <div className="min-w-0 flex-1">
            <p className="text-sm font-semibold text-ink-900">{selected.title}</p>
            <p className="mt-0.5 text-xs text-ink-500">
              {selected.area} · {SCOPE_LABEL[selected.scope]}
            </p>
          </div>
          {onClear && (
            <Button type="button" variant="secondary" size="sm" onClick={onClear}>
              Zmeniť
            </Button>
          )}
        </div>
      ) : (
        <div className="flex flex-col">
          {top.length > 0 && (
            <div className="animate-fade-in mb-4">
              <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-500">
                Najčastejšie
              </h4>
              <ul className="flex flex-col gap-2">
                {top.map(({ item, count }) => (
                  <li key={item.id}>
                    <button
                      type="button"
                      onClick={() => onPick(item)}
                      className={cn(
                        'group flex w-full items-start gap-3 rounded-2xl border border-firol-200 bg-firol-50 px-4 py-3 text-left',
                        'transition-[border-color,background-color,transform,box-shadow] duration-200',
                        'hover:border-firol-300 hover:bg-firol-100/70 hover:shadow-[var(--shadow-soft)]',
                        'active:scale-[0.99] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300',
                      )}
                    >
                      <span
                        aria-hidden
                        className="mt-0.5 size-5 shrink-0 rounded-full border-2 border-firol-400 transition-colors duration-200 group-hover:bg-firol-200"
                      />
                      <span className="min-w-0 flex-1">
                        <span className="block text-sm font-semibold text-ink-900">{item.title}</span>
                        <span className="mt-0.5 block text-xs text-firol-700">použité {count}×</span>
                      </span>
                    </button>
                  </li>
                ))}
              </ul>
              <h4 className="mt-4 text-xs font-semibold uppercase tracking-wide text-ink-500">
                Alebo hľadajte
              </h4>
            </div>
          )}

          <label htmlFor={searchId} className="sr-only">
            Hľadať v číselníku nedostatkov
          </label>
          <Input
            id={searchId}
            type="search"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Hľadať — „dvere“, „garáž“ aj „478“"
            autoComplete="off"
            leftIcon={<Search className="size-4" />}
          />
          <p className="mb-2 mt-1.5 text-xs text-ink-400">
            Píšte a zoznam sa zúži. {itemsLabel(visible.length)} v číselníku.
          </p>

          {loading ? (
            <div className="flex flex-col gap-2" aria-busy>
              {[0, 1, 2, 3].map((i) => (
                <Skeleton key={i} className="h-14 w-full rounded-2xl" />
              ))}
            </div>
          ) : error && items.length === 0 ? (
            <p className="rounded-2xl border border-dashed border-ink-200 px-4 py-6 text-center text-sm text-ink-500">
              Číselník sa nepodarilo načítať — vyžaduje pripojenie. Po prvom načítaní je dostupný aj bez siete.
            </p>
          ) : (
            <ul className="flex flex-col gap-2">
              {filtered.map((item) => (
                <li key={item.id} className="animate-fade-in flex items-stretch gap-1.5">
                  <button
                    type="button"
                    onClick={() => onPick(item)}
                    className={cn(
                      'group flex min-w-0 flex-1 items-start gap-3 rounded-2xl border border-ink-200 bg-white px-4 py-3 text-left',
                      'transition-[border-color,background-color,transform,box-shadow] duration-200',
                      'hover:border-firol-300 hover:bg-firol-50/60 hover:shadow-[var(--shadow-soft)]',
                      'active:scale-[0.99] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300',
                    )}
                  >
                    <span
                      aria-hidden
                      className="mt-0.5 size-5 shrink-0 rounded-full border-2 border-ink-300 transition-colors duration-200 group-hover:border-firol-400"
                    />
                    <span className="min-w-0 flex-1">
                      <span className="block text-sm font-semibold text-ink-900">{item.title}</span>
                      <span className="mt-0.5 block text-xs text-ink-500">
                        {item.area} · {SCOPE_LABEL[item.scope]}
                      </span>
                    </span>
                  </button>
                  {item.own && (
                    <>
                      <button
                        type="button"
                        title="Upraviť v číselníku"
                        aria-label={`Upraviť v číselníku: ${item.title}`}
                        onClick={() => setSheet({ mode: 'edit', item })}
                        className="grid w-10 shrink-0 place-items-center rounded-2xl border border-ink-200 bg-white text-status-warn transition-[background-color,transform] duration-200 hover:bg-status-warn-bg active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300"
                      >
                        <Pencil className="size-4" />
                      </button>
                      <button
                        type="button"
                        title="Odstrániť z číselníka"
                        aria-label={`Odstrániť z číselníka: ${item.title}`}
                        onClick={() => void remove(item)}
                        className="grid w-10 shrink-0 place-items-center rounded-2xl border border-status-bad/30 bg-white text-status-bad transition-[background-color,transform] duration-200 hover:bg-status-bad-bg active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300"
                      >
                        <Trash2 className="size-4" />
                      </button>
                    </>
                  )}
                </li>
              ))}
            </ul>
          )}

          {!loading && filtered.length === 0 && !(error && items.length === 0) && (
            <p className="animate-fade-in mt-2 text-sm text-ink-500">
              Nič sa nenašlo — zapíšte ho ako vlastný.
            </p>
          )}

          <Button
            type="button"
            variant="secondary"
            className="mt-3 border-dashed"
            leftIcon={<Plus className="size-4" />}
            onClick={() => setSheet({ mode: 'create' })}
          >
            Vlastný nedostatok
          </Button>
          <p className="mt-2 text-xs text-ink-400">
            Vlastný nedostatok ostane vo vašom číselníku <b className="font-semibold text-ink-500">pri všetkých klientoch</b>,
            nielen pri tejto. Dá sa upraviť aj odstrániť — odstránenie ho zmaže všade.
          </p>
        </div>
      )}

      <OwnItemSheet
        state={sheet}
        defaultScope={newItemScope ?? scopeFor(scope)}
        onClose={() => setSheet(null)}
        onCreated={(item) => {
          setSheet(null);
          setQuery('');
          toast.success('Pridané do číselníka.');
          onPick(item);
        }}
        onUpdated={(item) => {
          setSheet(null);
          toast.success('Upravené v číselníku.');
          onItemUpdated?.(item);
        }}
      />
    </section>
  );
}

// ─── „+ Vlastný nedostatok" / ✎ sheet ─────────────────────────────────────────

type SheetState = { mode: 'create' } | { mode: 'edit'; item: DefectCatalogItem } | null;

const TEXTAREA_CLASS =
  'w-full rounded-xl border border-ink-200 bg-white px-3 py-2.5 text-sm text-ink-800 placeholder:text-ink-400 ' +
  'transition-colors duration-150 hover:border-ink-300 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200';

function OwnItemSheet({
  state,
  defaultScope,
  onClose,
  onCreated,
  onUpdated,
}: {
  state: SheetState;
  defaultScope: DefectScope;
  onClose: () => void;
  onCreated: (item: DefectCatalogItem) => void;
  onUpdated: (item: DefectCatalogItem) => void;
}) {
  // Re-mounted per opening, so the fields always start from the right row.
  const key = state === null ? 'closed' : state.mode === 'edit' ? `edit-${state.item.id}` : 'create';
  return (
    <Dialog
      open={state !== null}
      onClose={onClose}
      title={state?.mode === 'edit' ? 'Upraviť vlastný nedostatok' : 'Vlastný nedostatok'}
      description={
        state?.mode === 'edit'
          ? 'Zmena sa prejaví vo vašom číselníku pri všetkých klientoch.'
          : 'Uloží sa aj do vášho číselníka na ďalšie prehliadky.'
      }
    >
      {state && (
        <OwnItemForm
          key={key}
          item={state.mode === 'edit' ? state.item : null}
          defaultScope={defaultScope}
          onCancel={onClose}
          onCreated={onCreated}
          onUpdated={onUpdated}
        />
      )}
    </Dialog>
  );
}

function OwnItemForm({
  item,
  defaultScope,
  onCancel,
  onCreated,
  onUpdated,
}: {
  item: DefectCatalogItem | null;
  defaultScope: DefectScope;
  onCancel: () => void;
  onCreated: (item: DefectCatalogItem) => void;
  onUpdated: (item: DefectCatalogItem) => void;
}) {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const [title, setTitle] = useState(item?.title ?? '');
  const [description, setDescription] = useState(item?.description ?? '');
  const [regulation, setRegulation] = useState(item?.regulation ?? '');
  const [justification, setJustification] = useState(item?.justification ?? '');
  const [measure, setMeasure] = useState(item?.measure ?? '');
  const [titleError, setTitleError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!title.trim()) {
      setTitleError('Zadajte názov.');
      return;
    }
    setSaving(true);
    try {
      if (item) {
        // An emptied popis / opatrenie keeps the old text (prototype `upravCis`).
        const patch: Partial<DefectCatalogPayload> = {
          title: title.trim(),
          description: description.trim() || item.description,
          regulation: regulation.trim(),
          justification: justification.trim(),
          measure: measure.trim() || item.measure,
        };
        await DefectCatalog.update(item, patch, csrfToken).catch((err: unknown) => {
          if (!(err instanceof OfflineQueuedError)) throw err;
        });
        onUpdated({ ...item, ...patch });
      } else {
        const body: DefectCatalogPayload = {
          title: title.trim(),
          scope: defaultScope,
          description: description.trim() || EMPTY_TEXT,
          regulation: regulation.trim(),
          justification: justification.trim(),
          measure: measure.trim() || EMPTY_TEXT,
        };
        let createdItem: DefectCatalogItem;
        try {
          const res = await DefectCatalog.create(body, csrfToken);
          createdItem = res.item;
        } catch (err) {
          if (err instanceof OfflineQueuedError) {
            createdItem = {
              id: mintTempId(),
              ...body,
              area: 'Vlastné',
              default_deadline: 'do 30 dní',
              own: true,
            };
          } else {
            throw err;
          }
        }
        onCreated(createdItem);
      }
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Uloženie sa nepodarilo.');
      setSaving(false);
    }
  }

  return (
    <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
      <Field label="Názov nedostatku" required error={titleError}>
        {(p) => (
          <Input
            {...p}
            value={title}
            maxLength={255}
            autoFocus
            placeholder="Chýbajúce označenie hlavného uzáveru vody"
            onChange={(e) => {
              setTitle(e.target.value);
              setTitleError(null);
            }}
          />
        )}
      </Field>
      <Field label="Popis zisteného stavu">
        {(p) => (
          <textarea
            {...p}
            rows={3}
            value={description}
            placeholder="Čo ste videli."
            onChange={(e) => setDescription(e.target.value)}
            className={TEXTAREA_CLASS}
          />
        )}
      </Field>
      <Field label="Podľa predpisu">
        {(p) => (
          <Input
            {...p}
            value={regulation}
            maxLength={500}
            placeholder="§ 5 písm. b) zákona č. 314/2001 Z. z."
            onChange={(e) => setRegulation(e.target.value)}
          />
        )}
      </Field>
      <Field label="Odôvodnenie — jedna veta">
        {(p) => (
          <textarea
            {...p}
            rows={2}
            value={justification}
            placeholder="Prečo je to nedostatok."
            onChange={(e) => setJustification(e.target.value)}
            className={TEXTAREA_CLASS}
          />
        )}
      </Field>
      <Field
        label="Navrhované opatrenie"
        hint={item ? undefined : 'Predvolená lehota je „do 30 dní“ — pri zápise ju môžete zmeniť.'}
      >
        {(p) => (
          <textarea
            {...p}
            rows={2}
            value={measure}
            placeholder="Čo sa má urobiť."
            onChange={(e) => setMeasure(e.target.value)}
            className={TEXTAREA_CLASS}
          />
        )}
      </Field>
      <div className="flex justify-end gap-2">
        <Button type="button" variant="ghost" size="sm" onClick={onCancel} disabled={saving}>
          Zrušiť
        </Button>
        <Button type="submit" size="sm" loading={saving}>
          {item ? 'Uložiť' : 'Pridať do číselníka'}
        </Button>
      </div>
    </form>
  );
}
