import { useEffect, useState } from 'react';
import { Link, Navigate, useParams } from 'react-router-dom';
import { ArrowLeft, Lock } from 'lucide-react';
import { Companies } from '@/api/companies';
import { Facilities, type Facility } from '@/api/facilities';
import { Inspections, type InspectionDetail } from '@/api/inspections';
import { ApiError } from '@/lib/api';
import { offerPrehliadkaDefectTasks } from '@/components/DefectTaskOffer';
import { isKnihaV2, knihaPath } from '@/lib/kniha';
import { isOpenDefect, parseDefect } from '@/lib/prehliadka';
import { responsibleForFacility } from '@/lib/responsiblePerson';
import { visitTrail } from '@/lib/visits';
import { Card } from '@/components/ui/Card';
import { Skeleton } from '@/components/ui/Skeleton';
import { DefectForm, type DefectFormHost, type DefectFormState } from '@/components/prehliadka/DefectForm';
import { entrySynced } from '@/pages/kniha/entrySynced';

type Loaded = {
  detail: InspectionDetail;
  /** The entry's prevádzky, the primary first. */
  facilities: Facility[];
  responsibleDefault: string;
};

/**
 * The požiarna kniha entry as host of the defect screen (spec 8, 12.1): the
 * same form as the prehliadka, with the entry's prevádzky (a chooser when there
 * are several), no device card, scope always PO and an optional deadline.
 *
 *   /kniha/:id/nedostatok/new        a new defect
 *   /kniha/:id/nedostatok/:itemId    a saved one
 */
export function KnihaDefectPage() {
  const { id: idStr, itemId: itemIdStr } = useParams<{ id: string; itemId?: string }>();
  const inspectionId = Number(idStr);
  const itemId = itemIdStr !== undefined ? Number(itemIdStr) : null;

  const [loaded, setLoaded] = useState<Loaded | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [formKey, setFormKey] = useState(0);
  const [seed, setSeed] = useState<DefectFormState | null>(null);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        await entrySynced(inspectionId);
        const detail = await Inspections.show(inspectionId);
        const i = detail.inspection;
        const rows = i.facilities && i.facilities.length > 0
          ? i.facilities
          : [{ facility_id: i.facility_id, name: i.facility_name }];
        // The primary prevádzka first — it is the form's default.
        const ordered = [...rows].sort((a, b) => Number(b.facility_id === i.facility_id) - Number(a.facility_id === i.facility_id));
        const [facilities, persons, company] = await Promise.all([
          Promise.all(
            ordered.map((r) =>
              Facilities.show(r.facility_id)
                .then((res) => res.facility)
                // Offline without the prevádzka cached: its name is enough for the form.
                .catch((): Facility => ({
                  id: r.facility_id,
                  name: r.name,
                  address: null,
                  street: null,
                  postal_code: null,
                  city: null,
                  contact_person: null,
                  notes: null,
                  entrances: [],
                  places: [],
                  company_id: i.company_id,
                  company_name: i.company_name,
                  company_client_type: i.company_client_type,
                })),
            ),
          ),
          Companies.persons(i.company_id).then((r) => r.items).catch(() => []),
          Companies.show(i.company_id).catch(() => null),
        ]);
        if (cancelled) return;
        const primary = facilities[0];
        setLoaded({
          detail,
          facilities,
          responsibleDefault:
            responsibleForFacility(primary.id, primary.contact_person, company?.company.approver ?? null, persons)?.fullname ?? '',
        });
      } catch (err) {
        if (!cancelled) setError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať zápis.');
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [inspectionId]);

  const back = (
    <Link to={knihaPath(inspectionId)} className="inline-flex items-center gap-1 self-start text-sm text-ink-500 transition-colors hover:text-ink-700">
      <ArrowLeft className="size-4" />
      Späť na zápis
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
  if (!loaded) return <DefectSkeleton />;

  const i = loaded.detail.inspection;
  if (!isKnihaV2(i.details)) return <Navigate to={`/inspections/${inspectionId}`} replace />;
  if (i.status !== 'draft') {
    return (
      <div className="flex flex-col gap-4">
        {back}
        <Card className="flex flex-col items-center gap-3 px-6 py-10 text-center">
          <div className="grid size-12 place-items-center rounded-2xl bg-ink-100 text-ink-500">
            <Lock className="size-5" />
          </div>
          <h2 className="text-base font-semibold text-ink-900">Zápis je uzamknutý</h2>
          <p className="max-w-sm text-sm text-ink-500">Nedostatky uzamknutého zápisu sa už nedajú meniť.</p>
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

  const entryPath = knihaPath(inspectionId);
  const host: DefectFormHost = {
    inspectionId,
    backPath: entryPath,
    backLabel: 'Späť na zápis',
    trail:
      i.visit_id !== null
        ? [
            ...visitTrail({ id: i.visit_id, companyName: i.company_name, date: i.executed_on }),
            { label: 'Požiarna kniha', to: entryPath },
          ]
        : null,
    executedOn: i.executed_on,
    facilities: loaded.facilities,
    scope: null,
    devices: null,
    deadline: 'optional',
    responsibleDefault: loaded.responsibleDefault,
    previousSource: null,
    onFacilityChange: (facility) =>
      setLoaded((cur) => (cur ? { ...cur, facilities: cur.facilities.map((f) => (f.id === facility.id ? facility : f)) } : cur)),
    // The „Overiť odstránenie" task, as for any defect with a date: only for a
    // saved row with a server id — the task's key is the item's (`p<id>`) — and
    // only when the date is new, so an edit of the text does not ask again.
    onSaved: (savedId, fields) => {
      const before = item ? (parseDefect(item.fields)?.deadline_date ?? null) : null;
      if (savedId > 0 && fields.deadline_date && fields.deadline_date !== before && isOpenDefect(fields)) {
        offerPrehliadkaDefectTasks(inspectionId, [
          { key: `p${savedId}`, title: fields.title, deadline_date: fields.deadline_date, task: null },
        ]);
      }
    },
  };

  return (
    <DefectForm
      key={`${itemId ?? 'new'}-${formKey}`}
      host={host}
      item={item}
      from={null}
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
