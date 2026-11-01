import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Lock, Plus, Search, Trash2 } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { Companies, type Company } from '@/api/companies';
import { useDefectCatalog, type DefectCatalogItem } from '@/api/defectCatalog';
import { Facilities, type Facility, type FacilityDeviceGroup } from '@/api/facilities';
import { Inspections, type InspectionDetail, type InspectionItem } from '@/api/inspections';
import { ApiError, OfflineQueuedError } from '@/lib/api';
import { writeCache } from '@/lib/cache';
import { formatDateSk } from '@/lib/clientNoticeEmail';
import { isBytoveDruzstvo } from '@/lib/clientKind';
import { useConfirm } from '@/lib/confirm';
import { responsibleForFacility } from '@/lib/responsiblePerson';
import { fold } from '@/lib/text';
import { useToast } from '@/lib/toast';
import { visitTrail } from '@/lib/visits';
import {
  DEADLINE_CHOICES,
  DEFAULT_PLACES,
  DEVICE_GROUP_LABELS,
  DEVICE_SEARCH_THRESHOLD,
  DEVICE_TYPES,
  ROUTINE_LABELS,
  choiceFromCatalog,
  composeWhere,
  deadlineFor,
  defaultDefectScope,
  detailsPayload,
  deviceKeyOf,
  deviceMatches,
  deviceToRef,
  floorTiles,
  newTmpId,
  offeredDefectScopes,
  parseDefect,
  parseDetails,
  prehliadkaPath,
  withoutDuplicates,
  type DeadlineChoice,
  type DefectFields,
  type DefectScope,
  type DeviceOption,
  type DeviceType,
  type NewDevice,
  type PrehliadkaDetails,
} from '@/lib/prehliadka';
import { Breadcrumb } from '@/components/ui/Breadcrumb';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Skeleton } from '@/components/ui/Skeleton';
import { ItemPhotoField, usePhotoStaging } from '@/components/ItemPhotos';
import { DefectPicker } from '@/components/DefectPicker';
import { Chip, CheckRow, Segmented } from '@/components/prehliadka/ui';
import { saveItemMessage, saveItemWithPhotos } from '@/inspection-types/saveItem';

const TEXTAREA_CLASS =
  'w-full rounded-xl border border-ink-200 bg-white px-3 py-2.5 text-sm text-ink-800 placeholder:text-ink-400 ' +
  'transition-colors duration-150 hover:border-ink-300 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200';

/** What the form edits; becomes the defect's `fields` on save. */
type FormState = {
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
  choice: DeadlineChoice;
  customDate: string;
  responsible: string;
  groupKey: string | null;
};

type Loaded = {
  detail: InspectionDetail;
  facility: Facility;
  groups: FacilityDeviceGroup[];
  company: Company | null;
  responsibleDefault: string;
};

/**
 * Zápis nedostatku (spec 6.3) — one page, in the order of what cannot be
 * filled in at home: Fotky → Čo to je → Text do záznamu → Do ktorého záznamu →
 * Kde → Na čom → Dokedy.
 *
 * `/prehliadky/:id/nedostatok/new` writes one, `/prehliadky/:id/nedostatok/:itemId`
 * edits one. „Uložiť a ďalšie zariadenie" keeps the catalogue item, the texts,
 * the deadline and the place and changes only the piece of equipment.
 */
