/**
 * Obrazovka „Dnes" — block 4 / chapter 18. Replaces the old Prehľad as the
 * first screen after login and answers „čo mám dnes robiť".
 *
 * Seven cards in a fixed order. A card with no rows is not rendered at all
 * (POKYNY rule 8) — a technician without nedostatky sees no empty box. Every
 * card shows its row count in the header and the header opens the related
 * section; every row opens its own record. A card shows its first rows and
 * folds the rest behind „+ N ďalšie", which unfolds them in place — so the
 * header count is always exactly the number of rows the card holds.
 *
 * Moje / Celý tím (chapters 11.5, 11.6): Dnes has no technician filter and
 * always shows the signed-in technician's own things. Only the main user of a
 * team gets the switch; in Celý tím every row carries the technician's avatar
 * on the right. A solo technician gets neither — everything in the account is
 * theirs, so the account's rows are shown (that also keeps legacy rows with no
 * recorded technician). The choice is remembered per account.
 *
 * Offline: both reads are plain GETs, which the api layer caches — the page
 * renders from the last answer it saw.
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  AlertTriangle,
  BadgeCheck,
  ChevronRight,
  ClipboardList,
  FilePen,
  GraduationCap,
  ListTodo,
  MapPin,
  Receipt,
  Route,
  ShieldAlert,
} from 'lucide-react';
import { useAuth, type TeamIdentity } from '@/auth/AuthContext';
import { useIsMainUser } from '@/auth/useIsMainUser';
import { Today, type FieldRow, type OpenDefect, type TodayData, type TodayScope, type UkonRow } from '@/api/today';
import { useUpcomingTasks, type Task } from '@/api/tasks';
import { INSPECTION_TYPE_LABELS, type InspectionType } from '@/api/inspections';
import { TRAINING_TYPE_SHORT, type TrainingType } from '@/api/trainings';
import { ZDROJ_LABELS, type CalendarDeadline, type OwnTerm, type TerminTechnician } from '@/api/calendar';
import { UNINVOICED_PARAM } from '@/api/invoicing';
import { SECTION_COLORS, sectionForInspectionType, type Section } from '@/lib/sections';
import { daysUntil } from '@/lib/calendarGrouping';
import { todayIso } from '@/lib/dates';
import { cn } from '@/lib/cn';
import { Card } from '@/components/ui/Card';
import { Skeleton } from '@/components/ui/Skeleton';
import { TechnicianAvatar } from '@/components/team/TechnicianAvatar';

// texty_ui.json → dnes, tim, ulohy
const T = {
  pozdravRano: 'Dobré ráno',
  pozdravDen: 'Dobrý deň',
  pozdravVecer: 'Dobrý večer',
  kartaTeren: 'Dnes v teréne',
  kartaPoTermine: 'Po termíne',
  kartaUlohy: 'Úlohy do 7 dní',
  kartaKoncepty: 'Rozrobené koncepty',
  kartaNedostatky: 'Otvorené nedostatky',
  kartaNefakturovane: 'Nevyfakturované',
  kartaVlastneTerminy: 'Tvoje termíny',
  prepinacMoje: 'Moje',
  prepinacTim: 'Celý tím',
  vseobecna: '— všeobecná —',
};

/** Rows a card shows before folding the rest behind „+ N ďalšie". */
const VISIBLE_ROWS = 3;

const SCOPE_KEY_PREFIX = 'firol.dnes.scope.';

function readScope(key: string): TodayScope {
  try {
    return window.localStorage.getItem(key) === 'team' ? 'team' : 'mine';
  } catch {
    return 'mine';
  }
}

function writeScope(key: string, value: TodayScope): void {
  try {
    window.localStorage.setItem(key, value);
  } catch {
    // Private mode / blocked storage — the switch still works for this visit.
  }
}

function greeting(hour: number): string {
  if (hour >= 4 && hour < 10) return T.pozdravRano;
  if (hour >= 10 && hour < 18) return T.pozdravDen;
  return T.pozdravVecer;
}

/** „2026-08-26" → „26. 8." this year, „26. 8. 2025" otherwise. */
function shortDate(iso: string): string {
  const [y, m, d] = iso.slice(0, 10).split('-').map(Number);
  return y === new Date().getFullYear() ? `${d}. ${m}.` : `${d}. ${m}. ${y}`;
}

/** Slovak plural of „deň": 1 deň, 2–4 dni, 5+ dní. */
function dayWord(n: number): string {
  if (n === 1) return 'deň';
  if (n >= 2 && n <= 4) return 'dni';
  return 'dní';
}

