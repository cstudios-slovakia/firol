import { useEffect, useMemo, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import {
    AlertTriangle,
    BadgeCheck,
    BellRing,
    Building2,
    CalendarDays,
    CalendarPlus,
    CheckCircle2,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Mail,
    MapPin,
    Pencil,
    Plus,
    Trash2,
    Warehouse,
    X,
} from "lucide-react";
import { useAuth, type User } from "@/auth/AuthContext";
import {
    Calendar,
    ZDROJ_LABELS,
    type CalendarData,
    type CalendarDeadline,
    type CalendarEvent,
    type CalendarEventInput,
    type TerminTechnician,
} from "@/api/calendar";
import {
    Companies,
    type CompanyListItem,
    type FacilityListItem,
} from "@/api/companies";
import { INSPECTION_TYPE_LABELS } from "@/api/inspections";
import { ApiError } from "@/lib/api";
import { useToast } from "@/lib/toast";
import { useConfirm } from "@/lib/confirm";
import { cn } from "@/lib/cn";
import {
    daysUntil,
    groupByFacilityDay,
    groupTerminy,
    toTerminy,
    type FacilityDayGroup,
    type GroupBy,
    type Termin,
    type TerminGroup,
} from "@/lib/calendarGrouping";
import {
    buildClientNotice,
    formatDateSk,
    mailtoUrl,
} from "@/lib/clientNoticeEmail";
import {
    SECTIONS,
    SECTION_COLORS,
    SECTION_LABELS,
    type Section,
} from "@/lib/sections";
import { useTechnicianFilter } from "@/lib/technicianFilter";
import { TechnicianAvatar } from "@/components/team/TechnicianAvatar";
import { TechnicianFilter } from "@/components/team/TechnicianFilter";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { Field } from "@/components/ui/Field";
import { Select } from "@/components/ui/Select";
import { Spinner } from "@/components/ui/Spinner";
import { Dialog } from "@/components/ui/Dialog";

const WEEKDAYS = ["Po", "Ut", "St", "Št", "Pi", "So", "Ne"];
const MONTHS = [
    "Január",
    "Február",
    "Marec",
    "Apríl",
    "Máj",
    "Jún",
    "Júl",
    "August",
    "September",
    "Október",
    "November",
    "December",
];

/** Colour of a vlastná udalosť — it belongs to no odbor. */
const EVENT_COLOR = "#94A3B8";

type SectionFilter = "all" | Section;

function iso(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}
function todayIso(): string {
    return iso(new Date());
}
/** `?den=YYYY-MM-DD` deep link (from the dashboard „Termíny" block). */
function dayFromParam(value: string | null): string | null {
    return value && /^\d{4}-\d{2}-\d{2}$/.test(value) ? value : null;
}

/** Slovak plural of „termín": 1 termín, 2–4 termíny, 5+ termínov. */
function terminCount(n: number): string {
    if (n === 1) return "1 termín";
    if (n < 5) return `${n} termíny`;
    return `${n} termínov`;
}
/** 1 deň, 2–4 dni, 5+ dní. */
function dayCount(n: number): string {
    if (n === 1) return "1 deň";
    if (n < 5) return `${n} dni`;
    return `${n} dní`;
}

function colorOf(t: Termin): string {
    return t.section ? SECTION_COLORS[t.section] : EVENT_COLOR;
}

/**
 * Kalendár (change request 2.5, chapter 11).
 *
 * A month at a time: a grid on wider screens as an overview, and under it the
 * month's termíny grouped by firm (or by city) — a firm with four termíny in
 * the month is ONE group with a count, expanded on tap (11.1). Colour is the
 * odbor's; the technician is the circle with initials on the right (11.5).
 * Po termíne always comes first and in red; splnené are greyed. The
 * technician's own certificate dates are listed apart from client termíny.
 * Filters: odbor, and „Kto" for teams (11.6); both combine.
 */
export function CalendarPage() {
    const { csrfToken, user, team } = useAuth();
    const toast = useToast();
    const confirm = useConfirm();
    const kto = useTechnicianFilter();

    const [data, setData] = useState<CalendarData | null>(null);

    const [searchParams, setSearchParams] = useSearchParams();
    const dayParam = dayFromParam(searchParams.get("den"));
    const initial = new Date((dayParam ?? todayIso()) + "T00:00:00");

    const [viewYear, setViewYear] = useState(initial.getFullYear());
    const [viewMonth, setViewMonth] = useState(initial.getMonth());
    const [section, setSection] = useState<SectionFilter>("all");
    const [groupBy, setGroupBy] = useState<GroupBy>("firma");
    const [showEventForm, setShowEventForm] = useState(false);
    const [editingEvent, setEditingEvent] = useState<CalendarEvent | null>(
        null,
    );

    async function reload() {
        try {
            setData(await Calendar.get());
        } catch {
            // Non-blocking: keep whatever we had.
            setData((d) => d ?? { deadlines: [], events: [], own_terms: [] });
        }
    }
    useEffect(() => {
        void reload();
    }, []);

    // Follow the deep link when it changes while the page stays mounted.
    useEffect(() => {
        if (!dayParam) return;
        const d = new Date(dayParam + "T00:00:00");
        setViewYear(d.getFullYear());
        setViewMonth(d.getMonth());
    }, [dayParam]);

    function setDay(day: string | null) {
        const next = new URLSearchParams(searchParams);
        if (day) next.set("den", day);
        else next.delete("den");
        setSearchParams(next, { replace: true });
    }

    function shiftMonth(delta: number) {
        const d = new Date(viewYear, viewMonth + delta, 1);
        setViewYear(d.getFullYear());
        setViewMonth(d.getMonth());
        setDay(null);
    }

    const me: TerminTechnician | null = useMemo(() => {
        if (!user) return null;
        const m = team.find((t) => t.id === user.id);
        return m
            ? {
                  id: m.id,
                  fullname: m.fullname,
                  initials: m.initials,
                  avatar_color: m.avatar_color,
              }
            : null;
    }, [team, user]);

    // Every termín that passes the odbor and technician filters.
    const visible = useMemo(() => {
        if (!data) return [];
        return toTerminy(data, me).filter(
            (t) =>
                (section === "all" || t.section === section) &&
                kto.matches(t.technician?.id),
        );
    }, [data, me, section, kto]);

    const monthPrefix = `${viewYear}-${String(viewMonth + 1).padStart(2, "0")}`;
    const isCurrentMonth = todayIso().startsWith(monthPrefix);

    const inMonth = useMemo(
        () => visible.filter((t) => t.date.startsWith(monthPrefix)),
        [visible, monthPrefix],
    );

    // The month's list. In the current month, termíny still po termíne from
    // earlier months are carried in too — they are today's work, and they go
    // on top (11.1).
    const listed = useMemo(() => {
        if (dayParam && dayParam.startsWith(monthPrefix)) {
            return inMonth.filter((t) => t.date === dayParam);
        }
        if (!isCurrentMonth) return inMonth;
        const carried = visible.filter(
            (t) => t.state === "po_termine" && t.date < `${monthPrefix}-01`,
        );
        return [...carried, ...inMonth];
    }, [inMonth, visible, dayParam, monthPrefix, isCurrentMonth]);

    const clientGroups = useMemo(
        () =>
            groupTerminy(
                listed.filter((t) => t.zdroj !== "technik"),
                groupBy,
            ),
        [listed, groupBy],
    );
    const ownTerms = listed.filter((t) => t.zdroj === "technik");

    const byDay = useMemo(() => {
        const m = new Map<string, Termin[]>();
        for (const t of inMonth) {
            const list = m.get(t.date) ?? [];
            list.push(t);
            m.set(t.date, list);
        }
        return m;
    }, [inMonth]);

    const weeks = useMemo(
        () => buildMonthGrid(viewYear, viewMonth),
        [viewYear, viewMonth],
    );

    async function handleDeleteEvent(id: number) {
        const ok = await confirm({
            title: "Zmazať udalosť?",
            description: "Vlastná udalosť bude natrvalo odstránená.",
            confirmLabel: "Zmazať",
        });
        if (!ok) return;
        try {
            await Calendar.deleteEvent(id, csrfToken);
            await reload();
            toast.success("Udalosť zmazaná");
        } catch (err) {
            toast.error(
                err instanceof ApiError ? err.message : "Mazanie zlyhalo.",
            );
        }
    }

    const selectedDay =
        dayParam && dayParam.startsWith(monthPrefix) ? dayParam : null;

    return (
        <div className="flex flex-col gap-5">
            <header className="flex items-center justify-between gap-3">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-wider text-firol-500">
                        Kalendár
                    </p>
                    <h1 className="mt-1 text-xl font-semibold tracking-tight text-ink-900">
                        Termíny
                    </h1>
                </div>
                <Button
                    type="button"
                    leftIcon={<CalendarPlus className="size-4" />}
                    onClick={() => {
                        setEditingEvent(null);
                        setShowEventForm(true);
                    }}
                >
                    Pridať udalosť
                </Button>
            </header>

            <Card className="flex flex-col gap-3 p-4">
                <div className="flex items-center justify-between">
                    <button
                        type="button"
                        onClick={() => shiftMonth(-1)}
                        className="grid size-9 place-items-center rounded-xl text-ink-500 transition-[background-color,transform] duration-150 hover:bg-ink-50 active:scale-95"
                        aria-label="Predchádzajúci mesiac"
                    >
                        <ChevronLeft className="size-5" />
                    </button>
                    <h2
                        key={monthPrefix}
                        className="animate-fade-in text-sm font-semibold text-ink-900"
                    >
                        {MONTHS[viewMonth]} {viewYear}
                    </h2>
                    <button
                        type="button"
                        onClick={() => shiftMonth(1)}
                        className="grid size-9 place-items-center rounded-xl text-ink-500 transition-[background-color,transform] duration-150 hover:bg-ink-50 active:scale-95"
                        aria-label="Nasledujúci mesiac"
                    >
                        <ChevronRight className="size-5" />
                    </button>
                </div>

                <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                    <SegmentedControl<SectionFilter>
                        label="Odbor"
                        value={section}
                        onChange={setSection}
                        options={[
                            { value: "all", label: "Všetko" },
                            ...SECTIONS.map((s) => ({
                                value: s,
                                label: SECTION_LABELS[s],
                                color: SECTION_COLORS[s],
                            })),
                        ]}
                    />
                    <TechnicianFilter filter={kto} />
                    <SegmentedControl<GroupBy>
                        label="Zoskupiť"
                        value={groupBy}
                        onChange={setGroupBy}
                        options={[
                            { value: "firma", label: "Firma" },
                            { value: "mesto", label: "Mesto" },
                        ]}
                    />
                </div>

                {/* The grid is an overview for wider screens; on a phone the
                    grouped list below is the calendar. */}
                <div className="hidden grid-cols-7 gap-1 sm:grid">
                    {WEEKDAYS.map((w) => (
                        <div
                            key={w}
                            className="py-1 text-center text-[11px] font-semibold uppercase text-ink-400"
                        >
                            {w}
                        </div>
                    ))}
                    {weeks.flat().map((cell) => {
                        const dayIso = iso(cell.date);
                        const inViewMonth = cell.date.getMonth() === viewMonth;
                        const items = inViewMonth
                            ? (byDay.get(dayIso) ?? [])
                            : [];
                        const chips = dayChips(items);
                        const isSelected = dayIso === selectedDay;
                        const isToday = dayIso === todayIso();
                        return (
                            <button
                                key={dayIso}
                                type="button"
                                disabled={!inViewMonth}
                                onClick={() =>
                                    setDay(isSelected ? null : dayIso)
                                }
                                className={cn(
                                    "flex min-h-[4rem] w-full min-w-0 flex-col items-center gap-1 rounded-xl border px-1 py-1.5 text-left transition-colors duration-150",
                                    isSelected
                                        ? "border-firol-400 bg-firol-50"
                                        : "border-transparent hover:bg-ink-50",
                                    !inViewMonth && "opacity-30",
                                )}
                            >
                                <span
                                    className={cn(
                                        "grid size-6 shrink-0 place-items-center rounded-full text-xs",
                                        isToday
                                            ? "bg-firol-500 font-semibold text-white"
                                            : "text-ink-700",
                                    )}
                                >
                                    {cell.date.getDate()}
                                </span>
                                <span className="flex w-full min-w-0 flex-col gap-0.5">
                                    {chips.slice(0, 3).map((c) => (
                                        <DayChip key={c.key} chip={c} />
                                    ))}
                                    {chips.length > 3 && (
                                        <span className="text-center text-[9px] font-semibold text-ink-400">
                                            +{chips.length - 3}
                                        </span>
                                    )}
                                </span>
                            </button>
                        );
                    })}
                </div>
            </Card>

            <EventForm
                open={showEventForm}
                initial={editingEvent}
                defaultDate={selectedDay ?? todayIso()}
                csrfToken={csrfToken}
                onClose={() => setShowEventForm(false)}
                onSaved={async () => {
                    setShowEventForm(false);
                    await reload();
                }}
            />

            {selectedDay && (
                <div className="flex items-center gap-2 px-1">
                    <span className="inline-flex items-center gap-1.5 rounded-full bg-firol-50 py-1 pl-3 pr-1 text-xs font-medium text-firol-700">
                        {formatDateSk(selectedDay)}
                        <button
                            type="button"
                            onClick={() => setDay(null)}
                            aria-label="Zobraziť celý mesiac"
                            className="grid size-5 place-items-center rounded-full transition-colors hover:bg-firol-100"
                        >
                            <X className="size-3" />
                        </button>
                    </span>
                </div>
            )}

            {data === null ? (
                <div className="flex justify-center py-8 text-ink-400">
                    <Spinner />
                </div>
            ) : clientGroups.length === 0 && ownTerms.length === 0 ? (
                <Card className="px-4 py-8 text-center text-sm text-ink-400">
                    <CalendarDays className="mx-auto mb-2 size-6 text-ink-300" />
                    {selectedDay
                        ? `${formatDateSk(selectedDay)} — žiadne termíny.`
                        : "V tomto mesiaci nie sú žiadne termíny."}
                </Card>
            ) : (
                <div
                    key={`${monthPrefix}-${groupBy}-${section}-${String(kto.value)}-${selectedDay ?? ""}`}
                    className="flex flex-col gap-3"
                >
                    {clientGroups.map((g, i) => (
                        <TerminGroupCard
                            key={g.key}
                            group={g}
                            groupBy={groupBy}
                            index={i}
                            user={user}
                            csrfToken={csrfToken}
                            onChanged={reload}
                            onEditEvent={(e) => {
                                setEditingEvent(e);
                                setShowEventForm(true);
                            }}
                            onDeleteEvent={handleDeleteEvent}
                        />
                    ))}
                    {ownTerms.length > 0 && <OwnTermsCard items={ownTerms} />}
                </div>
            )}
        </div>
    );
}

// ─── Filters ─────────────────────────────────────────────────────────────────

function SegmentedControl<T extends string>({
    label,
    value,
    onChange,
    options,
}: {
    label: string;
    value: T;
    onChange: (v: T) => void;
    options: { value: T; label: string; color?: string }[];
}) {
    return (
        <div className="flex items-center gap-2">
            <span className="text-xs font-semibold uppercase tracking-wider text-ink-500">
                {label}
            </span>
            <div className="flex rounded-xl bg-ink-50 p-0.5">
                {options.map((o) => {
                    const active = o.value === value;
                    return (
                        <button
                            key={o.value}
                            type="button"
                            aria-pressed={active}
                            onClick={() => onChange(o.value)}
                            className={cn(
                                "inline-flex h-8 items-center gap-1.5 rounded-[10px] px-2.5 text-xs font-medium transition-[background-color,color,box-shadow,transform] duration-150 active:scale-[0.97]",
                                "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300",
                                active
                                    ? "bg-white text-ink-900 shadow-sm"
                                    : "text-ink-500 hover:text-ink-800",
                            )}
                        >
                            {o.color && (
                                <span
                                    className="size-2 rounded-full"
                                    style={{ backgroundColor: o.color }}
                                />
                            )}
                            {o.label}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

// ─── Month grid ──────────────────────────────────────────────────────────────

type Chip = {
    key: string;
    label: string;
    count: number;
    colors: string[];
    overdue: boolean;
    done: boolean;
    own: boolean;
    event: boolean;
};

/**
 * One chip per firm per day (a firm with three termíny that day is one chip
 * with a count); own terms and firm-less events get a chip each.
 */
function dayChips(items: Termin[]): Chip[] {
    const map = new Map<string, Chip>();
    for (const t of items) {
        const key =
            t.zdroj === "technik"
                ? t.key
                : t.company_id !== null
                  ? `c${t.company_id}`
                  : t.key;
        const label =
            t.zdroj === "technik"
                ? ZDROJ_LABELS.technik
                : (t.company_name ?? t.event?.title ?? "");
        let c = map.get(key);
        if (!c) {
            c = {
                key,
                label,
                count: 0,
                colors: [],
                overdue: false,
                done: true,
                own: t.zdroj === "technik",
                event: t.zdroj === "vlastny",
            };
            map.set(key, c);
        }
        c.count++;
        const color = colorOf(t);
        if (!c.colors.includes(color)) c.colors.push(color);
        c.overdue = c.overdue || t.state === "po_termine";
        c.done = c.done && t.state === "splneny";
        c.event = c.event && t.zdroj === "vlastny";
    }
    return [...map.values()].sort(
        (a, b) =>
            Number(b.overdue) - Number(a.overdue) ||
            Number(a.done) - Number(b.done) ||
            a.label.localeCompare(b.label, "sk"),
    );
}

function DayChip({ chip }: { chip: Chip }) {
    return (
        <span
            title={chip.count > 1 ? `${chip.label} (${chip.count})` : chip.label}
            className={cn(
                "flex w-full min-w-0 items-center gap-1 rounded border-l-[3px] px-1 py-px text-[9px] font-semibold leading-tight",
                chip.own && "border border-dashed border-l-[3px]",
                chip.overdue
                    ? "bg-[var(--color-status-bad-bg)] text-status-bad"
                    : chip.done
                      ? "bg-ink-50 text-ink-400 line-through"
                      : "bg-ink-50 text-ink-700",
            )}
            style={{ borderLeftColor: chip.colors[0] }}
        >
            {chip.own && <BadgeCheck className="size-2.5 shrink-0" />}
            <span className="truncate">{chip.label}</span>
            {chip.count > 1 && (
                <span className="ml-auto shrink-0 tabular-nums">
                    {chip.count}
                </span>
            )}
        </span>
    );
}

// ─── Grouped list ────────────────────────────────────────────────────────────

function TerminGroupCard({
    group,
    groupBy,
    index,
    user,
    csrfToken,
    onChanged,
    onEditEvent,
    onDeleteEvent,
}: {
    group: TerminGroup;
    groupBy: GroupBy;
    index: number;
    user: User | null;
    csrfToken: string | null;
    onChanged: () => Promise<void>;
    onEditEvent: (e: CalendarEvent) => void;
    onDeleteEvent: (id: number) => void;
}) {
    // A single termín needs no expanding; a group of several opens on tap.
    const [open, setOpen] = useState(group.items.length === 1);
    const allDone = group.items.every((t) => t.state === "splneny");

    const notifyGroups = groupByFacilityDay(
        group.items
            .map((t) => t.deadline)
            .filter(
                (d): d is CalendarDeadline =>
                    d !== undefined && d.state !== "splneny",
            ),
    );

    return (
        <Card
            className={cn(
                "animate-fade-up overflow-hidden",
                group.overdue > 0 && "border-[var(--color-status-bad)]/40",
                allDone && "opacity-70",
            )}
            style={{ animationDelay: `${Math.min(index, 10) * 40}ms` }}
        >
            <button
                type="button"
                aria-expanded={open}
                onClick={() => setOpen((v) => !v)}
                className="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors duration-150 hover:bg-ink-50/60"
            >
                <span
                    className={cn(
                        "grid size-9 shrink-0 place-items-center rounded-xl",
                        group.overdue > 0
                            ? "bg-[var(--color-status-bad-bg)] text-status-bad"
                            : "bg-ink-50 text-ink-500",
                    )}
                >
                    {groupBy === "firma" ? (
                        <Building2 className="size-4" />
                    ) : (
                        <MapPin className="size-4" />
                    )}
                </span>
                <span className="min-w-0 flex-1">
                    <span
                        className={cn(
                            "block truncate text-sm font-semibold",
                            allDone ? "text-ink-500" : "text-ink-900",
                        )}
                    >
                        {group.label}
                    </span>
                    {group.sublabel && (
                        <span className="block truncate text-xs text-ink-500">
                            {group.sublabel}
                        </span>
                    )}
                </span>
                <span className="flex shrink-0 items-center gap-2">
                    <span className="flex gap-0.5">
                        {group.sections.map((s) => (
                            <span
                                key={s}
                                title={SECTION_LABELS[s]}
                                className="size-2 rounded-full"
                                style={{ backgroundColor: SECTION_COLORS[s] }}
                            />
                        ))}
                    </span>
                    {group.overdue > 0 && (
                        <span className="rounded-full bg-[var(--color-status-bad-bg)] px-2 py-0.5 text-[11px] font-semibold text-status-bad">
                            Po termíne: {group.overdue}
                        </span>
                    )}
                    <span className="text-xs font-medium tabular-nums text-ink-500">
                        {terminCount(group.items.length)}
                    </span>
                    <ChevronDown
                        className={cn(
                            "size-4 text-ink-300 transition-transform duration-200",
                            open && "rotate-180",
                        )}
                    />
                </span>
            </button>

            {open && (
                <div className="animate-fade-in border-t border-ink-100">
                    <ul className="divide-y divide-ink-50">
                        {group.items.map((t) => (
                            <li key={t.key}>
                                <TerminRow
                                    termin={t}
                                    groupBy={groupBy}
                                    csrfToken={csrfToken}
                                    onChanged={onChanged}
                                    onEditEvent={onEditEvent}
                                    onDeleteEvent={onDeleteEvent}
                                />
                            </li>
                        ))}
                    </ul>
                    {notifyGroups.map((g) => (
                        <NotifyClientButton
                            key={g.key}
                            group={g}
                            user={user}
                            showWhere={notifyGroups.length > 1}
                        />
                    ))}
                </div>
            )}
        </Card>
    );
}

/**
 * One termín. The odbor colour is the bar on the left, the technician the
 * avatar on the right — both always visible (11.5).
 */
function TerminRow({
    termin: t,
    groupBy,
    csrfToken,
    onChanged,
    onEditEvent,
    onDeleteEvent,
}: {
    termin: Termin;
    groupBy: GroupBy;
    csrfToken: string | null;
    onChanged: () => Promise<void>;
    onEditEvent: (e: CalendarEvent) => void;
    onDeleteEvent: (id: number) => void;
}) {
    const [planning, setPlanning] = useState(false);
    const d = t.deadline;
    const e = t.event;
    const overdue = t.state === "po_termine";
    const done = t.state === "splneny";

    const title = d ? INSPECTION_TYPE_LABELS[d.type] : (e?.title ?? "");
    const where =
        groupBy === "firma"
            ? [t.facility_name, t.city].filter(Boolean).join(" · ")
            : [t.company_name, t.facility_name].filter(Boolean).join(" — ");

    return (
        <div
            className={cn(
                "relative flex flex-col gap-2 py-3 pl-5 pr-4",
                overdue && "bg-[var(--color-status-bad-bg)]/50",
                done && "opacity-60",
            )}
        >
            <span
                aria-hidden
                className="absolute inset-y-2 left-2 w-1 rounded-full"
                style={{ backgroundColor: colorOf(t) }}
            />
            <div className="flex items-start gap-3">
                <div className="min-w-0 flex-1">
                    {d ? (
                        <Link
                            to={`/inspections/${d.inspection_id}`}
                            className={cn(
                                "text-sm font-medium transition-colors hover:text-firol-600",
                                done ? "text-ink-500" : "text-ink-900",
                            )}
                        >
                            {title}
                        </Link>
                    ) : (
                        <p className="text-sm font-medium text-ink-900">
                            {title}
                        </p>
                    )}
                    {where && (
                        <p className="truncate text-xs text-ink-500">{where}</p>
                    )}
                    <p className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-ink-500">
                        <span className="rounded-md bg-ink-100 px-1.5 py-0.5 text-[11px] font-medium text-ink-600">
                            {ZDROJ_LABELS[t.zdroj]}
                        </span>
                        {d ? (
                            <DeadlineDates deadline={d} />
                        ) : (
                            <span>{formatDateSk(t.date)}</span>
                        )}
                    </p>
                    {e?.note && (
                        <p className="mt-1 text-xs text-ink-600">{e.note}</p>
                    )}
                    {overdue && (
                        <p className="mt-1 flex items-center gap-1 text-xs font-semibold text-status-bad">
                            <AlertTriangle className="size-3.5" />
                            Po termíne · {dayCount(-daysUntil(d?.due_date ?? t.date))}
                        </p>
                    )}
                    {done && d?.done_on && (
                        <p className="mt-1 flex items-center gap-1 text-xs text-ink-500">
                            <CheckCircle2 className="size-3.5" />
                            Splnené {formatDateSk(d.done_on)}
                        </p>
                    )}
                    {d?.notice_sent_at && (
                        <p className="mt-1 flex items-center gap-1 text-xs text-ink-500">
                            <BellRing className="size-3.5" />
                            Klient upozornený{" "}
                            {formatDateSk(d.notice_sent_at.slice(0, 10))}
                        </p>
                    )}
                </div>
                <div className="flex shrink-0 items-center gap-1.5">
                    {d && !done && (
                        <button
                            type="button"
                            onClick={() => setPlanning((v) => !v)}
                            className="rounded-lg px-2 py-1 text-xs font-medium text-firol-600 transition-[background-color,transform] duration-150 hover:bg-firol-50 active:scale-95"
                        >
                            {d.planned_date ? "Zmeniť plán" : "Naplánovať"}
                        </button>
                    )}
                    {e && (
                        <>
                            <button
                                type="button"
                                onClick={() => onEditEvent(e)}
                                aria-label="Upraviť"
                                className="grid size-8 place-items-center rounded-lg text-ink-400 transition-colors hover:bg-ink-50 hover:text-ink-700"
                            >
                                <Pencil className="size-4" />
                            </button>
                            <button
                                type="button"
                                onClick={() => onDeleteEvent(e.id)}
                                aria-label="Zmazať"
                                className="grid size-8 place-items-center rounded-lg text-status-bad transition-colors hover:bg-[var(--color-status-bad-bg)]"
                            >
                                <Trash2 className="size-4" />
                            </button>
                        </>
                    )}
                    <TechnicianAvatar technician={t.technician} />
                </div>
            </div>
            {d && planning && (
                <PlanEditor
                    deadline={d}
                    csrfToken={csrfToken}
                    onDone={async () => {
                        setPlanning(false);
                        await onChanged();
                    }}
                />
            )}
        </div>
    );
}

function DeadlineDates({ deadline: d }: { deadline: CalendarDeadline }) {
    return (
        <>
            <span>{formatDateSk(d.due_date)}</span>
            {d.planned_date && (
                <span className="font-medium text-ink-700">
                    plán {formatDateSk(d.planned_date)}
                </span>
            )}
            <span className="text-ink-400">
                naposledy {formatDateSk(d.last_done_on)}
            </span>
        </>
    );
}

function PlanEditor({
    deadline,
    csrfToken,
    onDone,
}: {
    deadline: CalendarDeadline;
    csrfToken: string | null;
    onDone: () => Promise<void>;
}) {
    const toast = useToast();
    const [planned, setPlanned] = useState(deadline.planned_date ?? "");
    const [saving, setSaving] = useState(false);
    const overshoot = planned !== "" && planned > deadline.due_date;

    async function save() {
        if (!planned) return;
        setSaving(true);
        try {
            await Calendar.setPlan(deadline.inspection_id, planned, csrfToken);
            await onDone();
            toast.success("Plánovaný dátum uložený");
        } catch (err) {
            toast.error(
                err instanceof ApiError ? err.message : "Uloženie zlyhalo.",
            );
        } finally {
            setSaving(false);
        }
    }
    async function clearPlan() {
        setSaving(true);
        try {
            await Calendar.clearPlan(deadline.inspection_id, csrfToken);
            await onDone();
            toast.success("Plánovaný dátum zrušený");
        } catch (err) {
            toast.error(
                err instanceof ApiError ? err.message : "Operácia zlyhala.",
            );
        } finally {
            setSaving(false);
        }
    }

    return (
        <div className="animate-fade-in flex flex-col gap-2 rounded-xl bg-ink-50 p-3">
            <label className="text-xs font-semibold uppercase tracking-wide text-ink-500">
                Plánovaný dátum návštevy
            </label>
            <Input
                type="date"
                value={planned}
                onChange={(e) => setPlanned(e.target.value)}
                aria-label="Plánovaný dátum návštevy"
            />
            {overshoot && (
                <p className="flex items-center gap-1.5 text-xs text-status-warn">
                    <AlertTriangle className="size-3.5" />
                    Plánovaný dátum je po predpripravenom termíne.
                </p>
            )}
            <div className="flex items-center gap-2">
                <Button
                    type="button"
                    onClick={save}
                    loading={saving}
                    disabled={!planned}
                    className="flex-1"
                >
                    Uložiť plán
                </Button>
                {deadline.planned_date && (
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={clearPlan}
                        loading={saving}
                    >
                        Zrušiť plán
                    </Button>
                )}
            </div>
        </div>
    );
}

/**
 * „Tvoje termíny" — the technician's own certificate dates, kept apart from
 * client termíny and drawn differently (dashed, with a badge icon).
 */
function OwnTermsCard({ items }: { items: Termin[] }) {
    return (
        <Card className="animate-fade-up overflow-hidden border-dashed">
            <div className="flex items-center gap-3 border-b border-dashed border-ink-200 px-4 py-3">
                <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-violet-50 text-violet-600">
                    <BadgeCheck className="size-4" />
                </span>
                <span className="flex-1 text-sm font-semibold text-ink-900">
                    Tvoje termíny
                </span>
                <span className="text-xs font-medium tabular-nums text-ink-500">
                    {terminCount(items.length)}
                </span>
            </div>
            <ul className="divide-y divide-ink-50">
                {items.map((t) => {
                    const overdue = t.state === "po_termine";
                    const days = daysUntil(t.date);
                    return (
                        <li
                            key={t.key}
                            className={cn(
                                "relative flex items-center gap-3 py-3 pl-5 pr-4",
                                overdue && "bg-[var(--color-status-bad-bg)]/50",
                            )}
                        >
                            <span
                                aria-hidden
                                className="absolute inset-y-2 left-2 w-1 rounded-full border border-dashed"
                                style={{ borderColor: colorOf(t) }}
                            />
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-medium text-ink-900">
                                    {t.own?.title}
                                </p>
                                <p className="mt-1 flex flex-wrap items-center gap-x-2 text-xs text-ink-500">
                                    <span className="rounded-md border border-dashed border-ink-300 px-1.5 py-0.5 text-[11px] font-medium text-ink-600">
                                        {ZDROJ_LABELS.technik}
                                    </span>
                                    <span>{formatDateSk(t.date)}</span>
                                    <span
                                        className={cn(
                                            overdue
                                                ? "font-semibold text-status-bad"
                                                : "text-ink-400",
                                        )}
                                    >
                                        {overdue
                                            ? `uplynulo pred ${dayCount(-days)}`
                                            : days === 0
                                              ? "uplynie dnes"
                                              : `uplynie o ${dayCount(days)}`}
                                    </span>
                                </p>
                            </div>
                            <TechnicianAvatar technician={t.technician} />
                        </li>
                    );
                })}
            </ul>
        </Card>
    );
}

/**
 * „Oznámiť klientovi e-mailom" (11.3, manual). One message per prevádzka and
 * day covers every control due there. The app does not send it — the link
 * hands the pre-filled text to the technician's own mail client, where it can
 * still be edited. Without a contact e-mail the message opens with an empty
 * recipient.
 */
function NotifyClientButton({
    group,
    user,
    showWhere,
}: {
    group: FacilityDayGroup;
    user: User | null;
    showWhere: boolean;
}) {
    const notice = buildClientNotice(group, {
        fullname: user?.fullname ?? "",
        phone: user?.phone ?? null,
    });

    return (
        <div className="border-t border-ink-100 bg-ink-50/50 px-4 py-3">
            {showWhere && (
                <p className="mb-1.5 text-xs text-ink-500">
                    {group.facility_name} · {formatDateSk(group.date)}
                </p>
            )}
            <a
                href={mailtoUrl(notice)}
                className={cn(
                    "inline-flex h-9 items-center justify-center gap-1.5 rounded-xl border border-ink-200 bg-white px-3",
                    "text-sm font-medium text-ink-800 transition-[background-color,border-color,transform] duration-200",
                    "hover:border-ink-300 hover:bg-ink-50 active:scale-[0.99] active:bg-ink-100",
                    "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300 focus-visible:ring-offset-2",
                )}
            >
                <Mail className="size-4" />
                <span>Oznámiť klientovi e-mailom</span>
            </a>
            {!notice.to && (
                <p className="mt-2 text-xs text-ink-500">
                    Firma nemá kontaktný e-mail — správa sa otvorí s prázdnym
                    adresátom.{" "}
                    <Link
                        to={`/companies/${group.company_id}/edit`}
                        className="font-medium text-firol-600 transition-colors hover:text-firol-700"
                    >
                        Doplniť
                    </Link>
                </p>
            )}
        </div>
    );
}

function EventForm({
    open,
    initial,
    defaultDate,
    csrfToken,
    onClose,
    onSaved,
}: {
    open: boolean;
    initial: CalendarEvent | null;
    defaultDate: string;
    csrfToken: string | null;
    onClose: () => void;
    onSaved: () => void;
}) {
    const toast = useToast();
    const [title, setTitle] = useState(initial?.title ?? "");
    const [date, setDate] = useState(initial?.event_date ?? defaultDate);
    const [note, setNote] = useState(initial?.note ?? "");
    const [companyId, setCompanyId] = useState<number | null>(
        initial?.company_id ?? null,
    );
    const [facilityId, setFacilityId] = useState<number | null>(
        initial?.facility_id ?? null,
    );
    const [companies, setCompanies] = useState<CompanyListItem[]>([]);
    const [facilities, setFacilities] = useState<FacilityListItem[]>([]);
    const [loadingFacilities, setLoadingFacilities] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    // Re-seed the form each time it's opened, since it stays mounted
    // (inside the Dialog) instead of being created fresh per open.
    useEffect(() => {
        if (!open) return;
        setTitle(initial?.title ?? "");
        setDate(initial?.event_date ?? defaultDate);
        setNote(initial?.note ?? "");
        setCompanyId(initial?.company_id ?? null);
        setFacilityId(initial?.facility_id ?? null);
        setError(null);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, initial]);

    // Company list — loaded once, lazily, the first time the dialog opens.
    useEffect(() => {
        if (!open || companies.length > 0) return;
        Companies.list()
            .then((res) => setCompanies(res.items))
            .catch(() => {
                // Non-blocking: company/facility stay optional either way.
            });
    }, [open, companies.length]);

    // Facilities follow the picked company; keep the current pick if it's
    // still valid there, otherwise clear it.
    useEffect(() => {
        if (companyId === null) {
            setFacilities([]);
            setFacilityId(null);
            return;
        }
        let cancelled = false;
        setLoadingFacilities(true);
        Companies.show(companyId)
            .then((res) => {
                if (cancelled) return;
                setFacilities(res.facilities);
                setFacilityId((current) =>
                    current !== null &&
                    res.facilities.some((f) => f.id === current)
                        ? current
                        : null,
                );
            })
            .catch(() => {
                if (!cancelled) setFacilities([]);
            })
            .finally(() => {
                if (!cancelled) setLoadingFacilities(false);
            });
        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [companyId]);

    async function submit(e: React.FormEvent) {
        e.preventDefault();
        if (!title.trim() || !date) {
            setError("Doplň názov a dátum.");
            return;
        }
        setSaving(true);
        setError(null);
        const body: CalendarEventInput = {
            title: title.trim(),
            event_date: date,
            note: note.trim() || null,
            company_id: companyId,
            facility_id: facilityId,
        };
        try {
            if (initial) {
                await Calendar.updateEvent(initial.id, body, csrfToken);
            } else {
                await Calendar.createEvent(body, csrfToken);
            }
            toast.success(initial ? "Udalosť upravená" : "Udalosť pridaná");
            onSaved();
        } catch (err) {
            setError(
                err instanceof ApiError ? err.message : "Uloženie zlyhalo.",
            );
        } finally {
            setSaving(false);
        }
    }

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title={initial ? "Upraviť udalosť" : "Nová vlastná udalosť"}
            dismissible={!saving}
        >
            <form onSubmit={submit} className="flex flex-col gap-3" noValidate>
                <Field label="Názov" required>
                    {(p) => (
                        <Input
                            {...p}
                            required
                            value={title}
                            onChange={(e) => setTitle(e.target.value)}
                            placeholder="Stretnutie s klientom, obhliadka…"
                        />
                    )}
                </Field>
                <Field label="Dátum" required>
                    {(p) => (
                        <Input
                            {...p}
                            required
                            type="date"
                            value={date}
                            onChange={(e) => setDate(e.target.value)}
                        />
                    )}
                </Field>
                <Field label="Firma" hint="Voliteľné">
                    {(p) => (
                        <Select
                            id={p.id}
                            value={companyId !== null ? String(companyId) : ""}
                            onChange={(v) =>
                                setCompanyId(v ? Number(v) : null)
                            }
                            placeholder="— nepriradené —"
                            leftIcon={<Building2 className="size-4" />}
                            searchable
                            options={companies.map((c) => ({
                                value: String(c.id),
                                label: c.name,
                            }))}
                        />
                    )}
                </Field>
                <Field label="Prevádzka" hint="Voliteľné">
                    {(p) => (
                        <Select
                            id={p.id}
                            value={
                                facilityId !== null ? String(facilityId) : ""
                            }
                            onChange={(v) =>
                                setFacilityId(v ? Number(v) : null)
                            }
                            disabled={companyId === null || loadingFacilities}
                            placeholder={
                                companyId === null
                                    ? "— najprv vyber firmu —"
                                    : loadingFacilities
                                      ? "Načítavam…"
                                      : "— nepriradené —"
                            }
                            leftIcon={<Warehouse className="size-4" />}
                            searchable
                            options={facilities.map((f) => ({
                                value: String(f.id),
                                label: f.name,
                            }))}
                        />
                    )}
                </Field>
                <Field label="Poznámka" hint="Voliteľné">
                    {(p) => (
                        <textarea
                            id={p.id}
                            rows={2}
                            value={note}
                            onChange={(e) => setNote(e.target.value)}
                            placeholder="Detaily udalosti…"
                            className="w-full rounded-xl border border-ink-200 bg-white px-3 py-2.5 text-sm text-ink-800 placeholder:text-ink-400 transition-colors hover:border-ink-300 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200"
                        />
                    )}
                </Field>
                {error && <p className="text-xs text-status-bad">{error}</p>}
                <div className="flex justify-end gap-2 pt-1">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Zrušiť
                    </Button>
                    <Button
                        type="submit"
                        loading={saving}
                        leftIcon={
                            initial ? undefined : <Plus className="size-4" />
                        }
                    >
                        {initial ? "Uložiť zmeny" : "Pridať udalosť"}
                    </Button>
                </div>
            </form>
        </Dialog>
    );
}

type Cell = { date: Date };

/** Monday-first weeks covering the given month (with leading/trailing days). */
function buildMonthGrid(year: number, month: number): Cell[][] {
    const first = new Date(year, month, 1);
    // JS: 0=Sun … 6=Sat. Convert to Monday-first offset.
    const lead = (first.getDay() + 6) % 7;
    const start = new Date(year, month, 1 - lead);
    const weeks: Cell[][] = [];
    const cursor = new Date(start);
    for (let w = 0; w < 6; w++) {
        const week: Cell[] = [];
        for (let d = 0; d < 7; d++) {
            week.push({ date: new Date(cursor) });
            cursor.setDate(cursor.getDate() + 1);
        }
        weeks.push(week);
        // Stop after we've passed the month and completed a week.
        if (
            cursor.getMonth() !== month &&
            week[6].date.getMonth() !== month &&
            w >= 3
        )
            break;
    }
    return weeks;
}
