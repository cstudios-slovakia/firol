import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import {
    Building2,
    ChevronRight,
    ClipboardList,
    GraduationCap,
    Plus,
} from "lucide-react";
import { useAuth } from "@/auth/AuthContext";
import { Companies, type CompanyListItem } from "@/api/companies";
import {
    Inspections,
    type InspectionListItem,
    INSPECTION_TYPE_LABELS,
} from "@/api/inspections";
import {
    Trainings,
    type TrainingListItem,
    TRAINING_TYPE_SHORT,
} from "@/api/trainings";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";

export function DashboardPage() {
    const { user } = useAuth();
    const [companies, setCompanies] = useState<CompanyListItem[]>([]);
    const [inspections, setInspections] = useState<InspectionListItem[]>([]);
    const [trainings, setTrainings] = useState<TrainingListItem[]>([]);
    const [loaded, setLoaded] = useState(false);

    useEffect(() => {
        Promise.all([Companies.list(), Inspections.list(), Trainings.list()])
            .then(([co, ins, tr]) => {
                setCompanies(co.items);
                setInspections(ins.items);
                setTrainings(tr.items);
                setLoaded(true);
            })
            .catch(() => setLoaded(true));
    }, []);

    const firstName = user?.fullname.split(" ")[0] ?? "";

    return (
        <div className="flex flex-col gap-6">
            <div className="rounded-3xl bg-gradient-to-br from-firol-500 to-firol-600 px-5 py-6 text-white shadow-[var(--shadow-glow)]">
                <p className="text-xs font-medium uppercase tracking-wider opacity-70">
                    Vitaj späť
                </p>
                <h1 className="mt-1 text-2xl font-bold tracking-tight">
                    Ahoj, {firstName}
                </h1>
                <p className="mt-2 text-sm opacity-75">
                    Tu je prehľad tvojej aktuálnej činnosti.
                </p>
                <div className="mt-4 flex flex-wrap gap-2">
                    <Link
                        to="/inspections/new"
                        className="inline-flex items-center gap-1.5 rounded-2xl bg-white/20 px-3 py-1.5 text-xs font-semibold backdrop-blur-sm transition-colors hover:bg-white/30"
                    >
                        <ClipboardList className="size-3.5" />
                        Nová kontrola
                    </Link>
                    <Link
                        to="/trainings/new"
                        className="inline-flex items-center gap-1.5 rounded-2xl bg-white/20 px-3 py-1.5 text-xs font-semibold backdrop-blur-sm transition-colors hover:bg-white/30"
                    >
                        <GraduationCap className="size-3.5" />
                        Nové školenie
                    </Link>
                </div>
            </div>

            <div className="flex flex-col gap-4">
                <InspectionsWidget
                    items={inspections.slice(0, 4)}
                    total={inspections.length}
                    loaded={loaded}
                />
                <CompaniesWidget
                    items={companies.slice(0, 4)}
                    total={companies.length}
                    loaded={loaded}
                />
                <TrainingsWidget
                    items={trainings.slice(0, 4)}
                    total={trainings.length}
                    loaded={loaded}
                />
            </div>
        </div>
    );
}

