import { useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { ArrowLeft, ChevronRight, History, Plus, Search, Trash2 } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useDefectCatalog, type DefectCatalogItem } from '@/api/defectCatalog';
import { Inspections, photoSrc, type InspectionItem, type InspectionPhoto } from '@/api/inspections';
import type { Facility } from '@/api/facilities';
import { ApiError, OfflineQueuedError } from '@/lib/api';
import { writeCache } from '@/lib/cache';
import { formatDateSk } from '@/lib/clientNoticeEmail';
import { isBytoveDruzstvo } from '@/lib/clientKind';
import { cn } from '@/lib/cn';
import { Badge } from '@/components/ui/Badge';
import { useConfirm } from '@/lib/confirm';
import { fold } from '@/lib/text';
import { useToast } from '@/lib/toast';
import {
  DEADLINE_CHOICES,
  DEFAULT_PLACES,
  DEFECT_STATUS_OPTIONS,
  DEVICE_GROUP_LABELS,
  DEVICE_SEARCH_THRESHOLD,
  DEVICE_TYPES,
  ROUTINE_LABELS,
  carriedScope,
  choiceFromCatalog,
  composeWhere,
  deadlineFor,
  defaultDefectScope,
  deviceKeyOf,
  deviceMatches,
  deviceToRef,
  floorTiles,
  newTmpId,
  offeredDefectScopes,
  parseDefect,
  statusOfAnswer,
  withoutDuplicates,
  type DeadlineChoice,
  type DefectFields,
  type DefectScope,
  type DefectStatus,
  type DeviceOption,
  type DeviceType,
  vchodyPre,
  type NewDevice,
  type PersistsFrom,
  type PrehliadkaScope,
  type PreviousAnswer,
  type PreviousDefect,
  type PreviousSource,
} from '@/lib/prehliadka';
import { Breadcrumb, type Crumb } from '@/components/ui/Breadcrumb';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { ItemPhotoField, usePhotoStaging } from '@/components/ItemPhotos';
import { PhotoLightbox, usePhotoLightbox } from '@/components/PhotoLightbox';
import { DefectPicker } from '@/components/DefectPicker';
import { Chip, CheckRow, Segmented, TEXTAREA_CLASS } from '@/components/prehliadka/ui';
import { saveItemMessage, saveItemWithPhotos } from '@/inspection-types/saveItem';

/**
 * Who mounts the defect screen and what it offers (spec 8, 12.1). The
 * preventívna prehliadka is the first host (PrehliadkaDefectPage); the
 * požiarna kniha is meant to be the second, with the same form.
 */
export type DefectFormHost = {
  /** The inspection the defect is saved into, as one of its items. */
  inspectionId: number;
  /** Where „Späť", a finished save and a delete lead. */
  backPath: string;
  /** The plain back link's text when there is no breadcrumb trail. */
  backLabel: string;
  /** Breadcrumb trail without the last crumb; null shows the plain back link. */
  trail: Crumb[] | null;
  /** The date computed deadlines count from. */
  executedOn: string | null;
  /**
   * The prevádzky the defect may belong to; the first is the default. One —
   * no chooser and no `facility_id` (the prehliadka). Several — a chooser, the
   * choice is stored as `fields.facility_id` and its tiles are offered.
   */
  facilities: Facility[];
  /** Records a defect can go into; null — the switch does not apply and the scope stays `po`. */
  scope: PrehliadkaScope | null;
  /** The „Čoho sa to týka" device card; null — it does not apply. */
  devices: DefectDevicesHost | null;
  /** `optional` — no deadline is a valid answer and stays empty (the book). */
  deadline: 'required' | 'optional';
  /** The zodpovedný a new defect starts with. */
  responsibleDefault: string;
  /** The source of the previous defects, to name an edited carried-over row written before its number was stored. */
  previousSource: PreviousSource | null;
  /** A prevádzka learned an entrance or a place — the host keeps its copy current. */
  onFacilityChange: (f: Facility) => void;
};

export type DefectDevicesHost = {
  /** Device types whose list is on offer (the riadne kontroly of the prehliadka). */
  routine: DeviceType[];
  /** Every device the host knows, of any type — also resolves the references of an edited defect. */
  all: DeviceOption[];
  /** Writes a device that is not on the list yet; returns it as an option, or null when that failed. */
  onAddNew: (d: Omit<NewDevice, 'tmp_id'>) => Promise<DeviceOption | null>;
};

/** A defect of the last prehliadka the screen is opened for, not yet carried over. */
export type DefectFromPrevious = {
  defect: PreviousDefect;
  source: PreviousSource;
  /** The answer a draft begun before spec 12.1 already gave it, if any. */
  answer: PreviousAnswer | null;
};

/** What the form edits; becomes the defect's `fields` on save. */
export type DefectFormState = {
  catalogId: number | null;
  title: string;
  scope: DefectScope;
  description: string;
  regulation: string;
  justification: string;
  measure: string;
  entrance: string;
  floor: string;
  place: string;
  detail: string;
  /** Selected devices by key; holds the snapshot, so one filtered out of view stays selected. */
  selected: Record<string, DeviceOption>;
  deviceManual: string;
  /** Null only for an optional deadline left empty. */
  choice: DeadlineChoice | null;
  customDate: string;
  responsible: string;
  groupKey: string | null;
  /** „Stav nedostatku"; null until the technician picks one for a carried-over defect. */
  status: DefectStatus | null;
  reason: string;
  facilityId: number;
  /** Set for a defect taken over from the last prehliadka, kept as it is on edit. */
  persistsFrom: PersistsFrom | null;
  /** Photos of the old record shown while the defect is not carried over yet (the server copies them). */
  carriedPhotos: InspectionPhoto[];
};

/**
 * Zápis nedostatku (spec 6.3, 12.1) — one screen for a new defect and for one
 * taken over from the last prehliadka, in the order of what cannot be filled
 * in at home: Stav → Fotky → Čo to je → Text do záznamu → Do ktorého záznamu →
 * Kde → Na čom → Dokedy. A note on top says which of the two it is.
 *
 * `item` edits a saved row; `from` opens a previous defect (prefilled, no state
 * chosen); neither writes a new one. „Uložiť a ďalšie zariadenie" keeps the
 * catalogue item, the texts, the deadline and the place and changes only the
 * piece of equipment (`seed` carries them into the next form).
 */
