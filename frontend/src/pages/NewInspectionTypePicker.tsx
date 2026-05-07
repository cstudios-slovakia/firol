import { Link, useSearchParams } from 'react-router-dom';
import {
  ArrowLeft, BookOpen, ChevronRight, DoorClosed, Droplets, Flame,
  Gauge, Lightbulb, ShieldCheck, Wrench,
} from 'lucide-react';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import {
  INSPECTION_TYPE_LABELS,
  INSPECTION_TYPE_PERIODICITIES,
  type InspectionType,
} from '@/api/inspections';

type TypeMeta = {
  type: InspectionType;
  shortLabel: string;
  description: string;
  intervalLabel: string;
  icon: React.ReactNode;
  enabled: boolean;
};

/**
 * Order matters — RPHP first because it's the most common workflow and
 * the only enabled type in Phase 3a-1. Disabled cards stay visible so
 * the technician can see what's coming.
 */
const TYPES: TypeMeta[] = [
  {
    type: 'rphp',
    shortLabel: 'Hasiace prístroje',
    description: 'Kontrola RPHP s hodnotením A / TS / O / V.',
    intervalLabel: '12 / 24 mes.',
    icon: <Flame className="size-5" />,
    enabled: true,
  },
  {
    type: 'hydranty',
    shortLabel: 'Požiarne hydranty',
    description: 'Kontrola DN25 / DN33 / DN52 / C52 a ďalších.',
    intervalLabel: '12 mes.',
    icon: <Droplets className="size-5" />,
    enabled: false,
  },
  {
    type: 'oprava_ts_rphp',
    shortLabel: 'Oprava + TS RPHP',
    description: 'Oprava, plnenie a tlaková skúška hasiacich prístrojov.',
    intervalLabel: '60 mes.',
    icon: <Wrench className="size-5" />,
    enabled: false,
  },
  {
    type: 'poziarna_kniha',
    shortLabel: 'Požiarna kniha',
    description: 'Periodický záznam o stave protipožiarnej ochrany.',
    intervalLabel: '3 / 6 mes.',
    icon: <BookOpen className="size-5" />,
    enabled: false,
  },
  {
    type: 'pu_akcieschopnost',
    shortLabel: 'PU — akcieschopnosť',
    description: 'Požiarne uzávery, prevádzková kontrola.',
    intervalLabel: '3 mes.',
    icon: <ShieldCheck className="size-5" />,
    enabled: false,
  },
  {
    type: 'pu_udrzba',
    shortLabel: 'PU — údržba',
    description: 'Požiarne uzávery, ročná prevádzková údržba.',
    intervalLabel: '12 mes.',
    icon: <DoorClosed className="size-5" />,
    enabled: false,
  },
  {
    type: 'nudzove_osvetlenie',
    shortLabel: 'Núdzové osvetlenie',
    description: 'Test svietidiel a doby svietenia v núdzovom režime.',
    intervalLabel: '12 mes.',
    icon: <Lightbulb className="size-5" />,
    enabled: false,
  },
  {
    type: 'ts_hadic',
    shortLabel: 'TS hadíc',
    description: 'Tlaková skúška požiarnych hadíc.',
    intervalLabel: '60 mes.',
    icon: <Gauge className="size-5" />,
    enabled: false,
  },
];

export function NewInspectionTypePicker() {
  const [params] = useSearchParams();
  // Optional context — if the user came from a company or facility detail
  // we forward those IDs so Step 1 can prefill them.
  const facilityId = params.get('facility_id');
  const companyId = params.get('company_id');
  const backHref = facilityId
    ? `/facilities/${facilityId}`
    : companyId
      ? `/companies/${companyId}`
      : '/';

  const passthrough = new URLSearchParams();
  if (facilityId) passthrough.set('facility_id', facilityId);
  if (companyId) passthrough.set('company_id', companyId);
  const passthroughQs = passthrough.toString();
  const stepOnePathFor = (type: InspectionType) =>
    `/inspections/new/${type}/step-1${passthroughQs ? `?${passthroughQs}` : ''}`;

  return (
    <div className="flex flex-col gap-5">
      <Link
        to={backHref}
        className="inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-700 self-start"
      >
        <ArrowLeft className="size-4" />
        Späť
      </Link>

      <header>
        <h1 className="text-xl font-semibold tracking-tight text-ink-900">Nová kontrola</h1>
        <p className="mt-0.5 text-sm text-ink-500">
          Vyber typ kontroly. Periodicitu si zvolíš v ďalšom kroku.
        </p>
      </header>

      <div className="grid gap-3 sm:grid-cols-2">
        {TYPES.map((meta) => (
          <TypeCard key={meta.type} meta={meta} href={stepOnePathFor(meta.type)} />
        ))}
      </div>
    </div>
  );
}

function TypeCard({ meta, href }: { meta: TypeMeta; href: string }) {
  const fullLabel = INSPECTION_TYPE_LABELS[meta.type];
  const periodicities = INSPECTION_TYPE_PERIODICITIES[meta.type].join(' / ');

  if (!meta.enabled) {
    return (
      <Card
        className="flex items-start gap-3 px-4 py-4 opacity-60 cursor-not-allowed"
        aria-disabled="true"
        title={fullLabel}
      >
        <div className="grid size-11 shrink-0 place-items-center rounded-2xl bg-ink-100 text-ink-400">
          {meta.icon}
        </div>
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <h3 className="truncate text-sm font-semibold text-ink-700">{meta.shortLabel}</h3>
            <Badge tone="neutral">Čoskoro</Badge>
          </div>
          <p className="mt-0.5 line-clamp-2 text-xs text-ink-400">{meta.description}</p>
          <p className="mt-1 text-[11px] text-ink-400">{meta.intervalLabel} · {periodicities} mes.</p>
        </div>
      </Card>
    );
  }

  return (
    <Link
      to={href}
      className="group block rounded-2xl outline-none focus-visible:ring-2 focus-visible:ring-firol-300"
      title={fullLabel}
    >
      <Card className="flex items-start gap-3 px-4 py-4 transition-shadow group-hover:shadow-md">
        <div className="grid size-11 shrink-0 place-items-center rounded-2xl bg-firol-500 text-white shadow-[var(--shadow-glow)]">
          {meta.icon}
        </div>
        <div className="min-w-0 flex-1">
          <h3 className="truncate text-sm font-semibold text-ink-900">{meta.shortLabel}</h3>
          <p className="mt-0.5 line-clamp-2 text-xs text-ink-500">{meta.description}</p>
          <p className="mt-1 text-[11px] text-ink-400">{meta.intervalLabel}</p>
        </div>
        <ChevronRight className="size-4 shrink-0 text-ink-300 group-hover:text-firol-500" />
      </Card>
    </Link>
  );
}
