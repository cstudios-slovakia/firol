import { Link, Outlet, NavLink, useNavigate, useLocation } from "react-router-dom";
import {
    AlertTriangle,
    Building2,
    ClipboardList,
    CreditCard,
    GraduationCap,
    LayoutDashboard,
    LogOut,
    Settings,
    Sparkles,
} from "lucide-react";
import { useEffect, useLayoutEffect, useRef, useState } from "react";
import { useAuth } from "@/auth/AuthContext";
import { Billing } from "@/api/billing";
import { ApiError } from "@/lib/api";
import { offlineMessage } from "@/lib/offline";
import { useToast } from "@/lib/toast";
import { AccountSwitcher } from "./AccountSwitcher";
import { OfflineIndicator } from "./OfflineIndicator";
import { AuroraBackground } from "./AuroraBackground";
import { FeedbackFloater } from "./FeedbackFloater";
import { InstallPrompt } from "./InstallPrompt";
import { BrandMark } from "./Logo";
import { cn } from "@/lib/cn";

const TOP_TABS = [
    {
        to: "/",
        label: "Prehľad",
        icon: LayoutDashboard,
        activeColor: "text-firol-600",
        activeBg: "bg-firol-50 shadow-[inset_0_0_0_1px_var(--color-firol-200)]",
        iconBg: "bg-firol-100",
    },
    {
        to: "/companies",
        label: "Firmy",
        icon: Building2,
        activeColor: "text-blue-600",
        activeBg: "bg-blue-50 shadow-[inset_0_0_0_1px_theme(colors.blue.100)]",
        iconBg: "bg-blue-100",
    },
    {
        to: "/inspections",
        label: "Kontroly",
        icon: ClipboardList,
        activeColor: "text-orange-600",
        activeBg:
            "bg-orange-50 shadow-[inset_0_0_0_1px_theme(colors.orange.100)]",
        iconBg: "bg-orange-100",
    },
    {
        to: "/trainings",
        label: "Školenia",
        icon: GraduationCap,
        activeColor: "text-emerald-600",
        activeBg:
            "bg-emerald-50 shadow-[inset_0_0_0_1px_theme(colors.emerald.100)]",
        iconBg: "bg-emerald-100",
    },
] as const;

const SETTINGS_TAB = {
    to: "/settings",
    label: "Nastavenia",
    icon: Settings,
    activeColor: "text-violet-600",
    activeBg: "bg-violet-50 shadow-[inset_0_0_0_1px_theme(colors.violet.100)]",
    iconBg: "bg-violet-100",
} as const;

// Desktop sidebar only — not shown in mobile bottom nav
const DESKTOP_BOTTOM_TABS = [
    {
        to: "/billing",
        label: "Predplatné",
        icon: CreditCard,
        activeColor: "text-firol-600",
        activeBg: "bg-firol-50 shadow-[inset_0_0_0_1px_var(--color-firol-200)]",
        iconBg: "bg-firol-100",
    },
    SETTINGS_TAB,
] as const;

// Mobile bottom nav — 5 tabs only
const MOBILE_TABS = [...TOP_TABS, SETTINGS_TAB] as const;

type Tab = {
    readonly to: string;
    readonly label: string;
    readonly icon: typeof Settings;
    readonly activeColor: string;
    readonly activeBg: string;
    readonly iconBg: string;
};

