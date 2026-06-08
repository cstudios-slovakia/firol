import { useEffect, useRef, useState, type FormEvent } from "react";
import {
    Link,
    NavLink,
    Outlet,
    useLocation,
    useNavigate,
} from "react-router-dom";
import {
    AlertTriangle,
    AtSign,
    Building2,
    CalendarDays,
    Check,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    ClipboardList,
    Copy,
    CreditCard,
    Database,
    Download,
    FileSignature,
    FileSpreadsheet,
    GraduationCap,
    Hash,
    ImagePlus,
    MailPlus,
    MessageSquarePlus,
    Palette,
    Phone,
    RotateCcw,
    Shield,
    ShieldCheck,
    ShieldOff,
    Smartphone,
    Trash2,
    UploadCloud,
    User,
    UserCheck,
    UsersRound,
} from "lucide-react";
import { useAuth } from "@/auth/AuthContext";
import { AccountApi, type Account } from "@/api/account";
import { DataApi } from "@/api/data";
import { ImportApi, type ImportKind, type ImportResult } from "@/api/import";
import { BackupReminderModal } from "@/components/BackupReminderModal";
import { InstallAppCard } from "@/components/InstallAppCard";
import {
    InspectorProfileApi,
    type InspectorProfile,
} from "@/api/inspectorProfile";
import {
    Team,
    type TeamMember,
    type PendingInvite,
    type TeamDefaultKind,
} from "@/api/team";
import { Select, type SelectOption } from "@/components/ui/Select";
import { ApiError } from "@/lib/api";
import { offlineMessage } from "@/lib/offline";
import { useToast } from "@/lib/toast";
import { useConfirm } from "@/lib/confirm";
import { Card } from "@/components/ui/Card";
import { Input } from "@/components/ui/Input";
import { Field } from "@/components/ui/Field";
import { Button } from "@/components/ui/Button";
import { Spinner } from "@/components/ui/Spinner";
import { CardBlockSkeleton, SkeletonList } from "@/components/ui/Skeleton";
import { Badge } from "@/components/ui/Badge";
import { SignaturePickerModal } from "@/components/SignaturePickerModal";
import { FeedbackDialog } from "@/components/FeedbackFloater";
import { cn } from "@/lib/cn";

// ─── Tab definitions ─────────────────────────────────────────────────────────

const SECTION_TABS = [
    { to: "/settings/profil", label: "Profil technika", icon: ShieldCheck },
    { to: "/settings/branding", label: "Branding PDF", icon: Palette },
    { to: "/settings/technici", label: "Technici", icon: UsersRound },
    { to: "/settings/data", label: "Správa dát", icon: Database },
    { to: "/settings/systemove", label: "Systémové", icon: Smartphone },
] as const;

const MENU_ITEMS = [
    {
        to: "/billing",
        label: "Predplatné",
        description: "Správa predplatného a fakturačné údaje",
        icon: CreditCard,
        color: "text-firol-600",
        bg: "bg-firol-50",
    },
    {
        to: "/settings/profil",
        label: "Profil revízneho technika",
        description: "Podpis, číslo oprávnenia a platnosť",
        icon: ShieldCheck,
        color: "text-violet-600",
        bg: "bg-violet-50",
    },
    {
        to: "/settings/branding",
        label: "Branding na PDF",
        description: "Logo, farba a názov spoločnosti",
        icon: Palette,
        color: "text-blue-600",
        bg: "bg-blue-50",
    },
    {
        to: "/settings/technici",
        label: "Technici",
        description:
            "Ľudia s prístupom do vašej firmy. Môžu vykonávať revízie aj školenia.",
        icon: UsersRound,
        color: "text-orange-600",
        bg: "bg-orange-50",
    },
    {
        to: "/settings/data",
        label: "Správa dát",
        description:
            "Export zálohy, hromadné vymazanie firiem, kontrol alebo školení.",
        icon: Database,
        color: "text-slate-600",
        bg: "bg-slate-50",
    },
    {
        to: "/settings/systemove",
        label: "Systémové",
        description:
            "Inštalácia aplikácie na zariadenie a nastavenia appky.",
        icon: Smartphone,
        color: "text-cyan-600",
        bg: "bg-cyan-50",
    },
] as const;

const ADMIN_MENU_ITEM = {
    to: "/settings/admin",
    label: "Admin",
    description: "Správa účtov a systémové nastavenia",
    icon: Shield,
    color: "text-rose-600",
    bg: "bg-rose-50",
} as const;

// ─── Layout ───────────────────────────────────────────────────────────────────

export function SettingsLayout() {
    const { isAdmin } = useAuth();
    const location = useLocation();
    const isIndex =
        location.pathname === "/settings" || location.pathname === "/settings/";

    return (
        <div className="flex flex-col gap-5">
            {/* Header: always on desktop, only on index on mobile */}
            <header className={cn(isIndex ? "block" : "hidden sm:block")}>
                <h1 className="text-xl font-semibold tracking-tight text-ink-900">
                    Nastavenia
                </h1>
                <p className="mt-0.5 text-sm text-ink-500">
                    Profil, branding, tím a administrácia vášho účtu.
                </p>
            </header>

            {/* Desktop tabs */}
            <div className="relative hidden sm:block">
                <nav
                    aria-label="Sekcie nastavení"
                    className="flex items-center gap-0.5 overflow-x-auto border-b border-ink-100 [&::-webkit-scrollbar]:hidden"
                >
                    {SECTION_TABS.map((tab) => (
                        <NavLink
                            key={tab.to}
                            to={tab.to}
                            className={({ isActive }) =>
                                cn(
                                    "flex shrink-0 items-center gap-2 px-3.5 py-2.5 text-sm font-medium rounded-t-xl border-b-2 -mb-px transition-all duration-150",
                                    isActive
                                        ? "border-firol-500 text-firol-700 bg-firol-50/60"
                                        : "border-transparent text-ink-500 hover:text-ink-800 hover:bg-ink-50",
                                )
                            }
                        >
                            {({ isActive }) => (
                                <>
                                    <tab.icon
                                        className={cn(
                                            "size-4 shrink-0 transition-transform duration-150",
                                            isActive && "scale-110",
                                        )}
                                    />
                                    <span>{tab.label}</span>
                                </>
                            )}
                        </NavLink>
                    ))}
                    {isAdmin && (
                        <NavLink
                            to="/settings/admin"
                            className={({ isActive }) =>
                                cn(
                                    "flex shrink-0 items-center gap-2 px-3.5 py-2.5 text-sm font-medium rounded-t-xl border-b-2 -mb-px transition-all duration-150",
                                    isActive
                                        ? "border-rose-500 text-rose-700 bg-rose-50/60"
                                        : "border-transparent text-ink-500 hover:text-ink-800 hover:bg-ink-50",
                                )
                            }
                        >
                            {({ isActive }) => (
                                <>
                                    <Shield
                                        className={cn(
                                            "size-4 shrink-0 transition-transform duration-150",
                                            isActive && "scale-110",
                                        )}
                                    />
                                    <span>Admin</span>
                                </>
                            )}
                        </NavLink>
                    )}
                </nav>
            </div>

            <div key={location.pathname} className="animate-fade-up">
                <Outlet />
            </div>

            <p className="hidden sm:block text-center text-[11px] text-ink-300 tabular-nums pt-2">
                Firol {__APP_VERSION__}
            </p>
        </div>
    );
}

// ─── Index (mobile menu / desktop redirect) ───────────────────────────────────

export function SettingsIndexPage() {
    const { isAdmin } = useAuth();
    const navigate = useNavigate();
    const location = useLocation();
    const [feedbackOpen, setFeedbackOpen] = useState(false);

    useEffect(() => {
        const mq = window.matchMedia("(min-width: 640px)");
        if (mq.matches) {
            navigate("/settings/profil", { replace: true });
        }
    }, [navigate]);

    const items = isAdmin ? [...MENU_ITEMS, ADMIN_MENU_ITEM] : [...MENU_ITEMS];

    return (
        <div className="flex flex-col gap-2 sm:hidden">
            {items.map((item, i) => (
                <Link
                    key={item.to}
                    to={item.to}
                    className="group animate-fade-up flex items-center gap-3.5 rounded-2xl border border-ink-100 bg-white px-4 py-3.5 transition-[background-color,transform] duration-150 hover:bg-ink-50 hover:-translate-y-px active:bg-ink-100 active:translate-y-0"
                    style={{ animationDelay: `${i * 45}ms` }}
                >
                    <span
                        className={cn(
                            "grid size-11 shrink-0 place-items-center rounded-2xl",
                            item.bg,
                        )}
                    >
                        <item.icon className={cn("size-5", item.color)} />
                    </span>
                    <div className="min-w-0 flex-1">
                        <p className="text-sm font-semibold text-ink-900">
                            {item.label}
                        </p>
                        <p className="mt-0.5 text-xs text-ink-500">
                            {item.description}
                        </p>
                    </div>
                    <ChevronRight className="size-4 shrink-0 text-ink-300 transition-transform duration-150 group-hover:translate-x-0.5" />
                </Link>
            ))}

            {/* Feedback entry — visually separated, lives at the bottom. */}
            <div className="mt-4 border-t border-dashed border-ink-200 pt-4">
                <button
                    type="button"
                    onClick={() => setFeedbackOpen(true)}
                    className="group animate-fade-up flex w-full items-center gap-3.5 rounded-2xl border border-firol-200 bg-firol-50/60 px-4 py-3.5 text-left transition-[background-color,transform] duration-150 hover:bg-firol-50 hover:-translate-y-px active:translate-y-0"
                    style={{ animationDelay: `${items.length * 45}ms` }}
                >
                    <span className="grid size-11 shrink-0 place-items-center rounded-2xl bg-firol-100 text-firol-600">
                        <MessageSquarePlus className="size-5" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <p className="text-sm font-semibold text-firol-800">
                            Nahlásiť chybu / nápad
                        </p>
                        <p className="mt-0.5 text-xs text-firol-700/70">
                            Daj nám vedieť, čo nefunguje alebo čo by ti pomohlo.
                        </p>
                    </div>
                    <ChevronRight className="size-4 shrink-0 text-firol-400 transition-transform duration-150 group-hover:translate-x-0.5" />
                </button>
            </div>

            <p className="mt-6 text-center text-[11px] text-ink-300 tabular-nums">
                Firol {__APP_VERSION__}
            </p>

            {feedbackOpen && (
                <FeedbackDialog
                    onClose={() => setFeedbackOpen(false)}
                    sourceUrl={
                        window.location.origin +
                        location.pathname +
                        location.search
                    }
                />
            )}
        </div>
    );
}

