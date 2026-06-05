import { useEffect, useMemo, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import {
    ArrowLeft,
    Building2,
    CalendarDays,
    ChevronRight,
    ClipboardList,
    Edit2,
    GraduationCap,
    MapPin,
    NotebookPen,
    Plus,
    Repeat,
    Search,
    Trash2,
    User,
    Users,
    Warehouse,
} from "lucide-react";
import { useAuth } from "@/auth/AuthContext";
import { useIsReadOnly } from "@/auth/useIsReadOnly";
import { useToast } from "@/lib/toast";
import { Facilities, type Facility } from "@/api/facilities";
import {
    INSPECTION_TYPE_LABELS,
    Inspections,
    type InspectionListItem,
    type InspectionType,
} from "@/api/inspections";
import {
    TRAINING_TYPE_LABELS,
    TRAINING_TYPES,
    Trainings,
    type TrainingListItem,
    type TrainingType,
} from "@/api/trainings";
import { ApiError } from "@/lib/api";
import { cn } from "@/lib/cn";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Dialog } from "@/components/ui/Dialog";
import { Spinner } from "@/components/ui/Spinner";
import { DetailHeaderSkeleton, SkeletonList } from "@/components/ui/Skeleton";
import { PendingSyncBanner } from "@/components/PendingSyncBanner";

