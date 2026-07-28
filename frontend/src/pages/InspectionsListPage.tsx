import { useEffect, useMemo, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import {
    Building2,
    CalendarDays,
    ClipboardList,
    Edit2,
    Plus,
    Repeat,
    Search,
    Trash2,
    Warehouse,
} from "lucide-react";
import {
    INSPECTION_TYPE_LABELS,
    Inspections,
    type InspectionListItem,
    type InspectionType,
} from "@/api/inspections";
import { ApiError } from "@/lib/api";
import { cn } from "@/lib/cn";
import { daysUntilNext, getInspectionStatus } from "@/lib/inspectionStatus";
import { InspectionStatusBadge } from "@/components/InspectionStatusBadge";
import { useAuth } from "@/auth/AuthContext";
import { useIsReadOnly } from "@/auth/useIsReadOnly";
import { useToast } from "@/lib/toast";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Dialog } from "@/components/ui/Dialog";
import { Pagination } from "@/components/ui/Pagination";
import { SkeletonList } from "@/components/ui/Skeleton";
import { Spinner } from "@/components/ui/Spinner";

const PAGE_SIZE = 10;

/**
 * List sections, rendered (and paginated) in this order. Drafts are their own
 * group — a concept is unfinished work, not a valid inspection — and sit above
 * "Platné" so they stay reachable without paging past every finalized record.
 */
type GroupKey = "overdue" | "soon" | "drafts" | "valid";

const SECTIONS: { key: GroupKey; label: string; color: string }[] = [
    { key: "overdue", label: "Po termíne", color: "var(--color-status-bad)" },
    { key: "soon", label: "Blíži sa termín", color: "var(--color-status-warn)" },
    { key: "drafts", label: "Koncepty", color: "var(--color-ink-400)" },
    { key: "valid", label: "Platné", color: "var(--color-status-ok)" },
];

/** Which section an inspection belongs to — also the unit of the validity filter. */
function groupKeyFor(it: InspectionListItem): GroupKey {
    const { kind } = getInspectionStatus(it);
    if (kind === "draft") {
        // A concept has no validity yet — it never counts as platná.
        return "drafts";
    }
    if (kind === "overdue") return "overdue";
    if (kind === "soon") return "soon";
    // valid, superseded ("nahradená") and plain entries ("zápis").
    return "valid";
}

const TYPE_CHIPS: [InspectionType, string][] = [
    ["php", "PHP"],
    ["hydranty", "Hydranty"],
    ["oprava_ts_php", "Oprava & TS"],
    ["poziarna_kniha", "Pož. kniha"],
    ["pu_akcieschopnost", "PU – akciesch."],
    ["pu_udrzba", "PU – údržba"],
    ["nudzove_osvetlenie", "Nú. osvetlenie"],
    ["ts_hadic", "TS hadíc"],
    ["pokyn_zatva", "Pokyn — žatva"],
    ["vyradenie", "Vyradenie PHP"],
];