function InspectionsWidget({
    items,
    total,
    loaded,
}: {
    items: InspectionListItem[];
    total: number;
    loaded: boolean;
}) {
    return (
        <Card className="overflow-hidden p-0">
            <div className="flex items-center justify-between gap-3 border-b border-ink-100 px-4 py-3">
                <div className="flex items-center gap-2.5">
                    <span className="grid size-8 place-items-center rounded-xl bg-orange-100 text-orange-600">
                        <ClipboardList className="size-4" />
                    </span>
                    <span className="text-sm font-semibold text-ink-900">
                        Kontroly
                    </span>
                    {total > 0 && (
                        <span className="rounded-full bg-ink-100 px-2 py-0.5 text-xs text-ink-500">
                            {total}
                        </span>
                    )}
                </div>
                <Link
                    to="/inspections/new"
                    className="inline-flex items-center gap-1 rounded-xl bg-firol-500 px-3 py-1.5 text-xs font-medium text-white shadow-[var(--shadow-glow)] transition-colors hover:bg-firol-600"
                >
                    <Plus className="size-3.5" />
                    Nová kontrola
                </Link>
            </div>

            {!loaded ? (
                <p className="px-4 py-4 text-sm text-ink-400">Načítavam…</p>
            ) : items.length === 0 ? (
                <p className="px-4 py-6 text-center text-sm text-ink-400">
                    Žiadne kontroly.
                </p>
            ) : (
                <ul className="divide-y divide-ink-50">
                    {items.map((item) => (
                        <li key={item.id}>
                            <Link
                                to={`/inspections/${item.id}`}
                                className="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-ink-50"
                            >
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <span className="truncate text-sm font-medium text-ink-900">
                                            {INSPECTION_TYPE_LABELS[item.type]}
                                        </span>
                                        <Badge
                                            tone={
                                                item.status === "finalized"
                                                    ? "ok"
                                                    : "warn"
                                            }
                                        >
                                            {item.status === "finalized"
                                                ? "Hotová"
                                                : "Koncept"}
                                        </Badge>
                                    </div>
                                    <p className="mt-0.5 truncate text-xs text-ink-500">
                                        {item.company_name} ·{" "}
                                        {item.facility_name}
                                    </p>
                                </div>
                                {item.executed_on && (
                                    <span className="shrink-0 text-xs text-ink-400">
                                        {new Date(
                                            item.executed_on,
                                        ).toLocaleDateString("sk-SK")}
                                    </span>
                                )}
                                <ChevronRight className="size-4 shrink-0 text-ink-300" />
                            </Link>
                        </li>
                    ))}
                </ul>
            )}

            {loaded && total > 4 && (
                <div className="border-t border-ink-100 px-4 py-2.5">
                    <Link
                        to="/inspections"
                        className="text-xs font-medium text-firol-600 hover:text-firol-700"
                    >
                        Zobraziť všetky ({total}) →
                    </Link>
                </div>
            )}
        </Card>
    );
}

function CompaniesWidget({
    items,
    total,
    loaded,
}: {
    items: CompanyListItem[];
    total: number;
    loaded: boolean;
}) {
    return (
        <Card className="overflow-hidden p-0">
            <div className="flex items-center justify-between gap-3 border-b border-ink-100 px-4 py-3">
                <div className="flex items-center gap-2.5">
                    <span className="grid size-8 place-items-center rounded-xl bg-blue-100 text-blue-600">
                        <Building2 className="size-4" />
                    </span>
                    <span className="text-sm font-semibold text-ink-900">
                        Firmy
                    </span>
                    {total > 0 && (
                        <span className="rounded-full bg-ink-100 px-2 py-0.5 text-xs text-ink-500">
                            {total}
                        </span>
                    )}
                </div>
                <Link
                    to="/companies/new"
                    className="inline-flex items-center gap-1 rounded-xl bg-blue-500 px-3 py-1.5 text-xs font-medium text-white transition-colors hover:bg-blue-600"
                >
                    <Plus className="size-3.5" />
                    Nová firma
                </Link>
            </div>

            {!loaded ? (
                <p className="px-4 py-4 text-sm text-ink-400">Načítavam…</p>
            ) : items.length === 0 ? (
                <p className="px-4 py-6 text-center text-sm text-ink-400">
                    Žiadne firmy.
                </p>
            ) : (
                <ul className="divide-y divide-ink-50">
                    {items.map((company) => (
                        <li key={company.id}>
                            <Link
                                to={`/companies/${company.id}`}
                                className="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-ink-50"
                            >
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium text-ink-900">
                                        {company.name}
                                    </p>
                                    <p className="mt-0.5 text-xs text-ink-500">
                                        {company.ico && `IČO ${company.ico} · `}
                                        {company.facilities_count}{" "}
                                        {plural(
                                            company.facilities_count,
                                            "prevádzka",
                                            "prevádzky",
                                            "prevádzok",
                                        )}{" "}
                                        · {company.inspections_count}{" "}
                                        {plural(
                                            company.inspections_count,
                                            "kontrola",
                                            "kontroly",
                                            "kontrol",
                                        )}
                                    </p>
                                </div>
                                <ChevronRight className="size-4 shrink-0 text-ink-300" />
                            </Link>
                        </li>
                    ))}
                </ul>
            )}

            {loaded && total > 4 && (
                <div className="border-t border-ink-100 px-4 py-2.5">
                    <Link
                        to="/companies"
                        className="text-xs font-medium text-firol-600 hover:text-firol-700"
                    >
                        Zobraziť všetky ({total}) →
                    </Link>
                </div>
            )}
        </Card>
    );
}