export function FacilityDetailPage() {
    const { id: idStr } = useParams<{ id: string }>();
    const id = Number(idStr);

    const navigate = useNavigate();
    const { csrfToken } = useAuth();
    const isReadOnly = useIsReadOnly();
    const toast = useToast();
    const [facility, setFacility] = useState<Facility | null>(null);
    const [inspections, setInspections] = useState<InspectionListItem[] | null>(null);
    const [trainings, setTrainings] = useState<TrainingListItem[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [repeatingId, setRepeatingId] = useState<number | null>(null);
    const [pendingDeleteId, setPendingDeleteId] = useState<number | null>(null);
    const [deleting, setDeleting] = useState(false);
    const [pendingDeleteTrainingId, setPendingDeleteTrainingId] = useState<number | null>(null);
    const [deletingTraining, setDeletingTraining] = useState(false);
    const [query, setQuery] = useState("");
    const [typeFilter, setTypeFilter] = useState<InspectionType | "">("");
    const [activeTab, setActiveTab] = useState<"inspections" | "trainings">("inspections");
    const [trainingQuery, setTrainingQuery] = useState("");
    const [trainingTypeFilter, setTrainingTypeFilter] = useState<TrainingType | "">("");

    const filtered = useMemo(() => {
        if (!inspections) return null;
        const q = query.trim().toLowerCase();
        return inspections.filter((it) => {
            if (typeFilter && it.type !== typeFilter) return false;
            if (!q) return true;
            return (
                INSPECTION_TYPE_LABELS[it.type].toLowerCase().includes(q) ||
                it.inspector_name.toLowerCase().includes(q) ||
                (it.effective_inspector_name?.toLowerCase().includes(q) ?? false) ||
                (it.executed_on ?? "").includes(q)
            );
        });
    }, [inspections, query, typeFilter]);

    const filteredTrainings = useMemo(() => {
        if (!trainings) return null;
        const q = trainingQuery.trim().toLowerCase();
        return trainings.filter((t) => {
            if (trainingTypeFilter && t.type !== trainingTypeFilter) return false;
            if (!q) return true;
            return (
                TRAINING_TYPE_LABELS[t.type].toLowerCase().includes(q) ||
                (t.trainer_name ?? "").toLowerCase().includes(q) ||
                (t.date ?? "").includes(q)
            );
        });
    }, [trainings, trainingQuery, trainingTypeFilter]);

    async function onArchive() {
        if (!window.confirm("Naozaj archivovať prevádzku? Údaje zostanú v systéme, len sa skryjú.")) return;
        try {
            await Facilities.archive(id, csrfToken);
            navigate(facility ? `/companies/${facility.company_id}` : "/", { replace: true });
        } catch (err) {
            setError(err instanceof ApiError ? err.message : "Archiváciu sa nepodarilo dokončiť.");
        }
    }

    async function handleDelete() {
        if (pendingDeleteId === null) return;
        setDeleting(true);
        try {
            await Inspections.archive(pendingDeleteId, csrfToken);
            setInspections((prev) => prev?.filter((i) => i.id !== pendingDeleteId) ?? null);
            setPendingDeleteId(null);
            toast.success("Kontrola odstránená");
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : "Nepodarilo sa odstrániť kontrolu.");
        } finally {
            setDeleting(false);
        }
    }

    async function handleDeleteTraining() {
        if (pendingDeleteTrainingId === null) return;
        setDeletingTraining(true);
        try {
            await Trainings.archive(pendingDeleteTrainingId, csrfToken);
            setTrainings((prev) => prev?.filter((t) => t.id !== pendingDeleteTrainingId) ?? null);
            setPendingDeleteTrainingId(null);
            toast.success("Školenie odstránené");
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : "Nepodarilo sa odstrániť školenie.");
        } finally {
            setDeletingTraining(false);
        }
    }

    async function handleRepeat(insId: number) {
        setRepeatingId(insId);
        try {
            const res = await Inspections.repeat(insId, csrfToken);
            navigate(`/inspections/${res.inspection.id}`);
        } catch (err) {
            setError(
                err instanceof ApiError
                    ? err.message
                    : "Opakovať sa nepodarilo.",
            );
        } finally {
            setRepeatingId(null);
        }
    }

    useEffect(() => {
        let cancelled = false;
        // The facility drives the page and is served from cache offline. The
        // inspection/training lists are filtered queries that may not be cached
        // for a facility created offline, so fetch them best-effort — a failure
        // shouldn't blank out the whole page.
        Facilities.show(id)
            .then(async (f) => {
                if (cancelled) return;
                setFacility(f.facility);
                const [ins, trs] = await Promise.all([
                    Inspections.list({ facility_id: id }).catch(
                        () => ({ items: [] as InspectionListItem[] }),
                    ),
                    Trainings.list({ facility_id: id }).catch(
                        () => ({ items: [] as TrainingListItem[] }),
                    ),
                ]);
                if (cancelled) return;
                setInspections(ins.items);
                setTrainings(trs.items);
            })
            .catch((err: unknown) => {
                if (cancelled) return;
                setError(
                    err instanceof ApiError
                        ? err.message
                        : "Nepodarilo sa načítať prevádzku.",
                );
            });
        return () => {
            cancelled = true;
        };
    }, [id]);

    if (error) {
        return (
            <div className="flex flex-col gap-4">
                <Link
                    to="/"
                    className="inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-700 self-start"
                >
                    <ArrowLeft className="size-4" />
                    Späť
                </Link>
                <Card className="px-4 py-3 text-sm text-status-bad">
                    {error}
                </Card>
            </div>
        );
    }

    if (!facility) {
        return (
            <div className="flex flex-col gap-5">
                <Link
                    to="/"
                    className="inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-700 self-start"
                >
                    <ArrowLeft className="size-4" />
                    Späť
                </Link>
                <DetailHeaderSkeleton />
                <SkeletonList count={3} />
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-5">
            <Link
                to={`/companies/${facility.company_id}`}
                className="inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-700 self-start"
            >
                <ArrowLeft className="size-4" />
                Späť na firmu
            </Link>

            <PendingSyncBanner resource="facilities" id={id} />

            <Card className="overflow-hidden">
                <div className="bg-gradient-to-br from-firol-50/60 to-transparent px-5 pt-5">
                    <div className="flex items-start gap-3">
                        <div className="grid size-12 place-items-center rounded-2xl bg-firol-500 text-white shadow-[var(--shadow-glow)]">
                            <Warehouse className="size-5" />
                        </div>
                        <div className="min-w-0 flex-1">
                            <h1 className="truncate text-lg font-semibold tracking-tight text-ink-900">
                                {facility.name}
                            </h1>
                            {facility.company_name && (
                                <Link
                                    to={`/companies/${facility.company_id}`}
                                    className="inline-flex items-center gap-1 text-xs text-ink-500 hover:text-firol-600"
                                >
                                    <Building2 className="size-3" />
                                    {facility.company_name}
                                </Link>
                            )}
                        </div>
                        {!isReadOnly && (
                            <div className="flex items-center gap-3">
                                <Link
                                    to={`/facilities/${facility.id}/edit`}
                                    aria-label="Upraviť"
                                    className="grid size-8 place-items-center rounded-xl text-[var(--color-status-warn)] transition-colors hover:bg-[var(--color-status-warn-bg)]"
                                >
                                    <Edit2 className="size-4" />
                                </Link>
                                <button
                                    type="button"
                                    aria-label="Archivovať"
                                    onClick={onArchive}
                                    className="grid size-8 place-items-center rounded-xl text-[var(--color-status-bad)] transition-colors hover:bg-[var(--color-status-bad-bg)]"
                                >
                                    <Trash2 className="size-4" />
                                </button>
                            </div>
                        )}
                    </div>
                </div>

                <dl className="flex flex-col divide-y divide-ink-100 px-5 py-3 text-sm">
                    {facility.address && (
                        <DetailRow
                            icon={<MapPin className="size-4" />}
                            label="Adresa"
                            value={facility.address}
                        />
                    )}
                    {facility.contact_person && (
                        <DetailRow
                            icon={<User className="size-4" />}
                            label="Kontaktná osoba"
                            value={facility.contact_person}
                        />
                    )}
                    {facility.notes && (
                        <DetailRow
                            icon={<NotebookPen className="size-4" />}
                            label="Poznámky"
                            value={facility.notes}
                        />
                    )}
                    {!facility.address &&
                        !facility.contact_person &&
                        !facility.notes && (
                            <div className="py-2 text-ink-400">
                                Žiadne ďalšie údaje. Doplň ich úpravou
                                prevádzky.
                            </div>
                        )}
                </dl>
            </Card>

            {/* Action card menu */}
            {!isReadOnly && (
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <Link
                        to={`/inspections/new?company_id=${facility.company_id}&facility_id=${facility.id}`}
                        className="flex items-center gap-3.5 rounded-2xl border border-ink-100 bg-white px-4 py-3.5 transition-colors hover:bg-ink-50 active:bg-ink-100"
                    >
                        <span className="grid size-11 shrink-0 place-items-center rounded-2xl bg-firol-50">
                            <ClipboardList className="size-5 text-firol-600" />
                        </span>
                        <div className="min-w-0 flex-1">
                            <p className="text-sm font-semibold text-ink-900">Nová kontrola</p>
                            <p className="mt-0.5 text-xs text-ink-500">Vybrať typ a spustiť protokol</p>
                        </div>
                        <ChevronRight className="size-4 shrink-0 text-ink-300" />
                    </Link>
                    <Link
                        to={`/trainings/new?company_id=${facility.company_id}&facility_id=${facility.id}`}
                        className="flex items-center gap-3.5 rounded-2xl border border-ink-100 bg-white px-4 py-3.5 transition-colors hover:bg-ink-50 active:bg-ink-100"
                    >
                        <span className="grid size-11 shrink-0 place-items-center rounded-2xl bg-emerald-50">
                            <GraduationCap className="size-5 text-emerald-600" />
                        </span>
                        <div className="min-w-0 flex-1">
                            <p className="text-sm font-semibold text-ink-900">Nové školenie</p>
                            <p className="mt-0.5 text-xs text-ink-500">Evidencia školení PO pre prevádzku</p>
                        </div>
                        <ChevronRight className="size-4 shrink-0 text-ink-300" />
                    </Link>
                </div>
            )}

            {/* Tab bar */}
            <div className="relative border-b border-ink-100">
                <nav className="flex items-center gap-0.5 overflow-x-auto [&::-webkit-scrollbar]:hidden">
                    <button
                        type="button"
                        onClick={() => setActiveTab("inspections")}
                        className={cn(
                            "relative flex items-center gap-1.5 px-3 py-2.5 text-sm font-medium transition-all duration-150 whitespace-nowrap",
                            activeTab === "inspections"
                                ? "text-firol-600"
                                : "text-ink-500 hover:text-ink-800",
                        )}
                    >
                        <ClipboardList className={cn("size-4 transition-transform duration-150", activeTab === "inspections" && "scale-110")} />
                        Kontroly
                        {inspections !== null && (
                            <span className={cn(
                                "ml-1 rounded-full px-1.5 py-0.5 text-[10px] font-semibold transition-colors duration-150",
                                activeTab === "inspections" ? "bg-firol-100 text-firol-700" : "bg-ink-100 text-ink-500",
                            )}>
                                {inspections.length}
                            </span>
                        )}
                        <span className={cn(
                            "absolute inset-x-0 bottom-0 h-0.5 rounded-full bg-firol-500 transition-[transform,opacity] duration-200 origin-left",
                            activeTab === "inspections" ? "opacity-100 scale-x-100" : "opacity-0 scale-x-0",
                        )} />
                    </button>
                    <button
                        type="button"
                        onClick={() => setActiveTab("trainings")}
                        className={cn(
                            "relative flex items-center gap-1.5 px-3 py-2.5 text-sm font-medium transition-all duration-150 whitespace-nowrap",
                            activeTab === "trainings"
                                ? "text-firol-600"
                                : "text-ink-500 hover:text-ink-800",
                        )}
                    >
                        <GraduationCap className={cn("size-4 transition-transform duration-150", activeTab === "trainings" && "scale-110")} />
                        Školenia
                        {trainings !== null && (
                            <span className={cn(
                                "ml-1 rounded-full px-1.5 py-0.5 text-[10px] font-semibold transition-colors duration-150",
                                activeTab === "trainings" ? "bg-firol-100 text-firol-700" : "bg-ink-100 text-ink-500",
                            )}>
                                {trainings.length}
                            </span>
                        )}
                        <span className={cn(
                            "absolute inset-x-0 bottom-0 h-0.5 rounded-full bg-firol-500 transition-[transform,opacity] duration-200 origin-left",
                            activeTab === "trainings" ? "opacity-100 scale-x-100" : "opacity-0 scale-x-0",
                        )} />
                    </button>
                </nav>
            </div>

            {/* Inspection history */}
            {activeTab === "inspections" && <section key="inspections" className="animate-fade-up">
                <header className="mb-3 flex items-center justify-between">
                    <h2 className="sr-only">História kontrol</h2>
                    {!isReadOnly && (
                        <Link
                            to={`/inspections/new?company_id=${facility.company_id}&facility_id=${facility.id}`}
                            className="ml-auto inline-flex h-8 items-center gap-1 rounded-2xl bg-firol-500 px-3 text-xs font-medium text-white shadow-[var(--shadow-glow)] hover:bg-firol-600"
                        >
                            <Plus className="size-3.5" />
                            Nová kontrola
                        </Link>
                    )}
                </header>

                {inspections && inspections.length > 0 && (
                    <div className="mb-3 overflow-hidden rounded-2xl border border-ink-200 bg-white shadow-xs transition-all focus-within:border-firol-300">
                        <div className="flex items-center gap-2 px-3 py-2.5">
                            <Search className="size-4 shrink-0 text-ink-400" />
                            <input
                                type="search"
                                placeholder="Hľadať typ, technika, dátum…"
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
                                    "shrink-0 rounded-full px-3 py-1 text-xs font-medium transition-colors",
                                    typeFilter === ""
                                        ? "bg-firol-500 text-white"
                                        : "bg-ink-100 text-ink-600 hover:bg-ink-200",
                                )}
                            >
                                Všetky
                            </button>
                            {(["php", "hydranty", "oprava_ts_php", "poziarna_kniha", "pu_akcieschopnost", "pu_udrzba", "nudzove_osvetlenie", "ts_hadic"] as InspectionType[])
                                .filter((t) => inspections.some((i) => i.type === t))
                                .map((t) => (
                                    <button
                                        key={t}
                                        type="button"
                                        onClick={() => setTypeFilter(typeFilter === t ? "" : t)}
                                        className={cn(
                                            "shrink-0 rounded-full px-3 py-1 text-xs font-medium transition-colors",
                                            typeFilter === t
                                                ? "bg-firol-500 text-white"
                                                : "bg-ink-100 text-ink-600 hover:bg-ink-200",
                                        )}
                                    >
                                        {INSPECTION_TYPE_LABELS[t]}
                                    </button>
                                ))}
                        </div>
                    </div>
                )}

                {inspections === null ? (
                    <SkeletonList count={2} />
                ) : inspections.length === 0 ? (
                    <Card className="flex flex-col items-center gap-2 px-4 py-8 text-center">
                        <div className="grid size-12 place-items-center rounded-2xl bg-firol-50 text-firol-500">
                            <ClipboardList className="size-5" />
                        </div>
                        <p className="text-sm text-ink-700">
                            Zatiaľ žiadna kontrola.
                        </p>
                        <p className="max-w-xs text-xs text-ink-500">
                            Po prvej kontrole sa tu zobrazí chronologický
                            prehľad s odkazmi na PDF protokoly.
                        </p>
                    </Card>
                ) : filtered && filtered.length === 0 ? (
                    <Card className="flex flex-col items-center gap-2 px-6 py-8 text-center">
                        <Search className="size-6 text-ink-300" />
                        <p className="text-sm text-ink-500">Žiadne kontroly nevyhovujú filtru.</p>
                    </Card>
                ) : (
                    <ul className="flex flex-col gap-2">
                        {(filtered ?? []).map((ins) => {
                            const finalized = ins.status === "finalized";
                            const canRepeat = finalized;
                            return (
                                <li key={ins.id}>
                                    <Card className="flex items-center gap-3 px-4 py-3 transition-shadow hover:shadow-[var(--shadow-lift)]">
                                        <Link
                                            to={`/inspections/${ins.id}`}
                                            className="flex flex-1 items-center gap-3 group min-w-0"
                                        >
                                            <div className="grid size-10 shrink-0 place-items-center rounded-2xl bg-firol-50 text-firol-600">
                                                <ClipboardList className="size-5" />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <h3 className="truncate text-sm font-semibold text-ink-900 group-hover:text-firol-700">
                                                        {INSPECTION_TYPE_LABELS[ins.type]}
                                                    </h3>
                                                    <Badge tone={finalized ? "ok" : "warn"}>
                                                        {finalized ? "Hotová" : "Koncept"}
                                                    </Badge>
                                                </div>
                                                <p className="mt-0.5 text-xs text-ink-500">
                                                    <CalendarDays className="-mt-0.5 mr-1 inline size-3" />
                                                    {ins.executed_on ?? "—"}
                                                    <span className="mx-1.5 text-ink-300">·</span>
                                                    {ins.periodicity_months} mes.
                                                    <span className="mx-1.5 text-ink-300">·</span>
                                                    {ins.effective_inspector_name ?? ins.inspector_name}
                                                </p>
                                            </div>
                                        </Link>
                                        {!isReadOnly && (
                                            <div className="flex shrink-0 items-center gap-2">
                                                {canRepeat && (
                                                    <button
                                                        type="button"
                                                        onClick={() => handleRepeat(ins.id)}
                                                        disabled={repeatingId !== null}
                                                        title="Opakovať kontrolu"
                                                        aria-label="Opakovať"
                                                        className="grid size-8 place-items-center rounded-xl text-blue-500 transition-colors hover:bg-blue-50 disabled:opacity-50"
                                                    >
                                                        {repeatingId === ins.id ? (
                                                            <Spinner size="sm" />
                                                        ) : (
                                                            <Repeat className="size-4" />
                                                        )}
                                                    </button>
                                                )}
                                                <Link
                                                    to={`/inspections/${ins.id}`}
                                                    title="Upraviť"
                                                    aria-label="Upraviť"
                                                    className="grid size-8 place-items-center rounded-xl text-[var(--color-status-warn)] transition-colors hover:bg-[var(--color-status-warn-bg)]"
                                                >
                                                    <Edit2 className="size-4" />
                                                </Link>
                                                <button
                                                    type="button"
                                                    title="Odstrániť"
                                                    aria-label="Odstrániť"
                                                    onClick={() => setPendingDeleteId(ins.id)}
                                                    className="grid size-8 place-items-center rounded-xl text-[var(--color-status-bad)] transition-colors hover:bg-[var(--color-status-bad-bg)]"
                                                >
                                                    <Trash2 className="size-4" />
                                                </button>
                                            </div>
                                        )}
                                    </Card>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </section>}

            {/* Training history */}
            {activeTab === "trainings" && <section key="trainings" className="animate-fade-up">
                <header className="mb-3 flex items-center justify-between">
                    <h2 className="sr-only">História školení</h2>
                    {!isReadOnly && (
                        <Link
                            to={`/trainings/new?company_id=${facility.company_id}&facility_id=${facility.id}`}
                            className="ml-auto inline-flex h-8 items-center gap-1 rounded-2xl bg-emerald-500 px-3 text-xs font-medium text-white shadow-[var(--shadow-glow)] hover:bg-emerald-600"
                        >
                            <Plus className="size-3.5" />
                            Nové školenie
                        </Link>
                    )}
                </header>

                {trainings && trainings.length > 0 && (
                    <div className="mb-3 overflow-hidden rounded-2xl border border-ink-200 bg-white shadow-xs transition-all focus-within:border-firol-300">
                        <div className="flex items-center gap-2 px-3 py-2.5">
                            <Search className="size-4 shrink-0 text-ink-400" />
                            <input
                                type="search"
                                placeholder="Hľadať typ školenia, školiteľa, dátum…"
                                value={trainingQuery}
                                onChange={(e) => setTrainingQuery(e.target.value)}
                                className="min-w-0 flex-1 bg-transparent text-sm text-ink-900 placeholder:text-ink-400 outline-none"
                            />
                        </div>
                        <div className="h-px bg-ink-100" />
                        <div className="flex gap-1.5 overflow-x-auto px-3 py-2.5 [&::-webkit-scrollbar]:hidden">
                            <button
                                type="button"
                                onClick={() => setTrainingTypeFilter("")}
                                className={cn(
                                    "shrink-0 rounded-full px-3 py-1 text-xs font-medium transition-colors",
                                    trainingTypeFilter === ""
                                        ? "bg-firol-500 text-white"
                                        : "bg-ink-100 text-ink-600 hover:bg-ink-200",
                                )}
                            >
                                Všetky
                            </button>
                            {TRAINING_TYPES
                                .filter((t) => trainings.some((tr) => tr.type === t))
                                .map((t) => (
                                    <button
                                        key={t}
                                        type="button"
                                        onClick={() => setTrainingTypeFilter(trainingTypeFilter === t ? "" : t)}
                                        className={cn(
                                            "shrink-0 rounded-full px-3 py-1 text-xs font-medium transition-colors",
                                            trainingTypeFilter === t
                                                ? "bg-firol-500 text-white"
                                                : "bg-ink-100 text-ink-600 hover:bg-ink-200",
                                        )}
                                    >
                                        {TRAINING_TYPE_LABELS[t]}
                                    </button>
                                ))}
                        </div>
                    </div>
                )}

                {trainings === null ? (
                    <SkeletonList count={2} />
                ) : trainings.length === 0 ? (
                    <Card className="flex flex-col items-center gap-2 px-4 py-8 text-center">
                        <div className="grid size-12 place-items-center rounded-2xl bg-emerald-50 text-emerald-500">
                            <GraduationCap className="size-5" />
                        </div>
                        <p className="text-sm text-ink-700">
                            Zatiaľ žiadne školenie.
                        </p>
                        <p className="max-w-xs text-xs text-ink-500">
                            Po prvom školení sa tu zobrazí prehľad s protokolmi.
                        </p>
                    </Card>
                ) : filteredTrainings && filteredTrainings.length === 0 ? (
                    <Card className="flex flex-col items-center gap-2 px-6 py-8 text-center">
                        <Search className="size-6 text-ink-300" />
                        <p className="text-sm text-ink-500">Žiadne školenia nevyhovujú filtru.</p>
                    </Card>
                ) : (
                    <ul className="flex flex-col gap-2">
                        {(filteredTrainings ?? []).map((tr) => {
                            const finalized = tr.status === "finalized";
                            return (
                                <li key={tr.id}>
                                    <Card className="flex items-center gap-3 px-4 py-3 transition-shadow hover:shadow-[var(--shadow-lift)]">
                                        <Link
                                            to={`/trainings/${tr.id}`}
                                            className="flex flex-1 items-center gap-3 group min-w-0"
                                        >
                                            <div className="grid size-10 shrink-0 place-items-center rounded-2xl bg-emerald-50 text-emerald-600">
                                                <GraduationCap className="size-5" />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <h3 className="truncate text-sm font-semibold text-ink-900 group-hover:text-firol-700">
                                                        {TRAINING_TYPE_LABELS[tr.type]}
                                                    </h3>
                                                    <Badge tone={finalized ? "ok" : "warn"}>
                                                        {finalized ? "Hotové" : "Koncept"}
                                                    </Badge>
                                                </div>
                                                <p className="mt-0.5 text-xs text-ink-500">
                                                    <CalendarDays className="-mt-0.5 mr-1 inline size-3" />
                                                    {tr.date ?? "—"}
                                                    {tr.trainer_name && (
                                                        <>
                                                            <span className="mx-1.5 text-ink-300">·</span>
                                                            {tr.trainer_name}
                                                        </>
                                                    )}
                                                    <span className="mx-1.5 text-ink-300">·</span>
                                                    <Users className="-mt-0.5 mr-0.5 inline size-3" />
                                                    {tr.trainees_count}
                                                </p>
                                            </div>
                                        </Link>
                                        <div className="flex shrink-0 items-center gap-2">
                                            <Link
                                                to={`/trainings/${tr.id}`}
                                                title="Otvoriť"
                                                aria-label="Otvoriť"
                                                className="grid size-8 place-items-center rounded-xl text-[var(--color-status-warn)] transition-colors hover:bg-[var(--color-status-warn-bg)]"
                                            >
                                                <Edit2 className="size-4" />
                                            </Link>
                                            {!isReadOnly && (
                                                <button
                                                    type="button"
                                                    title="Odstrániť"
                                                    aria-label="Odstrániť"
                                                    onClick={() => setPendingDeleteTrainingId(tr.id)}
                                                    className="grid size-8 place-items-center rounded-xl text-[var(--color-status-bad)] transition-colors hover:bg-[var(--color-status-bad-bg)]"
                                                >
                                                    <Trash2 className="size-4" />
                                                </button>
                                            )}
                                        </div>
                                    </Card>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </section>}

            <Dialog
                open={pendingDeleteId !== null}
                onClose={() => { if (!deleting) setPendingDeleteId(null); }}
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

            <Dialog
                open={pendingDeleteTrainingId !== null}
                onClose={() => { if (!deletingTraining) setPendingDeleteTrainingId(null); }}
                title="Odstrániť školenie?"
                description="Táto akcia je nevratná. Školenie bude trvalo odstránené spolu so zoznamom účastníkov."
                dismissible={!deletingTraining}
            >
                <div className="flex justify-end gap-2">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setPendingDeleteTrainingId(null)}
                        disabled={deletingTraining}
                    >
                        Zrušiť
                    </Button>
                    <Button
                        variant="danger"
                        size="sm"
                        loading={deletingTraining}
                        onClick={handleDeleteTraining}
                    >
                        Odstrániť
                    </Button>
                </div>
            </Dialog>
        </div>
    );
}

function DetailRow({
    icon,
    label,
    value,
}: {
    icon: React.ReactNode;
    label: string;
    value: string;
}) {
    return (
        <div className="flex items-start gap-3 py-2.5">
            <span className="grid size-6 shrink-0 place-items-center text-ink-400">
                {icon}
            </span>
            <div className="flex-1">
                <dt className="text-[10px] font-semibold uppercase tracking-wider text-ink-400">
                    {label}
                </dt>
                <dd className="text-sm text-ink-800 break-words whitespace-pre-wrap">
                    {value}
                </dd>
            </div>
        </div>
    );
}