// ─── Section back link (mobile only) ─────────────────────────────────────────

export function SectionBack({ label }: { label: string }) {
    return (
        <div className="sm:hidden">
            <Link
                to="/settings"
                className="inline-flex items-center gap-1.5 rounded-xl py-1 text-sm font-medium text-ink-500 transition-colors hover:text-ink-800"
            >
                <ChevronLeft className="size-4" />
                Nastavenia
            </Link>
            <h2 className="mt-1 text-lg font-semibold tracking-tight text-ink-900">
                {label}
            </h2>
        </div>
    );
}

// ─── Inspector Profile Page ───────────────────────────────────────────────────

export function InspectorProfilePage() {
    return (
        <>
            <SectionBack label="Profil revízneho technika" />
            <InspectorProfileSection />
        </>
    );
}

function InspectorProfileSection() {
    const { csrfToken } = useAuth();
    const toast = useToast();

    const [profile, setProfile] = useState<InspectorProfile | null>(null);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [signatureCacheBust, setSignatureCacheBust] = useState<number>(() =>
        Date.now(),
    );

    const [certPhp, setCertPhp] = useState("");
    const [validFromPhp, setValidFromPhp] = useState("");
    const [validToPhp, setValidToPhp] = useState("");
    const [certOprava, setCertOprava] = useState("");
    const [validFromOprava, setValidFromOprava] = useState("");
    const [validToOprava, setValidToOprava] = useState("");
    const [certGeneral, setCertGeneral] = useState("");
    const [validFromGeneral, setValidFromGeneral] = useState("");
    const [validToGeneral, setValidToGeneral] = useState("");

    const [showSigPicker, setShowSigPicker] = useState(false);

    useEffect(() => {
        let cancelled = false;
        InspectorProfileApi.show()
            .then((res) => {
                if (cancelled) return;
                applyProfile(res.profile);
            })
            .catch((err: unknown) => {
                if (cancelled) return;
                setError(
                    err instanceof ApiError
                        ? err.message
                        : "Nepodarilo sa načítať profil.",
                );
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });
        return () => {
            cancelled = true;
        };
    }, []);

    function applyProfile(p: InspectorProfile) {
        setProfile(p);
        setCertPhp(p.cert_php ?? "");
        setValidFromPhp(p.valid_from_php ?? "");
        setValidToPhp(p.valid_to_php ?? "");
        setCertOprava(p.cert_oprava ?? "");
        setValidFromOprava(p.valid_from_oprava ?? "");
        setValidToOprava(p.valid_to_oprava ?? "");
        setCertGeneral(p.cert_general ?? "");
        setValidFromGeneral(p.valid_from_general ?? "");
        setValidToGeneral(p.valid_to_general ?? "");
    }

    async function onSubmit(e: FormEvent) {
        e.preventDefault();
        setError(null);
        setSaving(true);
        try {
            const res = await InspectorProfileApi.update(
                {
                    cert_php: certPhp.trim() || null,
                    cert_oprava: certOprava.trim() || null,
                    cert_general: certGeneral.trim() || null,
                    valid_from_php: validFromPhp || null,
                    valid_to_php: validToPhp || null,
                    valid_from_oprava: validFromOprava || null,
                    valid_to_oprava: validToOprava || null,
                    valid_from_general: validFromGeneral || null,
                    valid_to_general: validToGeneral || null,
                },
                csrfToken,
            );
            applyProfile(res.profile);
            toast.success("Profil revízneho technika uložený");
        } catch (err) {
            const msg =
                err instanceof ApiError ? err.message : "Niečo sa pokazilo.";
            setError(msg);
            toast.error(msg);
        } finally {
            setSaving(false);
        }
    }

    async function onSignatureChosen(blob: Blob) {
        setShowSigPicker(false);
        setError(null);
        setSaving(true);
        try {
            const res = await InspectorProfileApi.uploadSignature(
                blob,
                csrfToken,
            );
            applyProfile(res.profile);
            setSignatureCacheBust(Date.now());
            toast.success("Podpis nahraný");
        } catch (err) {
            const msg =
                err instanceof ApiError
                    ? err.message
                    : "Nahranie podpisu sa nepodarilo.";
            setError(msg);
            toast.error(msg);
        } finally {
            setSaving(false);
        }
    }

    if (loading) {
        return (
            <div className="flex flex-col gap-4">
                <CardBlockSkeleton rows={4} />
                <CardBlockSkeleton rows={4} />
            </div>
        );
    }

    return (
        <Card className="overflow-hidden">
            <div className="flex items-center gap-3 border-b border-ink-100 bg-gradient-to-br from-firol-50/60 to-transparent px-5 py-4">
                <div className="grid size-11 place-items-center rounded-2xl bg-firol-500 text-white shadow-[var(--shadow-glow)]">
                    <ShieldCheck className="size-5" />
                </div>
                <div className="min-w-0 flex-1">
                    <h2 className="text-base font-semibold text-ink-900">
                        Profil revízneho technika
                    </h2>
                    <p className="text-xs text-ink-500">
                        Podpis, číslo oprávnenia a platnosť — všetko pôjde do
                        hlavičky a päty PDF.
                    </p>
                </div>
                {profile && (
                    <Badge tone={profile.is_active ? "ok" : "neutral"}>
                        {profile.is_active ? "Aktívny" : "Neaktívny"}
                    </Badge>
                )}
            </div>

            <div className="grid gap-5 px-5 py-5 lg:grid-cols-[260px_1fr]">
                <div className="flex flex-col gap-3">
                    <span className="text-[10px] font-semibold uppercase tracking-wider text-ink-400">
                        Podpis
                    </span>
                    <SignaturePreview
                        hasSignature={profile?.has_signature ?? false}
                        cacheBuster={signatureCacheBust}
                    />
                    <Button
                        type="button"
                        variant="secondary"
                        leftIcon={<FileSignature className="size-4" />}
                        onClick={() => setShowSigPicker(true)}
                        loading={saving}
                    >
                        {profile?.has_signature
                            ? "Zmeniť podpis"
                            : "Pridať podpis"}
                    </Button>
                    {showSigPicker && (
                        <SignaturePickerModal
                            onClose={() => setShowSigPicker(false)}
                            onSave={onSignatureChosen}
                            saving={saving}
                        />
                    )}
                </div>

                <form
                    onSubmit={onSubmit}
                    className="flex flex-col gap-3"
                    noValidate
                >
                    <p className="rounded-xl border border-ink-100 bg-ink-50/40 px-3 py-2 text-xs text-ink-500">
                        Každý typ kontroly vyžaduje iné oprávnenie. Vypíš čísla,
                        ktoré máš — do každého PDF pôjde to správne.
                    </p>

                    <CertCard
                        color="firol"
                        title="Kontrola PHP"
                        subtitle="Oprávnenie na kontrolu hasiacich prístrojov"
                        certValue={certPhp}
                        certPlaceholder="napr. RT-PHP-2024-0123"
                        onCertChange={setCertPhp}
                        validFrom={validFromPhp}
                        validTo={validToPhp}
                        onValidFromChange={setValidFromPhp}
                        onValidToChange={setValidToPhp}
                    />

                    <CertCard
                        color="violet"
                        title="Oprava / plnenie / TS PHP"
                        subtitle="Oprávnenie na opravu, plnenie a tlakovú skúšku PHP"
                        certValue={certOprava}
                        certPlaceholder="napr. RT-TS-2024-0456"
                        onCertChange={setCertOprava}
                        validFrom={validFromOprava}
                        validTo={validToOprava}
                        onValidFromChange={setValidFromOprava}
                        onValidToChange={setValidToOprava}
                    />

                    <CertCard
                        color="blue"
                        title="Technik PO"
                        subtitle="Hydranty, požiarna kniha, PU, núdzové osvetlenia, TS hadíc, školenia"
                        certValue={certGeneral}
                        certPlaceholder="napr. TPO-2024-0789"
                        onCertChange={setCertGeneral}
                        validFrom={validFromGeneral}
                        validTo={validToGeneral}
                        onValidFromChange={setValidFromGeneral}
                        onValidToChange={setValidToGeneral}
                    />

                    {error && (
                        <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
                            {error}
                        </div>
                    )}

                    <div className="flex justify-end pt-1">
                        <Button
                            type="submit"
                            loading={saving}
                            leftIcon={<FileSignature className="size-4" />}
                        >
                            Uložiť profil
                        </Button>
                    </div>
                </form>
            </div>
        </Card>
    );
}

