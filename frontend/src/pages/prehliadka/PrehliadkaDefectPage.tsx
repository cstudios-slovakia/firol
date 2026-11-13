import { useEffect, useMemo, useState } from 'react';
import { Link, Navigate, useParams, useSearchParams } from 'react-router-dom';
import { ArrowLeft, Lock } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { Companies } from '@/api/companies';
import { Facilities, type Facility, type FacilityDeviceGroup } from '@/api/facilities';
import { Inspections, type InspectionDetail } from '@/api/inspections';
import { Prehliadka, type PreviousDefects } from '@/api/prehliadka';
import { ApiError, OfflineQueuedError } from '@/lib/api';
import { responsibleForFacility } from '@/lib/responsiblePerson';
import { useToast } from '@/lib/toast';
import { visitTrail } from '@/lib/visits';
import {
  defectPath,
  detailsPayload,
  newTmpId,
  parseDefect,
  parseDetails,
  prehliadkaPath,
  previousAnswers,
  type DeviceOption,
  type NewDevice,
  type PrehliadkaDetails,
} from '@/lib/prehliadka';
import { Card } from '@/components/ui/Card';
import { Skeleton } from '@/components/ui/Skeleton';
import {
  DefectForm,
  type DefectFormHost,
  type DefectFormState,
  type DefectFromPrevious,
} from '@/components/prehliadka/DefectForm';

type Loaded = {
  detail: InspectionDetail;
  facility: Facility;
  groups: FacilityDeviceGroup[];
  responsibleDefault: string;
  /** The open defects of the last prehliadka (cached GET); null when it could not be read. */
  previous: PreviousDefects | null;
};

/**
 * The preventívna prehliadka as host of the defect screen (spec 6.3, 12.1).
 *
 *   /prehliadky/:id/nedostatok/new             a new defect
 *   /prehliadky/:id/nedostatok/new?z=<key>     a defect of the last prehliadka, taken over
 *   /prehliadky/:id/nedostatok/:itemId         a saved one (new or carried over)
 *
 * The form itself is {@see DefectForm}; this page loads what it needs and says
 * what the prehliadka offers: its one prevádzka, its records, its device list
 * and a required deadline.
 */