export function AppShell() {
    const { logout } = useAuth();
    const toast = useToast();
    const location = useLocation();
    const navigate = useNavigate();

    // Logout hits the server (POST /api/auth/logout) and isn't queueable, so
    // offline the request just rejects and nothing visible happens. Guard it
    // with a clear toast instead of a silent no-op.
    async function handleLogout() {
        if (!navigator.onLine) {
            toast.error("Odhlásenie vyžaduje pripojenie na internet.");
            return;
        }
        try {
            await logout();
        } catch {
            toast.error("Odhlásenie vyžaduje pripojenie na internet.");
        }
    }

    // When a queued create syncs, queue.ts fires `firol:remap` with the temp
    // id and the server-issued real id. If the user is sitting on the temp-id
    // route (e.g. a draft inspection they created offline), swap it for the
    // real id so subsequent loads hit the persisted record.
    useEffect(() => {
        function onRemap(e: Event) {
            const { tempId, realId } = (e as CustomEvent<{ tempId: number; realId: number }>).detail;
            const segment = `/${tempId}`;
            if (location.pathname.includes(segment)) {
                const next =
                    location.pathname.replace(segment, `/${realId}`) + location.search;
                navigate(next, { replace: true });
            }
        }
        window.addEventListener("firol:remap", onRemap);
        return () => window.removeEventListener("firol:remap", onRemap);
    }, [location.pathname, location.search, navigate]);

    const topBarRef = useRef<HTMLDivElement>(null);
    const [topBarH, setTopBarH] = useState(65);

    useLayoutEffect(() => {
        const el = topBarRef.current;
        if (!el) return;
        const update = () => setTopBarH(el.offsetHeight);
        update();
        const obs = new ResizeObserver(update);
        obs.observe(el);
        return () => obs.disconnect();
    }, []);

    return (
        <div className="bg-app relative min-h-screen pb-20 sm:pb-0">
            <AuroraBackground />
            <div ref={topBarRef} className="sticky top-0 z-20">
                <SubscriptionBanner />
                <TrialBanner />
                <header className="border-b border-ink-100/80 bg-white/80 backdrop-blur">
                    <div className="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-3">
                        <Link
                            to="/"
                            aria-label="Domov"
                            className="group flex items-center gap-2.5 rounded-2xl outline-none focus-visible:ring-2 focus-visible:ring-firol-300"
                        >
                            <BrandMark className="size-9 text-firol-600 transition-transform group-hover:scale-105" />
                            <span className="font-bold tracking-tight text-ink-900 transition-colors group-hover:text-firol-700">
                                PO<span className="text-firol-600">app</span>
                            </span>
                        </Link>
                        <div className="flex items-center gap-2">
                            <OfflineIndicator />
                            <AccountSwitcher />
                            <button
                                type="button"
                                onClick={handleLogout}
                                aria-label="Odhlásiť"
                                className="grid size-9 place-items-center rounded-2xl text-ink-500 transition-colors hover:bg-ink-100 hover:text-ink-700"
                            >
                                <LogOut className="size-4" />
                            </button>
                        </div>
                    </div>
                </header>
            </div>

            <div className="mx-auto relative flex max-w-6xl gap-6 px-4">
                <SideNav topOffset={topBarH} />
                <main className="min-w-0 flex-1 py-5 sm:py-8">
                    <div key={location.pathname} className="animate-fade-up">
                        <Outlet />
                    </div>
                </main>
            </div>

            <BottomTabBar />
            <FeedbackFloater />
            <InstallPrompt />
        </div>
    );
}

/**
 * Phase 6a — read-only mode banner. Shown when the active account's
 * subscription_end_date is in the past. The "Zaplatiť" CTA is a stub
 * until Phase 6b wires up Stripe Checkout.
 */
