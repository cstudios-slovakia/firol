import { useEffect, useMemo, useState } from "react";
import { Pagination } from "@/components/ui/Pagination";
import { Link, useSearchParams } from "react-router-dom";
import {
    Building2,
    CalendarDays,
    Edit2,
    GraduationCap,
    Plus,
    Receipt,
    Search,
    Trash2,
    User,
    Users,
    Warehouse,
    Wheat,
} from "lucide-react";
import {
    isPokyn,
    TRAINING_TYPE_LABELS,
    TRAINING_TYPE_SHORT,
    TRAINING_TYPES,
    Trainings,
    type TrainingListItem,
    type TrainingType,
} from "@/api/trainings";
import { ApiError } from "@/lib/api";
import { cn } from "@/lib/cn";
import { periodicityShort } from "@/lib/periodicity";
import { useAuth } from "@/auth/AuthContext";
import { useIsReadOnly } from "@/auth/useIsReadOnly";
import { useToast } from "@/lib/toast";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Dialog } from "@/components/ui/Dialog";
import { SkeletonList } from "@/components/ui/Skeleton";
import { InvoiceTickButton } from "@/components/InvoicingBlock";
import {
    UNINVOICED_LABEL,
    UNINVOICED_PARAM,
    isUninvoiced,
    type InvoicingFields,
} from "@/api/invoicing";
import { TRAINING_COLOR, TRAINING_SECTION } from "@/lib/sections";
import { useMemberRights } from "@/auth/useMemberRights";

const ACTIVE_CHIP_STYLE = { backgroundColor: TRAINING_COLOR };

/**
 * The Školenia tab of the OPP section (chapter 2). It has no page header of
 * its own — the section supplies it, because a training is one of that
 * section's úkony rather than a list of its own. There is no top-level
 * "Školenia" any more; /trainings redirects here.
 *
 * Everything that marks the list as trainings — the row tiles, the active
 * chip, the empty-state button — wears TRAINING_COLOR, the colour of its tab.
 */