export function PrehliadkaDefectPage() {
  const { id: idStr, itemId: itemIdStr } = useParams<{ id: string; itemId?: string }>();
  const inspectionId = Number(idStr);
  const itemId = itemIdStr !== undefined ? Number(itemIdStr) : null;

  const [loaded, setLoaded] = useState<Loaded | null>(null);
  const [error, setError] = useState<string | null>(null);
  // Bumped by „Uložiť a ďalšie zariadenie": a fresh form (and fresh photos) over the kept values.
  const [formKey, setFormKey] = useState(0);
  const [seed, setSeed] = useState<FormState | null>(null);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const detail = await Inspections.show(inspectionId);
        const { facility } = await Facilities.show(detail.inspection.facility_id);
        const [groups, companyDetail, persons] = await Promise.all([
          Facilities.devices(facility.id).then((r) => r.groups).catch(() => [] as FacilityDeviceGroup[]),
          Companies.show(facility.company_id).catch(() => null),
          Companies.persons(facility.company_id).then((r) => r.items).catch(() => []),
        ]);
        if (cancelled) return;
        const approver = companyDetail?.company.approver ?? null;
        setLoaded({
          detail,
          facility,
          groups,
          company: companyDetail?.company ?? null,
          responsibleDefault:
            responsibleForFacility(facility.id, facility.contact_person, approver, persons)?.fullname ?? '',
        });
      } catch (err) {
        if (!cancelled) setError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať prehliadku.');
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [inspectionId]);

  if (error) {
    return (
      <div className="flex flex-col gap-4">
        <Link to={prehliadkaPath(inspectionId)} className="inline-flex items-center gap-1 self-start text-sm text-ink-500 hover:text-ink-700">
          <ArrowLeft className="size-4" />
          Späť
        </Link>
        <Card className="px-4 py-3 text-sm text-status-bad">{error}</Card>
      </div>
    );
  }
  if (!loaded) return <DefectSkeleton />;

  const { inspection: i } = loaded.detail;
  if (i.status !== 'draft') {
    return (
      <div className="flex flex-col gap-4">
        <Link to={prehliadkaPath(inspectionId)} className="inline-flex items-center gap-1 self-start text-sm text-ink-500 hover:text-ink-700">
          <ArrowLeft className="size-4" />
          Späť
        </Link>
        <Card className="flex flex-col items-center gap-3 px-6 py-10 text-center">
          <div className="grid size-12 place-items-center rounded-2xl bg-ink-100 text-ink-500">
            <Lock className="size-5" />
          </div>
          <h2 className="text-base font-semibold text-ink-900">Prehliadka je uzavretá</h2>
          <p className="max-w-sm text-sm text-ink-500">Nedostatky uzavretej prehliadky sa už nedajú meniť.</p>
        </Card>
      </div>
    );
  }

  const item = itemId !== null ? (loaded.detail.items.find((it) => it.id === itemId) ?? null) : null;
  if (itemId !== null && item === null) {
    return (
      <div className="flex flex-col gap-4">
        <Link to={prehliadkaPath(inspectionId)} className="inline-flex items-center gap-1 self-start text-sm text-ink-500 hover:text-ink-700">
          <ArrowLeft className="size-4" />
          Späť
        </Link>
        <Card className="px-4 py-3 text-sm text-status-bad">Nedostatok sa nenašiel — možno bol medzitým zmazaný.</Card>
      </div>
    );
  }

  return (
    <DefectForm
      key={`${itemId ?? 'new'}-${formKey}`}
      inspectionId={inspectionId}
      item={item}
      loaded={loaded}
      seed={seed}
      onFacilityChange={(facility) => setLoaded((cur) => (cur ? { ...cur, facility } : cur))}
      onDetailsChange={(details) =>
        setLoaded((cur) =>
          cur ? { ...cur, detail: { ...cur.detail, inspection: { ...cur.detail.inspection, details: detailsPayload(details) } } } : cur,
        )
      }
      onNext={(next) => {
        setSeed(next);
        setFormKey((k) => k + 1);
      }}
    />
  );
}

// ─── The form ───────────────────────────────────────────────────────────────