function SubscriptionBanner() {
    const { accounts, activeAccountId, csrfToken, isAdmin } = useAuth();
    const toast = useToast();
    const navigate = useNavigate();
    const [busy, setBusy] = useState(false);

    const account = accounts.find((a) => a.id === activeAccountId);
    if (!account) return null;
    // App admins get free, full access — no expiry banner. Admin-owned
    // accounts (main_user_id is an app admin) extend that exemption to
    // every technician on the account, so no one on the team sees a
    // paywall for an account that's effectively free.
    if (isAdmin) return null;
    if (account.admin_owned) return null;

    const endStr = account.subscription_end_date;
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const end = new Date(`${endStr}T00:00:00`);
    const expired = !Number.isNaN(end.getTime()) && end < today;
    if (!expired) return null;

    const human = end.toLocaleDateString("sk-SK", {
        day: "numeric",
        month: "long",
        year: "numeric",
    });

    async function onPay() {
        setBusy(true);
        try {
            const res = await Billing.checkout(
                account?.billing_period ?? "monthly",
                csrfToken,
            );
            window.location.assign(res.url);
        } catch (err) {
            if (err instanceof ApiError && err.status === 422) {
                // Billing details missing — redirect to settings to fill them.
                navigate("/billing?onboarding=billing");
                return;
            }
            const msg = offlineMessage(
                err,
                "Stripe Checkout sa nepodarilo otvoriť.",
            );
            toast.error(msg);
            setBusy(false);
        }
    }

    return (
        <div className="border-b border-[var(--color-status-bad)]/30 bg-[var(--color-status-bad-bg)]">
            <div className="mx-auto flex max-w-6xl flex-col gap-2 px-4 py-3 text-sm text-[var(--color-status-bad)] sm:flex-row sm:items-center sm:gap-3">
                <span className="grid size-8 shrink-0 place-items-center rounded-xl bg-white/70">
                    <AlertTriangle className="size-4" />
                </span>
                <div className="min-w-0 flex-1">
                    <p className="font-semibold">Predplatné vypršalo {human}</p>
                    <p className="text-xs opacity-80">
                        Účet je v režime len na čítanie — nové kontroly,
                        školenia ani úpravy nie sú možné, kým si neobnovíš
                        predplatné.
                    </p>
                </div>
                <button
                    type="button"
                    onClick={onPay}
                    disabled={busy}
                    className="inline-flex items-center gap-2 rounded-xl bg-[var(--color-status-bad)] px-3 py-2 text-xs font-semibold text-white shadow transition-opacity hover:opacity-90 disabled:opacity-60"
                >
                    <CreditCard className="size-4" />
                    {busy ? "Otváram Stripe…" : "Zaplatiť"}
                </button>
            </div>
        </div>
    );
}

/**
 * Compact banner shown above the app shell during/near the end of the
 * trial. Branches on the backend-derived `subscription_state`:
 *  - `none`       → free trial only, no paid sub → warn + "Predplatiť" CTA
 *  - `trial_paid` → trial with sub on file       → confirm auto-charge + "Spravovať"
 *  - anything else → no banner (active sub or expired SubscriptionBanner takes over)
 */
function TrialBanner() {
    const { accounts, activeAccountId, csrfToken, isAdmin } = useAuth();
    const toast = useToast();
    const navigate = useNavigate();

    const account = accounts.find((a) => a.id === activeAccountId);
    if (!account) return null;
    if (isAdmin) return null;
    if (account.admin_owned) return null;

    const state = account.subscription_state;
    if (state !== "none" && state !== "trial_paid") return null;

    const endStr = account.subscription_end_date;
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const end = new Date(`${endStr}T00:00:00`);
    if (Number.isNaN(end.getTime()) || end < today) return null;

    const daysLeft = Math.max(
        0,
        Math.round((end.getTime() - today.getTime()) / 86_400_000),
    );
    const human = end.toLocaleDateString("sk-SK", {
        day: "numeric",
        month: "long",
    });

    const periodPrice =
        account.billing_period === "yearly" ? "199 € / rok" : "19 € / mesiac";

    const isTrialPaid = state === "trial_paid";

    async function onPay() {
        try {
            const res = await Billing.checkout(
                account?.billing_period ?? "monthly",
                csrfToken,
            );
            window.location.assign(res.url);
        } catch (err) {
            if (err instanceof ApiError && err.status === 422) {
                navigate("/billing?onboarding=billing");
                return;
            }
            const msg = offlineMessage(
                err,
                "Stripe Checkout sa nepodarilo otvoriť.",
            );
            toast.error(msg);
        }
    }

    return (
        <div className="border-b border-firol-200/60 bg-firol-50/70">
            <div className="mx-auto flex max-w-6xl items-center gap-2 px-4 py-1.5 text-xs text-firol-900">
                <Sparkles className="size-3.5 shrink-0 text-firol-600" />
                <p className="min-w-0 flex-1 truncate">
                    <span className="font-semibold">
                        Skúšobné obdobie do {human}
                    </span>
                    <span className="opacity-80">
                        {" "}
                        ({daysLeft}{" "}
                        {daysLeft === 1 ? "deň" : daysLeft < 5 ? "dni" : "dní"})
                        {isTrialPaid
                            ? ` · potom sa automaticky zaúčtuje ${periodPrice}`
                            : " · potom režim len na čítanie"}
                    </span>
                </p>
                {isTrialPaid ? (
                    <button
                        type="button"
                        onClick={() => navigate("/billing")}
                        className="inline-flex shrink-0 items-center gap-1 rounded-lg bg-firol-600 px-2.5 py-1 text-[11px] font-semibold text-white shadow-sm transition-opacity hover:opacity-90"
                    >
                        Spravovať predplatné
                    </button>
                ) : (
                    <button
                        type="button"
                        onClick={onPay}
                        className="inline-flex shrink-0 items-center gap-1 rounded-lg bg-firol-600 px-2.5 py-1 text-[11px] font-semibold text-white shadow-sm transition-opacity hover:opacity-90"
                    >
                        Predplatiť
                    </button>
                )}
            </div>
        </div>
    );
}

