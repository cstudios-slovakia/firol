import { Link, useSearchParams } from 'react-router-dom';
import {
  ArrowLeft, Ban, BookOpen, ChevronRight, DoorClosed, Droplets,
  Flame, Gauge, LibraryBig, Lightbulb, Shield, ShieldCheck, Signpost, TrainTrack, Wrench,
  TestTube, Users, Wind,
} from 'lucide-react';
// Block 2 — single-record BOZP úkony.
import { BookCheck, CigaretteOff, Factory, PhoneCall } from 'lucide-react';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import {
  INSPECTION_TYPE_LABELS,
  type InspectionType,
} from '@/api/inspections';
import { RECOMMENDED_MONTHS } from '@/lib/periodicity';
import {
  SECTION_INSPECTION_TYPES,
  SECTION_LABELS,
  SECTION_PATHS,
  isSection,
  type Section,
} from '@/lib/sections';

type TypeMeta = {
  type: InspectionType;
  shortLabel: string;
  description: string;
  intervalLabel: string;
  icon: React.ReactNode;
  enabled: boolean;
};

/**
 * Order matters — PHP first because it's the most common workflow and
 * the only enabled type in Phase 3a-1. Disabled cards stay visible so
 * the technician can see what's coming.
 */
const TYPES: TypeMeta[] = [
  {
    type: 'php',
    shortLabel: 'Hasiace prístroje',
    description: 'Kontrola PHP s hodnotením A / TS / O / V.',
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
    enabled: true,
  },
  {
    type: 'oprava_ts_php',
    shortLabel: 'Oprava + TS PHP',
    description: 'Oprava, plnenie a tlaková skúška hasiacich prístrojov.',
    intervalLabel: '60 mes.',
    icon: <Wrench className="size-5" />,
    enabled: true,
  },
  {
    type: 'poziarna_kniha',
    shortLabel: 'Požiarna kniha',
    description: 'Periodický záznam o stave protipožiarnej ochrany.',
    intervalLabel: '3 / 6 / 12 mes.',
    icon: <BookOpen className="size-5" />,
    enabled: true,
  },
  {
    type: 'pu_akcieschopnost',
    shortLabel: 'PU — akcieschopnosť',
    description: 'Požiarne uzávery, prevádzková kontrola.',
    intervalLabel: '3 mes.',
    icon: <ShieldCheck className="size-5" />,
    enabled: true,
  },
  {
    type: 'pu_udrzba',
    shortLabel: 'PU — údržba',
    description: 'Požiarne uzávery, ročná prevádzková údržba.',
    intervalLabel: '12 mes.',
    icon: <DoorClosed className="size-5" />,
    enabled: true,
  },
  {
    type: 'nudzove_osvetlenie',
    shortLabel: 'Núdzové osvetlenie',
    description: 'Test svietidiel a doby svietenia v núdzovom režime.',
    intervalLabel: '12 mes.',
    icon: <Lightbulb className="size-5" />,
    enabled: true,
  },
  {
    type: 'ts_hadic',
    shortLabel: 'TS hadíc',
    description: 'Tlaková skúška požiarnych hadíc.',
    intervalLabel: '12 mes.',
    icon: <Gauge className="size-5" />,
    enabled: true,
  },
  {
    type: 'vyradenie',
    shortLabel: 'Vyradenie PHP',
    description: 'Protokol o vyradení hasiacich prístrojov z používania.',
    intervalLabel: 'podľa potreby',
    icon: <Ban className="size-5" />,
    enabled: true,
  },
  // Block 2 — single-record BOZP úkony (kniha, pracovisko, osamelé, fajčenie).
  {
    type: 'kniha_bozp',
    shortLabel: 'Kniha kontrol BOZP',
    description: 'Záznam o kontrole stavu BOZP s prehľadom termínov klienta.',
    intervalLabel: '12 / 6 / 3 mes.',
    icon: <BookCheck className="size-5" />,
    enabled: true,
  },
  {
    type: 'pracovisko',
    shortLabel: 'Kontrola pracoviska',
    description: 'Pracovisko a pracovné prostredie po oblastiach.',
    intervalLabel: '12 / 6 mes.',
    icon: <Factory className="size-5" />,
    enabled: true,
  },
  {
    type: 'osamele_pracovisko',
    shortLabel: 'Osamelé pracoviská',
    description: 'Spojenie a kontrola prítomnosti osamotene pracujúcich.',
    intervalLabel: '12 / 6 mes.',
    icon: <PhoneCall className="size-5" />,
    enabled: true,
  },
  {
    type: 'fajcenie',
    shortLabel: 'Zákaz fajčenia',
    description: 'Kontrola dodržiavania zákazu fajčenia v priestoroch.',
    intervalLabel: 'bez opakovania',
    icon: <CigaretteOff className="size-5" />,
    enabled: true,
  },
  // Block 2 — BOZP úkony, chapter 5.3 order.
  {
    type: 'oopp',
    shortLabel: 'Kontrola OOPP',
    description: 'Poskytovanie a používanie osobných ochranných pracovných prostriedkov.',
    intervalLabel: '12 mes.',
    icon: <Shield className="size-5" />,
    enabled: true,
  },
  {
    type: 'pracovne_prostriedky',
    shortLabel: 'Pracovné prostriedky',
    description: 'Zoznam pracovných prostriedkov s výsledkom a opatreniami.',
    intervalLabel: '12 mes.',
    icon: <Wrench className="size-5" />,
    enabled: true,
  },
  {
    type: 'rebriky',
    shortLabel: 'Rebríky',
    description: 'Kontrola rebríkov — vyhovuje / nevyhovuje / vyradené.',
    intervalLabel: '12 mes.',
    icon: <TrainTrack className="size-5" />,
    enabled: true,
  },
  {
    type: 'regale',
    shortLabel: 'Regály',
    description: 'Kontrola regálov vrátane označenia nosnosti.',
    intervalLabel: '12 mes.',
    icon: <LibraryBig className="size-5" />,
    enabled: true,
  },
  {
    type: 'oznacenie',
    shortLabel: 'Bezpečnostné označenie',
    description: 'Kontrola bezpečnostného a zdravotného označenia pri práci.',
    intervalLabel: '12 mes.',
    icon: <Signpost className="size-5" />,
    enabled: true,
  },
  // Block 2 — the person-list úkony (chapters 7, 8, 8.1).
  {
    type: 'dychova_skuska',
    shortLabel: 'Dychová skúška',
    description: 'Zoznam osôb s výsledkom — aj prázdny formulár na ručné doplnenie.',
    intervalLabel: 'bez opakovania',
    icon: <Wind className="size-5" />,
    enabled: true,
  },
  {
    type: 'omamne_latky',
    shortLabel: 'Omamné látky',
    description: 'Kontrola omamných a psychotropných látok — aj prázdny formulár.',
    intervalLabel: 'bez opakovania',
    icon: <TestTube className="size-5" />,
    enabled: true,
  },
  {
    type: 'skolenie_bozp',
    shortLabel: 'Oboznámenie BOZP',
    description: 'Prezenčná listina s odkazom na osnovu — bez tematického plánu.',
    intervalLabel: '36 / 24 / 12 mes.',
    icon: <Users className="size-5" />,
    enabled: true,
  },
];