function DefectForm({
  inspectionId,
  item,
  loaded,
  seed,
  onFacilityChange,
  onDetailsChange,
  onNext,
}: {
  inspectionId: number;
  item: InspectionItem | null;
  loaded: Loaded;
  seed: FormState | null;
  onFacilityChange: (f: Facility) => void;
  onDetailsChange: (d: PrehliadkaDetails) => void;
  onNext: (seed: FormState) => void;
}) {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const navigate = useNavigate();
  const { items: catalogItems } = useDefectCatalog();

  const { facility, groups, detail } = loaded;
  const inspection = detail.inspection;
  const details = parseDetails(inspection.details);
  const executedOn = inspection.executed_on;
  const bd = isBytoveDruzstvo(facility.company_client_type);
  const walkPath = prehliadkaPath(inspectionId);

  const [state, setState] = useState<FormState>(() => initialState(item, seed, loaded, details));
  const [textOpen, setTextOpen] = useState(false);
  const [errors, setErrors] = useState<{ title?: string; where?: string; description?: string; date?: string }>({});
  const [saving, setSaving] = useState<'save' | 'next' | null>(null);
  const [deleting, setDeleting] = useState(false);
  const photos = usePhotoStaging(item?.photos);

  const patch = (p: Partial<FormState>) => setState((s) => ({ ...s, ...p }));

  // ── Čo to je
  const catalogKnown = state.catalogId !== null && catalogItems.some((c) => c.id === state.catalogId);

  function applyCatalog(c: DefectCatalogItem) {
    patch({
      catalogId: c.id,
      title: c.title,
      scope: constrainScope(c.scope, details),
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
  const entranceTiles = withoutDuplicates([...(facility.entrances ?? []), ...(state.entrance ? [state.entrance] : [])]);
  const placeTiles = withoutDuplicates([
    ...(bd ? DEFAULT_PLACES : []),
    ...(facility.places ?? []),
    ...(state.place ? [state.place] : []),
  ]);
  const floors = bd && facility.floors_above != null ? floorTiles(facility.floors_below, facility.floors_above) : [];
  const whereText = composeWhere({
    entrance: state.entrance,
    floor: state.floor,
    place: state.place,
    detail: state.detail,
  });

  // ── Na čom
  const options = useMemo<DeviceOption[]>(() => {
    const out: DeviceOption[] = [];
    for (const g of groups) {
      if (!details.routine.includes(g.type)) continue;
      for (const d of g.items) {
        out.push({
          key: `s${d.source_item_id}`,
          type: g.type,
          label: d.label,
          serial: d.serial,
          description: d.description,
          location: d.location,
          source_item_id: d.source_item_id,
        });
      }
    }
    for (const n of details.new_devices) {
      if (!details.routine.includes(n.type)) continue;
      out.push({
        key: `n${n.tmp_id}`,
        type: n.type,
        label: n.label,
        serial: n.serial,
        description: n.description,
        location: n.location,
        new_device_tmp_id: n.tmp_id,
      });
    }
    return out;
    // details is rebuilt on every render; its content is what the loaded inspection holds.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [groups, inspection.details]);

  async function addNewDevice(nd: Omit<NewDevice, 'tmp_id'>): Promise<boolean> {
    const device: NewDevice = { ...nd, tmp_id: newTmpId('nd') };
    const next: PrehliadkaDetails = { ...details, new_devices: [...details.new_devices, device] };
    try {
      await Inspections.update(inspectionId, { details: detailsPayload(next) }, csrfToken);
    } catch (err) {
      if (!(err instanceof OfflineQueuedError)) {
        toast.error(err instanceof ApiError ? err.message : 'Zariadenie sa nepodarilo zapísať.');
        return false;
      }
    }
    onDetailsChange(next);
    const opt: DeviceOption = {
      key: `n${device.tmp_id}`,
      type: device.type,
      label: device.label,
      serial: device.serial,
      description: device.description,
      location: device.location,
      new_device_tmp_id: device.tmp_id,
    };
    patch({ selected: { ...state.selected, [opt.key]: opt } });
    toast.success('Zapísané. Zariadenie je v koncepte kontroly.');
    return true;
  }

  // ── Dokedy
  const computedDeadline = state.choice === 'custom' ? null : deadlineFor(state.choice, executedOn);
  const deadlineDate = state.choice === 'custom' ? state.customDate : computedDeadline;

  const scopes = offeredDefectScopes(details.scope);
  const selectedCount = Object.keys(state.selected).length;
  const canChain = item === null && selectedCount === 1;

  function validate(): boolean {
    const next: typeof errors = {};
    if (state.title.trim() === '') next.title = 'Vyber, o aký nedostatok ide.';
    if (whereText === '') next.where = 'Ťukni aspoň na jedno — vchod, podlažie alebo miesto — alebo ho dopíš vlastnými slovami.';
    if (state.description.trim() === '') next.description = 'Doplň popis zisteného stavu.';
    if (state.choice === 'custom' && !state.customDate) next.date = 'Zadaj vlastný dátum odstránenia.';
    setErrors(next);
    if (Object.keys(next).length > 0) {
      toast.error(Object.values(next)[0] as string);
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
      deadline_date: state.choice === 'bezodkladne' ? null : deadlineDate,
      responsible: state.responsible.trim() || null,
      group_key: groupKey,
      persists_from: null,
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
    onFacilityChange(nextFacility);
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
        onNext({ ...state, groupKey, selected: {}, deviceManual: '', entrance: '', detail: '' });
        window.scrollTo({ top: 0, behavior: 'smooth' });
        return;
      }
      toast.success(saveItemMessage(result, item ? 'Zmeny uložené' : 'Nedostatok zapísaný'));
      navigate(walkPath, { replace: true });
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
      navigate(walkPath, { replace: true });
    } catch (err) {
      if (err instanceof OfflineQueuedError) {
        toast.success('Uloží sa keď budeš online');
        navigate(walkPath, { replace: true });
        return;
      }
      toast.error(err instanceof ApiError ? err.message : 'Zmazanie sa nepodarilo.');
    } finally {
      setDeleting(false);
    }
  }

  const textSummary =
    state.description.length > 95 ? `${state.description.slice(0, 95)}…` : state.description;

  return (
    <div className="flex flex-col gap-4">
      {inspection.visit_id !== null ? (
        <Breadcrumb
          items={[
            ...visitTrail({ id: inspection.visit_id, companyName: inspection.company_name, date: inspection.executed_on }),
            { label: 'Preventívna prehliadka', to: walkPath },
            { label: item ? 'Upraviť nedostatok' : 'Nový nedostatok' },
          ]}
        />
      ) : (
        <Link to={walkPath} className="inline-flex items-center gap-1 self-start text-sm text-ink-500 hover:text-ink-700">
          <ArrowLeft className="size-4" />
          Späť na obchôdzku
        </Link>
      )}

      <header className="animate-fade-up">
        <h1 className="text-xl font-semibold tracking-tight text-ink-900">
          {item ? 'Upraviť nedostatok' : 'Nový nedostatok'}
        </h1>
        <p className="mt-1 text-sm text-ink-500">Najprv fotka, potom čo to je.</p>
        {state.groupKey && item === null && (
          <p className="mt-2 rounded-xl bg-firol-50 px-3 py-2 text-xs text-firol-700">
            Ďalšie zariadenie k rovnakému nedostatku — v zázname pôjdu pod jeden bod, každé s vlastnou fotkou.
          </p>
        )}
      </header>

      {/* 1 · Fotky */}
      <Card className="p-4">
        <ItemPhotoField
          photos={photos}
          helpText="Dá sa aj preskočiť — niektoré nedostatky sa fotiť nedajú."
        />
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
            scope={details.scope}
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
      <Card className="flex flex-col gap-2.5 p-4">
        <span className="text-xs font-semibold uppercase tracking-wide text-ink-500">Do ktorého záznamu patrí</span>
        <Segmented
          label="Do ktorého záznamu patrí"
          value={state.scope}
          options={scopes}
          onChange={(v) => patch({ scope: v })}
        />
      </Card>

      {/* 5 · Kde */}
      <Card className="flex flex-col gap-3 p-4">
        <span className="text-xs font-semibold uppercase tracking-wide text-ink-500">Kde</span>
        <div
          className="min-h-11 rounded-xl border border-ink-200 bg-ink-50 px-3 py-2.5 text-sm text-ink-800"
          aria-live="polite"
        >
          {whereText || <span className="text-ink-400">Ťukni na vchod, podlažie a miesto — alebo ho dopíš.</span>}
        </div>
        {errors.where && <p className="text-xs text-status-bad">{errors.where}</p>}

        {bd && (
          <TileGroup label="Vchod">
            {entranceTiles.map((e) => (
              <Chip key={e} active={state.entrance === e} onClick={() => patch({ entrance: state.entrance === e ? '' : e })}>
                {e}
              </Chip>
            ))}
            <AddTile
              label="+ iný"
              inputLabel="Označenie vchodu"
              placeholder="Vchod 3"
              onAdd={(v) => patch({ entrance: v })}
            />
            {entranceTiles.length === 0 && (
              <p className="basis-full text-xs text-ink-400">
                Vchody sa nikde nenastavujú — napíš ho raz a odvtedy sa ponúka sám.
              </p>
            )}
          </TileGroup>
        )}

        {bd && (
          <TileGroup label="Podlažie">
            {floors.map((f) => (
              <Chip key={f} active={state.floor === f} onClick={() => patch({ floor: state.floor === f ? '' : f })}>
                {f}
              </Chip>
            ))}
            {floors.length === 0 && (
              <p className="basis-full text-xs text-ink-400">
                Počet podlaží doplníš v úprave bytového domu — dovtedy podlažie dopíš vlastnými slovami.
              </p>
            )}
          </TileGroup>
        )}

        <TileGroup label="Miesto">
          {placeTiles.map((m) => (
            <Chip key={m} active={state.place === m} onClick={() => patch({ place: state.place === m ? '' : m })}>
              {m}
            </Chip>
          ))}
          <AddTile
            label="+ iné"
            inputLabel="Iné miesto"
            placeholder="strojovňa výťahu"
            onAdd={(v) => patch({ place: v })}
          />
        </TileGroup>

        <Field
          label="Dopísať vlastnými slovami"
          hint="Dlaždice sú len skratka. Čokoľvek sa dá dopísať rukou — aj miesto, ktoré v ponuke nie je."
        >
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
      <DevicesCard
        routine={details.routine}
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

      {/* 7 · Dokedy */}
      <Card className="flex flex-col gap-3 p-4">
        <span className="text-xs font-semibold uppercase tracking-wide text-ink-500">Termín odstránenia</span>
        <div className="flex flex-wrap gap-2">
          {DEADLINE_CHOICES.map((c) => (
            <Chip
              key={c.value}
              active={state.choice === c.value}
              onClick={() => patch({ choice: c.value })}
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
        ) : (
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
        )}
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

// ─── Kde ────────────────────────────────────────────────────────────────────

function TileGroup({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-1.5">
      <span className="text-xs font-semibold text-ink-500">{label}</span>
      <div className="flex flex-wrap gap-2">{children}</div>
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

// ─── Na čom ─────────────────────────────────────────────────────────────────

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
  const [query, setQuery] = useState('');
  const [manualOpen, setManualOpen] = useState(manual !== '');
  const [newOpen, setNewOpen] = useState(false);

  const foldedQuery = fold(query).trim();
  const showSearch = options.length > DEVICE_SEARCH_THRESHOLD || query !== '';
  const visible = foldedQuery ? options.filter((o) => deviceMatches(o, foldedQuery, fold)) : options;
  const selectedCount = Object.keys(selected).length;

  // Selected ones the list no longer offers (type unticked, entry gone) stay visible so they can be unticked.
  const optionKeys = new Set(options.map((o) => o.key));
  const orphans = Object.values(selected).filter((o) => !optionKeys.has(o.key));

  const byType = new Map<DeviceType, DeviceOption[]>();
  for (const o of visible) byType.set(o.type, [...(byType.get(o.type) ?? []), o]);

  // No routine control ticked: nothing to pick from, the device is written by hand (spec 6.3.5, 11.15).
  if (routine.length === 0) {
    return (
      <Card className="flex flex-col gap-3 p-4">
        <span className="text-xs font-semibold uppercase tracking-wide text-ink-500">Ktorých zariadení sa týka</span>
        <p className="rounded-xl bg-ink-50 px-3 py-2.5 text-xs leading-relaxed text-ink-600">
          V tejto prehliadke nerobíš žiadnu riadnu kontrolu, takže nie je z čoho vyberať. Ak zariadenie kontroluje niekto
          iný, <b className="font-semibold">vypíš jeho číslo rukou</b> — do záznamu sa vytlačí tak, ako ho napíšeš.
        </p>
        <Field label="Zariadenie rukou" hint="Napríklad „PHP č. 25-08014, kotolňa“.">
          {(p) => (
            <Input {...p} value={manual} maxLength={191} placeholder="PHP č. 25-08014, kotolňa" onChange={(e) => onManual(e.target.value)} />
          )}
        </Field>
        <p className="text-xs text-ink-400">
          Keď chceš, aby sa prístroj rovno prepísal do konceptu kontroly ako vadný, vráť sa do kroku 1 a zaškrtni
          príslušnú kontrolu — potom si ho vyberieš zo zoznamu.
        </p>
      </Card>
    );
  }

  return (
    <Card className="flex flex-col gap-3 p-4">
      <span className="text-xs font-semibold uppercase tracking-wide text-ink-500">Ktorých zariadení sa týka</span>

      {showSearch && (
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
                <button type="button" className="font-medium text-firol-700 hover:underline" onClick={() => setQuery('')}>
                  zrušiť hľadanie
                </button>
              </>
            )}
          </p>
        </div>
      )}

      {DEVICE_TYPES.filter((t) => byType.has(t)).map((t) => (
        <div key={t} className="flex flex-col gap-2">
          <h4 className="text-xs font-semibold text-ink-500">
            {DEVICE_GROUP_LABELS[t]} · {byType.get(t)!.length}
          </h4>
          <ul className="flex flex-col gap-2">
            {byType.get(t)!.map((o) => (
              <li key={o.key}>
                <CheckRow
                  checked={selected[o.key] !== undefined}
                  onToggle={() => onToggle(o)}
                  title={[o.label, o.description].filter(Boolean).join(' · ')}
                  sub={[o.location, o.serial ? `v. č. ${o.serial}` : null].filter(Boolean).join(' · ') || undefined}
                />
              </li>
            ))}
          </ul>
        </div>
      ))}

      {visible.length === 0 && (
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

      {manual !== '' && !manualOpen && (
        <p className="rounded-xl bg-ink-50 px-3 py-2 text-xs text-ink-600">
          Dopísané rukou: <b>{manual}</b>
        </p>
      )}

      <div className="flex flex-col items-start gap-2">
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
            Zariadenie, ktoré tu ešte nie je
          </Button>
        )}
        {manualOpen ? (
          <div className="w-full">
            <Field label="Zariadenie rukou" hint="Vytlačí sa do záznamu tak, ako ho napíšeš.">
              {(p) => (
                <Input {...p} value={manual} maxLength={191} placeholder="PHP č. 25-08014, kotolňa" onChange={(e) => onManual(e.target.value)} />
              )}
            </Field>
          </div>
        ) : (
          <Button type="button" id="pp-manual-device" variant="ghost" size="sm" onClick={() => setManualOpen(true)}>
            Alebo vypísať číslo rukou
          </Button>
        )}
      </div>

      <p className="text-xs text-ink-400">
        Čo tu označíš, prepíše sa do konceptu danej kontroly ako nevyhovujúce — aj s fotkami. Ostatné zariadenia budú
        vyhovujúce, nemusíš na ne klikať. Keď je rovnaký nedostatok na viacerých zariadeniach, zapíš každé zvlášť s
        vlastnou fotkou — dole je na to tlačidlo „Uložiť a ďalšie zariadenie“.
      </p>
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
function constrainScope(scope: DefectScope, details: PrehliadkaDetails): DefectScope {
  if (details.scope.po && !details.scope.bozp) return 'po';
  if (details.scope.bozp && !details.scope.po) return 'bo';
  return scope;
}

function initialState(
  item: InspectionItem | null,
  seed: FormState | null,
  loaded: Loaded,
  details: PrehliadkaDetails,
): FormState {
  if (seed) return seed;
  const defect = item ? parseDefect(item.fields) : null;
  if (!defect) {
    return {
      catalogId: null,
      title: '',
      scope: defaultDefectScope(details.scope),
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
      choice: '30d',
      customDate: '',
      responsible: loaded.responsibleDefault,
      groupKey: null,
    };
  }

  // The device list as it is now, so an edited defect shows fresh labels; the snapshot covers the rest.
  const fresh = new Map<string, DeviceOption>();
  for (const g of loaded.groups) {
    for (const d of g.items) {
      fresh.set(`s${d.source_item_id}`, {
        key: `s${d.source_item_id}`,
        type: g.type,
        label: d.label,
        serial: d.serial,
        description: d.description,
        location: d.location,
        source_item_id: d.source_item_id,
      });
    }
  }
  for (const n of details.new_devices) {
    fresh.set(`n${n.tmp_id}`, {
      key: `n${n.tmp_id}`,
      type: n.type,
      label: n.label,
      serial: n.serial,
      description: n.description,
      location: n.location,
      new_device_tmp_id: n.tmp_id,
    });
  }
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
  };
}

function DefectSkeleton() {
  return (
    <div className="flex flex-col gap-4" aria-busy>
      <Skeleton className="h-4 w-32" />
      <Skeleton className="h-7 w-48" />
      <Skeleton className="h-24 w-full rounded-2xl" />
      <Skeleton className="h-64 w-full rounded-2xl" />
    </div>
  );
}
