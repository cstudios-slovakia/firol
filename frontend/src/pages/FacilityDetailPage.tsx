import { useEffect, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import {
    ArrowLeft,
    Building2,
    CalendarDays,
    ClipboardList,
    Edit2,
    FileText,
    MapPin,
    NotebookPen,
    Plus,
    Repeat,
    Trash2,
    User,
    Warehouse,
} from "lucide-react";
import { useAuth } from "@/auth/AuthContext";
import { Facilities, type Facility } from "@/api/facilities";
import {
    INSPECTION_TYPE_LABELS,
    Inspections,
    type InspectionListItem,
} from "@/api/inspections";
import { ApiError } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Spinner } from "@/components/ui/Spinner";
import { DetailHeaderSkeleton, SkeletonList } from "@/components/ui/Skeleton";

export function FacilityDetailPage() {
    const { id: idStr } = useParams<{ id: string }>();
    const id = Number(idStr);

    const navigate = useNavigate();
    const { csrfToken } = useAuth();
    const [facility, setFacility] = useState<Facility | null>(null);
    const [inspections, setInspections] = useState<InspectionListItem[] | null>(
        null,
    );
    const [error, setError] = useState<string | null>(null);
    const [repeatingId, setRepeatingId] = useState<number | null>(null);

    async function onArchive() {
        if (!window.confirm("Naozaj archivovať prevádzku? Údaje zostanú v systéme, len sa skryjú.")) return;
        try {
            await Facilities.archive(id, csrfToken);
            navigate(facility ? `/companies/${facility.company_id}` : "/", { replace: true });
        } catch (err) {
            setError(err instanceof ApiError ? err.message : "Archiváciu sa nepodarilo dokončiť.");
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
        Promise.all([
            Facilities.show(id),
            Inspections.list({ facility_id: id }),
        ])
            .then(([f, ins]) => {
                if (cancelled) return;
                setFacility(f.facility);
                setInspections(ins.items);
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

            <section>
                <header className="mb-3 flex items-center justify-between">
                    <h2 className="text-sm font-semibold uppercase tracking-wide text-ink-500">
                        História kontrol
                    </h2>
                    <Link
                        to={`/inspections/new?company_id=${facility.company_id}&facility_id=${facility.id}`}
                        className="inline-flex h-8 items-center gap-1 rounded-2xl bg-firol-500 px-3 text-xs font-medium text-white shadow-[var(--shadow-glow)] hover:bg-firol-600"
                    >
                        <Plus className="size-3.5" />
                        Nová kontrola
                    </Link>
                </header>

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
                ) : (
                    <ul className="flex flex-col gap-2">
                        {inspections.map((ins) => {
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
                                                        {
                                                            INSPECTION_TYPE_LABELS[
                                                                ins.type
                                                            ]
                                                        }
                                                    </h3>
                                                    <Badge
                                                        tone={
                                                            finalized
                                                                ? "ok"
                                                                : "warn"
                                                        }
                                                    >
                                                        {finalized
                                                            ? "Hotová"
                                                            : "Koncept"}
                                                    </Badge>
                                                </div>
                                                <p className="mt-0.5 text-xs text-ink-500">
                                                    <CalendarDays className="-mt-0.5 mr-1 inline size-3" />
                                                    {ins.executed_on ?? "—"}
                                                    <span className="mx-1.5 text-ink-300">
                                                        ·
                                                    </span>
                                                    {ins.periodicity_months}{" "}
                                                    mes.
                                                    <span className="mx-1.5 text-ink-300">
                                                        ·
                                                    </span>
                                                    {ins.inspector_name}
                                                </p>
                                            </div>
                                        </Link>
                                        <div className="flex shrink-0 items-center gap-0.5">
                                            {finalized && (
                                                <Link
                                                    to={`/inspections/${ins.id}`}
                                                    title="PDF protokol"
                                                    aria-label="PDF protokol"
                                                    className="grid size-8 place-items-center rounded-xl text-firol-500 transition-colors hover:bg-firol-50"
                                                >
                                                    <FileText className="size-4" />
                                                </Link>
                                            )}
                                            {canRepeat && (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        handleRepeat(ins.id)
                                                    }
                                                    disabled={
                                                        repeatingId === ins.id
                                                    }
                                                    title="Vytvor novú kontrolu s tými istými prístrojmi"
                                                    aria-label="Opakovať"
                                                    className="grid size-8 place-items-center rounded-xl text-ink-500 transition-colors hover:bg-firol-50 hover:text-firol-700 disabled:opacity-50"
                                                >
                                                    {repeatingId === ins.id ? (
                                                        <Spinner size="sm" />
                                                    ) : (
                                                        <Repeat className="size-4" />
                                                    )}
                                                </button>
                                            )}
                                        </div>
                                    </Card>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </section>
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