export function DefectForm({
  host,
  item,
  from,
  seed,
  onNext,
}: {
  host: DefectFormHost;
  item: InspectionItem | null;
  from: DefectFromPrevious | null;
  seed: DefectFormState | null;
  onNext: (seed: DefectFormState) => void;
}) {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const navigate = useNavigate();
  const { items: catalogItems } = useDefectCatalog();

  const { inspectionId, executedOn, backPath } = host;
  const pickerScope: PrehliadkaScope = host.scope ?? { po: true, bozp: false };

  const [state, setState] = useState<DefectFormState>(() => initialState(item, seed, from, host));
  const [textOpen, setTextOpen] = useState(false);
  const [errors, setErrors] = useState<{
    status?: string;
    reason?: string;
    title?: string;
    where?: string;
    description?: string;
    date?: string;
  }>({});
  const [saving, setSaving] = useState<'save' | 'next' | null>(null);
  const [deleting, setDeleting] = useState(false);
  const photos = usePhotoStaging(item?.photos);

  const patch = (p: Partial<DefectFormState>) => setState((s) => ({ ...s, ...p }));

  const facility = host.facilities.find((f) => f.id === state.facilityId) ?? host.facilities[0];
  const bd = isBytoveDruzstvo(facility.company_client_type);

  // ── Stav
  const closedCarry =
    state.persistsFrom !== null && (state.status === 'odstraneny' || state.status === 'zanik');

  // ── Čo to je
  const catalogKnown = state.catalogId !== null && catalogItems.some((c) => c.id === state.catalogId);

  function applyCatalog(c: DefectCatalogItem) {
    patch({
      catalogId: c.id,
      title: c.title,
      scope: constrainScope(c.scope, host.scope),
      description: c.description,
      regulation: c.regulation,
      justification: c.justification,
      measure: c.measure,
      choice: choiceFromCatalog(c.default_deadline),
      customDate: '',
    });
    setErrors((e) => ({ ...e, title: undefined, description: undefined }));
  }

  // ── Kde
  const entranceTiles = bd
    ? withoutDuplicates([...vchodyPre(facility.vchody, facility.entrances), ...(state.entrance ? [state.entrance] : [])])
    : [];
  const placeTiles = withoutDuplicates([
    ...(bd ? DEFAULT_PLACES : []),
    ...(facility.places ?? []),
    ...(state.place ? [state.place] : []),
  ]);
  const floors = bd && facility.floors_above != null ? floorTiles(facility.floors_below, facility.floors_above) : [];
  const whereText = composeWhere({
    entrance: bd ? state.entrance : '',
    floor: bd ? state.floor : '',
    place: state.place,
    detail: state.detail,
  });

  const [openEntrance, setOpenEntrance] = useState(!state.entrance);
  const [openFloor, setOpenFloor] = useState(!state.floor);
  const [openPlace, setOpenPlace] = useState(!state.place);

  // ── Na čom
  const devicesHost = host.devices;
  const options = useMemo<DeviceOption[]>(
    () => (devicesHost ? devicesHost.all.filter((o) => devicesHost.routine.includes(o.type)) : []),
    [devicesHost],
  );

  async function addNewDevice(nd: Omit<NewDevice, 'tmp_id'>): Promise<boolean> {
    if (!devicesHost) return false;
    const opt = await devicesHost.onAddNew(nd);
    if (!opt) return false;
    setState((s) => ({ ...s, selected: { ...s.selected, [opt.key]: opt } }));
    toast.success('Zapísané. Zariadenie je v koncepte kontroly.');
    return true;
  }

  // ── Dokedy
  const optionalDeadline = host.deadline === 'optional';
  const computedDeadline =
    state.choice === null || state.choice === 'custom' ? null : deadlineFor(state.choice, executedOn);
  const deadlineDate = state.choice === 'custom' ? state.customDate : computedDeadline;

  const scopes = host.scope ? offeredDefectScopes(host.scope) : [];
  const selectedCount = Object.keys(state.selected).length;
  const canChain = item === null && selectedCount === 1;

  function validate(): boolean {
    const next: typeof errors = {};
    if (state.status === null) next.status = 'Zvoľ stav nedostatku.';
    if (state.status === 'zanik' && state.reason.trim() === '')
      next.reason = 'Napíš jednu vetu, prečo dôvod zanikol.';
    if (state.title.trim() === '') next.title = 'Vyber, o aký nedostatok ide.';
    if (whereText === '' && !closedCarry)
      next.where = bd
        ? 'Ťukni aspoň na jedno — vchod, podlažie alebo miesto — alebo ho dopíš vlastnými slovami.'
        : 'Ťukni na miesto alebo ho dopíš vlastnými slovami.';
    if (state.description.trim() === '') next.description = 'Doplň popis zisteného stavu.';
    if (state.choice === 'custom' && !state.customDate) next.date = 'Zadaj vlastný dátum odstránenia.';
    setErrors(next);
    if (Object.keys(next).length > 0) {
      toast.error(Object.values(next)[0] as string);
      if (next.status) document.getElementById('pp-status')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return false;
    }
    return true;
  }

  function toFields(groupKey: string | null): DefectFields {
    const w = {
      entrance: bd ? state.entrance.trim() || null : null,
      floor: bd ? state.floor.trim() || null : null,
      place: state.place.trim() || null,
      detail: state.detail.trim() || null,
    };
    return {
      catalog_id: state.catalogId,
      title: state.title.trim(),
      scope: state.scope,
      where: { ...w, text: composeWhere(w) },
      devices: Object.values(state.selected).map(deviceToRef),
      device_manual: state.deviceManual.trim() || null,
      description: state.description.trim(),
      regulation: state.regulation.trim(),
      justification: state.justification.trim(),
      measure: state.measure.trim(),
      deadline_choice: state.choice,
      deadline_date: state.choice === null || state.choice === 'bezodkladne' ? null : deadlineDate,
      responsible: state.responsible.trim() || null,
      group_key: groupKey,
      status: state.status ?? 'zisteny',
      reason: state.status === 'zanik' ? state.reason.trim() : null,
      persists_from: state.persistsFrom,
      ...(host.facilities.length > 1 ? { facility_id: state.facilityId } : {}),
    };
  }

  /** The prevádzka's tiles learn the entrance / place at once, not only after the server round trip. */
  async function rememberLocally(fields: DefectFields) {
    const entrance = fields.where.entrance;
    const place = fields.where.place;
    const nextFacility: Facility = {
      ...facility,
      entrances: entrance && bd ? withoutDuplicates([...(facility.entrances ?? []), entrance]) : facility.entrances,
      places: place ? withoutDuplicates([...(facility.places ?? []), place]) : facility.places,
    };
    if (nextFacility.entrances === facility.entrances && nextFacility.places === facility.places) return;
    host.onFacilityChange(nextFacility);
    await writeCache(`/api/facilities/${facility.id}`, { facility: nextFacility }).catch(() => undefined);
  }

  async function save(chain: boolean) {
    if (!validate()) return;
    setSaving(chain ? 'next' : 'save');
    try {
      // Rows saved with „Uložiť a ďalšie zariadenie" share a group_key — one point of the record.
      const groupKey = chain ? (state.groupKey ?? newTmpId('g')) : state.groupKey;
      const fields = toFields(groupKey);
      const result = await saveItemWithPhotos({
        inspectionId,
        itemId: item?.id ?? null,
        fields,
        csrfToken,
        photos,
      });
      await rememberLocally(fields);
      if (chain) {
        toast.success('Zapísané. Vyber ďalšie zariadenie.');
        // The prototype's reset: the device, the entrance and the free text go, the photos too (new form);
        // the floor and the place stay.
        // The next piece of equipment is a defect of its own — it is no longer the carried-over one.
        onNext({
          ...state,
          groupKey,
          selected: {},
          deviceManual: '',
          entrance: '',
          detail: '',
          persistsFrom: null,
          carriedPhotos: [],
          status: state.persistsFrom ? 'zisteny' : state.status,
        });
        window.scrollTo({ top: 0, behavior: 'smooth' });
        return;
      }
      toast.success(saveItemMessage(result, item ? 'Zmeny uložené' : 'Nedostatok zapísaný'));
      navigate(backPath, { replace: true });
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : 'Uloženie sa nepodarilo.');
    } finally {
      setSaving(null);
    }
  }

  async function remove() {
    if (!item) return;
    const ok = await confirm({
      title: 'Zmazať nedostatok?',
      description: 'Zo záznamu sa odstráni aj s fotkami.',
      confirmLabel: 'Zmazať',
    });
    if (!ok) return;
    setDeleting(true);
    try {
      await Inspections.deleteItem(inspectionId, item.id, csrfToken);
      toast.success('Zmazané.');
      navigate(backPath, { replace: true });
    } catch (err) {
      if (err instanceof OfflineQueuedError) {
        toast.success('Uloží sa keď budeš online');
        navigate(backPath, { replace: true });
        return;
      }
      toast.error(err instanceof ApiError ? err.message : 'Zmazanie sa nepodarilo.');
    } finally {
      setDeleting(false);
    }
  }

  const textSummary =
    state.description.length > 95 ? `${state.description.slice(0, 95)}…` : state.description;
  const heading = item ? 'Upraviť nedostatok' : state.persistsFrom ? 'Nedostatok' : 'Nový nedostatok';

  return (
    <div className="flex flex-col gap-4">
      {host.trail !== null ? (
        <Breadcrumb items={[...host.trail, { label: heading }]} />
      ) : (
        <Link to={backPath} className="inline-flex items-center gap-1 self-start text-sm text-ink-500 hover:text-ink-700">
          <ArrowLeft className="size-4" />
          {host.backLabel}
        </Link>
      )}

      <header className="animate-fade-up">
        <h1 className="text-xl font-semibold tracking-tight text-ink-900">{heading}</h1>
        <p
          id="pp-origin"
          className={cn(
            'mt-2 flex items-center gap-1.5 rounded-xl px-3 py-2 text-xs font-medium',
            state.persistsFrom ? 'bg-ink-100 text-ink-700' : 'bg-firol-50 text-firol-700',
          )}
        >
          {state.persistsFrom && <History className="size-3.5 shrink-0" />}
          {originNote(state.persistsFrom, host.previousSource)}
        </p>
        {state.groupKey && item === null && (
          <p className="mt-2 rounded-xl bg-firol-50 px-3 py-2 text-xs text-firol-700">
            Ďalšie zariadenie k rovnakému nedostatku — v zázname pôjdu pod jeden bod, každé s vlastnou fotkou.
          </p>
        )}
      </header>

      {/* 0 · Stav nedostatku */}
      <Card id="pp-status" className={cn('flex flex-col gap-2.5 p-4 transition-shadow duration-300', errors.status && 'ring-2 ring-status-bad/40')}>
        <span className="text-xs font-semibold uppercase tracking-wide text-ink-500">Stav nedostatku</span>
        <div className="flex flex-wrap gap-2">
          {DEFECT_STATUS_OPTIONS.map((o) => (
            <Chip
              key={o.value}
              id={`pp-status-${o.value}`}
              active={state.status === o.value}
              onClick={() => {
                patch({ status: o.value });
                setErrors((e) => ({ ...e, status: undefined, reason: undefined, where: undefined }));
              }}
            >
              {o.label}
            </Chip>
          ))}
        </div>
        {errors.status && <p className="text-xs text-status-bad">{errors.status}</p>}
        {state.status === 'zanik' && (
          <Field label="Dôvod" required error={errors.reason}>
            {(p) => (
              <textarea
                {...p}
                rows={2}
                maxLength={500}
                value={state.reason}
                placeholder="Dvere boli vymenené za nové, pôvodné už v objekte nie sú."
                onChange={(e) => {
                  patch({ reason: e.target.value });
                  setErrors((er) => ({ ...er, reason: undefined }));
                }}
                className={cn(TEXTAREA_CLASS, 'animate-fade-in')}
              />
            )}
          </Field>
        )}
      </Card>

      {/* Prevádzka — only when the host offers several */}
      {host.facilities.length > 1 && (
        <Card className="flex flex-col gap-2.5 p-4">
          <span className="text-xs font-semibold uppercase tracking-wide text-ink-500">Prevádzka</span>
          <div className="flex flex-wrap gap-2">
            {host.facilities.map((f) => (
              <Chip key={f.id} active={state.facilityId === f.id} onClick={() => patch({ facilityId: f.id })}>
                {f.name}
              </Chip>
            ))}
          </div>
        </Card>
      )}

      {/* 1 · Fotky */}
      <Card className="flex flex-col gap-3 p-4">
        {state.carriedPhotos.length > 0 && <CarriedPhotos photos={state.carriedPhotos} />}
        <ItemPhotoField photos={photos} helpText="" />
      </Card>

      {/* 2 · Čo to je */}
      <Card className="p-4">
        {state.title !== '' && !catalogKnown && state.catalogId === null ? (
          <div className="flex items-start gap-3">
            <div className="min-w-0 flex-1">
              <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-500">Čo to je</h3>
              <p className="text-sm font-semibold text-ink-900">{state.title}</p>
              <p className="mt-0.5 text-xs text-ink-500">Nedostatok už nie je v číselníku — text v zázname ostáva.</p>
            </div>
            <Button type="button" variant="secondary" size="sm" onClick={() => patch({ title: '', catalogId: null })}>
              Zmeniť
            </Button>
          </div>
        ) : (
          <DefectPicker
            scope={pickerScope}
            selectedId={state.catalogId}
            onPick={applyCatalog}
            onClear={() => patch({ catalogId: null, title: '' })}
            onItemUpdated={(c) => {
              if (c.id === state.catalogId) applyCatalog(c);
            }}
            onItemRemoved={(id) => {
              if (id === state.catalogId) patch({ catalogId: null, title: '' });
            }}
          />
        )}
        {errors.title && <p className="mt-2 text-xs text-status-bad">{errors.title}</p>}
      </Card>

      {/* 3 · Text do záznamu */}
      {state.title !== '' && !textOpen && (
        <Card className="animate-fade-in flex items-start gap-3 p-4">
          <div className="min-w-0 flex-1">
            <h3 className="text-xs font-semibold uppercase tracking-wide text-ink-500">Text do záznamu</h3>
            <p className="mt-1.5 text-xs leading-relaxed text-ink-600">{textSummary || '—'}</p>
            {state.regulation && <p className="mt-1 text-[11px] text-ink-400">{state.regulation}</p>}
            {errors.description && <p className="mt-1 text-xs text-status-bad">{errors.description}</p>}
          </div>
          <Button type="button" id="pp-text-edit" variant="secondary" size="sm" onClick={() => setTextOpen(true)}>
            Upraviť
          </Button>
        </Card>
      )}
      {state.title !== '' && textOpen && (
        <Card className="animate-fade-in flex flex-col gap-3 p-4">
          <h3 className="text-xs font-semibold uppercase tracking-wide text-ink-500">Text do záznamu</h3>
          <Field label="Popis zisteného stavu" error={errors.description}>
            {(p) => (
              <textarea
                {...p}
                rows={3}
                value={state.description}
                onChange={(e) => patch({ description: e.target.value })}
                className={TEXTAREA_CLASS}
              />
            )}
          </Field>
          <Field label="Podľa predpisu">
            {(p) => (
              <Input
                {...p}
                value={state.regulation}
                maxLength={500}
                placeholder="§ 5 písm. b) zákona č. 314/2001 Z. z."
                onChange={(e) => patch({ regulation: e.target.value })}
              />
            )}
          </Field>
          <Field label="Odôvodnenie — jedna veta">
            {(p) => (
              <textarea
                {...p}
                rows={2}
                value={state.justification}
                onChange={(e) => patch({ justification: e.target.value })}
                className={TEXTAREA_CLASS}
              />
            )}
          </Field>
          <Field label="Navrhované opatrenie">
            {(p) => (
              <textarea
                {...p}
                rows={2}
                value={state.measure}
                onChange={(e) => patch({ measure: e.target.value })}
                className={TEXTAREA_CLASS}
              />
            )}
          </Field>
          <p className="text-xs text-ink-400">
            Predvyplnené z číselníka. Čokoľvek tu prepíšeš, ide presne tak do záznamu.
          </p>
          <Button type="button" variant="secondary" size="sm" className="self-start" onClick={() => setTextOpen(false)}>
            Hotovo
          </Button>
        </Card>
      )}

      {/* 4 · Do ktorého záznamu */}
      {host.scope && (
        <Card className="flex flex-col gap-2.5 p-4">
          <span className="text-xs font-semibold uppercase tracking-wide text-ink-500">Do ktorého záznamu patrí</span>
          <Segmented
            label="Do ktorého záznamu patrí"
            value={state.scope}
            options={scopes}
            onChange={(v) => patch({ scope: v })}
          />
        </Card>
      )}

      {/* 5 · Kde */}
      <Card className="flex flex-col gap-3 p-4">
        <span className="text-xs font-semibold uppercase tracking-wide text-ink-500">Kde</span>
        {errors.where && <p className="text-xs text-status-bad">{errors.where}</p>}

        {bd && (
          <CollapsibleKdeRow
            label="Vchod"
            value={state.entrance}
            isOpen={openEntrance}
            onToggle={() => setOpenEntrance((v) => !v)}
          >
            <div className="flex flex-wrap gap-2">
              {entranceTiles.map((e) => (
                <Chip
                  key={e}
                  active={state.entrance === e}
                  onClick={() => {
                    if (state.entrance === e) {
                      patch({ entrance: '' });
                    } else {
                      patch({ entrance: e });
                      setOpenEntrance(false);
                    }
                  }}
                >
                  {e}
                </Chip>
              ))}
              <AddTile
                label="+ iný"
                inputLabel="Označenie vchodu"
                placeholder="Vchod 3"
                onAdd={(v) => {
                  patch({ entrance: v });
                  setOpenEntrance(false);
                }}
              />
            </div>
            {entranceTiles.length === 0 && (
              <p className="mt-2 text-xs text-ink-400">
                Vchody si môžeš dopísať cez „+ iný“ a od tej chvíle sa ponúkajú samy.
              </p>
            )}
          </CollapsibleKdeRow>
        )}

        {bd && (
          <CollapsibleKdeRow
            label="Podlažie"
            value={state.floor}
            isOpen={openFloor}
            onToggle={() => setOpenFloor((v) => !v)}
          >
            <div className="flex flex-wrap gap-2">
              {floors.map((f) => (
                <Chip
                  key={f}
                  active={state.floor === f}
                  onClick={() => {
                    if (state.floor === f) {
                      patch({ floor: '' });
                    } else {
                      patch({ floor: f });
                      setOpenFloor(false);
                    }
                  }}
                >
                  {f}
                </Chip>
              ))}
              <AddTile
                label="+ iné"
                inputLabel="Iné podlažie"
                placeholder="napr. 7. NP"
                onAdd={(v) => {
                  patch({ floor: v });
                  setOpenFloor(false);
                }}
              />
            </div>
            {floors.length === 0 && (
              <p className="mt-2 text-xs text-ink-400">
                Počet podlaží doplníš v úprave bytového domu — alebo ho zadaj cez „+ iné“.
              </p>
            )}
          </CollapsibleKdeRow>
        )}

        <CollapsibleKdeRow
          label="Miesto"
          value={state.place}
          isOpen={openPlace}
          onToggle={() => setOpenPlace((v) => !v)}
        >
          <div className="flex flex-wrap gap-2">
            {placeTiles.map((m) => (
              <Chip
                key={m}
                active={state.place === m}
                onClick={() => {
                  if (state.place === m) {
                    patch({ place: '' });
                  } else {
                    patch({ place: m });
                    setOpenPlace(false);
                  }
                }}
              >
                {m}
              </Chip>
            ))}
            <AddTile
              label="+ iné"
              inputLabel="Iné miesto"
              placeholder="strojovňa výťahu"
              onAdd={(v) => {
                patch({ place: v });
                setOpenPlace(false);
              }}
            />
          </div>
        </CollapsibleKdeRow>

        <Field label="Upresniť miesto vlastnými slovami">
          {(p) => (
            <Input
              {...p}
              value={state.detail}
              maxLength={255}
              placeholder="státie č. 11 a 14"
              onChange={(e) => patch({ detail: e.target.value })}
            />
          )}
        </Field>
      </Card>

      {/* 6 · Na čom */}
      {devicesHost && (
        <DevicesCard
          routine={devicesHost.routine}
          options={options}
          selected={state.selected}
          manual={state.deviceManual}
          defaultLocation={whereText}
          onToggle={(o) =>
            setState((s) => {
              const next = { ...s.selected };
              if (next[o.key]) delete next[o.key];
              else next[o.key] = o;
              return { ...s, selected: next };
            })
          }
          onManual={(v) => patch({ deviceManual: v })}
          onAddNew={addNewDevice}
        />
      )}

      {/* 7 · Dokedy */}
      <Card className="flex flex-col gap-3 p-4">
        <span className="text-xs font-semibold uppercase tracking-wide text-ink-500">Termín odstránenia</span>
        <div className="flex flex-wrap gap-2">
          {DEADLINE_CHOICES.map((c) => (
            <Chip
              key={c.value}
              active={state.choice === c.value}
              // An optional deadline is cleared by tapping the chosen chip again.
              onClick={() => patch({ choice: optionalDeadline && state.choice === c.value ? null : c.value })}
            >
              {c.label}
            </Chip>
          ))}
        </div>
        {state.choice === 'custom' ? (
          <Field label="Dátum" error={errors.date}>
            {(p) => (
              <Input
                {...p}
                type="date"
                value={state.customDate}
                onChange={(e) => {
                  patch({ customDate: e.target.value });
                  setErrors((er) => ({ ...er, date: undefined }));
                }}
              />
            )}
          </Field>
        ) : state.choice !== null ? (
          <>
            <div className="rounded-xl border border-ink-200 bg-ink-50 px-3 py-2.5 text-sm text-ink-800">
              {state.choice === 'bezodkladne'
                ? 'bezodkladne'
                : computedDeadline
                  ? `do ${formatDateSk(computedDeadline)}`
                  : '—'}
            </div>
            {state.choice !== 'bezodkladne' && (
              <p className="text-xs text-ink-400">
                Do záznamu ide konkrétny dátum, nie lehota — počíta sa od dátumu prehliadky
                {executedOn ? ` (${formatDateSk(executedOn)})` : ''}.
              </p>
            )}
          </>
        ) : null}
        <Field label="Zodpovedný">
          {(p) => (
            <Input {...p} value={state.responsible} maxLength={191} onChange={(e) => patch({ responsible: e.target.value })} />
          )}
        </Field>
      </Card>

      <div className="flex flex-col gap-2.5">
        {canChain && (
          <Button
            type="button"
            id="pp-save-next"
            variant="secondary"
            loading={saving === 'next'}
            disabled={saving !== null}
            onClick={() => void save(true)}
          >
            Uložiť a ďalšie zariadenie
          </Button>
        )}
        <div className="flex gap-2.5">
          {item && (
            <Button
              type="button"
              id="pp-delete"
              variant="danger-outline"
              loading={deleting}
              disabled={saving !== null}
              leftIcon={<Trash2 className="size-4" />}
              onClick={() => void remove()}
            >
              Zmazať
            </Button>
          )}
          <Button
            type="button"
            id="pp-save"
            className="flex-1"
            loading={saving === 'save'}
            disabled={saving !== null || deleting}
            onClick={() => void save(false)}
          >
            {item ? 'Uložiť zmeny' : 'Uložiť nedostatok'}
          </Button>
        </div>
      </div>
    </div>
  );
}