function ukonLabel(row: UkonRow): string {
  return row.kind === 'training'
    ? TRAINING_TYPE_SHORT[row.type as TrainingType] ?? row.type
    : INSPECTION_TYPE_LABELS[row.type as InspectionType] ?? row.type;
}

function ukonSection(row: UkonRow): Section | null {
  // Every training of the training tree belongs to OPP (lib/sections.ts).
  return row.kind === 'training' ? 'opp' : sectionForInspectionType(row.type as InspectionType);
}

function ukonHref(row: UkonRow): string {
  return row.kind === 'training' ? `/trainings/${row.id}` : `/inspections/${row.id}`;
}

// ── Page ─────────────────────────────────────────────────────────────────────

export function TodayPage() {
  const { user, team, activeAccountId } = useAuth();
  const isMainUser = useIsMainUser();

  const isSolo = team.length <= 1;
  const showSwitch = isMainUser && !isSolo;

  const storageKey = `${SCOPE_KEY_PREFIX}${activeAccountId ?? 0}`;
  const [choice, setChoice] = useState<TodayScope>(() => readScope(storageKey));
  useEffect(() => {
    setChoice(readScope(storageKey));
  }, [storageKey]);

  const scope: TodayScope = showSwitch ? choice : isSolo ? 'team' : 'mine';
  const showAvatars = showSwitch && choice === 'team';

  const selectScope = useCallback(
    (next: TodayScope) => {
      setChoice(next);
      writeScope(storageKey, next);
    },
    [storageKey],
  );

  const [data, setData] = useState<TodayData | null>(null);
  const [status, setStatus] = useState<'loading' | 'idle' | 'error'>('loading');
  const [tick, setTick] = useState(0);

  useEffect(() => {
    const bump = () => setTick((t) => t + 1);
    window.addEventListener('online', bump);
    window.addEventListener('firol:remap', bump);
    return () => {
      window.removeEventListener('online', bump);
      window.removeEventListener('firol:remap', bump);
    };
  }, []);

  useEffect(() => {
    let cancelled = false;
    Today.get(scope)
      .then((res) => {
        if (cancelled) return;
        setData(res);
        setStatus('idle');
      })
      .catch(() => {
        if (cancelled) return;
        setStatus((s) => (s === 'idle' ? s : 'error'));
      });
    return () => {
      cancelled = true;
    };
  }, [scope, tick]);

  const tasks = useUpcomingTasks(scope);

  // Rows of a switched scope never mix with the previous one's.
  const current = data && data.scope === scope ? data : null;
  const loading = (current === null && status !== 'error') || tasks.loading;

  const me: TerminTechnician | null = team.find((m) => m.id === user?.id) ?? null;
  const firstName = user?.fullname.split(' ')[0] ?? '';
  const now = new Date();
  const dateLine = now.toLocaleDateString('sk-SK', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  });

  const cards = current ? buildCards(current, tasks.items, team, me, showAvatars) : [];

  return (
    <div className="flex flex-col gap-5">
      <header className="rounded-3xl bg-gradient-to-br from-firol-500 to-firol-600 px-5 py-6 text-white shadow-[var(--shadow-glow)]">
        <h1 className="text-2xl font-bold tracking-tight">
          {greeting(now.getHours())}
          {firstName && `, ${firstName}`}
        </h1>
        <p className="mt-1 text-sm opacity-80">{dateLine}</p>
        <div className="mt-4 flex flex-wrap gap-2">
          {/* Chapter 2: a návšteva starts from Dnes or from a firm's detail;
              chapter 29 starts a kontrola from the first screen too. */}
          <QuickAction id="dnes-nova-navsteva" to="/visits/new" icon={<Route className="size-3.5" />}>
            Nová návšteva
          </QuickAction>
          <QuickAction id="dnes-nova-kontrola" to="/inspections/new" icon={<ClipboardList className="size-3.5" />}>
            Nová kontrola
          </QuickAction>
          <QuickAction id="dnes-nove-skolenie" to="/trainings/new" icon={<GraduationCap className="size-3.5" />}>
            Nové školenie
          </QuickAction>
        </div>
      </header>

      {showSwitch && <ScopeSwitch value={choice} onChange={selectScope} />}

      {loading ? (
        <TodaySkeleton />
      ) : current === null ? (
        <Card className="border-status-bad/30 bg-[var(--color-status-bad-bg)]/50 px-4 py-3 text-sm text-[var(--color-status-bad)]">
          Prehľad na dnes sa nepodarilo načítať. Skús to znova, keď budeš online.
        </Card>
      ) : cards.length === 0 ? (
        <p className="px-1 text-sm text-ink-400">Na dnes tu nič nečaká.</p>
      ) : (
        <div className="flex flex-col gap-4">
          {cards.map((card, i) => (
            <div key={card.id} className="animate-fade-up" style={{ animationDelay: `${i * 50}ms` }}>
              <TodayCard card={card} />
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

function QuickAction({
  id,
  to,
  icon,
  children,
}: {
  id: string;
  to: string;
  icon: React.ReactNode;
  children: React.ReactNode;
}) {
  return (
    <Link
      id={id}
      to={to}
      className="inline-flex items-center gap-1.5 rounded-2xl bg-white/20 px-3 py-1.5 text-xs font-semibold backdrop-blur-sm transition-[background-color,transform] duration-150 hover:bg-white/30 active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/70"
    >
      {icon}
      {children}
    </Link>
  );
}

function ScopeSwitch({ value, onChange }: { value: TodayScope; onChange: (v: TodayScope) => void }) {
  const options: { value: TodayScope; label: string }[] = [
    { value: 'mine', label: T.prepinacMoje },
    { value: 'team', label: T.prepinacTim },
  ];
  return (
    <div
      role="tablist"
      aria-label="Koho veci zobraziť"
      className="relative grid grid-cols-2 self-start rounded-2xl border border-ink-100 bg-white p-1 shadow-[var(--shadow-soft)]"
    >
      {/* Sliding thumb under the active option. */}
      <span
        aria-hidden
        className={cn(
          'absolute inset-y-1 left-1 w-[calc(50%-0.25rem)] rounded-xl bg-firol-500 shadow-[var(--shadow-glow)] transition-transform duration-300 ease-[cubic-bezier(0.16,1,0.3,1)]',
          value === 'team' && 'translate-x-full',
        )}
      />
      {options.map((o) => (
        <button
          key={o.value}
          id={`dnes-rozsah-${o.value}`}
          type="button"
          role="tab"
          aria-selected={value === o.value}
          onClick={() => onChange(o.value)}
          className={cn(
            'relative min-h-10 rounded-xl px-5 text-sm font-medium transition-[color,transform] duration-200 active:scale-[0.97] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300',
            value === o.value ? 'text-white' : 'text-ink-600 hover:text-ink-900',
          )}
        >
          {o.label}
        </button>
      ))}
    </div>
  );
}

function TodaySkeleton() {
  return (
    <div className="flex flex-col gap-4" aria-busy="true" aria-label="Načítavam">
      {[3, 2, 2].map((rows, i) => (
        <Card key={i} className="overflow-hidden p-0">
          <div className="flex items-center gap-2.5 border-b border-ink-100 px-4 py-3">
            <Skeleton className="size-8 rounded-xl" />
            <Skeleton className="h-4 w-32" />
            <Skeleton className="ml-auto h-5 w-7 rounded-full" />
          </div>
          <div className="flex flex-col gap-3 px-4 py-3">
            {Array.from({ length: rows }, (_, j) => (
              <div key={j} className="space-y-1.5">
                <Skeleton className="h-4 w-2/3" />
                <Skeleton className="h-3 w-1/2" />
              </div>
            ))}
          </div>
        </Card>
      ))}
    </div>
  );
}

// ── Cards ────────────────────────────────────────────────────────────────────

type RowTone = 'bad' | 'warn' | 'muted';

type CardRow = {
  key: string;
  href: string;
  title: string;
  sub: string | null;
  /** Small text on the right — a date, a countdown. */
  meta?: string | null;
  metaTone?: RowTone;
  /** Chip under the text — what kind of thing the row is (11.2 labels). */
  tag?: string;
  tagTone?: RowTone;
  /** Odbor colour bar on the left (kept next to the avatar, 11.5). */
  section?: Section | null;
  technician?: TerminTechnician | null;
};

type CardSpec = {
  id: string;
  title: string;
  icon: React.ReactNode;
  iconClass: string;
  /** The related section; null when there is none to open. */
  href: string | null;
  danger?: boolean;
  rows: CardRow[];
};

function buildCards(
  data: TodayData,
  tasks: Task[],
  team: TeamIdentity[],
  me: TerminTechnician | null,
  showAvatars: boolean,
): CardSpec[] {
  const today = todayIso();
  const who = (t: TerminTechnician | null | undefined) => (showAvatars ? t ?? null : null);

  const cards: CardSpec[] = [
    {
      id: 'teren',
      title: T.kartaTeren,
      icon: <MapPin className="size-4" />,
      iconClass: 'bg-firol-100 text-firol-600',
      href: `/kalendar?den=${data.date}`,
      rows: data.field.map((r) => fieldRow(r, who)),
    },
    {
      id: 'po-termine',
      title: T.kartaPoTermine,
      icon: <AlertTriangle className="size-4" />,
      iconClass: 'bg-[var(--color-status-bad-bg)] text-[var(--color-status-bad)]',
      href: '/casova-os',
      danger: true,
      rows: data.overdue.map((d) => overdueRow(d, who)),
    },
    {
      id: 'ulohy',
      title: T.kartaUlohy,
      icon: <ListTodo className="size-4" />,
      iconClass: 'bg-[var(--color-status-warn-bg)] text-[var(--color-status-warn)]',
      href: '/ulohy',
      rows: tasks.map((t) => taskRow(t, team, today, who)),
    },
    {
      id: 'koncepty',
      title: T.kartaKoncepty,
      icon: <FilePen className="size-4" />,
      iconClass: 'bg-ink-100 text-ink-600',
      href: '/inspections',
      rows: data.drafts.map((r) => ({
        key: r.key,
        href: ukonHref(r),
        title: ukonLabel(r),
        sub: `${r.company_name} · začaté ${shortDate(r.created_at)}`,
        section: ukonSection(r),
        technician: who(r.technician),
      })),
    },
    {
      id: 'nedostatky',
      title: T.kartaNedostatky,
      icon: <ShieldAlert className="size-4" />,
      iconClass: 'bg-[var(--color-status-bad-bg)] text-[var(--color-status-bad)]',
      // There is no list of nedostatky in the app to open; each row opens
      // the úkon the nedostatok was recorded on.
      href: null,
      rows: data.defects.map((d) => defectRow(d, today, who)),
    },
    {
      id: 'nevyfakturovane',
      title: T.kartaNefakturovane,
      icon: <Receipt className="size-4" />,
      iconClass: 'bg-emerald-100 text-emerald-700',
      href: data.uninvoiced.some((r) => r.kind === 'inspection') || data.uninvoiced.length === 0
        ? `/inspections?${UNINVOICED_PARAM}=1`
        : `/trainings?${UNINVOICED_PARAM}=1`,
      rows: data.uninvoiced.map((r) => ({
        key: r.key,
        href: ukonHref(r),
        title: ukonLabel(r),
        sub: [r.company_name, r.done_on ? shortDate(r.done_on) : null].filter(Boolean).join(' · '),
        section: ukonSection(r),
        technician: who(r.technician),
      })),
    },
    {
      id: 'vlastne-terminy',
      title: T.kartaVlastneTerminy,
      icon: <BadgeCheck className="size-4" />,
      iconClass: 'bg-violet-100 text-violet-600',
      href: data.own_terms.every((t) => t.level === 'firemne') && data.own_terms.length > 0
        ? '/settings/opravnenia'
        : '/settings/profil',
      rows: data.own_terms.map((t) => ownTermRow(t, who(me))),
    },
  ];

  // Rule 8 — an empty card is not rendered at all.
  return cards.filter((c) => c.rows.length > 0);
}

function fieldRow(r: FieldRow, who: (t: TerminTechnician | null) => TerminTechnician | null): CardRow {
  const types = r.types.map((t) => INSPECTION_TYPE_LABELS[t] ?? t).join(', ');
  const place = r.city ?? r.facility_name;
  if (r.kind === 'navsteva') {
    return {
      key: r.key,
      href: `/visits/${r.id}`,
      title: r.company_name ?? '',
      sub: [place, types].filter(Boolean).join(' · ') || null,
      tag: r.status === 'dokoncena' ? 'Návšteva · dokončená' : 'Návšteva',
      technician: who(r.technician),
    };
  }
  if (r.kind === 'plan') {
    return {
      key: r.key,
      href: `/inspections/${r.id}`,
      title: r.company_name ?? '',
      sub: [place, types].filter(Boolean).join(' · ') || null,
      tag: ZDROJ_LABELS.kontrola,
      tagTone: r.status === 'po_termine' ? 'bad' : 'muted',
      section: r.types[0] ? sectionForInspectionType(r.types[0]) : null,
      technician: who(r.technician),
    };
  }
  return {
    key: r.key,
    // A vlastná udalosť has no page of its own — it lives on its calendar day.
    href: `/kalendar?den=${todayIso()}`,
    title: r.title ?? '',
    sub: [r.company_name, place].filter(Boolean).join(' · ') || null,
    tag: ZDROJ_LABELS.vlastny,
    technician: who(r.technician),
  };
}

function overdueRow(d: CalendarDeadline, who: (t: TerminTechnician | null) => TerminTechnician | null): CardRow {
  const late = -daysUntil(d.due_date);
  return {
    key: d.key,
    href: `/inspections/${d.inspection_id}`,
    title: d.company_name,
    sub: [INSPECTION_TYPE_LABELS[d.type] ?? d.type, d.facility_city ?? d.facility_name].join(' · '),
    meta: `−${late} ${dayWord(late)}`,
    metaTone: 'bad',
    section: d.section,
    technician: who(d.technician),
  };
}

function taskRow(
  t: Task,
  team: TeamIdentity[],
  today: string,
  who: (t: TerminTechnician | null) => TerminTechnician | null,
): CardRow {
  const assignee = team.find((m) => m.id === t.assignee_user_id) ?? null;
  return {
    key: `uloha-${t.id}`,
    href: `/ulohy?uloha=${t.id}`,
    title: t.text,
    sub: t.company_name ?? T.vseobecna,
    meta: t.due_date ? shortDate(t.due_date) : null,
    metaTone: t.due_date && t.due_date < today ? 'bad' : 'warn',
    section: t.source ? sectionForInspectionType(t.source.type) : null,
    technician: who(assignee),
  };
}

function defectRow(
  d: OpenDefect,
  today: string,
  who: (t: TerminTechnician | null) => TerminTechnician | null,
): CardRow {
  return {
    key: d.key,
    href: `/inspections/${d.inspection_id}`,
    title: d.description,
    sub: `${d.company_name} · ${INSPECTION_TYPE_LABELS[d.type] ?? d.type}`,
    meta: shortDate(d.deadline),
    metaTone: d.deadline < today ? 'bad' : 'warn',
    section: sectionForInspectionType(d.type),
    technician: who(d.technician),
  };
}

function ownTermRow(t: OwnTerm, technician: TerminTechnician | null): CardRow {
  const days = daysUntil(t.date);
  // title reads „Platnosť oprávnenia — <oprávnenie>"; the card names the
  // oprávnenie and says what is happening to its validity underneath.
  const name = t.title.includes(' — ') ? t.title.slice(t.title.indexOf(' — ') + 3) : t.title;
  const level = t.level === 'firemne' ? 'Firemné oprávnenie' : 'Osobné oprávnenie';
  const when =
    days < 0
      ? `platnosť skončila ${shortDate(t.date)}`
      : days === 0
        ? 'platnosť končí dnes'
        : `uplynie o ${days} ${dayWord(days)}`;
  return {
    key: t.key,
    href: t.level === 'firemne' ? '/settings/opravnenia' : '/settings/profil',
    title: name,
    sub: `${level} · ${when}`,
    meta: shortDate(t.date),
    metaTone: days < 0 ? 'bad' : 'warn',
    section: t.section,
    technician,
  };
}

function TodayCard({ card }: { card: CardSpec }) {
  const [expanded, setExpanded] = useState(false);
  const count = card.rows.length;
  const hidden = count - VISIBLE_ROWS;
  const rows = expanded || hidden <= 0 ? card.rows : card.rows.slice(0, VISIBLE_ROWS);
  const shown = useCountUp(count);

  const headerInner = (
    <>
      <span className={cn('grid size-8 shrink-0 place-items-center rounded-xl', card.iconClass)}>{card.icon}</span>
      <h2 className="min-w-0 flex-1 truncate text-sm font-semibold text-ink-900">{card.title}</h2>
      <span
        className={cn(
          'min-w-7 rounded-full px-2 py-0.5 text-center text-xs font-semibold tabular-nums',
          card.danger ? 'bg-[var(--color-status-bad)] text-white' : 'bg-ink-100 text-ink-600',
        )}
      >
        {shown}
      </span>
      {card.href && (
        <ChevronRight className="size-4 shrink-0 text-ink-300 transition-transform duration-150 group-hover:translate-x-0.5" />
      )}
    </>
  );

  return (
    <Card
      id={`dnes-karta-${card.id}`}
      className={cn(
        'overflow-hidden p-0',
        // Chapter 18: Po termíne gets a red frame once it holds anything —
        // and it is only rendered when it does.
        card.danger && 'border-[var(--color-status-bad)] ring-1 ring-[var(--color-status-bad)]/40',
      )}
    >
      {card.href ? (
        <Link
          to={card.href}
          className="group flex items-center gap-2.5 border-b border-ink-100 px-4 py-3 transition-colors duration-150 hover:bg-ink-50/70 active:bg-ink-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-firol-300"
        >
          {headerInner}
        </Link>
      ) : (
        <div className="flex items-center gap-2.5 border-b border-ink-100 px-4 py-3">{headerInner}</div>
      )}

      <ul className="divide-y divide-ink-50">
        {rows.map((row, i) => (
          <li
            key={row.key}
            className={cn(i >= VISIBLE_ROWS && 'animate-fade-up')}
            style={i >= VISIBLE_ROWS ? { animationDelay: `${(i - VISIBLE_ROWS) * 30}ms` } : undefined}
          >
            <TodayRow row={row} />
          </li>
        ))}
      </ul>

      {hidden > 0 && (
        <button
          type="button"
          onClick={() => setExpanded((v) => !v)}
          aria-expanded={expanded}
          className="flex w-full items-center justify-center border-t border-ink-100 px-4 py-2.5 text-xs font-medium text-firol-600 transition-[background-color,transform] duration-150 hover:bg-firol-50/60 active:scale-[0.99] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-firol-300"
        >
          {expanded ? 'Zobraziť menej' : `+ ${hidden} ${hidden === 1 ? 'ďalší' : hidden < 5 ? 'ďalšie' : 'ďalších'}`}
        </button>
      )}
    </Card>
  );
}

const META_TONE: Record<RowTone, string> = {
  bad: 'text-[var(--color-status-bad)] font-semibold',
  warn: 'text-[var(--color-status-warn)] font-medium',
  muted: 'text-ink-400',
};

function TodayRow({ row }: { row: CardRow }) {
  return (
    <Link
      to={row.href}
      className="relative flex items-center gap-3 py-3 pl-5 pr-4 transition-[background-color,transform] duration-150 hover:bg-ink-50 active:scale-[0.99] active:bg-ink-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-firol-300"
    >
      {row.section && (
        <span
          aria-hidden
          className="absolute inset-y-2.5 left-2 w-1 rounded-full"
          style={{ backgroundColor: SECTION_COLORS[row.section] }}
        />
      )}
      <div className="min-w-0 flex-1">
        <p className="line-clamp-2 text-sm font-medium text-ink-900">{row.title}</p>
        {row.sub && <p className="mt-0.5 line-clamp-2 text-xs text-ink-500">{row.sub}</p>}
        {row.tag && (
          <span
            className={cn(
              'mt-1 inline-block rounded-md px-1.5 py-0.5 text-[11px] font-medium',
              row.tagTone === 'bad'
                ? 'bg-[var(--color-status-bad-bg)] text-[var(--color-status-bad)]'
                : 'bg-ink-100 text-ink-600',
            )}
          >
            {row.tag}
          </span>
        )}
      </div>
      {row.meta && (
        <span className={cn('shrink-0 text-right text-xs tabular-nums', META_TONE[row.metaTone ?? 'muted'])}>
          {row.meta}
        </span>
      )}
      {row.technician && <TechnicianAvatar technician={row.technician} size="sm" />}
    </Link>
  );
}

/** Header counts tween up to their value; instant under reduced motion. */
function useCountUp(value: number): number {
  const [shown, setShown] = useState(0);
  const from = useRef(0);

  useEffect(() => {
    const start = from.current;
    from.current = value;
    if (start === value) {
      setShown(value);
      return;
    }
    let reduce = false;
    try {
      reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    } catch {
      reduce = false;
    }
    if (reduce) {
      setShown(value);
      return;
    }
    const began = performance.now();
    const duration = 500;
    let frame = 0;
    const step = (now: number) => {
      const t = Math.min((now - began) / duration, 1);
      const eased = 1 - Math.pow(1 - t, 3);
      setShown(Math.round(start + (value - start) * eased));
      if (t < 1) frame = requestAnimationFrame(step);
    };
    frame = requestAnimationFrame(step);
    return () => cancelAnimationFrame(frame);
  }, [value]);

  return shown;
}
