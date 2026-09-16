import { useState } from 'react';
import { Link, Navigate, useLocation } from 'react-router-dom';
import { ClipboardList, GraduationCap, Plus } from 'lucide-react';
import { useIsReadOnly } from '@/auth/useIsReadOnly';
import { InspectionsListPage } from '@/pages/InspectionsListPage';
import { TrainingsListPage } from '@/pages/TrainingsListPage';
import { Card } from '@/components/ui/Card';
import {
  SECTION_COLORS,
  SECTION_INSPECTION_TYPES,
  SECTION_LABELS,
  isSection,
  sectionHasTrainings,
  type Section,
} from '@/lib/sections';
import { cn } from '@/lib/cn';

/**
 * One of the three sections the app is split into — block 1 / chapter 2.
 *
 * Revízie, OPP and BOZP replace the single "Kontroly" list and match the paid
 * modules one to one, so a technician sees exactly the work they do. Firms, the
 * calendar, the profile and the branding stay common to all three.
 *
 * The section Školenia is gone. A training has its own protocol and its own
 * next term, exactly like a kontrola, so školenie PO belongs inside OPP —
 * putting it in a menu of its own meant looking for last year's školenie
 * somewhere other than the rest of the same odbor's work. It appears here as a
 * second tab rather than mixed into the list, because the two carry different
 * columns: one counts devices, the other counts people.
 */
export function SectionPage() {
  // The section is the first path segment (/revizie, /opp, /bozp) — one
  // component serves all three, so the routes stay literal.
  const { pathname } = useLocation();
  const raw = pathname.split('/')[1];
  if (!isSection(raw)) {
    return <Navigate to="/" replace />;
  }
  return <SectionView section={raw} />;
}

function SectionView({ section }: { section: Section }) {
  const isReadOnly = useIsReadOnly();
  const withTrainings = sectionHasTrainings(section);
  const [tab, setTab] = useState<'inspections' | 'trainings'>('inspections');

  const hasInspectionTypes = SECTION_INSPECTION_TYPES[section].length > 0;
  const color = SECTION_COLORS[section];

  return (
    <div className="flex flex-col gap-4">
      <header className="flex items-center justify-between gap-3">
        <div className="flex items-center gap-2.5">
          <span
            aria-hidden
            className="h-8 w-1.5 shrink-0 rounded-full"
            style={{ backgroundColor: color }}
          />
          <div>
            <h1 className="text-xl font-semibold tracking-tight text-ink-900">
              {SECTION_LABELS[section]}
            </h1>
            <p className="mt-0.5 text-sm text-ink-500">
              Vykonané úkony a rozpracované koncepty.
            </p>
          </div>
        </div>
        {!isReadOnly && hasInspectionTypes && (
          <Link
            to={
              tab === 'trainings'
                ? '/trainings/new'
                : `/inspections/new?section=${section}`
            }
            className="inline-flex h-10 items-center gap-1.5 rounded-2xl bg-firol-500 px-3 text-sm font-medium text-white shadow-[var(--shadow-glow)] transition-transform hover:bg-firol-600 active:scale-[0.98]"
          >
            <Plus className="size-4" />
            {tab === 'trainings' ? 'Nové školenie' : 'Nová kontrola'}
          </Link>
        )}
      </header>

      {withTrainings && (
        <div
          role="tablist"
          aria-label={`Obsah sekcie ${SECTION_LABELS[section]}`}
          className="flex gap-1.5"
        >
          <SectionTab
            active={tab === 'inspections'}
            color={color}
            icon={<ClipboardList className="size-4" />}
            label="Kontroly"
            onClick={() => setTab('inspections')}
          />
          <SectionTab
            active={tab === 'trainings'}
            color={color}
            icon={<GraduationCap className="size-4" />}
            label="Školenia"
            onClick={() => setTab('trainings')}
          />
        </div>
      )}

      {tab === 'trainings' ? (
        <TrainingsListPage embedded />
      ) : hasInspectionTypes ? (
        <InspectionsListPage section={section} embedded />
      ) : (
        <EmptySection section={section} />
      )}
    </div>
  );
}

function SectionTab({
  active,
  color,
  icon,
  label,
  onClick,
}: {
  active: boolean;
  color: string;
  icon: React.ReactNode;
  label: string;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      role="tab"
      aria-selected={active}
      onClick={onClick}
      style={active ? { backgroundColor: color } : undefined}
      className={cn(
        'inline-flex h-9 items-center gap-1.5 rounded-2xl px-3.5 text-sm font-medium transition-all duration-150 active:scale-[0.98]',
        active ? 'text-white shadow-sm' : 'bg-ink-100 text-ink-600 hover:bg-ink-200',
      )}
    >
      {icon}
      {label}
    </button>
  );
}

/**
 * A section whose types have not shipped yet. BOZP is in this state until
 * block 2 adds its thirteen úkony; saying so plainly beats an empty list that
 * reads as lost data.
 */
function EmptySection({ section }: { section: Section }) {
  return (
    <Card className="flex flex-col items-center gap-3 px-6 py-12 text-center">
      <div
        className="grid size-14 place-items-center rounded-2xl text-white"
        style={{ backgroundColor: SECTION_COLORS[section] }}
      >
        <ClipboardList className="size-6" />
      </div>
      <h2 className="text-base font-semibold text-ink-900">
        Sekcia {SECTION_LABELS[section]} sa pripravuje
      </h2>
      <p className="max-w-sm text-sm text-ink-500">
        Typy úkonov pre túto sekciu zatiaľ nie sú k dispozícii. Firmy, prevádzky
        a kalendár sú spoločné pre všetky sekcie a fungujú aj bez nej.
      </p>
    </Card>
  );
}