// ─── Branding Page ────────────────────────────────────────────────────────────

export function BrandingPage() {
    return (
        <>
            <SectionBack label="Branding na PDF" />
            <BrandingSection />
        </>
    );
}

const FIROL_DEFAULT_COLOR = "#e8433a";

function BrandingSection() {
    const { csrfToken } = useAuth();
    const toast = useToast();
    const confirm = useConfirm();
    const [account, setAccount] = useState<Account | null>(null);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [logoCacheBust, setLogoCacheBust] = useState<number>(() =>
        Date.now(),
    );

    const [companyName, setCompanyName] = useState("");
    const [themeColor, setThemeColor] = useState(FIROL_DEFAULT_COLOR);
    const [usingCustomColor, setUsingCustomColor] = useState(false);

    const logoInputRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        let cancelled = false;
        AccountApi.show()
            .then((res) => {
                if (cancelled) return;
                applyAccount(res.account);
            })
            .catch((err: unknown) => {
                if (cancelled) return;
                setError(
                    err instanceof ApiError
                        ? err.message
                        : "Nepodarilo sa načítať brand.",
                );
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });
        return () => {
            cancelled = true;
        };
    }, []);

    function applyAccount(a: Account) {
        setAccount(a);
        setCompanyName(a.invoice_company_name ?? "");
        if (a.theme_color) {
            setThemeColor(a.theme_color);
            setUsingCustomColor(true);
        } else {
            setThemeColor(FIROL_DEFAULT_COLOR);
            setUsingCustomColor(false);
        }
    }

    async function onSubmit(e: FormEvent) {
        e.preventDefault();
        setError(null);
        setSaving(true);
        try {
            const res = await AccountApi.update(
                {
                    invoice_company_name: companyName.trim() || null,
                    theme_color: usingCustomColor ? themeColor : "",
                },
                csrfToken,
            );
            applyAccount(res.account);
            toast.success("Brand uložený");
        } catch (err) {
            const msg =
                err instanceof ApiError ? err.message : "Niečo sa pokazilo.";
            setError(msg);
            toast.error(msg);
        } finally {
            setSaving(false);
        }
    }

    async function onLogoChosen(file: File | undefined) {
        if (!file) return;
        setError(null);
        setBusy(true);
        try {
            const res = await AccountApi.uploadLogo(file, csrfToken);
            applyAccount(res.account);
            setLogoCacheBust(Date.now());
            toast.success("Logo nahrané");
        } catch (err) {
            const msg =
                err instanceof ApiError
                    ? err.message
                    : "Nahranie loga sa nepodarilo.";
            setError(msg);
            toast.error(msg);
        } finally {
            setBusy(false);
        }
    }

    async function onLogoDelete() {
        const ok = await confirm({
            title: "Odstrániť logo?",
            description: "Logo zmizne z PDF protokolov.",
            confirmLabel: "Odstrániť",
        });
        if (!ok) return;
        setError(null);
        setBusy(true);
        try {
            const res = await AccountApi.deleteLogo(csrfToken);
            applyAccount(res.account);
            setLogoCacheBust(Date.now());
            toast.success("Logo odstránené");
        } catch (err) {
            const msg =
                err instanceof ApiError
                    ? err.message
                    : "Odstránenie loga zlyhalo.";
            setError(msg);
            toast.error(msg);
        } finally {
            setBusy(false);
        }
    }

    if (loading) {
        return <CardBlockSkeleton rows={5} />;
    }

    return (
        <Card className="overflow-hidden">
            <div className="flex items-center gap-3 border-b border-ink-100 bg-gradient-to-br from-firol-50/60 to-transparent px-5 py-4">
                <div
                    className="grid size-11 place-items-center rounded-2xl text-white shadow-[var(--shadow-glow)]"
                    style={{
                        background: usingCustomColor
                            ? themeColor
                            : "var(--color-firol-500)",
                    }}
                >
                    <Palette className="size-5" />
                </div>
                <div className="min-w-0 flex-1">
                    <h2 className="text-base font-semibold text-ink-900">
                        Branding na PDF
                    </h2>
                    <p className="text-xs text-ink-500">
                        Logo, farba a názov spoločnosti — objavia sa v hlavičke
                        každého protokolu.
                    </p>
                </div>
                {account?.has_logo && <Badge tone="ok">Logo nahrané</Badge>}
            </div>

            <div className="grid gap-5 px-5 py-5 lg:grid-cols-[260px_1fr]">
                <div className="flex flex-col gap-3">
                    <span className="text-[10px] font-semibold uppercase tracking-wider text-ink-400">
                        Logo
                    </span>
                    <LogoPreview
                        hasLogo={account?.has_logo ?? false}
                        cacheBuster={logoCacheBust}
                    />
                    <input
                        ref={logoInputRef}
                        type="file"
                        accept="image/png,image/jpeg"
                        className="hidden"
                        onChange={(e) => onLogoChosen(e.target.files?.[0])}
                    />
                    <div className="flex gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            leftIcon={<UploadCloud className="size-4" />}
                            onClick={() => logoInputRef.current?.click()}
                            loading={busy}
                            className="flex-1"
                        >
                            {account?.has_logo
                                ? "Nahrať nové logo"
                                : "Nahrať logo"}
                        </Button>
                        {account?.has_logo && (
                            <button
                                type="button"
                                onClick={onLogoDelete}
                                disabled={busy}
                                title="Odstrániť logo"
                                className="grid size-10 place-items-center rounded-xl text-ink-500 transition-colors hover:bg-[var(--color-status-bad-bg)] hover:text-status-bad disabled:opacity-50"
                            >
                                <Trash2 className="size-4" />
                            </button>
                        )}
                    </div>
                    <p className="text-xs text-ink-400">
                        PNG alebo JPG, max 1 MB. Odporúčame priehľadné PNG.
                    </p>
                </div>

                <form
                    onSubmit={onSubmit}
                    className="flex flex-col gap-4"
                    noValidate
                >
                    <Field
                        label="Názov spoločnosti"
                        hint={
                            "Zobrazí sa v záhlaví PDF: „<Názov> · Záznam o kontrole PHP”"
                        }
                    >
                        {(p) => (
                            <Input
                                {...p}
                                leftIcon={<Building2 className="size-4" />}
                                value={companyName}
                                onChange={(e) => setCompanyName(e.target.value)}
                                placeholder="Firol s.r.o."
                            />
                        )}
                    </Field>

                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-ink-800">
                            Hlavná farba
                        </label>
                        <div className="flex items-center gap-2">
                            <input
                                type="color"
                                value={themeColor}
                                onChange={(e) => {
                                    setThemeColor(e.target.value);
                                    setUsingCustomColor(true);
                                }}
                                className="size-11 cursor-pointer rounded-xl border border-ink-200 bg-white p-1"
                                aria-label="Vybrať hlavnú farbu"
                            />
                            <Input
                                value={themeColor}
                                onChange={(e) => {
                                    setThemeColor(e.target.value);
                                    setUsingCustomColor(true);
                                }}
                                placeholder="#E8433A"
                                className="font-mono uppercase"
                            />
                            <button
                                type="button"
                                onClick={() => {
                                    setThemeColor(FIROL_DEFAULT_COLOR);
                                    setUsingCustomColor(false);
                                }}
                                disabled={!usingCustomColor}
                                title="Obnoviť pôvodnú Firol farbu"
                                className="grid size-10 shrink-0 place-items-center rounded-xl text-ink-500 transition-colors hover:bg-ink-100 hover:text-ink-700 disabled:opacity-40"
                            >
                                <RotateCcw className="size-4" />
                            </button>
                        </div>
                        <p className="mt-1.5 text-xs text-ink-400">
                            Použije sa na hlavičkový pruh a nadpisy v PDF.
                        </p>
                    </div>

                    {error && (
                        <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
                            {error}
                        </div>
                    )}

                    <div className="flex justify-end pt-1">
                        <Button
                            type="submit"
                            loading={saving}
                            leftIcon={<FileSignature className="size-4" />}
                        >
                            Uložiť brand
                        </Button>
                    </div>
                </form>
            </div>
        </Card>
    );
}

function LogoPreview({
    hasLogo,
    cacheBuster,
}: {
    hasLogo: boolean;
    cacheBuster: number;
}) {
    if (!hasLogo) {
        return (
            <div
                className="relative w-full overflow-hidden rounded-2xl border border-dashed border-ink-200 bg-white"
                style={{ paddingTop: "calc(100% / 3)" }}
            >
                <div className="absolute inset-0 flex flex-col items-center justify-center gap-1 text-center text-ink-400">
                    <ImagePlus className="size-5" />
                    <span className="text-xs">Zatiaľ bez loga</span>
                </div>
            </div>
        );
    }
    return (
        <div
            className="relative w-full overflow-hidden rounded-2xl border border-ink-200 bg-white"
            style={{ paddingTop: "calc(100% / 3)" }}
        >
            <div className="absolute inset-0 flex items-center justify-center p-3">
                <img
                    src={AccountApi.logoUrl(cacheBuster)}
                    alt="Logo spoločnosti"
                    className="max-h-full max-w-full object-contain"
                />
            </div>
        </div>
    );
}