export function InspectionsListPage() {
    const { csrfToken } = useAuth();
    const isReadOnly = useIsReadOnly();
    const toast = useToast();
    const navigate = useNavigate();

    const [items, setItems] = useState<InspectionListItem[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [query, setQuery] = useState("");
    const [typeFilter, setTypeFilter] = useState<InspectionType | "">("");
    const [statusFilter, setStatusFilter] = useState<GroupKey | "">("");
    const [pendingDeleteId, setPendingDeleteId] = useState<number | null>(null);
    const [deleting, setDeleting] = useState(false);
    const [repeatingId, setRepeatingId] = useState<number | null>(null);
    const [page, setPage] = useState(1);

    useEffect(() => {
        let cancelled = false;
        Inspections.list()
            .then((res) => {
                if (!cancelled) setItems(res.items);
            })
            .catch((err: unknown) => {
                if (cancelled) return;
                setError(
                    err instanceof ApiError
                        ? err.message
                        : "Nepodarilo sa načítať kontroly.",
                );
            });
        return () => {
            cancelled = true;
        };
    }, []);

    // Search + type only. The validity chips count against this set, so their
    // numbers stay stable while switching between them.
    const searched = useMemo(() => {
        if (!items) return null;
        const q = query.trim().toLowerCase();
        return items.filter((it) => {
            if (typeFilter && it.type !== typeFilter) return false;
            if (!q) return true;
            return (
                it.company_name.toLowerCase().includes(q) ||
                it.facility_name.toLowerCase().includes(q) ||
                it.inspector_name.toLowerCase().includes(q) ||
                (it.effective_inspector_name?.toLowerCase().includes(q) ?? false) ||
                INSPECTION_TYPE_LABELS[it.type].toLowerCase().includes(q)
            );
        });
    }, [items, query, typeFilter]);

    const statusCounts = useMemo(() => {
        if (!searched) return null;
        const counts: Record<GroupKey, number> = {
            overdue: 0,
            soon: 0,
            drafts: 0,
            valid: 0,
        };
        for (const it of searched) counts[groupKeyFor(it)] += 1;
        return counts;
    }, [searched]);

    const filtered = useMemo(() => {
        if (!searched) return null;
        if (!statusFilter) return searched;
        return searched.filter((it) => groupKeyFor(it) === statusFilter);
    }, [searched, statusFilter]);

    const grouped = useMemo(() => {
        if (!filtered) return null;
        // A missing execution date sorts to the top of its section in both
        // orders — it is the one thing the technician still has to fill in.
        const byDaysAsc = (a: InspectionListItem, b: InspectionListItem) => {
            const da =
                daysUntilNext(a.executed_on, a.periodicity_months) ?? -Infinity;
            const db =
                daysUntilNext(b.executed_on, b.periodicity_months) ?? -Infinity;
            return da - db;
        };
        const byDateDesc = (a: InspectionListItem, b: InspectionListItem) => {
            if (a.executed_on && b.executed_on) {
                const byDate = b.executed_on.localeCompare(a.executed_on);
                if (byDate !== 0) return byDate;
            } else if (a.executed_on !== b.executed_on) {
                return a.executed_on ? 1 : -1;
            }
            // Same date (or both undated): newest record first, so an
            // inspection never renders above the one that superseded it.
            return b.id - a.id;
        };
        const groups: Record<GroupKey, InspectionListItem[]> = {
            overdue: [],
            soon: [],
            drafts: [],
            valid: [],
        };
        for (const it of filtered) groups[groupKeyFor(it)].push(it);
        groups.overdue.sort(byDaysAsc);
        groups.soon.sort(byDaysAsc);
        groups.drafts.sort(byDateDesc);
        groups.valid.sort(byDateDesc);
        return groups;
    }, [filtered]);

    const totalItems = filtered?.length ?? 0;
    const totalPages = Math.ceil(totalItems / PAGE_SIZE);

    useEffect(() => {
        setPage(1);
    }, [query, typeFilter, statusFilter]);

    // Paginate across the ordered groups so each page holds at most PAGE_SIZE
    // rows; section headers render only for the items that fall on the current
    // page.
    const pageIds = useMemo(() => {
        if (!grouped) return null;
        const ordered = SECTIONS.flatMap((s) => grouped[s.key]);
        const slice = ordered.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);
        return new Set(slice.map((it) => it.id));
    }, [grouped, page]);

    const pagedGroups = useMemo(() => {
        if (!grouped || !pageIds) return null;
        return Object.fromEntries(
            SECTIONS.map((s) => [
                s.key,
                grouped[s.key].filter((it) => pageIds.has(it.id)),
            ]),
        ) as Record<GroupKey, InspectionListItem[]>;
    }, [grouped, pageIds]);

    async function handleDelete() {
        if (pendingDeleteId === null) return;
        setDeleting(true);
        try {
            await Inspections.archive(pendingDeleteId, csrfToken);
            setItems(
                (prev) => prev?.filter((i) => i.id !== pendingDeleteId) ?? null,
            );
            setPendingDeleteId(null);
            toast.success("Kontrola odstránená");
        } catch (err) {
            toast.error(
                err instanceof ApiError
                    ? err.message
                    : "Nepodarilo sa odstrániť kontrolu.",
            );
        } finally {
            setDeleting(false);
        }
    }

    async function handleRepeat(insId: number) {
        setRepeatingId(insId);
        try {
            const res = await Inspections.repeat(insId, csrfToken);
            navigate(`/inspections/${res.inspection.id}`);
        } catch (err) {
            toast.error(
                err instanceof ApiError ? err.message : "Opakovať sa nepodarilo.",
            );
        } finally {
            setRepeatingId(null);
        }
    }

    return (
        <div className="flex flex-col gap-4">
            <header className="flex items-center justify-between gap-3">
                <div>
                    <h1 className="text-xl font-semibold tracking-tight text-ink-900">
                        Kontroly
                    </h1>
                    <p className="mt-0.5 text-sm text-ink-500">
                        Všetky vykonané kontroly a rozpracované koncepty.
                    </p>
                </div>
                {!isReadOnly && (
                    <Link
                        to="/inspections/new"
                        className="inline-flex h-10 items-center gap-1.5 rounded-2xl bg-firol-500 px-3 text-sm font-medium text-white shadow-[var(--shadow-glow)] hover:bg-firol-600"
                    >
                        <Plus className="size-4" />
                        Nová kontrola
                    </Link>
                )}
            </header>

            {items && items.length > 0 && (
                <div className="overflow-hidden rounded-2xl border border-ink-200 bg-white shadow-xs transition-all focus-within:border-firol-300">
                    <div className="flex items-center gap-2 px-3 py-2.5">
                        <Search className="size-4 shrink-0 text-ink-400" />
                        <input
                            type="search"
                            placeholder="Hľadať firmu, prevádzku, technika…"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            className="min-w-0 flex-1 bg-transparent text-sm text-ink-900 placeholder:text-ink-400 outline-none"
                        />
                    </div>
                    <div className="h-px bg-ink-100" />
                    <div className="flex gap-1.5 overflow-x-auto px-3 py-2.5 [&::-webkit-scrollbar]:hidden">
                        <button
                            type="button"
                            onClick={() => setTypeFilter("")}
                            className={cn(
                                "shrink-0 rounded-full px-3 py-1 text-xs font-medium transition-all duration-150",
                                typeFilter === ""
                                    ? "bg-firol-500 text-white scale-[1.04]"
                                    : "bg-ink-100 text-ink-600 hover:bg-ink-200",
                            )}
                        >
                            Všetky
                        </button>
                        {TYPE_CHIPS.map(([val, label]) => (
                            <button
                                key={val}
                                type="button"
                                onClick={() =>
                                    setTypeFilter(typeFilter === val ? "" : val)
                                }
                                className={cn(
                                    "shrink-0 rounded-full px-3 py-1 text-xs font-medium transition-all duration-150",
                                    typeFilter === val
                                        ? "bg-firol-500 text-white scale-[1.04]"
                                        : "bg-ink-100 text-ink-600 hover:bg-ink-200",
                                )}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                    <div className="h-px bg-ink-100" />
                    {/* Validity filter — same buckets as the list sections. */}
                    <div className="flex gap-1.5 overflow-x-auto px-3 py-2.5 [&::-webkit-scrollbar]:hidden">
                        <button
                            type="button"
                            onClick={() => setStatusFilter("")}
                            className={cn(
                                "shrink-0 rounded-full px-3 py-1 text-xs font-medium transition-all duration-150",
                                statusFilter === ""
                                    ? "bg-ink-800 text-white scale-[1.04]"
                                    : "bg-ink-100 text-ink-600 hover:bg-ink-200",
                            )}
                        >
                            Všetky stavy
                        </button>
                        {SECTIONS.map(({ key, label, color }) => {
                            const count = statusCounts?.[key] ?? 0;
                            const active = statusFilter === key;
                            return (
                                <button
                                    key={key}
                                    type="button"
                                    disabled={count === 0 && !active}
                                    onClick={() =>
                                        setStatusFilter(active ? "" : key)
                                    }
                                    className={cn(
                                        "flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-all duration-150",
                                        active
                                            ? "bg-ink-800 text-white scale-[1.04]"
                                            : "bg-ink-100 text-ink-600 hover:bg-ink-200 disabled:opacity-40 disabled:hover:bg-ink-100",
                                    )}
                                >
                                    <span
                                        className="size-2 shrink-0 rounded-full"
                                        style={{ backgroundColor: color }}
                                    />
                                    {label}
                                    <span
                                        className={cn(
                                            "tabular-nums",
                                            active ? "text-white/70" : "text-ink-400",
                                        )}
                                    >
                                        {count}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                </div>
            )}

            {error && (
                <Card className="px-4 py-3 text-sm text-status-bad">
                    {error}
                </Card>
            )}

            {!items && !error && <SkeletonList count={4} />}

            {items && items.length === 0 && (
                <Card className="flex flex-col items-center gap-3 px-6 py-12 text-center">
                    <div className="grid size-14 place-items-center rounded-2xl bg-firol-50 text-firol-500">
                        <ClipboardList className="size-6" />
                    </div>
                    <h2 className="text-base font-semibold text-ink-900">
                        Zatiaľ žiadne kontroly
                    </h2>
                    <p className="max-w-xs text-sm text-ink-500">
                        Začni výberom firmy a typu kontroly. Drafty zostávajú
                        uložené, kým nevygeneruješ PDF protokol.
                    </p>
                    <Link
                        to="/inspections/new"
                        className="inline-flex h-11 items-center gap-1.5 rounded-2xl bg-firol-500 px-4 text-sm font-medium text-white shadow-[var(--shadow-glow)] hover:bg-firol-600"
                    >
                        <Plus className="size-4" />
                        Nová kontrola
                    </Link>
                </Card>
            )}

            {filtered && filtered.length === 0 && items && items.length > 0 && (
                <Card className="flex flex-col items-center gap-2 px-6 py-10 text-center">
                    <Search className="size-6 text-ink-300" />
                    <p className="text-sm text-ink-500">
                        Žiadne kontroly nevyhovujú filtru.
                    </p>
                </Card>
            )}

            {pagedGroups && SECTIONS.some((s) => pagedGroups[s.key].length > 0) && (
                <>
                    <div className="flex flex-col gap-5">
                        {SECTIONS.map(({ key, label, color }) =>
                            pagedGroups[key].length === 0 ? null : (
                                <section key={key}>
                                    <div className="mb-2 flex items-center gap-2">
                                        <span
                                            className="size-2 rounded-full"
                                            style={{ backgroundColor: color }}
                                        />
                                        <h2
                                            className="text-xs font-semibold uppercase tracking-wide"
                                            style={{ color }}
                                        >
                                            {label}
                                        </h2>
                                    </div>
                                    <ul className="flex flex-col gap-2">
                                        {pagedGroups[key].map((it) => (
                                            <li key={it.id}>
                                                <InspectionRow it={it} onDelete={setPendingDeleteId} onRepeat={handleRepeat} repeatingId={repeatingId} isReadOnly={isReadOnly} />
                                            </li>
                                        ))}
                                    </ul>
                                </section>
                            ),
                        )}
                    </div>
                    <Pagination
                        page={page}
                        totalPages={totalPages}
                        totalItems={totalItems}
                        pageSize={PAGE_SIZE}
                        onChange={setPage}
                    />
                </>
            )}

            <Dialog
                open={pendingDeleteId !== null}
                onClose={() => {
                    if (!deleting) setPendingDeleteId(null);
                }}
                title="Odstrániť kontrolu?"
                description="Táto akcia je nevratná. Kontrola bude trvalo odstránená spolu so všetkými položkami."
                dismissible={!deleting}
            >
                <div className="flex justify-end gap-2">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setPendingDeleteId(null)}
                        disabled={deleting}
                    >
                        Zrušiť
                    </Button>
                    <Button
                        variant="danger"
                        size="sm"
                        loading={deleting}
                        onClick={handleDelete}
                    >
                        Odstrániť
                    </Button>
                </div>
            </Dialog>
        </div>
    );
}

function InspectionRow({
    it,
    onDelete,
    onRepeat,
    repeatingId,
    isReadOnly,
}: {
    it: InspectionListItem;
    onDelete: (id: number) => void;
    onRepeat: (id: number) => void;
    repeatingId: number | null;
    isReadOnly: boolean;
}) {
    const isRepeating = repeatingId === it.id;

    const actions = isReadOnly ? null : (
        <>
            {it.status === "finalized" && (
                <button
                    type="button"
                    title="Opakovať kontrolu"
                    disabled={repeatingId !== null}
                    onClick={() => onRepeat(it.id)}
                    className="grid size-8 place-items-center rounded-xl text-blue-500 transition-colors hover:bg-blue-50 disabled:opacity-50"
                >
                    {isRepeating ? <Spinner size="sm" /> : <Repeat className="size-4" />}
                </button>
            )}
            <Link
                to={`/inspections/${it.id}`}
                title="Upraviť"
                className="grid size-8 place-items-center rounded-xl text-[var(--color-status-warn)] transition-colors hover:bg-[var(--color-status-warn-bg)]"
            >
                <Edit2 className="size-4" />
            </Link>
            <button
                type="button"
                title="Odstrániť"
                onClick={() => onDelete(it.id)}
                className="grid size-8 place-items-center rounded-xl text-[var(--color-status-bad)] transition-colors hover:bg-[var(--color-status-bad-bg)]"
            >
                <Trash2 className="size-4" />
            </button>
        </>
    );

    return (
        <Card className="px-4 py-3">
            <div className="flex items-start gap-3">
                <Link
                    to={`/inspections/${it.id}`}
                    className="mt-0.5 grid size-11 shrink-0 place-items-center rounded-2xl bg-firol-500 text-white shadow-[var(--shadow-glow)] transition-colors hover:bg-firol-600"
                >
                    <ClipboardList className="size-5" />
                </Link>

                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <Link
                            to={`/inspections/${it.id}`}
                            className="text-sm font-semibold text-ink-900 transition-colors hover:text-firol-600"
                        >
                            {INSPECTION_TYPE_LABELS[it.type]}
                        </Link>
                        {it.status === "draft" ? (
                            <Badge tone="warn" className="shrink-0">
                                Koncept
                            </Badge>
                        ) : (
                            <InspectionStatusBadge inspection={it} className="shrink-0" />
                        )}
                    </div>
                    <p className="mt-0.5 truncate text-xs text-ink-500">
                        <Building2 className="-mt-0.5 mr-1 inline size-3" />
                        {it.company_name}
                        <span className="mx-1.5 text-ink-300">·</span>
                        <Warehouse className="-mt-0.5 mr-1 inline size-3" />
                        {it.facility_name}
                    </p>
                    <p className="mt-0.5 text-[11px] text-ink-400">
                        <CalendarDays className="-mt-0.5 mr-1 inline size-3" />
                        {it.executed_on
                            ? new Date(it.executed_on + "T00:00:00").toLocaleDateString("sk-SK")
                            : "—"}
                        <span className="mx-1.5 text-ink-300">·</span>
                        {it.periodicity_months} mes.
                        <span className="mx-1.5 text-ink-300">·</span>
                        {it.effective_inspector_name ?? it.inspector_name}
                    </p>

                    {/* Mobile actions — second row */}
                    <div className="mt-2 flex items-center justify-end gap-3 sm:hidden">
                        {actions}
                    </div>
                </div>

                {/* Desktop actions — right column */}
                <div className="hidden shrink-0 items-center gap-3 self-center sm:flex">
                    {actions}
                </div>
            </div>
        </Card>
    );
}