export function TrainingsListPage() {
    const { csrfToken } = useAuth();
    const isReadOnly = useIsReadOnly();
    const toast = useToast();

    const [items, setItems] = useState<TrainingListItem[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [query, setQuery] = useState("");
    const [typeFilter, setTypeFilter] = useState<TrainingType | "">("");
    const [pendingDeleteId, setPendingDeleteId] = useState<number | null>(null);
    const [deleting, setDeleting] = useState(false);
    const [page, setPage] = useState(1);

    const PAGE_SIZE = 10;

    // Chapter 22 — „Nevyfakturované", fetched on its own and filtered
    // server-side so the capped main list can never hide one.
    const [searchParams, setSearchParams] = useSearchParams();
    const uninvoicedOnly = searchParams.get(UNINVOICED_PARAM) === "1";
    const [uninvoicedItems, setUninvoicedItems] = useState<TrainingListItem[] | null>(null);

    useEffect(() => {
        let cancelled = false;
        Trainings.list({ uninvoiced: true })
            .then((res) => {
                if (!cancelled) setUninvoicedItems(res.items);
            })
            .catch(() => {
                // The chip just stays at zero; the main list reports errors.
            });
        return () => {
            cancelled = true;
        };
    }, []);

    function setUninvoicedOnly(on: boolean) {
        setSearchParams(
            (prev) => {
                const next = new URLSearchParams(prev);
                if (on) next.set(UNINVOICED_PARAM, "1");
                else next.delete(UNINVOICED_PARAM);
                return next;
            },
            { replace: true },
        );
    }

    /** A row was ticked off as vyfakturované — it leaves the filtered list. */
    function handleInvoiced(id: number, next: InvoicingFields) {
        setUninvoicedItems((prev) =>
            prev?.filter((it) => it.id !== id || isUninvoiced(next)) ?? null,
        );
        setItems((prev) =>
            prev?.map((it) => (it.id === id ? { ...it, ...next } : it)) ?? null,
        );
    }

    useEffect(() => {
        let cancelled = false;
        Trainings.list()
            .then((res) => {
                if (!cancelled) setItems(res.items);
            })
            .catch((err: unknown) => {
                if (cancelled) return;
                setError(
                    err instanceof ApiError
                        ? err.message
                        : "Nepodarilo sa načítať školenia.",
                );
            });
        return () => {
            cancelled = true;
        };
    }, []);

    const pool = uninvoicedOnly ? uninvoicedItems : items;

    const filtered = useMemo(() => {
        if (!pool) return null;
        const q = query.trim().toLowerCase();
        return pool.filter((it) => {
            if (typeFilter && it.type !== typeFilter) return false;
            if (!q) return true;
            return (
                it.company_name.toLowerCase().includes(q) ||
                (it.facility_name ?? "").toLowerCase().includes(q) ||
                (it.trainer_name ?? "").toLowerCase().includes(q) ||
                TRAINING_TYPE_LABELS[it.type].toLowerCase().includes(q)
            );
        });
    }, [pool, query, typeFilter]);

    useEffect(() => { setPage(1); }, [query, typeFilter, uninvoicedOnly]);

    const totalPages = filtered ? Math.ceil(filtered.length / PAGE_SIZE) : 0;
    const paged = filtered
        ? filtered.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE)
        : null;

    async function handleDelete() {
        if (pendingDeleteId === null) return;
        setDeleting(true);
        try {
            await Trainings.archive(pendingDeleteId, csrfToken);
            setItems(
                (prev) => prev?.filter((i) => i.id !== pendingDeleteId) ?? null,
            );
            setPendingDeleteId(null);
            toast.success("Školenie odstránené");
        } catch (err) {
            toast.error(
                err instanceof ApiError
                    ? err.message
                    : "Nepodarilo sa odstrániť školenie.",
            );
        } finally {
            setDeleting(false);
        }
    }

    return (
        <div className="flex flex-col gap-4">
            {items && items.length > 0 && (
                <div className="overflow-hidden rounded-2xl border border-ink-200 bg-white shadow-xs transition-all focus-within:border-firol-300">
                    <div className="flex items-center gap-2 px-3 py-2.5">
                        <Search className="size-4 shrink-0 text-ink-400" />
                        <input
                            type="search"
                            placeholder="Hľadať firmu, prevádzku, trénera…"
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
                            style={typeFilter === "" ? ACTIVE_CHIP_STYLE : undefined}
                            className={cn(
                                "shrink-0 rounded-full px-3 py-1 text-xs font-medium transition-colors",
                                typeFilter === ""
                                    ? "text-white"
                                    : "bg-ink-100 text-ink-600 hover:bg-ink-200",
                            )}
                        >
                            Všetky
                        </button>
                        {TRAINING_TYPES.map((val) => (
                            <button
                                key={val}
                                type="button"
                                onClick={() =>
                                    setTypeFilter(typeFilter === val ? "" : val)
                                }
                                style={typeFilter === val ? ACTIVE_CHIP_STYLE : undefined}
                                className={cn(
                                    "shrink-0 rounded-full px-3 py-1 text-xs font-medium transition-colors",
                                    typeFilter === val
                                        ? "text-white"
                                        : "bg-ink-100 text-ink-600 hover:bg-ink-200",
                                )}
                            >
                                {TRAINING_TYPE_SHORT[val]}
                            </button>
                        ))}
                    </div>
                    <div className="h-px bg-ink-100" />
                    {/* Chapter 22 — the end-of-month round. */}
                    <div className="flex gap-1.5 overflow-x-auto px-3 py-2.5 [&::-webkit-scrollbar]:hidden">
                        <button
                            type="button"
                            id="filter-trainings-uninvoiced"
                            aria-pressed={uninvoicedOnly}
                            disabled={(uninvoicedItems?.length ?? 0) === 0 && !uninvoicedOnly}
                            onClick={() => setUninvoicedOnly(!uninvoicedOnly)}
                            className={cn(
                                "flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-all duration-150",
                                uninvoicedOnly
                                    ? "bg-ink-800 text-white scale-[1.04]"
                                    : "bg-ink-100 text-ink-600 hover:bg-ink-200 disabled:opacity-40 disabled:hover:bg-ink-100",
                            )}
                        >
                            <Receipt className="size-3 shrink-0" />
                            {UNINVOICED_LABEL}
                            <span
                                className={cn(
                                    "tabular-nums",
                                    uninvoicedOnly ? "text-white/70" : "text-ink-400",
                                )}
                            >
                                {uninvoicedItems?.length ?? 0}
                            </span>
                        </button>
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
                    <div
                        className="grid size-14 place-items-center rounded-2xl"
                        style={{ backgroundColor: `${TRAINING_COLOR}1A`, color: TRAINING_COLOR }}
                    >
                        <GraduationCap className="size-6" />
                    </div>
                    <h2 className="text-base font-semibold text-ink-900">
                        Zatiaľ žiadne školenia
                    </h2>
                    <p className="max-w-xs text-sm text-ink-500">
                        Vytvor prvé školenie a doplň účastníkov s podpismi. Po
                        dokončení vznikne PDF protokol.
                    </p>
                    <Link
                        to={`/trainings/new?section=${TRAINING_SECTION}`}
                        style={{ backgroundColor: TRAINING_COLOR }}
                        className="inline-flex h-11 items-center gap-1.5 rounded-2xl px-4 text-sm font-medium text-white shadow-sm transition-[filter] duration-150 hover:brightness-95"
                    >
                        <Plus className="size-4" />
                        Nové školenie
                    </Link>
                </Card>
            )}

            {filtered && filtered.length === 0 && items && items.length > 0 && (
                <Card className="flex flex-col items-center gap-2 px-6 py-10 text-center">
                    <Search className="size-6 text-ink-300" />
                    <p className="text-sm text-ink-500">
                        {uninvoicedOnly && !query && !typeFilter
                            ? "Všetko je vyfakturované."
                            : "Žiadne školenia nevyhovujú filtru."}
                    </p>
                </Card>
            )}

            {paged && paged.length > 0 && (
                <>
                    <ul className="flex flex-col gap-2">
                        {paged.map((it) => (
                            <li key={it.id}>
                                <TrainingRow
                                    it={it}
                                    onDelete={setPendingDeleteId}
                                    isReadOnly={isReadOnly}
                                    onInvoiced={uninvoicedOnly ? handleInvoiced : undefined}
                                />
                            </li>
                        ))}
                    </ul>
                    <Pagination
                        page={page}
                        totalPages={totalPages}
                        totalItems={filtered!.length}
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
                title="Odstrániť školenie?"
                description="Táto akcia je nevratná. Školenie bude trvalo odstránené spolu so zoznamom účastníkov."
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

function TrainingRow({
    it,
    onDelete,
    isReadOnly,
    onInvoiced,
}: {
    it: TrainingListItem;
    onDelete: (id: number) => void;
    isReadOnly: boolean;
    /** Set in the „Nevyfakturované" view — offers the one-tap check-off. */
    onInvoiced?: (id: number, next: InvoicingFields) => void;
}) {
    const { canDelete, canDeleteUkon } = useMemberRights();
    return (
        <Card className="px-4 py-3">
            <div className="flex items-center gap-3">
                <Link
                    to={`/trainings/${it.id}`}
                    style={{ backgroundColor: TRAINING_COLOR }}
                    className="grid size-11 shrink-0 place-items-center rounded-2xl text-white shadow-sm transition-[filter] duration-150 hover:brightness-95"
                >
                    {isPokyn(it.type)
                        ? <Wheat className="size-5" />
                        : <GraduationCap className="size-5" />}
                </Link>

                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                        <Link
                            to={`/trainings/${it.id}`}
                            className="truncate text-sm font-semibold text-ink-900 transition-colors hover:text-firol-600"
                        >
                            {TRAINING_TYPE_SHORT[it.type]}
                        </Link>
                        <Badge
                            tone={it.status === "finalized" ? "ok" : "warn"}
                            className="shrink-0"
                        >
                            {it.status === "finalized" ? "Hotové" : "Koncept"}
                        </Badge>
                    </div>
                    <p className="mt-0.5 truncate text-xs text-ink-500">
                        <Building2 className="-mt-0.5 mr-1 inline size-3" />
                        {it.company_name}
                        {it.facility_name && (
                            <>
                                <span className="mx-1.5 text-ink-300">·</span>
                                <Warehouse className="-mt-0.5 mr-1 inline size-3" />
                                {it.facility_name}
                            </>
                        )}
                    </p>
                    <p className="mt-0.5 text-[11px] text-ink-400">
                        <CalendarDays className="-mt-0.5 mr-1 inline size-3" />
                        {it.date
                            ? new Date(it.date + "T00:00:00").toLocaleDateString("sk-SK")
                            : "—"}
                        <span className="mx-1.5 text-ink-300">·</span>
                        {periodicityShort({ value: it.periodicity_value, unit: it.periodicity_unit })}
                        {it.trainer_name && (
                            <>
                                <span className="mx-1.5 text-ink-300">·</span>
                                <User className="-mt-0.5 mr-1 inline size-3" />
                                {it.trainer_name}
                            </>
                        )}
                        <span className="mx-1.5 text-ink-300">·</span>
                        {isPokyn(it.type) ? (
                            <>
                                <Wheat className="-mt-0.5 mr-1 inline size-3" />
                                {`žatva ${it.pokyn_year ?? "—"}`}
                            </>
                        ) : (
                            <>
                                <Users className="-mt-0.5 mr-1 inline size-3" />
                                {it.trainees_count}{" "}
                                {it.trainees_count === 1 ? "účastník" : "účastníkov"}
                            </>
                        )}
                    </p>
                </div>

                {!isReadOnly && (
                    <div className="flex shrink-0 items-center gap-3">
                        {onInvoiced && isUninvoiced(it) && (
                            <InvoiceTickButton
                                target="trainings"
                                id={it.id}
                                compact
                                onDone={(next) => onInvoiced(it.id, next)}
                            />
                        )}
                        {(it.status === "draft" || canDelete) && (
                            <Link
                                to={`/trainings/${it.id}/edit`}
                                title="Upraviť"
                                className="grid size-8 place-items-center rounded-xl text-[var(--color-status-warn)] transition-colors hover:bg-[var(--color-status-warn-bg)]"
                            >
                                <Edit2 className="size-4" />
                            </Link>
                        )}
                        {canDeleteUkon(it, it.trainer_id) && (
                            <button
                                type="button"
                                title="Odstrániť"
                                onClick={() => onDelete(it.id)}
                                className="grid size-8 place-items-center rounded-xl text-[var(--color-status-bad)] transition-colors hover:bg-[var(--color-status-bad-bg)]"
                            >
                                <Trash2 className="size-4" />
                            </button>
                        )}
                    </div>
                )}
            </div>
        </Card>
    );
}