// ─── Pôvod a fotky z minula ─────────────────────────────────────────────────

/** The note on top: which of the two cases this is (spec 12.1). */
function originNote(pf: PersistsFrom | null, source: PreviousSource | null): string {
  if (!pf) return 'Nový nedostatok — zistený dnes';
  const number = pf.number ?? (source && source.inspection_id === pf.inspection_id ? (source.number ?? null) : null);
  return [
    'Nedostatok z prehliadky',
    number ? `č. ${number}` : null,
    pf.executed_on ? `zo dňa ${formatDateSk(pf.executed_on)}` : null,
  ]
    .filter(Boolean)
    .join(' ');
}

/**
 * The photos of the old record, shown while the defect is being taken over.
 * They are not removable here — the server copies them onto the new row when
 * it is saved; new photos are added next to them below.
 */
function CarriedPhotos({ photos }: { photos: InspectionPhoto[] }) {
  const lightbox = usePhotoLightbox();
  const viewable = photos.map((p) => ({ src: photoSrc(p, 'full') }));
  return (
    <div className="flex flex-col gap-2">
      <span className="text-xs font-semibold uppercase tracking-wide text-ink-500">Fotky z minulej prehliadky</span>
      <ul className="grid grid-cols-3 gap-2 sm:grid-cols-4">
        {photos.map((p, i) => (
          <li key={p.id} className="group relative aspect-square overflow-hidden rounded-xl border border-ink-200 bg-ink-50">
            <button
              type="button"
              onClick={() => lightbox.openAt(viewable, i)}
              aria-label="Zväčšiť fotku"
              className="block size-full cursor-zoom-in"
            >
              <img
                src={photoSrc(p, 'thumb')}
                alt=""
                loading="lazy"
                className="size-full object-cover transition-transform duration-300 group-hover:scale-105"
              />
            </button>
          </li>
        ))}
      </ul>
      <PhotoLightbox state={lightbox.state} onClose={lightbox.close} onNext={lightbox.next} onPrev={lightbox.prev} />
    </div>
  );
}

