import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Building2, Camera, ChevronRight, Info, Plus, Settings2, Warehouse } from 'lucide-react';
import { Inspections, type InspectionDetail, type InspectionItem } from '@/api/inspections';
import { ApiError } from '@/lib/api';
import { formatDateSk } from '@/lib/clientNoticeEmail';
import {
  ROUTINE_LABELS,
  defectNewPath,
  defectPath,
  deviceLine,
  effectiveDeadline,
  parseDefect,
  parseDetails,
  prehliadkaSetupPath,
  scopeLabel,
  type DefectFields,
} from '@/lib/prehliadka';
import { visitTrail } from '@/lib/visits';
import { Badge } from '@/components/ui/Badge';
import { Breadcrumb } from '@/components/ui/Breadcrumb';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Skeleton } from '@/components/ui/Skeleton';
import { PendingSyncBanner } from '@/components/PendingSyncBanner';
import { useIsReadOnly } from '@/auth/useIsReadOnly';
import { DefectScopeBadge, StepBars } from '@/components/prehliadka/ui';

type Row = { item: InspectionItem; defect: DefectFields };

function nedostatkyLabel(n: number): string {
  if (n === 1) return '1 nedostatok';
  if (n >= 2 && n <= 4) return `${n} nedostatky`;
  return `${n} nedostatkov`;
}

/**
 * Krok 2 — Obchôdzka (spec 6.2): „Pridať nedostatok" at any moment, no forced
 * order and no list of entrances to tick off. The written defects are only
 * grouped by entrance when the technician gave one, „Bez vchodu" last.
 * „Prerušiť" keeps the draft; it is found again on the prevádzka.
 */
export function PrehliadkaWalkPage() {
  const { id: idStr } = useParams<{ id: string }>();
  const id = Number(idStr);
  const navigate = useNavigate();
  const isReadOnly = useIsReadOnly();

  const [data, setData] = useState<InspectionDetail | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    Inspections.show(id)
      .then((res) => {
        if (!cancelled) setData(res);
      })
      .catch((err: unknown) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : 'Prehliadku sa nepodarilo načítať.');
      });
    return () => {
      cancelled = true;
    };
  }, [id]);

  const rows = useMemo<Row[]>(
    () =>
      (data?.items ?? []).flatMap((item) => {
        const defect = parseDefect(item.fields);
        return defect ? [{ item, defect }] : [];
      }),
    [data],
  );

  if (error) {
    return (
      <div className="flex flex-col gap-4">
        <Link to="/" className="inline-flex items-center gap-1 self-start text-sm text-ink-500 hover:text-ink-700">
          <ArrowLeft className="size-4" />
          Späť
        </Link>
        <Card className="px-4 py-3 text-sm text-status-bad">{error}</Card>
      </div>
    );
  }
  if (!data) return <WalkSkeleton />;

  const { inspection: i } = data;
  const details = parseDetails(i.details);
  const draft = i.status === 'draft';
  const canEdit = draft && !isReadOnly;
  const executedOn = i.executed_on;
  const facilityHref = `/facilities/${i.facility_id}`;

  // Grouped by entrance only when at least one defect names one.
  const anyEntrance = rows.some((r) => r.defect.where.entrance);
  const groups: { key: string; title: string | null; rows: Row[] }[] = [];
  if (anyEntrance) {
    const byEntrance = new Map<string, Row[]>();
    const without: Row[] = [];
    for (const r of rows) {
      const e = r.defect.where.entrance?.trim();
      if (!e) without.push(r);
      else byEntrance.set(e, [...(byEntrance.get(e) ?? []), r]);
    }
    for (const [e, list] of byEntrance) groups.push({ key: e, title: e, rows: list });
    if (without.length > 0) groups.push({ key: '__bez', title: 'Bez vchodu', rows: without });
  } else if (rows.length > 0) {
    groups.push({ key: '__all', title: null, rows });
  }

  return (
    <div className="flex flex-col gap-5">
      {i.visit_id !== null ? (
        <Breadcrumb
          items={[
            ...visitTrail({ id: i.visit_id, companyName: i.company_name, date: i.executed_on }),
            { label: 'Preventívna prehliadka' },
          ]}
        />
      ) : (
        <Link to={facilityHref} className="inline-flex items-center gap-1 self-start text-sm text-ink-500 hover:text-ink-700">
          <ArrowLeft className="size-4" />
          Späť na prevádzku
        </Link>
      )}

      <header className="animate-fade-up">
        <p className="text-xs font-semibold uppercase tracking-wider text-firol-500">Krok 2 z 3 · Obchôdzka</p>
        <h1 className="mt-1 text-xl font-semibold tracking-tight text-ink-900">Zapisujem len nedostatky</h1>
        <p className="mt-1 flex flex-wrap items-center gap-x-1.5 text-xs text-ink-500">
          <Building2 className="size-3" />
          {i.company_name}
          <span className="text-ink-300">·</span>
          <Warehouse className="size-3" />
          {i.facility_name}
          <span className="text-ink-300">·</span>
          {rows.length === 0 ? 'zatiaľ nič' : nedostatkyLabel(rows.length)}
        </p>
        <StepBars step={2} />
      </header>

      <PendingSyncBanner resource="inspections" id={id} />

      {/* What this prehliadka is — one tap to change it. */}
      <Card className="animate-fade-up flex items-start gap-3 p-4">
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-1.5">
            <Badge tone="brand">{scopeLabel(details.scope)}</Badge>
            {executedOn && <span className="text-xs text-ink-500">{formatDateSk(executedOn)}</span>}
          </div>
          <p className="mt-1.5 text-xs text-ink-500">
            {details.routine.length > 0
              ? `Riadne kontroly: ${details.routine.map((t) => ROUTINE_LABELS[t]).join(', ')}`
              : 'Bez riadnych kontrol — zariadenie sa pri nedostatku vypisuje rukou.'}
          </p>
          {details.scope.po && (
            <p className="mt-0.5 text-xs text-ink-500">
              Zápis do požiarnej knihy: {details.book_entry ? 'áno' : 'nie'}
            </p>
          )}
        </div>
        {canEdit && (
          <Link to={prehliadkaSetupPath(id)} className="shrink-0">
            <Button type="button" variant="secondary" size="sm" id="pp-edit-setup" leftIcon={<Settings2 className="size-4" />}>
              Nastavenie
            </Button>
          </Link>
        )}
      </Card>

      {canEdit && (
        <Link to={defectNewPath(id)} className="animate-fade-up">
          <Button type="button" id="pp-add-defect" className="w-full" leftIcon={<Plus className="size-4" />}>
            Pridať nedostatok
          </Button>
        </Link>
      )}

      {rows.length === 0 ? (
        <p className="animate-fade-in rounded-2xl border border-dashed border-ink-200 bg-white px-4 py-5 text-sm text-ink-500">
          Zatiaľ nič. Čo nezapíšeš, do záznamu nejde — záznam vypisuje len to, čo si našiel.
        </p>
      ) : (
        groups.map((g) => (
          <section key={g.key} className="flex flex-col gap-2">
            {g.title !== null && (
              <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-500">
                {g.title} · {nedostatkyLabel(g.rows.length)}
              </h2>
            )}
            <ul className="flex flex-col gap-2">
              {g.rows.map(({ item, defect }) => (
                <li key={item.id}>
                  <DefectCard
                    to={defectPath(id, item.id)}
                    item={item}
                    defect={defect}
                    deadline={effectiveDeadline(defect, executedOn)}
                    clickable={canEdit}
                  />
                </li>
              ))}
            </ul>
          </section>
        ))
      )}

      {draft && (
        <Card className="flex items-start gap-3 border-dashed p-4 text-xs text-ink-500">
          <Info className="mt-0.5 size-4 shrink-0 text-ink-400" />
          <p>
            Termíny, vykonané činnosti a uzavretie prehliadky ešte nie sú dostupné. Zapísané nedostatky ostanú uložené
            v telefóne a na serveri — prehliadku nájdeš na prevádzke ako „Prehliadka prebieha".
          </p>
        </Card>
      )}

      <Button type="button" id="pp-interrupt" variant="secondary" onClick={() => navigate(facilityHref)}>
        Prerušiť
      </Button>
      <p className="-mt-2 text-center text-xs text-ink-400">Prerušenú prehliadku nájdeš na prevádzke.</p>
    </div>
  );
}