export function PrehliadkaDefectPage() {
  const { id: idStr, itemId: itemIdStr } = useParams<{ id: string; itemId?: string }>();
  const [search] = useSearchParams();
  const inspectionId = Number(idStr);
  const itemId = itemIdStr !== undefined ? Number(itemIdStr) : null;
  const fromKey = itemId === null ? search.get('z') : null;
  const { csrfToken } = useAuth();
  const toast = useToast();

  const [loaded, setLoaded] = useState<Loaded | null>(null);
  const [error, setError] = useState<string | null>(null);
  // Bumped by „Uložiť a ďalšie zariadenie": a fresh form (and fresh photos) over the kept values.
  const [formKey, setFormKey] = useState(0);
  const [seed, setSeed] = useState<DefectFormState | null>(null);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const detail = await Inspections.show(inspectionId);
        const { facility } = await Facilities.show(detail.inspection.facility_id);
        const [groups, companyDetail, persons, previous] = await Promise.all([
          Facilities.devices(facility.id).then((r) => r.groups).catch(() => [] as FacilityDeviceGroup[]),
          Companies.show(facility.company_id).catch(() => null),
          Companies.persons(facility.company_id).then((r) => r.items).catch(() => []),
          Prehliadka.previous(facility.id, inspectionId).catch(() => null),
        ]);
        if (cancelled) return;
        const approver = companyDetail?.company.approver ?? null;
        setLoaded({
          detail,
          facility,
          groups,
          previous,
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

  const details = useMemo(
    () => (loaded ? parseDetails(loaded.detail.inspection.details) : null),
    [loaded],
  );

  // Every device the prehliadka knows: the derived list and the ones written during the walk.
  const allDevices = useMemo<DeviceOption[]>(() => {
    if (!loaded || !details) return [];
    const out: DeviceOption[] = [];
    for (const g of loaded.groups) {
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
  }, [loaded, details]);

  const back = (
    <Link to={prehliadkaPath(inspectionId)} className="inline-flex items-center gap-1 self-start text-sm text-ink-500 hover:text-ink-700">
      <ArrowLeft className="size-4" />
      Späť
    </Link>
  );

  if (error) {
    return (
      <div className="flex flex-col gap-4">
        {back}
        <Card className="px-4 py-3 text-sm text-status-bad">{error}</Card>
      </div>
    );
  }
  if (!loaded || !details) return <DefectSkeleton />;

  const { inspection: i } = loaded.detail;
  if (i.status !== 'draft') {
    return (
      <div className="flex flex-col gap-4">
        {back}
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
        {back}
        <Card className="px-4 py-3 text-sm text-status-bad">Nedostatok sa nenašiel — možno bol medzitým zmazaný.</Card>
      </div>
    );
  }

  // A defect of the last prehliadka: the row that already carries it over is edited,
  // otherwise the form opens prefilled from it.
  let from: DefectFromPrevious | null = null;
  if (fromKey !== null) {
    const rows = loaded.detail.items.flatMap((it) => {
      const defect = parseDefect(it.fields);
      return defect ? [{ itemId: it.id, defect }] : [];
    });
    const answer = previousAnswers(details.previous, rows).get(fromKey) ?? null;
    if (answer?.itemId != null) {
      return <Navigate to={defectPath(inspectionId, answer.itemId)} replace />;
    }
    const defect = loaded.previous?.defects.find((d) => d.key === fromKey) ?? null;
    if (!defect || !loaded.previous?.source) {
      return (
        <div className="flex flex-col gap-4">
          {back}
          <Card className="px-4 py-3 text-sm text-status-bad">
            {loaded.previous
              ? 'Nedostatok z minulej prehliadky sa nenašiel.'
              : 'Nedostatky z minulej prehliadky sa nepodarilo načítať. Skús to znova, keď budeš online.'}
          </Card>
        </div>
      );
    }
    from = { defect, source: loaded.previous.source, answer };
  }

  async function addNewDevice(nd: Omit<NewDevice, 'tmp_id'>): Promise<DeviceOption | null> {
    if (!details) return null;
    const device: NewDevice = { ...nd, tmp_id: newTmpId('nd') };
    const next: PrehliadkaDetails = { ...details, new_devices: [...details.new_devices, device] };
    try {
      await Inspections.update(inspectionId, { details: detailsPayload(next) }, csrfToken);
    } catch (err) {
      if (!(err instanceof OfflineQueuedError)) {
        toast.error(err instanceof ApiError ? err.message : 'Zariadenie sa nepodarilo zapísať.');
        return null;
      }
    }
    setLoaded((cur) =>
      cur ? { ...cur, detail: { ...cur.detail, inspection: { ...cur.detail.inspection, details: detailsPayload(next) } } } : cur,
    );
    return {
      key: `n${device.tmp_id}`,
      type: device.type,
      label: device.label,
      serial: device.serial,
      description: device.description,
      location: device.location,
      new_device_tmp_id: device.tmp_id,
    };
  }

  const walkPath = prehliadkaPath(inspectionId);
  const host: DefectFormHost = {
    inspectionId,
    backPath: walkPath,
    backLabel: 'Späť na obchôdzku',
    trail:
      i.visit_id !== null
        ? [
            ...visitTrail({ id: i.visit_id, companyName: i.company_name, date: i.executed_on }),
            { label: 'Preventívna prehliadka', to: walkPath },
          ]
        : null,
    executedOn: i.executed_on,
    facilities: [loaded.facility],
    scope: details.scope,
    devices: { routine: details.routine, all: allDevices, onAddNew: addNewDevice },
    deadline: 'required',
    responsibleDefault: loaded.responsibleDefault,
    previousSource: loaded.previous?.source ?? null,
    onFacilityChange: (facility) => setLoaded((cur) => (cur ? { ...cur, facility } : cur)),
  };

  return (
    <DefectForm
      key={`${itemId ?? fromKey ?? 'new'}-${formKey}`}
      host={host}
      item={item}
      from={from}
      seed={seed}
      onNext={(next) => {
        setSeed(next);
        setFormKey((k) => k + 1);
      }}
    />
  );
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
