import { useEffect, useMemo, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import {
    AlertTriangle,
    Building2,
    CalendarCheck,
    CalendarDays,
    ChartNoAxesGantt,
    MapPin,
} from "lucide-react";
import { useAuth } from "@/auth/AuthContext";
import { Calendar, type CalendarData, type CalendarDeadline } from "@/api/calendar";
import { INSPECTION_TYPE_LABELS } from "@/api/inspections";
import { daysUntil } from "@/lib/calendarGrouping";
import { formatDateSk } from "@/lib/clientNoticeEmail";
import { cn } from "@/lib/cn";
import {
    SECTIONS,
    SECTION_COLORS,
    SECTION_LABELS,
    isSection,
    type Section,
} from "@/lib/sections";
import { useTechnicianFilter } from "@/lib/technicianFilter";
import {
    countdownLabel,
    groupTimeline,
    timelineItems,
    type TimelineGroup,
    type TimelineGroupBy,
} from "@/lib/timeline";
import { TechnicianAvatar } from "@/components/team/TechnicianAvatar";
import { TechnicianFilter } from "@/components/team/TechnicianFilter";
import { Card } from "@/components/ui/Card";
import { Skeleton } from "@/components/ui/Skeleton";
import { PlanEditor, SegmentedControl } from "@/pages/CalendarPage";

type SectionFilter = "all" | Section;

const GROUP_BYS: TimelineGroupBy[] = ["mesiac", "mesto", "firma"];

/** Colour for a deadline whose type maps to no odbor (legacy data). */
const NO_SECTION_COLOR = "#94A3B8";

/** Slovak plural of „termín": 1 termín, 2–4 termíny, 5+ termínov. */
function terminCount(n: number): string {
    if (n === 1) return "1 termín";
    if (n >= 2 && n <= 4) return `${n} termíny`;
    return `${n} termínov`;
}

/**
 * Časová os termínov — chapter 19.
 *
 * The same open client termíny as the calendar, as one list ordered by the
 * nearest due date and grouped by month (default), city or firm; „Po termíne"
 * is always the first group. Filters: odbor, grouping and „Kto" (11.6 — the
 * choice is shared with the calendar). Odbor and grouping live in the URL, so
 * coming back from a firm restores the same view.
 *
 * Only client deadlines (zdroj `kontrola`) are listed: a row needs a firm, a
 * type of úkon and a last control, which the technician's own certificate
 * dates and vlastné udalosti don't have — those stay in the calendar.
 */
export function TimelinePage() {
    const kto = useTechnicianFilter();
    const [searchParams, setSearchParams] = useSearchParams();
    const [data, setData] = useState<CalendarData | null>(null);

    const odborParam = searchParams.get("odbor") ?? undefined;
    const section: SectionFilter = isSection(odborParam) ? odborParam : "all";
    const podlaParam = searchParams.get("podla");
    const groupBy: TimelineGroupBy = GROUP_BYS.includes(
        podlaParam as TimelineGroupBy,
    )
        ? (podlaParam as TimelineGroupBy)
        : "mesiac";

    function setParam(name: string, value: string | null) {
        const next = new URLSearchParams(searchParams);
        if (value) next.set(name, value);
        else next.delete(name);
        setSearchParams(next, { replace: true });
    }

    async function reload() {
        try {
            // Offline this resolves from the cached /api/calendar read.
            setData(await Calendar.get());
        } catch {
            setData((d) => d ?? { deadlines: [], events: [], own_terms: [] });
        }
    }
    useEffect(() => {
        void reload();
    }, []);

    const items = useMemo(
        () => (data ? timelineItems(data.deadlines, section, kto.matches) : []),
        [data, section, kto.matches],
    );
    const groups = useMemo(() => groupTimeline(items, groupBy), [items, groupBy]);

    return (
        <div className="flex flex-col gap-5">
            <header>
                <p className="text-xs font-semibold uppercase tracking-wider text-firol-500">
                    Termíny
                </p>
                <h1 className="mt-1 text-xl font-semibold tracking-tight text-ink-900">
                    Časová os
                </h1>
                <p className="mt-1 text-sm text-ink-500">
                    Zoradené podľa najbližšieho termínu
                    {data !== null && ` · ${terminCount(items.length)}`}
                </p>
            </header>

            <Card className="flex flex-wrap items-center gap-x-4 gap-y-2 p-4">
                <SegmentedControl<SectionFilter>
                    label="Odbor"
                    value={section}
                    onChange={(v) => setParam("odbor", v === "all" ? null : v)}
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
                <SegmentedControl<TimelineGroupBy>
                    label="Zoskupiť"
                    value={groupBy}
                    onChange={(v) =>
                        setParam("podla", v === "mesiac" ? null : v)
                    }
                    options={[
                        { value: "mesiac", label: "Mesiac" },
                        { value: "mesto", label: "Mesto" },
                        { value: "firma", label: "Firma" },
                    ]}
                />
            </Card>

            {data === null ? (
                <TimelineSkeleton />
            ) : groups.length === 0 ? (
                <Card className="animate-fade-in px-4 py-8 text-center text-sm text-ink-400">
                    <ChartNoAxesGantt className="mx-auto mb-2 size-6 text-ink-300" />
                    Žiadne termíny.
                </Card>
            ) : (
                <div
                    key={`${groupBy}-${section}-${String(kto.value)}`}
                    className="flex flex-col gap-3"
                >
                    {groups.map((g, i) => (
                        <TimelineGroupCard
                            key={g.key}
                            group={g}
                            groupBy={groupBy}
                            index={i}
                            onChanged={reload}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}

function TimelineGroupCard({
    group,
    groupBy,
    index,
    onChanged,
}: {
    group: TimelineGroup;
    groupBy: TimelineGroupBy;
    index: number;
    onChanged: () => Promise<void>;
}) {
    const Icon = group.overdue
        ? AlertTriangle
        : groupBy === "mesto"
          ? MapPin
          : groupBy === "firma"
            ? Building2
            : CalendarDays;

    return (
        <Card
            className={cn(
                "animate-fade-up overflow-hidden",
                group.overdue && "border-[var(--color-status-bad)]/40",
            )}
            style={{ animationDelay: `${Math.min(index, 10) * 40}ms` }}
        >
            <div className="flex items-center gap-3 border-b border-ink-100 px-4 py-3">
                <span
                    className={cn(
                        "grid size-9 shrink-0 place-items-center rounded-xl",
                        group.overdue
                            ? "bg-[var(--color-status-bad-bg)] text-status-bad"
                            : "bg-ink-50 text-ink-500",
                    )}
                >
                    <Icon className="size-4" />
                </span>
                <span className="min-w-0 flex-1">
                    <span
                        className={cn(
                            "block truncate text-sm font-semibold",
                            group.overdue ? "text-status-bad" : "text-ink-900",
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
                <span className="shrink-0 text-xs font-medium tabular-nums text-ink-500">
                    {terminCount(group.items.length)}
                </span>
            </div>
            <ul className="divide-y divide-ink-50">
                {group.items.map((d) => (
                    <li key={d.key}>
                        <TimelineRow deadline={d} onChanged={onChanged} />
                    </li>
                ))}
            </ul>
        </Card>
    );
}

/**
 * One termín: firm, type of úkon, city, last control and the countdown. The
 * odbor is the bar on the left, the technician the circle on the right — both
 * always visible (11.5). Tapping the row opens the firm; „Naplánovať" sets the
 * day it shows on in the calendar.
 */
function TimelineRow({
    deadline: d,
    onChanged,
}: {
    deadline: CalendarDeadline;
    onChanged: () => Promise<void>;
}) {
    const { csrfToken } = useAuth();
    const [planning, setPlanning] = useState(false);
    const overdue = d.state === "po_termine";
    const days = daysUntil(d.due_date);
    const soon = !overdue && days <= 30;
    const where = [d.facility_city, d.facility_name].filter(Boolean).join(" · ");

    return (
        <div
            className={cn(
                "relative flex flex-col gap-2 py-3 pl-5 pr-4",
                overdue && "bg-[var(--color-status-bad-bg)]/50",
            )}
        >
            <span
                aria-hidden
                title={d.section ? SECTION_LABELS[d.section] : undefined}
                className="absolute inset-y-2 left-2 w-1 rounded-full"
                style={{
                    backgroundColor: d.section
                        ? SECTION_COLORS[d.section]
                        : NO_SECTION_COLOR,
                }}
            />
            <div className="flex items-start gap-3">
                <Link
                    to={`/companies/${d.company_id}`}
                    className="group min-w-0 flex-1 rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300"
                >
                    <p className="truncate text-sm font-semibold text-ink-900 transition-colors duration-150 group-hover:text-firol-600">
                        {d.company_name}
                    </p>
                    <p className="text-sm text-ink-700">
                        {INSPECTION_TYPE_LABELS[d.type]}
                    </p>
                    {where && (
                        <p className="truncate text-xs text-ink-500">{where}</p>
                    )}
                    <p className="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-ink-500">
                        <span
                            className={cn(
                                "rounded-full px-2 py-0.5 text-[11px] font-semibold tabular-nums",
                                overdue
                                    ? "bg-[var(--color-status-bad-bg)] text-status-bad"
                                    : soon
                                      ? "bg-[var(--color-status-warn-bg)] text-status-warn"
                                      : "bg-ink-100 text-ink-600",
                            )}
                        >
                            {countdownLabel(d.due_date)}
                        </span>
                        <span className="tabular-nums">
                            termín {formatDateSk(d.due_date)}
                        </span>
                        <span className="tabular-nums text-ink-400">
                            posledná kontrola {formatDateSk(d.last_done_on)}
                        </span>
                    </p>
                </Link>
                <div className="flex shrink-0 flex-col items-end gap-2">
                    <TechnicianAvatar technician={d.technician} />
                    <button
                        type="button"
                        aria-expanded={planning}
                        onClick={() => setPlanning((v) => !v)}
                        className="rounded-lg px-2 py-1 text-xs font-medium text-firol-600 transition-[background-color,transform] duration-150 hover:bg-firol-50 active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300"
                    >
                        {d.planned_date ? "Zmeniť plán" : "Naplánovať"}
                    </button>
                </div>
            </div>
            {d.planned_date && !planning && (
                <Link
                    to={`/kalendar?den=${d.planned_date}`}
                    className="inline-flex w-fit items-center gap-1.5 rounded-lg bg-firol-50 px-2 py-1 text-xs font-medium text-firol-700 transition-[background-color,transform] duration-150 hover:bg-firol-100 active:scale-[0.98]"
                >
                    <CalendarCheck className="size-3.5" />
                    Naplánované v kalendári na {formatDateSk(d.planned_date)}
                </Link>
            )}
            {planning && (
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

/** Mirrors a month group with its rows while the termíny load. */
function TimelineSkeleton() {
    return (
        <div className="flex flex-col gap-3" aria-busy>
            {[3, 2].map((rows, i) => (
                <Card key={i} className="overflow-hidden">
                    <div className="flex items-center gap-3 border-b border-ink-100 px-4 py-3">
                        <Skeleton className="size-9 shrink-0 rounded-xl" />
                        <Skeleton className="h-4 w-32" />
                        <Skeleton className="ml-auto h-3 w-14" />
                    </div>
                    {Array.from({ length: rows }, (_, r) => (
                        <div
                            key={r}
                            className="flex items-start gap-3 border-b border-ink-50 py-3 pl-5 pr-4 last:border-b-0"
                        >
                            <div className="min-w-0 flex-1 space-y-2">
                                <Skeleton className="h-4 w-2/3" />
                                <Skeleton className="h-3 w-1/2" />
                                <Skeleton className="h-4 w-3/4 rounded-full" />
                            </div>
                            <Skeleton className="size-6 shrink-0 rounded-full" />
                        </div>
                    ))}
                </Card>
            ))}
        </div>
    );
}