export function NewInspectionTypePicker() {
  const [params] = useSearchParams();
  // Optional context — if the user came from a company or facility detail
  // we forward those IDs so Step 1 can prefill them.
  const facilityId = params.get('facility_id');
  const companyId = params.get('company_id');
  // Set when an úkon is being added to a visit on the spot (chapter 9): the
  // new úkon must end up under that visit, and „Späť" returns to it.
  const visitId = params.get('visit_id');
  const executedOn = params.get('executed_on');
  // Which section the technician came from (chapter 2). It narrows the list to
  // that odbor's types — arriving from Revízie and being offered a požiarna
  // kniha would just be noise to scroll past.
  const sectionParam = params.get('section') ?? undefined;
  const section: Section | null = isSection(sectionParam) ? sectionParam : null;
  const backHref = visitId
    ? `/visits/${visitId}`
    : facilityId
    ? `/facilities/${facilityId}`
    : companyId
      ? `/companies/${companyId}`
      : section
        ? SECTION_PATHS[section]
        : '/';

  const passthrough = new URLSearchParams();
  if (facilityId) passthrough.set('facility_id', facilityId);
  if (companyId) passthrough.set('company_id', companyId);
  if (visitId) {
    passthrough.set('visit_id', visitId);
    if (executedOn) passthrough.set('executed_on', executedOn);
  }
  // Step 1 needs the section only for its „Späť", which must return to this
  // same narrowed list rather than to every type.
  if (section) passthrough.set('section', section);

  const offered = section
    ? TYPES.filter((m) => SECTION_INSPECTION_TYPES[section].includes(m.type))
    : TYPES;
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
        <h1 className="text-xl font-semibold tracking-tight text-ink-900">
          Nová kontrola{section ? ` — ${SECTION_LABELS[section]}` : ''}
        </h1>
        <p className="mt-0.5 text-sm text-ink-500">
          Vyber typ kontroly. Periodicitu si zvolíš v ďalšom kroku.
        </p>
      </header>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {offered.map((meta, i) => (
          <div key={meta.type} className="animate-fade-up" style={{ animationDelay: `${i * 40}ms` }}>
            <TypeCard meta={meta} href={stepOnePathFor(meta.type)} />
          </div>
        ))}
      </div>
    </div>
  );
}

function TypeCard({ meta, href }: { meta: TypeMeta; href: string }) {
  const fullLabel = INSPECTION_TYPE_LABELS[meta.type];
  // What the app suggests for this type — a starting point the technician
  // overrides freely in Step 1 (chapter 5), never a fixed interval.
  const recommended = RECOMMENDED_MONTHS[meta.type] ?? [];
  const periodicities = recommended.length > 0
    ? `odporúčané ${recommended.join(' / ')} mes.`
    : 'bez opakovania';

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
          <p className="mt-1 text-[11px] text-ink-400">{periodicities}</p>
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
      <Card className="flex items-center gap-3 px-4 py-4 transition-[box-shadow,transform] duration-150 group-hover:-translate-y-px group-hover:shadow-md">
        <div className="grid size-11 shrink-0 place-items-center rounded-2xl bg-firol-500 text-white shadow-[var(--shadow-glow)]">
          {meta.icon}
        </div>
        <div className="min-w-0 flex-1">
          <h3 className="truncate text-sm font-semibold text-ink-900">{meta.shortLabel}</h3>
          <p className="mt-0.5 line-clamp-2 text-xs text-ink-500">{meta.description}</p>
          <p className="mt-1 text-[11px] text-ink-400">{periodicities}</p>
        </div>
        <ChevronRight className="size-4 shrink-0 text-ink-300 transition-transform duration-150 group-hover:translate-x-0.5 group-hover:text-firol-500" />
      </Card>
    </Link>
  );
}