// Trainers section was removed — any active technician can act as a trainer
// on a training PDF (see migration 013_drop_trainers_use_users).

// ─── Team Page ────────────────────────────────────────────────────────────────

export function TeamPage() {
    return (
        <>
            <SectionBack label="Technici" />
            <TeamSection />
        </>
    );
}

function TeamSection() {
    const { csrfToken, user, accounts, activeAccountId } = useAuth();
    const toast = useToast();
    const confirm = useConfirm();

    const activeAccount =
        accounts.find((a) => a.id === activeAccountId) ?? null;
    const isMain =
        user !== null &&
        activeAccount !== null &&
        activeAccount.main_user_id === user.id;

    const [members, setMembers] = useState<TeamMember[] | null>(null);
    const [invites, setInvites] = useState<PendingInvite[] | null>(null);
    const [seatInfo, setSeatInfo] = useState<Account | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busyId, setBusyId] = useState<number | null>(null);
    const [busyInviteId, setBusyInviteId] = useState<number | null>(null);
    const [busyDefault, setBusyDefault] = useState<TeamDefaultKind | null>(
        null,
    );
    const [adding, setAdding] = useState(false);

    const [showInvite, setShowInvite] = useState(false);
    const [inviteName, setInviteName] = useState("");
    const [inviteEmail, setInviteEmail] = useState("");
    const [invitePhone, setInvitePhone] = useState("");
    const [inviteLink, setInviteLink] = useState<string | null>(null);
    const [linkCopied, setLinkCopied] = useState(false);
    const [inviteFieldErrors, setInviteFieldErrors] = useState<{
        name?: string;
        email?: string;
    }>({});

    function reloadSeats() {
        AccountApi.show()
            .then((res) => setSeatInfo(res.account))
            .catch(() => {});
    }

    function reloadInvites() {
        Team.listInvites()
            .then((res) => setInvites(res.items))
            .catch(() => {});
    }

    useEffect(() => {
        let cancelled = false;
        Team.list()
            .then((res) => {
                if (!cancelled) setMembers(res.items);
            })
            .catch((err: unknown) => {
                if (cancelled) return;
                setError(
                    err instanceof ApiError
                        ? err.message
                        : "Nepodarilo sa načítať tím.",
                );
            });
        reloadSeats();
        reloadInvites();
        return () => {
            cancelled = true;
        };
    }, []);

    const included = seatInfo?.included_technicians ?? 0;
    const active =
        seatInfo?.active_technicians ??
        members?.filter((m) => m.is_active).length ??
        0;
    const extra = Math.max(0, active - included);
    const max = seatInfo?.max_self_service_technicians ?? 20;
    const perExtra = (seatInfo?.price_per_extra_technician_cents ?? 0) / 100;
    const atCap = active >= max;

    async function onInvite(e: FormEvent) {
        e.preventDefault();
        const errs: typeof inviteFieldErrors = {};
        if (!inviteName.trim()) errs.name = "Doplň meno a priezvisko.";
        if (!inviteEmail.trim()) errs.email = "Doplň e-mail.";
        if (Object.keys(errs).length > 0) {
            setInviteFieldErrors(errs);
            return;
        }
        setInviteFieldErrors({});
        setError(null);
        setAdding(true);
        try {
            const res = await Team.invite(
                {
                    fullname: inviteName.trim(),
                    email: inviteEmail.trim(),
                    phone: invitePhone.trim() || null,
                },
                csrfToken,
            );
            reloadInvites();
            toast.success(
                "Pozvánka odoslaná. Technik sa pridá do tímu po jej potvrdení.",
            );
            if (res.invite_token) {
                // Fallback link — let the inviter copy & forward if the email
                // bounces or the technician can't find it.
                const link = `${window.location.origin}/invite/accept?token=${res.invite_token}`;
                setInviteLink(link);
                setLinkCopied(false);
            } else {
                setShowInvite(false);
            }
            setInviteName("");
            setInviteEmail("");
            setInvitePhone("");
        } catch (err) {
            const msg =
                err instanceof ApiError
                    ? err.message
                    : "Pozvánku sa nepodarilo vytvoriť.";
            setError(msg);
            toast.error(msg);
        } finally {
            setAdding(false);
        }
    }

    async function onToggleActive(m: TeamMember) {
        setBusyId(m.id);
        try {
            const res = await Team.setActive(m.id, !m.is_active, csrfToken);
            setMembers((prev) =>
                prev ? prev.map((x) => (x.id === m.id ? res.item : x)) : prev,
            );
            reloadSeats();
            toast.success(
                res.item.is_active
                    ? "Technik aktivovaný"
                    : "Technik deaktivovaný",
            );
        } catch (err) {
            const msg =
                err instanceof ApiError ? err.message : "Operácia zlyhala.";
            setError(msg);
            toast.error(msg);
        } finally {
            setBusyId(null);
        }
    }

    async function onRemove(m: TeamMember) {
        const ok = await confirm({
            title: "Odstrániť technika?",
            description: `${m.fullname} sa odpojí od tejto firmy. Jeho účet zostane zachovaný.`,
            confirmLabel: "Odstrániť",
        });
        if (!ok) return;
        setBusyId(m.id);
        try {
            await Team.remove(m.id, csrfToken);
            setMembers((prev) =>
                prev ? prev.filter((x) => x.id !== m.id) : prev,
            );
            reloadSeats();
            toast.success("Technik odstránený");
        } catch (err) {
            const msg =
                err instanceof ApiError ? err.message : "Odstránenie zlyhalo.";
            setError(msg);
            toast.error(msg);
        } finally {
            setBusyId(null);
        }
    }

    async function onCancelInvite(invite: PendingInvite) {
        const ok = await confirm({
            title: "Zrušiť pozvánku?",
            description: `Odkaz pre ${invite.email} prestane platiť.`,
            confirmLabel: "Zrušiť pozvánku",
            cancelLabel: "Späť",
        });
        if (!ok) return;
        setBusyInviteId(invite.id);
        try {
            await Team.cancelInvite(invite.id, csrfToken);
            setInvites((prev) =>
                prev ? prev.filter((x) => x.id !== invite.id) : prev,
            );
            toast.success("Pozvánka zrušená");
        } catch (err) {
            const msg =
                err instanceof ApiError ? err.message : "Zrušenie zlyhalo.";
            setError(msg);
            toast.error(msg);
        } finally {
            setBusyInviteId(null);
        }
    }

    function reloadMembers() {
        Team.list()
            .then((res) => setMembers(res.items))
            .catch(() => {});
    }

    async function onSetDefault(kind: TeamDefaultKind, userId: number | null) {
        setBusyDefault(kind);
        try {
            await Team.setDefault(kind, userId, csrfToken);
            reloadMembers();
            toast.success(
                userId === null
                    ? "Predvolený technik zrušený"
                    : "Predvolený technik nastavený",
            );
        } catch (err) {
            const msg =
                err instanceof ApiError
                    ? err.message
                    : "Nastavenie sa nepodarilo.";
            setError(msg);
            toast.error(msg);
        } finally {
            setBusyDefault(null);
        }
    }

    async function copyLink() {
        if (!inviteLink) return;
        try {
            await navigator.clipboard.writeText(inviteLink);
            setLinkCopied(true);
            toast.success("Odkaz skopírovaný");
        } catch {
            toast.error("Kopírovanie zlyhalo, vyber odkaz manuálne.");
        }
    }

    return (
        <Card className="overflow-hidden">
            <div className="flex items-start gap-3 border-b border-ink-100 bg-gradient-to-br from-firol-50/60 to-transparent px-5 py-4">
                <div className="grid size-11 shrink-0 place-items-center rounded-2xl bg-firol-500 text-white shadow-[var(--shadow-glow)]">
                    <UsersRound className="size-5" />
                </div>
                <div className="min-w-0 flex-1">
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <div className="min-w-0 flex-1">
                            <h2 className="text-base font-semibold text-ink-900">
                                Technici
                            </h2>
                            <p className="text-xs text-ink-500">
                                Ľudia, ktorí majú prístup do tejto firmy.
                                Spravovať môže len hlavný používateľ.
                            </p>
                        </div>
                        {isMain && (
                            <Button
                                type="button"
                                variant="secondary"
                                leftIcon={<MailPlus className="size-4" />}
                                onClick={() => {
                                    setShowInvite((v) => !v);
                                    setInviteLink(null);
                                    setInviteFieldErrors({});
                                }}
                                disabled={atCap}
                                title={
                                    atCap
                                        ? `Limit ${max} technikov dosiahnutý — kontaktuj nás pre individuálnu ponuku.`
                                        : undefined
                                }
                                className="self-start shrink-0"
                            >
                                {showInvite ? "Zrušiť" : "Pozvať technika"}
                            </Button>
                        )}
                    </div>
                </div>
            </div>

            <div className="px-5 py-4">
                {seatInfo && (
                    <div className="mb-3 rounded-2xl border border-ink-100 bg-ink-50/40 px-3 py-2.5 text-xs text-ink-600">
                        <span className="font-semibold text-ink-800">
                            Predplatné
                        </span>{" "}
                        zahŕňa {included} technikov (vrátane teba ako admina).
                        Aktívnych v tíme:{" "}
                        <span className="font-semibold">{active}</span>
                        {extra > 0 && (
                            <>
                                {" "}
                                · extra{" "}
                                <span className="font-semibold">
                                    {extra}
                                </span> × {perExtra.toFixed(2)} €/mes
                            </>
                        )}
                        .{" "}
                        {isMain && !atCap && (
                            <>
                                Každý ďalší technik nad zahrnutý počet stojí{" "}
                                {perExtra.toFixed(2)} € / mes a pripočíta sa
                                proratovane k najbližšej fakture.
                            </>
                        )}
                    </div>
                )}

                {isMain && atCap && (
                    <div className="mb-3 flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50/80 px-3 py-2.5 text-sm text-amber-900">
                        <UsersRound className="mt-0.5 size-4 shrink-0 text-amber-600" />
                        <div className="min-w-0 flex-1">
                            <p className="font-semibold">
                                Si na hranici self-service plánu ({max}{" "}
                                technikov).
                            </p>
                            <p className="mt-0.5 text-xs text-amber-800">
                                Pre väčší tím nám napíš — pripravíme
                                individuálnu cenovú ponuku. Po dohode admin
                                Firolu zvýši zahrnutý počet technikov pre tvoj
                                účet.
                            </p>
                        </div>
                    </div>
                )}

                {error && (
                    <div className="mb-3 rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
                        {error}
                    </div>
                )}

                {isMain && showInvite && (
                    <form
                        onSubmit={onInvite}
                        className="mb-4 flex flex-col gap-3 rounded-2xl border border-dashed border-ink-200 p-3"
                    >
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field
                                label="Meno a priezvisko"
                                required
                                error={inviteFieldErrors.name}
                            >
                                {(p) => (
                                    <Input
                                        {...p}
                                        required
                                        leftIcon={<User className="size-4" />}
                                        value={inviteName}
                                        onChange={(e) => {
                                            setInviteName(e.target.value);
                                            if (inviteFieldErrors.name)
                                                setInviteFieldErrors(
                                                    (prev) => ({
                                                        ...prev,
                                                        name: undefined,
                                                    }),
                                                );
                                        }}
                                        placeholder="Ján Technik"
                                    />
                                )}
                            </Field>
                            <Field
                                label="E-mail"
                                required
                                error={inviteFieldErrors.email}
                            >
                                {(p) => (
                                    <Input
                                        {...p}
                                        required
                                        type="email"
                                        leftIcon={<AtSign className="size-4" />}
                                        value={inviteEmail}
                                        onChange={(e) => {
                                            setInviteEmail(e.target.value);
                                            if (inviteFieldErrors.email)
                                                setInviteFieldErrors(
                                                    (prev) => ({
                                                        ...prev,
                                                        email: undefined,
                                                    }),
                                                );
                                        }}
                                        placeholder="jan@firma.sk"
                                    />
                                )}
                            </Field>
                        </div>
                        <Field label="Telefón">
                            {(p) => (
                                <Input
                                    {...p}
                                    leftIcon={<Phone className="size-4" />}
                                    value={invitePhone}
                                    onChange={(e) =>
                                        setInvitePhone(e.target.value)
                                    }
                                    placeholder="+421 900 000 000"
                                />
                            )}
                        </Field>
                        {Object.keys(inviteFieldErrors).length > 0 && (
                            <p className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
                                Formulár obsahuje nevyplnené povinné polia.
                            </p>
                        )}
                        <div className="flex items-center justify-end gap-2">
                            <Button
                                type="submit"
                                loading={adding}
                                leftIcon={<MailPlus className="size-4" />}
                            >
                                Vytvoriť pozvánku
                            </Button>
                        </div>
                    </form>
                )}

                {inviteLink && (
                    <div className="mb-4 rounded-2xl border border-firol-200 bg-firol-50/60 p-3">
                        <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-firol-700">
                            Záložný odkaz na potvrdenie
                        </p>
                        <p className="mb-2 text-xs text-ink-600">
                            Pozvánka bola odoslaná emailom. Ak nedorazí, pošli
                            technikovi tento odkaz — platí 7 dní.
                        </p>
                        <div className="flex gap-2">
                            <Input
                                value={inviteLink}
                                readOnly
                                className="font-mono text-xs"
                                onFocus={(e) => e.currentTarget.select()}
                            />
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={copyLink}
                                leftIcon={
                                    linkCopied ? (
                                        <Check className="size-4" />
                                    ) : (
                                        <Copy className="size-4" />
                                    )
                                }
                            >
                                {linkCopied ? "Skopírované" : "Kopírovať"}
                            </Button>
                        </div>
                    </div>
                )}

                {invites !== null && invites.length > 0 && (
                    <div className="mb-4">
                        <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-ink-500">
                            Čakajúce pozvánky ({invites.length})
                        </p>
                        <ul className="flex flex-col gap-2">
                            {invites.map((inv) => (
                                <li
                                    key={inv.id}
                                    className="flex items-center gap-3 rounded-2xl border border-dashed border-amber-200 bg-amber-50/40 px-3 py-2.5"
                                >
                                    <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-amber-100 text-amber-700">
                                        <MailPlus className="size-4" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-semibold text-ink-900">
                                            {inv.fullname}
                                        </p>
                                        <p className="mt-0.5 flex items-center gap-1.5 truncate text-xs text-ink-500">
                                            <span className="truncate">
                                                {inv.email}
                                            </span>
                                            <span className="text-ink-300">
                                                ·
                                            </span>
                                            <Badge tone="warn">
                                                Čaká na potvrdenie
                                            </Badge>
                                        </p>
                                    </div>
                                    {isMain && (
                                        <button
                                            type="button"
                                            onClick={() => onCancelInvite(inv)}
                                            disabled={busyInviteId === inv.id}
                                            title="Zrušiť pozvánku"
                                            className="grid size-9 place-items-center rounded-xl text-ink-500 transition-colors hover:bg-[var(--color-status-bad-bg)] hover:text-status-bad disabled:opacity-50"
                                        >
                                            {busyInviteId === inv.id ? (
                                                <Spinner size="sm" />
                                            ) : (
                                                <Trash2 className="size-4" />
                                            )}
                                        </button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {isMain && members !== null && (
                    <div className="mb-4 rounded-2xl border border-ink-100 bg-ink-50/40 px-4 py-3">
                        <p className="mb-2.5 text-xs font-semibold uppercase tracking-wider text-ink-500">
                            Predvolený technik
                        </p>
                        <p className="mb-3 text-xs text-ink-500">
                            Ak technik nemá vlastné oprávnenie, pri kontrole sa
                            použije číslo predvoleného technika.
                        </p>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <DefaultPicker
                                label="Kontrola PHP"
                                kind="php"
                                members={members}
                                busy={busyDefault === "php"}
                                onSet={(userId) => onSetDefault("php", userId)}
                            />
                            <DefaultPicker
                                label="Oprava / plnenie / TS PHP"
                                kind="oprava"
                                members={members}
                                busy={busyDefault === "oprava"}
                                onSet={(userId) =>
                                    onSetDefault("oprava", userId)
                                }
                            />
                        </div>
                    </div>
                )}

                {members === null ? (
                    <SkeletonList count={2} />
                ) : (
                    <ul className="flex flex-col gap-2">
                        {members.map((m) => {
                            const isSelf = user !== null && m.id === user.id;
                            const canMutate = isMain && !m.is_main && !isSelf;
                            return (
                                <li
                                    key={m.id}
                                    className="flex flex-col gap-2.5 rounded-2xl border border-ink-100 px-3 py-2.5"
                                >
                                    <div className="flex items-center gap-3">
                                        <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-firol-50 text-firol-600">
                                            <User className="size-4" />
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-semibold text-ink-900">
                                                {m.fullname}
                                                {isSelf && (
                                                    <span className="ml-1.5 text-xs font-normal text-ink-400">
                                                        (ty)
                                                    </span>
                                                )}
                                            </p>
                                            <p className="mt-0.5 flex items-center gap-1.5 truncate text-xs text-ink-500">
                                                <span className="truncate">
                                                    {m.email}
                                                </span>
                                                <span className="text-ink-300">
                                                    ·
                                                </span>
                                                {m.is_main ? (
                                                    <Badge tone="ok">
                                                        Hlavný
                                                    </Badge>
                                                ) : m.is_active ? (
                                                    <Badge tone="neutral">
                                                        Technik
                                                    </Badge>
                                                ) : (
                                                    <Badge tone="warn">
                                                        Neaktívny
                                                    </Badge>
                                                )}
                                            </p>
                                        </div>
                                        {canMutate && (
                                            <>
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        onToggleActive(m)
                                                    }
                                                    disabled={busyId === m.id}
                                                    title={
                                                        m.is_active
                                                            ? "Deaktivovať"
                                                            : "Aktivovať"
                                                    }
                                                    className="grid size-9 place-items-center rounded-xl text-ink-500 transition-colors hover:bg-ink-100 hover:text-ink-700 disabled:opacity-50"
                                                >
                                                    {busyId === m.id ? (
                                                        <Spinner size="sm" />
                                                    ) : m.is_active ? (
                                                        <ShieldOff className="size-4" />
                                                    ) : (
                                                        <UserCheck className="size-4" />
                                                    )}
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => onRemove(m)}
                                                    disabled={busyId === m.id}
                                                    title="Odstrániť z tímu"
                                                    className="grid size-9 place-items-center rounded-xl text-ink-500 transition-colors hover:bg-[var(--color-status-bad-bg)] hover:text-status-bad disabled:opacity-50"
                                                >
                                                    <Trash2 className="size-4" />
                                                </button>
                                            </>
                                        )}
                                    </div>

                                    <div className="grid gap-2 grid-cols-1 sm:grid-cols-3">
                                        <CertSummaryBlock
                                            color="firol"
                                            title="Kontrola PHP"
                                            certNumber={m.cert_php}
                                            validTo={m.valid_to_php}
                                            isDefault={m.is_default_php}
                                        />
                                        <CertSummaryBlock
                                            color="violet"
                                            title="Oprava / plnenie / TS PHP"
                                            certNumber={m.cert_oprava}
                                            validTo={m.valid_to_oprava}
                                            isDefault={m.is_default_oprava}
                                        />
                                        <CertSummaryBlock
                                            color="blue"
                                            title="Technik PO"
                                            certNumber={m.cert_general}
                                            validTo={m.valid_to_general}
                                        />
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}

                {!isMain && (
                    <p className="mt-3 text-xs text-ink-400">
                        Si prihlásený ako technik. Správu tímu robí hlavný
                        používateľ účtu.
                    </p>
                )}
            </div>
        </Card>
    );
}

// ─── Cert Card ────────────────────────────────────────────────────────────────

type CertCardColor = "firol" | "violet" | "blue";

const CERT_CARD_STYLES: Record<
    CertCardColor,
    { border: string; bg: string; iconBg: string; iconColor: string }
> = {
    firol: {
        border: "border-firol-200",
        bg: "bg-firol-50/40",
        iconBg: "bg-firol-100",
        iconColor: "text-firol-600",
    },
    violet: {
        border: "border-violet-200",
        bg: "bg-violet-50/40",
        iconBg: "bg-violet-100",
        iconColor: "text-violet-600",
    },
    blue: {
        border: "border-blue-200",
        bg: "bg-blue-50/40",
        iconBg: "bg-blue-100",
        iconColor: "text-blue-600",
    },
};

function certDaysLeft(validTo: string): number | null {
    if (!validTo) return null;
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    // Parse as local date — "YYYY-MM-DD" without time is treated as UTC by the
    // Date constructor, which causes off-by-one errors in timezones ahead of UTC.
    const [y, m, d] = validTo.split("-").map(Number);
    const expiry = new Date(y, m - 1, d);
    return Math.ceil((expiry.getTime() - today.getTime()) / 86_400_000);
}

function CertCard({
    color,
    title,
    subtitle,
    certValue,
    certPlaceholder,
    onCertChange,
    validFrom,
    validTo,
    onValidFromChange,
    onValidToChange,
}: {
    color: CertCardColor;
    title: string;
    subtitle: string;
    certValue: string;
    certPlaceholder: string;
    onCertChange: (v: string) => void;
    validFrom: string;
    validTo: string;
    onValidFromChange: (v: string) => void;
    onValidToChange: (v: string) => void;
}) {
    const s = CERT_CARD_STYLES[color];
    const days = certDaysLeft(validTo);
    const isExpired = days !== null && days < 0;
    const isExpiringSoon = days !== null && days >= 0 && days <= 30;

    return (
        <div
            className={cn(
                "rounded-2xl border p-4 flex flex-col gap-3",
                s.border,
                s.bg,
            )}
        >
            <div className="flex items-start gap-2.5">
                <div
                    className={cn(
                        "mt-0.5 grid size-8 shrink-0 place-items-center rounded-xl",
                        s.iconBg,
                    )}
                >
                    <Hash className={cn("size-4", s.iconColor)} />
                </div>
                <div className="min-w-0 flex-1">
                    <p className="text-sm font-semibold text-ink-900">
                        {title}
                    </p>
                    <p className="mt-0.5 text-xs text-ink-500">{subtitle}</p>
                </div>
            </div>

            <Field label="Číslo oprávnenia / osvedčenia">
                {(p) => (
                    <Input
                        {...p}
                        leftIcon={<Hash className="size-4" />}
                        value={certValue}
                        onChange={(e) => onCertChange(e.target.value)}
                        placeholder={certPlaceholder}
                    />
                )}
            </Field>

            <div className="grid gap-3 grid-cols-2">
                <Field label="Platnosť od">
                    {(p) => (
                        <Input
                            {...p}
                            type="date"
                            leftIcon={<CalendarDays className="size-4" />}
                            value={validFrom}
                            onChange={(e) => onValidFromChange(e.target.value)}
                        />
                    )}
                </Field>
                <Field label="Platnosť do">
                    {(p) => (
                        <Input
                            {...p}
                            type="date"
                            leftIcon={<CalendarDays className="size-4" />}
                            value={validTo}
                            onChange={(e) => onValidToChange(e.target.value)}
                        />
                    )}
                </Field>
            </div>

            {isExpired && (
                <p className="flex items-center gap-1.5 text-xs font-medium text-[var(--color-status-bad)]">
                    <AlertTriangle className="size-3.5 shrink-0" />
                    Neplatné
                </p>
            )}
            {isExpiringSoon && (
                <p className="flex items-center gap-1.5 text-xs font-medium text-[var(--color-status-warn)]">
                    <AlertTriangle className="size-3.5 shrink-0" />
                    Blíži sa koniec platnosti
                </p>
            )}
        </div>
    );
}

function DefaultPicker({
    label,
    kind,
    members,
    busy,
    onSet,
}: {
    label: string;
    kind: TeamDefaultKind;
    members: TeamMember[];
    busy: boolean;
    onSet: (userId: number | null) => void;
}) {
    const certKey = kind === "php" ? "cert_php" : "cert_oprava";
    const eligible = members.filter((m) => m.is_active && m[certKey]);
    const currentId =
        members.find(
            kind === "php"
                ? (m) => m.is_default_php
                : (m) => m.is_default_oprava,
        )?.id ?? null;

    const options: SelectOption[] = [
        { value: "", label: "— žiadny" },
        ...eligible.map((m) => ({
            value: String(m.id),
            label: m.fullname,
            description:
                (kind === "php" ? m.cert_php : m.cert_oprava) ?? undefined,
        })),
    ];

    return (
        <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium text-ink-800">{label}</label>
            <Select
                value={currentId !== null ? String(currentId) : ""}
                onChange={(v) => onSet(v ? Number(v) : null)}
                options={options}
                disabled={busy || eligible.length === 0}
                emptyLabel="— žiadny"
                placeholder="— žiadny"
            />
            {eligible.length === 0 && (
                <p className="text-xs text-ink-400">
                    Žiadny technik nemá vyplnené oprávnenie.
                </p>
            )}
        </div>
    );
}

function CertSummaryBlock({
    color,
    title,
    certNumber,
    validTo,
    isDefault,
}: {
    color: CertCardColor;
    title: string;
    certNumber: string | null;
    validTo: string | null;
    isDefault?: boolean;
}) {
    const s = CERT_CARD_STYLES[color];
    const days = certDaysLeft(validTo ?? "");
    const isExpired = days !== null && days < 0;
    const isExpiringSoon = days !== null && days >= 0 && days <= 30;

    return (
        <div
            className={cn(
                "flex flex-col gap-1 rounded-xl border px-2.5 py-2",
                s.border,
                s.bg,
            )}
        >
            <div className="flex items-center gap-1.5">
                <div
                    className={cn(
                        "grid size-6 shrink-0 place-items-center rounded-lg",
                        s.iconBg,
                    )}
                >
                    <Hash className={cn("size-3", s.iconColor)} />
                </div>
                <p className="min-w-0 flex-1 truncate text-[11px] font-semibold uppercase tracking-wide text-ink-700">
                    {title}
                </p>
                {isDefault && (
                    <span className="rounded-md bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700">
                        predvolený
                    </span>
                )}
            </div>

            <p
                className={cn(
                    "truncate font-mono text-xs",
                    certNumber ? "text-ink-900" : "text-ink-300",
                )}
            >
                {certNumber ?? "—"}
            </p>

            {certNumber && validTo && (
                <p
                    className={cn(
                        "flex items-center gap-1 text-[10px]",
                        isExpired
                            ? "text-[var(--color-status-bad)]"
                            : isExpiringSoon
                              ? "text-[var(--color-status-warn)]"
                              : "text-ink-400",
                    )}
                >
                    {(isExpired || isExpiringSoon) && (
                        <AlertTriangle className="size-3 shrink-0" />
                    )}
                    do {validTo}
                </p>
            )}
        </div>
    );
}

// ─── Shared helpers ───────────────────────────────────────────────────────────

// ─── Import subsections (embedded in Data Management) ────────────────────────

type ImportSectionDef = {
    kind: ImportKind;
    title: string;
    description: string;
    hint: string;
    Icon: typeof Building2;
    color: string;
    bg: string;
    createdLabels: Record<string, string>;
};

const IMPORT_DEFS: ImportSectionDef[] = [
    {
        kind: "companies",
        title: "Firmy a prevádzky",
        description: "Importuj firmy aj ich prevádzky naraz. Prevádzky sa naviažu na firmu cez IČO.",
        hint: 'Sheet „Firmy" — Názov je povinný. Sheet „Prevadzky" — IČO firmy musí existovať (buď v sheete vyššie, alebo už v aplikácii).',
        Icon: Building2,
        color: "text-blue-600",
        bg: "bg-blue-50",
        createdLabels: { companies: "firiem", facilities: "prevádzok" },
    },
    {
        kind: "inspections",
        title: "Kontroly",
        description: "Hlavičky kontrol aj ich položky. Každý typ má vlastný sheet s položkami (Polozky_php, Polozky_hydranty, …).",
        hint: 'Sheet „Kontroly" — # riadok je tvoje vlastné poradie (1, 2, 3…). Polozky_* odkazujú na toto # cez stĺpec „# kontrola". Firma sa hľadá podľa IČO — ak ešte neexistuje, vytvorí sa automaticky pod zadaným názvom (IČO má prednosť pred názvom). Prevádzka sa rovnako vytvorí, ak chýba. E-mail technika, ktorý ešte nemá konto, sa predvytvorí a pridá do tvojho tímu; kontroly sa mu priradia, keď sa zaregistruje.',
        Icon: ClipboardList,
        color: "text-firol-600",
        bg: "bg-firol-50",
        createdLabels: { inspections: "kontrol", items: "položiek", technicians: "nových technikov", companies: "nových firiem", facilities: "nových prevádzok" },
    },
    {
        kind: "trainings",
        title: "Školenia",
        description: "Školenia a ich účastníkov. Podpisy účastníkov sa zachytia neskôr v aplikácii — Excel ich neimportuje.",
        hint: 'Sheet „Skolenia" — # riadok je tvoje vlastné poradie. Sheet „Ucastnici" odkazuje na toto # cez „# riadok školenia". Firma sa hľadá podľa IČO — ak ešte neexistuje, vytvorí sa automaticky pod zadaným názvom (IČO má prednosť pred názvom). Prevádzka sa rovnako vytvorí, ak chýba. E-mail lektora, ktorý ešte nemá konto, sa predvytvorí a pridá do tvojho tímu; školenia sa mu priradia, keď sa zaregistruje.',
        Icon: GraduationCap,
        color: "text-violet-600",
        bg: "bg-violet-50",
        createdLabels: { trainings: "školení", trainees: "účastníkov", trainers: "nových lektorov", companies: "nových firiem", facilities: "nových prevádzok" },
    },
];

function ImportSubSection({ section }: { section: ImportSectionDef }) {
    const { csrfToken } = useAuth();
    const toast = useToast();
    const fileRef = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);
    const [downloading, setDownloading] = useState(false);
    const [result, setResult] = useState<ImportResult | null>(null);
    const [networkError, setNetworkError] = useState<string | null>(null);
    const [showReminder, setShowReminder] = useState(false);

    async function onDownload() {
        setDownloading(true);
        setNetworkError(null);
        try {
            await ImportApi.downloadTemplate(section.kind);
        } catch (err) {
            const msg = offlineMessage(err, "Stiahnutie šablóny zlyhalo.");
            setNetworkError(msg);
            toast.error(msg);
        } finally {
            setDownloading(false);
        }
    }

    async function onPick(file: File | undefined) {
        if (!file) return;
        setUploading(true);
        setResult(null);
        setNetworkError(null);
        try {
            const res = await ImportApi.upload(section.kind, file, csrfToken);
            setResult(res);
            if (res.errors.length > 0) {
                toast.error(`Import zlyhal — ${res.errors.length} chýb v súbore.`);
            } else if (res.created) {
                const summary = Object.entries(res.created)
                    .map(([k, v]) => `${v} ${section.createdLabels[k] ?? k}`)
                    .join(", ");
                toast.success(`Naimportované: ${summary}`);
            }
        } catch (err) {
            const msg = offlineMessage(err, "Nahrávanie zlyhalo.");
            setNetworkError(msg);
            toast.error(msg);
        } finally {
            setUploading(false);
            if (fileRef.current) fileRef.current.value = "";
        }
    }

    return (
        <>
            {showReminder && (
                <BackupReminderModal
                    onProceed={() => {
                        setShowReminder(false);
                        fileRef.current?.click();
                    }}
                    onCancel={() => setShowReminder(false)}
                />
            )}
            <div className="overflow-hidden rounded-2xl border border-ink-100">
                <div className="flex items-start gap-3 border-b border-ink-100 px-5 py-4">
                    <div className={cn("grid size-11 shrink-0 place-items-center rounded-2xl", section.bg)}>
                        <section.Icon className={cn("size-5", section.color)} />
                    </div>
                    <div className="min-w-0 flex-1">
                        <h3 className="text-base font-semibold text-ink-900">{section.title}</h3>
                        <p className="mt-0.5 text-xs text-ink-500">{section.description}</p>
                    </div>
                </div>

                <div className="flex flex-col gap-3 px-5 py-4">
                    <p className="rounded-xl border border-ink-100 bg-ink-50/40 px-3 py-2 text-xs text-ink-600">
                        <FileSpreadsheet className="-mt-0.5 mr-1 inline size-3.5 text-ink-400" />
                        {section.hint}
                    </p>

                    <div className="flex flex-col gap-2 sm:flex-row">
                        <Button
                            type="button"
                            variant="secondary"
                            leftIcon={<Download className="size-4" />}
                            onClick={onDownload}
                            loading={downloading}
                            className="sm:flex-1"
                        >
                            Stiahnuť vzorový .xlsx
                        </Button>
                        <input
                            ref={fileRef}
                            type="file"
                            accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                            className="hidden"
                            onChange={(e) => onPick(e.target.files?.[0])}
                        />
                        <Button
                            type="button"
                            leftIcon={<UploadCloud className="size-4" />}
                            onClick={() => setShowReminder(true)}
                            loading={uploading}
                            className="sm:flex-1"
                        >
                            Nahrať vyplnený súbor
                        </Button>
                    </div>

                    {networkError && (
                        <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
                            {networkError}
                        </div>
                    )}

                    {result && result.errors.length === 0 && result.created && (
                        <div className="rounded-xl border border-emerald-200 bg-emerald-50/60 px-3 py-2.5 text-sm text-emerald-800">
                            <p className="flex items-center gap-2 font-semibold">
                                <CheckCircle2 className="size-4" />
                                Import dokončený
                            </p>
                            <ul className="mt-1.5 flex flex-wrap gap-2">
                                {Object.entries(result.created).map(([k, v]) => (
                                    <li key={k}>
                                        <Badge tone="ok">
                                            {v} {section.createdLabels[k] ?? k}
                                        </Badge>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {result && result.errors.length > 0 && (
                        <div className="rounded-xl border border-rose-200 bg-rose-50/40 px-3 py-2.5 text-sm">
                            <p className="flex items-center gap-2 font-semibold text-rose-800">
                                <AlertTriangle className="size-4" />
                                Súbor obsahuje {result.errors.length}{" "}
                                {result.errors.length === 1 ? "chybu" : "chýb"} — neuložilo sa nič.
                            </p>
                            <ul className="mt-2 max-h-64 space-y-1 overflow-y-auto pr-1">
                                {result.errors.map((e, i) => (
                                    <li key={i} className="text-xs text-rose-800">
                                        <span className="font-mono font-semibold">
                                            {e.sheet} · riadok {e.row}
                                        </span>{" "}
                                        — {e.message}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

// ─── Data Management Page ─────────────────────────────────────────────────────

export function DataPage() {
    return (
        <>
            <SectionBack label="Správa dát" />
            <DataSection />
        </>
    );
}

// ─── Systémové ───────────────────────────────────────────────────────────────

export function SystemPage() {
    return (
        <>
            <SectionBack label="Systémové" />
            <InstallAppCard />
        </>
    );
}

const CONFIRM_KEYWORD = "VYMAZAŤ";

function PurgeCard({
    title,
    description,
    detail,
    onConfirm,
    busy,
}: {
    title: string;
    description: string;
    detail: string;
    onConfirm: () => void;
    busy: boolean;
}) {
    const [showReminder, setShowReminder] = useState(false);
    const [open, setOpen] = useState(false);
    const [keyword, setKeyword] = useState("");
    const valid = keyword.trim() === CONFIRM_KEYWORD;

    function reset() {
        setOpen(false);
        setKeyword("");
    }

    function onDeleteClick() {
        setShowReminder(true);
    }

    function onReminderProceed() {
        setShowReminder(false);
        setOpen(true);
    }

    return (
        <>
            {showReminder && (
                <BackupReminderModal
                    onProceed={onReminderProceed}
                    onCancel={() => setShowReminder(false)}
                />
            )}
            <div className="rounded-2xl border border-red-200 bg-red-50/40 p-4">
                <div className="flex items-center gap-3">
                    <div className="grid size-9 shrink-0 place-items-center rounded-xl bg-red-100 text-red-600">
                        <Trash2 className="size-4" />
                    </div>
                    <div className="min-w-0 flex-1">
                        <p className="text-sm font-semibold text-ink-900">{title}</p>
                        <p className="mt-0.5 text-xs text-ink-500">{description}</p>
                        <p className="mt-1 text-[11px] text-red-700/80">{detail}</p>
                    </div>
                    {!open && (
                        <button
                            type="button"
                            onClick={onDeleteClick}
                            disabled={busy}
                            className="shrink-0 rounded-xl border border-red-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 transition-colors hover:bg-red-50 disabled:opacity-50"
                        >
                            Vymazať
                        </button>
                    )}
                </div>

                {open && (
                    <div className="mt-4 flex flex-col gap-3 border-t border-red-200 pt-4">
                        <p className="text-xs text-ink-700">
                            Pre potvrdenie napíš{" "}
                            <span className="font-mono font-bold text-red-700">
                                {CONFIRM_KEYWORD}
                            </span>{" "}
                            do poľa nižšie:
                        </p>
                        <input
                            type="text"
                            value={keyword}
                            onChange={(e) => setKeyword(e.target.value)}
                            placeholder={CONFIRM_KEYWORD}
                            autoComplete="off"
                            spellCheck={false}
                            className="w-full rounded-xl border border-red-200 bg-white px-3 py-2 font-mono text-sm text-ink-900 outline-none focus:border-red-400 focus:ring-2 focus:ring-red-200"
                        />
                        <div className="flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={reset}
                                className="rounded-xl px-3 py-1.5 text-xs font-semibold text-ink-600 transition-colors hover:bg-ink-100"
                            >
                                Zrušiť
                            </button>
                            <Button
                                type="button"
                                disabled={!valid}
                                loading={busy}
                                onClick={() => {
                                    if (valid) {
                                        onConfirm();
                                        reset();
                                    }
                                }}
                                className="bg-red-600 hover:bg-red-700 focus-visible:ring-red-300"
                            >
                                Potvrdiť vymazanie
                            </Button>
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}

function DataSection() {
    const { csrfToken } = useAuth();
    const toast = useToast();
    const [busyCompanies, setBusyCompanies] = useState(false);
    const [busyInspections, setBusyInspections] = useState(false);
    const [busyTrainings, setBusyTrainings] = useState(false);

    async function purge(
        action: () => Promise<{ deleted: number }>,
        setBusy: (v: boolean) => void,
        label: string,
    ) {
        setBusy(true);
        try {
            const res = await action();
            toast.success(
                `${label}: vymazaných ${res.deleted} záznamov.`,
            );
        } catch (err) {
            toast.error(offlineMessage(err, "Vymazanie zlyhalo. Skús to znova."));
        } finally {
            setBusy(false);
        }
    }

    function downloadExport() {
        const url = DataApi.exportUrl();
        const a = document.createElement("a");
        a.href = url;
        a.download = "";
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }

    return (
        <div className="flex flex-col gap-4">
            {/* Export */}
            <Card className="overflow-hidden">
                <div className="flex items-center gap-3 border-b border-ink-100 bg-gradient-to-br from-slate-50/80 to-transparent px-5 py-4">
                    <div className="grid size-11 place-items-center rounded-2xl bg-slate-700 text-white shadow-[var(--shadow-glow)]">
                        <Download className="size-5" />
                    </div>
                    <div className="min-w-0 flex-1">
                        <h2 className="text-base font-semibold text-ink-900">
                            Export / záloha dát
                        </h2>
                        <p className="text-xs text-ink-500">
                            Stiahni všetky firmy, kontroly a školenia ako JSON
                            súbor. Vhodné ako osobná záloha pred hromadnými
                            zmenami.
                        </p>
                    </div>
                </div>
                <div className="px-5 py-5">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-start">
                        <div className="min-w-0 flex-1 text-sm text-ink-600">
                            <p>
                                Export obsahuje{" "}
                                <strong className="text-ink-800">
                                    firmy, prevádzky, kontroly, školenia
                                </strong>{" "}
                                vrátane všetkých položiek a účastníkov. PDF
                                protokoly nie sú súčasťou exportu — sú
                                dostupné cez tlačidlo stiahnutia pri každej
                                kontrole.
                            </p>
                            <p className="mt-2 text-xs text-ink-400">
                                Súbor si ulož na bezpečné miesto (napr. Google
                                Drive alebo e-mail). Ak omylom zmažeš dáta,
                                vieme ich z tohto súboru obnoviť.
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={downloadExport}
                            className="flex shrink-0 items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-100"
                        >
                            <Download className="size-4" />
                            Stiahnuť zálohu
                        </button>
                    </div>
                </div>
            </Card>

            {/* Import z Excelu */}
            <Card className="overflow-hidden">
                <div className="flex items-center gap-3 border-b border-ink-100 bg-gradient-to-br from-emerald-50/60 to-transparent px-5 py-4">
                    <div className="grid size-11 place-items-center rounded-2xl bg-emerald-600 text-white shadow-[var(--shadow-glow)]">
                        <FileSpreadsheet className="size-5" />
                    </div>
                    <div className="min-w-0 flex-1">
                        <h2 className="text-base font-semibold text-ink-900">
                            Import z Excelu
                        </h2>
                        <p className="text-xs text-ink-500">
                            Hromadný import firiem, kontrol a školení zo vzorovej Excel šablóny.
                        </p>
                    </div>
                </div>
                <div className="flex flex-col gap-4 px-5 py-5">
                    <div className="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50/60 px-3 py-2 text-xs text-ink-700">
                        <AlertTriangle className="mt-0.5 size-4 shrink-0 text-amber-500" />
                        <span>
                            Import beží <strong>atomicky</strong> — ak ktorýkoľvek riadok
                            obsahuje chybu, neuloží sa <em>nič</em>. Oprav riadky podľa
                            chybového hlásenia a nahraj súbor znova.
                        </span>
                    </div>
                    {IMPORT_DEFS.map((section) => (
                        <ImportSubSection key={section.kind} section={section} />
                    ))}
                </div>
            </Card>

            {/* Danger zone */}
            <Card className="overflow-hidden">
                <div className="flex items-center gap-3 border-b border-red-100 bg-gradient-to-br from-red-50/60 to-transparent px-5 py-4">
                    <div className="grid size-11 place-items-center rounded-2xl bg-red-500 text-white shadow-[var(--shadow-glow)]">
                        <AlertTriangle className="size-5" />
                    </div>
                    <div className="min-w-0 flex-1">
                        <h2 className="text-base font-semibold text-ink-900">
                            Hromadné vymazanie
                        </h2>
                        <p className="text-xs text-ink-500">
                            Nevratné operácie. Pred vymazaním si odporúčame
                            stiahnuť zálohu.
                        </p>
                    </div>
                </div>
                <div className="flex flex-col gap-3 px-5 py-5">
                    <PurgeCard
                        title="Vymazať všetky firmy"
                        description="Vymaže všetky firmy a prevádzky vrátane všetkých kontrol, školení a dokumentov."
                        detail="Toto je totálny reset — zmažú sa aj všetky kontroly a školenia, pretože sú naviazané na firmy."
                        busy={busyCompanies}
                        onConfirm={() =>
                            purge(
                                () => DataApi.purgeCompanies(csrfToken),
                                setBusyCompanies,
                                "Firmy",
                            )
                        }
                    />
                    <PurgeCard
                        title="Vymazať všetky kontroly"
                        description="Vymaže všetky záznamy o kontrolách a ich položky. Firmy a školenia zostanú."
                        detail="Zmažú sa všetky kontroly bez ohľadu na stav (draft aj finalizované)."
                        busy={busyInspections}
                        onConfirm={() =>
                            purge(
                                () => DataApi.purgeInspections(csrfToken),
                                setBusyInspections,
                                "Kontroly",
                            )
                        }
                    />
                    <PurgeCard
                        title="Vymazať všetky školenia"
                        description="Vymaže všetky školenia a zoznamy účastníkov. Firmy a kontroly zostanú."
                        detail="Zmažú sa všetky školenia bez ohľadu na stav vrátane podpisov účastníkov."
                        busy={busyTrainings}
                        onConfirm={() =>
                            purge(
                                () => DataApi.purgeTrainings(csrfToken),
                                setBusyTrainings,
                                "Školenia",
                            )
                        }
                    />
                </div>
            </Card>
        </div>
    );
}

// ─── Shared helpers ───────────────────────────────────────────────────────────

function SignaturePreview({
    hasSignature,
    cacheBuster,
}: {
    hasSignature: boolean;
    cacheBuster: number;
}) {
    if (!hasSignature) {
        return (
            <div
                className="relative w-full overflow-hidden rounded-2xl border border-dashed border-ink-200 bg-white"
                style={{ paddingTop: "calc(100% / 3)" }}
            >
                <div className="absolute inset-0 flex flex-col items-center justify-center gap-1 text-center text-ink-400">
                    <ImagePlus className="size-5" />
                    <span className="text-xs">Zatiaľ bez podpisu</span>
                </div>
            </div>
        );
    }
    return (
        <div
            className="relative w-full overflow-hidden rounded-2xl border border-ink-200 bg-white"
            style={{ paddingTop: "calc(100% / 3)" }}
        >
            <div className="absolute inset-0 flex items-center justify-center p-3">
                <img
                    src={InspectorProfileApi.signatureUrl(cacheBuster)}
                    alt="Podpis revízneho technika"
                    className="max-h-full max-w-full object-contain"
                />
            </div>
        </div>
    );
}