// ─── Kde ────────────────────────────────────────────────────────────────────

function CollapsibleKdeRow({
  label,
  value,
  isOpen,
  onToggle,
  children,
}: {
  label: string;
  value: string;
  isOpen: boolean;
  onToggle: () => void;
  children: React.ReactNode;
}) {
  return (
    <div className="flex flex-col gap-1.5 border-t border-ink-100 pt-2.5">
      <button
        type="button"
        onClick={onToggle}
        aria-expanded={isOpen}
        aria-label={`${label}: ${value || 'nezvolené'}`}
        className="flex w-full items-center justify-between rounded-lg text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300"
      >
        <span className="text-xs font-semibold uppercase tracking-wide text-ink-500">
          {label}: <span className="font-normal text-ink-900">{value || 'nezvolené'}</span>
        </span>
        <ChevronRight
          className={cn('size-4 text-ink-400 transition-transform duration-200', isOpen && 'rotate-90')}
        />
      </button>
      {isOpen && <div className="mt-1">{children}</div>}
    </div>
  );
}

/** The dashed „+ iný" tile: opens a one-line field that adds and selects what is typed. */
function AddTile({
  label,
  inputLabel,
  placeholder,
  onAdd,
}: {
  label: string;
  inputLabel: string;
  placeholder: string;
  onAdd: (value: string) => void;
}) {
  const [open, setOpen] = useState(false);
  const [value, setValue] = useState('');

  function commit() {
    const v = value.trim();
    if (v === '') return;
    onAdd(v);
    setValue('');
    setOpen(false);
  }

  if (!open) {
    return (
      <Chip dashed onClick={() => setOpen(true)}>
        {label}
      </Chip>
    );
  }
  return (
    <div className="flex basis-full items-center gap-2">
      <Input
        autoFocus
        aria-label={inputLabel}
        value={value}
        maxLength={120}
        placeholder={placeholder}
        onChange={(e) => setValue(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === 'Enter') {
            e.preventDefault();
            commit();
          }
        }}
      />
      <Button type="button" size="sm" onClick={commit} disabled={value.trim() === ''}>
        Pridať
      </Button>
      <Button type="button" size="sm" variant="ghost" onClick={() => setOpen(false)}>
        Zrušiť
      </Button>
    </div>
  );
}