function DefectCard({
  to,
  item,
  defect,
  deadline,
  clickable,
}: {
  to: string;
  item: InspectionItem;
  defect: DefectFields;
  deadline: string | null;
  clickable: boolean;
}) {
  const photos = item.photos?.length ?? 0;
  const devices = deviceLine(defect);
  const body = (
    <div className="flex items-start gap-2">
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-1.5">
          <DefectScopeBadge scope={defect.scope} />
          {photos > 0 && (
            <span className="inline-flex items-center gap-1 rounded-full bg-ink-100 px-2 py-0.5 text-xs font-medium text-ink-600">
              <Camera className="size-3" />
              {photos}
            </span>
          )}
          {devices && (
            <span className="max-w-full truncate rounded-full bg-ink-100 px-2 py-0.5 text-xs font-medium text-ink-600">
              {devices}
            </span>
          )}
        </div>
        <p className="mt-1.5 text-sm font-semibold text-ink-900">{defect.title}</p>
        <p className="mt-0.5 text-xs text-ink-500">
          {defect.where.text} · termín {deadline ? `do ${formatDateSk(deadline)}` : 'bezodkladne'}
        </p>
      </div>
      {clickable && <ChevronRight className="mt-1 size-4 shrink-0 text-ink-300" />}
    </div>
  );
  const cls =
    'block w-full rounded-2xl border border-ink-200 bg-white px-4 py-3 text-left transition-[border-color,background-color,transform,box-shadow] duration-200';
  if (!clickable) return <div className={cls}>{body}</div>;
  return (
    <Link
      to={to}
      className={`${cls} animate-fade-in hover:border-firol-300 hover:bg-firol-50/40 hover:shadow-[var(--shadow-soft)] active:scale-[0.99] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300`}
    >
      {body}
    </Link>
  );
}

function WalkSkeleton() {
  return (
    <div className="flex flex-col gap-4" aria-busy>
      <Skeleton className="h-4 w-32" />
      <Skeleton className="h-7 w-64" />
      <Skeleton className="h-24 w-full rounded-2xl" />
      <Skeleton className="h-12 w-full rounded-2xl" />
      <Skeleton className="h-20 w-full rounded-2xl" />
    </div>
  );
}