function TrainingsWidget({
    items,
    total,
    loaded,
}: {
    items: TrainingListItem[];
    total: number;
    loaded: boolean;
}) {
    return (
        <Card className="overflow-hidden p-0">
            <div className="flex items-center justify-between gap-3 border-b border-ink-100 px-4 py-3">
                <div className="flex items-center gap-2.5">
                    <span className="grid size-8 place-items-center rounded-xl bg-emerald-100 text-emerald-600">
                        <GraduationCap className="size-4" />
                    </span>
                    <span className="text-sm font-semibold text-ink-900">
                        Školenia
                    </span>
                    {total > 0 && (
                        <span className="rounded-full bg-ink-100 px-2 py-0.5 text-xs text-ink-500">
                            {total}
                        </span>
                    )}
                </div>
                <Link
                    to="/trainings/new"
                    className="inline-flex items-center gap-1 rounded-xl bg-emerald-500 px-3 py-1.5 text-xs font-medium text-white transition-colors hover:bg-emerald-600"
                >
                    <Plus className="size-3.5" />
                    Nové školenie
                </Link>
            </div>

            {!loaded ? (
                <p className="px-4 py-4 text-sm text-ink-400">Načítavam…</p>
            ) : items.length === 0 ? (
                <p className="px-4 py-6 text-center text-sm text-ink-400">
                    Žiadne školenia.
                </p>
            ) : (
                <ul className="divide-y divide-ink-50">
                    {items.map((training) => (
                        <li key={training.id}>
                            <Link
                                to={`/trainings/${training.id}`}
                                className="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-ink-50"
                            >
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <span className="truncate text-sm font-medium text-ink-900">
                                            {TRAINING_TYPE_SHORT[training.type]}
                                        </span>
                                        <Badge
                                            tone={
                                                training.status === "finalized"
                                                    ? "ok"
                                                    : "warn"
                                            }
                                        >
                                            {training.status === "finalized"
                                                ? "Hotové"
                                                : "Koncept"}
                                        </Badge>
                                    </div>
                                    <p className="mt-0.5 truncate text-xs text-ink-500">
                                        {training.company_name}
                                    </p>
                                </div>
                                {training.date && (
                                    <span className="shrink-0 text-xs text-ink-400">
                                        {new Date(
                                            training.date,
                                        ).toLocaleDateString("sk-SK")}
                                    </span>
                                )}
                                <ChevronRight className="size-4 shrink-0 text-ink-300" />
                            </Link>
                        </li>
                    ))}
                </ul>
            )}

            {loaded && total > 4 && (
                <div className="border-t border-ink-100 px-4 py-2.5">
                    <Link
                        to="/trainings"
                        className="text-xs font-medium text-firol-600 hover:text-firol-700"
                    >
                        Zobraziť všetky ({total}) →
                    </Link>
                </div>
            )}
        </Card>
    );
}

function plural(n: number, one: string, few: string, many: string): string {
    if (n === 1) return one;
    if (n >= 2 && n <= 4) return few;
    return many;
}