function SideNav({ topOffset }: { topOffset: number }) {
    const renderItem = (tab: Tab) => (
        <li key={tab.to}>
            <NavLink
                to={tab.to}
                end={tab.to === "/"}
                className={({ isActive }) =>
                    cn(
                        "flex items-center gap-2.5 rounded-2xl px-3 py-2.5 text-sm font-medium transition-all duration-150",
                        isActive
                            ? cn("text-ink-900", tab.activeBg)
                            : "text-ink-600 hover:bg-ink-50 hover:text-ink-900",
                    )
                }
            >
                {({ isActive }) => (
                    <>
                        <tab.icon
                            className={cn(
                                "size-4 shrink-0 transition-transform duration-150",
                                isActive && "scale-110",
                                tab.activeColor,
                            )}
                        />
                        <span>{tab.label}</span>
                    </>
                )}
            </NavLink>
        </li>
    );

    const [dashboardTab, ...sectionTabs] = TOP_TABS;

    return (
        <aside
            aria-label="Hlavná navigácia"
            className="hidden sm:block w-56 shrink-0"
        >
            <nav
                className="sticky flex flex-col pt-5 sm:pt-8"
                style={{ top: topOffset, height: `calc(100vh - ${topOffset}px)` }}
            >
                <ul className="flex flex-col gap-1">
                    {renderItem(dashboardTab)}
                </ul>
                <div className="my-3 border-t border-ink-100" />
                <ul className="flex flex-col gap-1">
                    {sectionTabs.map(renderItem)}
                </ul>
                <div className="mt-auto pt-4 pb-5 sm:pb-8">
                    <div className="mb-2 border-t border-ink-100" />
                    <ul className="flex flex-col gap-1">
                        {DESKTOP_BOTTOM_TABS.map(renderItem)}
                    </ul>
                </div>
            </nav>
        </aside>
    );
}

function BottomTabBar() {
    return (
        <nav
            aria-label="Hlavná navigácia"
            className="fixed inset-x-0 bottom-0 z-10 border-t border-ink-100 bg-white/95 backdrop-blur sm:hidden"
        >
            <ul className="mx-auto flex max-w-2xl items-stretch justify-around px-2 py-1.5">
                {MOBILE_TABS.map((tab) => (
                    <li key={tab.to} className="flex-1">
                        <NavLink
                            to={tab.to}
                            end={tab.to === "/"}
                            className={({ isActive }) =>
                                cn(
                                    "flex flex-col items-center gap-0.5 rounded-2xl py-2 text-[11px] font-medium transition-all duration-150",
                                    isActive
                                        ? cn(tab.activeColor, tab.activeBg)
                                        : "text-ink-400 hover:text-ink-600",
                                )
                            }
                        >
                            {({ isActive }) => (
                                <>
                                    <tab.icon
                                        className={cn(
                                            "size-5 transition-transform duration-150",
                                            tab.activeColor,
                                            isActive && "scale-110 stroke-[2.25px]",
                                        )}
                                    />
                                    <span>{tab.label}</span>
                                </>
                            )}
                        </NavLink>
                    </li>
                ))}
            </ul>
        </nav>
    );
}
