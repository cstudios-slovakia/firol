import { Link, Outlet, NavLink } from 'react-router-dom';
import {
  AlertTriangle, Building2, ClipboardList, CreditCard, Flame, GraduationCap, LogOut, Settings,
} from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { AccountSwitcher } from './AccountSwitcher';
import { cn } from '@/lib/cn';

const TABS = [
  { to: '/',            label: 'Firmy',      icon: Building2 },
  { to: '/inspections', label: 'Kontroly',   icon: ClipboardList },
  { to: '/trainings',   label: 'Školenia',   icon: GraduationCap },
  { to: '/settings',    label: 'Nastavenia', icon: Settings },
] as const;

export function AppShell() {
  const { logout } = useAuth();

  return (
    <div className="bg-app min-h-screen pb-20 sm:pb-0">
      <SubscriptionBanner />
      <header className="sticky top-0 z-20 border-b border-ink-100/80 bg-white/80 backdrop-blur">
        <div className="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-3">
          <Link
            to="/"
            aria-label="Domov"
            className="group flex items-center gap-2.5 rounded-2xl outline-none focus-visible:ring-2 focus-visible:ring-firol-300"
          >
            <span className="grid size-9 place-items-center rounded-2xl bg-firol-500 text-white shadow-[var(--shadow-glow)] transition-transform group-hover:scale-105">
              <Flame className="size-4" />
            </span>
            <span className="font-semibold tracking-tight text-ink-900 transition-colors group-hover:text-firol-700">
              Firol
            </span>
          </Link>
          <div className="flex items-center gap-2">
            <AccountSwitcher />
            <button
              type="button"
              onClick={logout}
              aria-label="Odhlásiť"
              className="grid size-9 place-items-center rounded-2xl text-ink-500 transition-colors hover:bg-ink-100 hover:text-ink-700"
            >
              <LogOut className="size-4" />
            </button>
          </div>
        </div>
      </header>

      <div className="mx-auto flex max-w-6xl gap-6 px-4 py-5 sm:py-8">
        <SideNav />
        <main className="min-w-0 flex-1">
          <Outlet />
        </main>
      </div>

      <BottomTabBar />
    </div>
  );
}

/**
 * Phase 6a — read-only mode banner. Shown when the active account's
 * subscription_end_date is in the past. The "Zaplatiť" CTA is a stub
 * until Phase 6b wires up Stripe Checkout.
 */
function SubscriptionBanner() {
  const { accounts, activeAccountId } = useAuth();
  const account = accounts.find((a) => a.id === activeAccountId);
  if (!account) return null;

  const endStr = account.subscription_end_date;
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const end = new Date(`${endStr}T00:00:00`);
  const expired = !Number.isNaN(end.getTime()) && end < today;
  if (!expired) return null;

  const human = end.toLocaleDateString('sk-SK', {
    day: 'numeric', month: 'long', year: 'numeric',
  });

  return (
    <div className="border-b border-[var(--color-status-bad)]/30 bg-[var(--color-status-bad-bg)]">
      <div className="mx-auto flex max-w-6xl flex-col gap-2 px-4 py-3 text-sm text-[var(--color-status-bad)] sm:flex-row sm:items-center sm:gap-3">
        <span className="grid size-8 shrink-0 place-items-center rounded-xl bg-white/70">
          <AlertTriangle className="size-4" />
        </span>
        <div className="min-w-0 flex-1">
          <p className="font-semibold">Predplatné vypršalo {human}</p>
          <p className="text-xs opacity-80">
            Účet je v režime len na čítanie — nové kontroly, školenia ani úpravy nie sú možné, kým si neobnovíš predplatné.
          </p>
        </div>
        <button
          type="button"
          disabled
          title="Platobnú bránu pripravujeme"
          className="inline-flex items-center gap-2 rounded-xl bg-[var(--color-status-bad)] px-3 py-2 text-xs font-semibold text-white opacity-70"
        >
          <CreditCard className="size-4" />
          Zaplatiť (čoskoro)
        </button>
      </div>
    </div>
  );
}

function SideNav() {
  return (
    <aside
      aria-label="Hlavná navigácia"
      className="hidden sm:block w-56 shrink-0"
    >
      <nav className="sticky top-[81px]">
        <ul className="flex flex-col gap-1">
          {TABS.map((tab) => (
            <li key={tab.to}>
              <NavLink
                to={tab.to}
                end={tab.to === '/'}
                className={({ isActive }) =>
                  cn(
                    'flex items-center gap-2.5 rounded-2xl px-3 py-2.5 text-sm font-medium transition-colors',
                    isActive
                      ? 'bg-firol-50 text-firol-700 shadow-[inset_0_0_0_1px_var(--color-firol-100)]'
                      : 'text-ink-600 hover:bg-ink-100 hover:text-ink-900',
                  )
                }
              >
                {({ isActive }) => (
                  <>
                    <tab.icon className={cn('size-4 shrink-0', isActive && 'text-firol-600')} />
                    <span>{tab.label}</span>
                  </>
                )}
              </NavLink>
            </li>
          ))}
        </ul>
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
        {TABS.map((tab) => (
          <li key={tab.to} className="flex-1">
            <NavLink
              to={tab.to}
              end={tab.to === '/'}
              className={({ isActive }) =>
                cn(
                  'flex flex-col items-center gap-0.5 rounded-2xl py-2 text-[11px] font-medium transition-colors',
                  isActive ? 'text-firol-600' : 'text-ink-400 hover:text-ink-700',
                )
              }
            >
              {({ isActive }) => (
                <>
                  <tab.icon className={cn('size-5', isActive && 'stroke-[2.25px]')} />
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