// ─── Čoho sa to týka ────────────────────────────────────────────────────────

function DevicesCard({
  routine,
  options,
  selected,
  manual,
  defaultLocation,
  onToggle,
  onManual,
  onAddNew,
}: {
  routine: DeviceType[];
  options: DeviceOption[];
  selected: Record<string, DeviceOption>;
  manual: string;
  defaultLocation: string;
  onToggle: (o: DeviceOption) => void;
  onManual: (v: string) => void;
  onAddNew: (d: Omit<NewDevice, 'tmp_id'>) => Promise<boolean>;
}) {
  const [cardOpen, setCardOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [newOpen, setNewOpen] = useState(false);
  const [groupOpen, setGroupOpen] = useState<Record<string, boolean>>({});

  const selectedList = Object.values(selected);
  const names = selectedList.map((o) => o.label || o.serial).filter(Boolean);
  const devicesSummary =
    names.length > 0
      ? names.slice(0, 3).join(', ') + (names.length > 3 ? ` +${names.length - 3}` : '')
      : '';
  const fullSummary = devicesSummary
    ? devicesSummary + (manual.trim() ? ` · ${manual.trim()}` : '')
    : manual.trim() || 'nič neoznačené — nemusí to byť zariadenie';

  const foldedQuery = fold(query).trim();
  const showSearch = options.length > DEVICE_SEARCH_THRESHOLD || query !== '';
  const visible = foldedQuery ? options.filter((o) => deviceMatches(o, foldedQuery, fold)) : options;
  const selectedCount = selectedList.length;

  const optionKeys = new Set(options.map((o) => o.key));
  const orphans = selectedList.filter((o) => !optionKeys.has(o.key));

  const byType = new Map<DeviceType, DeviceOption[]>();
  for (const o of visible) byType.set(o.type, [...(byType.get(o.type) ?? []), o]);

  if (!cardOpen) {
    return (
      <Card className="p-4 transition-all duration-200">
        <button
          type="button"
          onClick={() => setCardOpen(true)}
          className="flex w-full items-center justify-between text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300 rounded-lg"
        >
          <div className="flex flex-col gap-0.5">
            <span className="text-xs font-semibold uppercase tracking-wide text-ink-500">Čoho sa to týka</span>
            <span
              className={cn(
                'text-sm',
                selectedCount > 0 || manual.trim() ? 'font-semibold text-firol-700' : 'text-ink-400',
              )}
            >
              {fullSummary}
            </span>
          </div>
          <ChevronRight className="size-4 text-ink-400" />
        </button>
      </Card>
    );
  }

  return (
    <Card className="flex flex-col gap-3 p-4">
      <div className="flex items-center justify-between">
        <button
          type="button"
          onClick={() => {
            setCardOpen(false);
            setQuery('');
          }}
          className="flex w-full items-center justify-between text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300 rounded-lg"
        >
          <span className="text-xs font-semibold uppercase tracking-wide text-ink-500">Čoho sa to týka</span>
          <ChevronRight className="size-4 rotate-90 text-ink-400" />
        </button>
      </div>

      {routine.length === 0 ? (
        <p className="rounded-xl bg-ink-50 px-3 py-2.5 text-xs leading-relaxed text-ink-600">
          Register je prázdny — v tejto prehliadke nerobíte riadnu kontrolu.{' '}
          <b className="font-semibold">Nevadí, nedostatok nemusí byť na zariadení.</b> Napíšte vlastnými slovami,
          čoho sa týka.
        </p>
      ) : options.length === 0 ? (
        <div className="flex flex-col gap-2">
          <p className="rounded-xl bg-ink-50 px-3 py-2.5 text-xs leading-relaxed text-ink-600">
            Zoznam zariadení je zatiaľ prázdny. Prístroj zapíšte rovno teraz — odvtedy sa ponúka sám. Ak nejde o
            zariadenie, napíšte to vlastnými slovami.
          </p>
          <Button
            type="button"
            variant="secondary"
            size="sm"
            leftIcon={<Plus className="size-4" />}
            onClick={() => setNewOpen(true)}
          >
            + Zapísať zariadenie
          </Button>
        </div>
      ) : null}

      {options.length > 0 && showSearch && (
        <div className="flex flex-col gap-1.5">
          <Input
            type="search"
            aria-label="Hľadať zariadenie"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Hľadať — výrobné alebo evidenčné číslo, umiestnenie…"
            autoComplete="off"
            leftIcon={<Search className="size-4" />}
          />
          <p className="text-xs text-ink-400">
            {visible.length} z {options.length} zariadení
            {query !== '' && (
              <>
                {' · '}
                <button
                  type="button"
                  className="font-medium text-firol-700 hover:underline"
                  onClick={() => setQuery('')}
                >
                  zrušiť hľadanie
                </button>
              </>
            )}
          </p>
        </div>
      )}

      {options.length > 0 &&
        DEVICE_TYPES.filter((t) => byType.has(t)).map((t) => {
          const typeItems = byType.get(t)!;
          const selectedInType = typeItems.filter((o) => selected[o.key] !== undefined).length;
          const isOpen = Boolean(foldedQuery || selectedInType > 0 || groupOpen[t]);
          return (
            <div key={t} className="flex flex-col rounded-xl border border-ink-100 bg-white p-2.5">
              <button
                type="button"
                onClick={() => setGroupOpen((prev) => ({ ...prev, [t]: !isOpen }))}
                className="flex w-full items-center justify-between text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300 rounded-lg"
              >
                <div className="flex items-center gap-2">
                  <span className="text-xs font-semibold text-ink-700">{DEVICE_GROUP_LABELS[t]}</span>
                  <span className="text-xs text-ink-400">({typeItems.length})</span>
                  {selectedInType > 0 && (
                    <Badge tone="ok" className="text-[11px] font-medium">
                      {selectedInType} označené
                    </Badge>
                  )}
                </div>
                <ChevronRight
                  className={cn('size-4 text-ink-400 transition-transform duration-200', isOpen && 'rotate-90')}
                />
              </button>
              {isOpen && (
                <ul className="mt-2.5 flex flex-col gap-2 border-t border-ink-100 pt-2.5">
                  {typeItems.map((o) => (
                    <li key={o.key}>
                      <CheckRow
                        checked={selected[o.key] !== undefined}
                        onToggle={() => onToggle(o)}
                        title={[o.label, o.description].filter(Boolean).join(' · ')}
                        sub={
                          [o.location, o.serial && o.serial !== o.label ? `v. č. ${o.serial}` : null]
                            .filter(Boolean)
                            .join(' · ') || undefined
                        }
                      />
                    </li>
                  ))}
                </ul>
              )}
            </div>
          );
        })}

      {options.length > 0 && visible.length === 0 && (
        <p className="rounded-xl bg-ink-50 px-3 py-2.5 text-sm text-ink-500">
          Nič nesedí s hľadaním. Skús iné číslo, alebo zariadenie zapíš nižšie ako nové.
        </p>
      )}

      {orphans.length > 0 && (
        <div className="flex flex-col gap-2">
          <h4 className="text-xs font-semibold text-ink-500">Vybrané mimo zoznamu</h4>
          <ul className="flex flex-col gap-2">
            {orphans.map((o) => (
              <li key={o.key}>
                <CheckRow
                  checked
                  onToggle={() => onToggle(o)}
                  title={o.label || ROUTINE_LABELS[o.type]}
                  sub={o.location ?? undefined}
                />
              </li>
            ))}
          </ul>
        </div>
      )}

      {query !== '' && selectedCount > 0 && (
        <p className="rounded-xl bg-[var(--color-status-ok-bg)] px-3 py-2 text-xs text-[var(--color-status-ok)]">
          Označené ostávajú označené, aj keď ich hľadanie práve nezobrazuje — vybraných je {selectedCount}.
        </p>
      )}

      {routine.length > 0 && (
        <div className="flex flex-col items-start gap-2 pt-1">
          {newOpen ? (
            <NewDeviceForm
              routine={routine}
              defaultLocation={defaultLocation}
              onCancel={() => setNewOpen(false)}
              onSave={async (d) => {
                const ok = await onAddNew(d);
                if (ok) setNewOpen(false);
              }}
            />
          ) : (
            <Button
              type="button"
              id="pp-new-device"
              variant="secondary"
              size="sm"
              leftIcon={<Plus className="size-4" />}
              onClick={() => setNewOpen(true)}
            >
              + Zariadenie, ktoré tu ešte nie je
            </Button>
          )}
        </div>
      )}

      {/* Always available free-text field (spec 6.3.5 / 3.3) */}
      <div className="mt-1 border-t border-ink-100 pt-3">
        <Field label="Zariadenie alebo priestor napíšem ručne">
          {(p) => (
            <Input
              {...p}
              value={manual}
              maxLength={191}
              placeholder="únikový východ pri schodisku / PHP č. 25-08014"
              onChange={(e) => onManual(e.target.value)}
            />
          )}
        </Field>
      </div>

      <div className="flex justify-end pt-1">
        <Button
          type="button"
          size="sm"
          variant="secondary"
          onClick={() => {
            setCardOpen(false);
            setQuery('');
          }}
        >
          Hotovo
        </Button>
      </div>
    </Card>
  );
}

function NewDeviceForm({
  routine,
  defaultLocation,
  onCancel,
  onSave,
}: {
  routine: DeviceType[];
  defaultLocation: string;
  onCancel: () => void;
  onSave: (d: Omit<NewDevice, 'tmp_id'>) => Promise<void>;
}) {
  const [type, setType] = useState<DeviceType>(routine[0]);
  const [label, setLabel] = useState('');
  const [serial, setSerial] = useState('');
  const [description, setDescription] = useState('');
  const [location, setLocation] = useState(defaultLocation);
  const [labelError, setLabelError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function submit() {
    if (label.trim() === '') {
      setLabelError('Zadaj evidenčné číslo.');
      return;
    }
    setSaving(true);
    try {
      await onSave({
        type,
        label: label.trim(),
        serial: serial.trim() || null,
        description: description.trim() || null,
        location: location.trim() || null,
      });
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="flex w-full flex-col gap-3 rounded-2xl border border-firol-200 bg-firol-50/40 p-3.5">
      <p className="text-sm font-semibold text-ink-900">Nové zariadenie</p>
      <p className="-mt-2 text-xs text-ink-500">Pristane v koncepte kontroly a odvtedy sa bude ponúkať samo.</p>
      {routine.length > 1 && (
        <Field label="Typ">
          {(p) => (
            <Select
              id={p.id}
              value={type}
              onChange={(v) => setType(v as DeviceType)}
              options={routine.map((t) => ({ value: t, label: DEVICE_GROUP_LABELS[t] }))}
            />
          )}
        </Field>
      )}
      <Field label="Evidenčné číslo" required error={labelError}>
        {(p) => (
          <Input
            {...p}
            value={label}
            maxLength={80}
            placeholder="PHP-14"
            onChange={(e) => {
              setLabel(e.target.value);
              setLabelError(null);
            }}
          />
        )}
      </Field>
      <Field label="Výrobné číslo">
        {(p) => <Input {...p} value={serial} maxLength={80} placeholder="23-04471" onChange={(e) => setSerial(e.target.value)} />}
      </Field>
      <Field label="Popis">
        {(p) => (
          <Input {...p} value={description} maxLength={191} placeholder="práškový 6 kg" onChange={(e) => setDescription(e.target.value)} />
        )}
      </Field>
      <Field label="Umiestnenie">
        {(p) => <Input {...p} value={location} maxLength={191} onChange={(e) => setLocation(e.target.value)} />}
      </Field>
      <div className="flex justify-end gap-2">
        <Button type="button" variant="ghost" size="sm" onClick={onCancel} disabled={saving}>
          Zrušiť
        </Button>
        <Button type="button" size="sm" loading={saving} onClick={() => void submit()}>
          Zapísať zariadenie
        </Button>
      </div>
    </div>
  );
}

// ─── State ──────────────────────────────────────────────────────────────────

/** A BOZP-only prehliadka writes `bo`, a PO-only one `po`; only „obidve" keeps the catalogue's own scope. */
function constrainScope(scope: DefectScope, records: PrehliadkaScope | null): DefectScope {
  if (!records) return 'po';
  if (records.po && !records.bozp) return 'po';
  if (records.bozp && !records.po) return 'bo';
  return scope;
}

function initialState(
  item: InspectionItem | null,
  seed: DefectFormState | null,
  from: DefectFromPrevious | null,
  host: DefectFormHost,
): DefectFormState {
  if (seed) return seed;
  const facilityId = host.facilities[0]?.id ?? 0;
  const defect = item ? parseDefect(item.fields) : null;

  if (!defect && from) {
    // Taken over from the last prehliadka: everything as it was, all of it
    // editable; no state until the technician picks one (spec 12.1).
    const d = from.defect;
    const selected: Record<string, DeviceOption> = {};
    for (const dev of d.devices) {
      const key = deviceKeyOf(dev);
      selected[key] = {
        key,
        type: dev.type,
        label: dev.label,
        serial: dev.serial,
        description: null,
        location: dev.location,
      };
    }
    return {
      catalogId: d.catalog_id,
      title: d.title,
      scope: host.scope ? carriedScope(d.scope, host.scope) : 'po',
      description: d.description,
      regulation: d.regulation,
      justification: d.justification,
      measure: d.measure,
      entrance: d.where?.entrance ?? '',
      floor: d.where?.floor ?? '',
      place: d.where?.place ?? '',
      detail: d.where?.detail ?? '',
      selected,
      deviceManual: d.device_manual ?? '',
      // The deadline is chosen again, counted from this prehliadka's date.
      choice: host.deadline === 'required' ? '30d' : null,
      customDate: '',
      responsible: d.responsible ?? host.responsibleDefault,
      groupKey: null,
      status: from.answer?.status ? statusOfAnswer(from.answer.status) : null,
      reason: from.answer?.reason ?? '',
      facilityId,
      persistsFrom: {
        key: d.key,
        inspection_id: from.source.inspection_id,
        executed_on: from.source.executed_on,
        number: from.source.number ?? null,
        source_item_id: d.legacy ? null : d.source_item_id,
      },
      carriedPhotos: d.photos ?? [],
    };
  }

  if (!defect) {
    return {
      catalogId: null,
      title: '',
      scope: host.scope ? defaultDefectScope(host.scope) : 'po',
      description: '',
      regulation: '',
      justification: '',
      measure: '',
      entrance: '',
      floor: '',
      place: '',
      detail: '',
      selected: {},
      deviceManual: '',
      choice: host.deadline === 'required' ? '30d' : null,
      customDate: '',
      responsible: host.responsibleDefault,
      groupKey: null,
      status: 'zisteny',
      reason: '',
      facilityId,
      persistsFrom: null,
      carriedPhotos: [],
    };
  }

  // The device list as it is now, so an edited defect shows fresh labels; the snapshot covers the rest.
  const fresh = new Map<string, DeviceOption>((host.devices?.all ?? []).map((o) => [o.key, o]));
  const selected: Record<string, DeviceOption> = {};
  for (const d of defect.devices) {
    const key = deviceKeyOf(d);
    selected[key] = fresh.get(key) ?? {
      key,
      type: d.type,
      label: d.label,
      serial: d.serial,
      description: null,
      location: d.location,
      source_item_id: d.source_item_id,
      new_device_tmp_id: d.new_device_tmp_id,
    };
  }

  return {
    catalogId: defect.catalog_id,
    title: defect.title,
    scope: defect.scope,
    description: defect.description,
    regulation: defect.regulation,
    justification: defect.justification,
    measure: defect.measure,
    entrance: defect.where.entrance ?? '',
    floor: defect.where.floor ?? '',
    place: defect.where.place ?? '',
    detail: defect.where.detail ?? '',
    selected,
    deviceManual: defect.device_manual ?? '',
    choice: defect.deadline_choice,
    customDate: defect.deadline_choice === 'custom' ? (defect.deadline_date ?? '') : '',
    responsible: defect.responsible ?? '',
    groupKey: defect.group_key,
    status: defect.status,
    reason: defect.reason ?? '',
    facilityId: defect.facility_id ?? facilityId,
    persistsFrom: defect.persists_from,
    carriedPhotos: [],
  };
}
