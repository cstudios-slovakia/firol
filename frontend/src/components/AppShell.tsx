import { Outlet, NavLink } from 'react-router-dom';
import { Building2, ClipboardList, Flame, GraduationCap, LogOut, Settings } from 'lucide-react';
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
      <header className="sticky top-0 z-10 border-b border-ink-100/80 bg-white/80 backdrop-blur">
        <div className="mx-auto flex max-w-2xl items-center justify-between gap-3 px-4 py-3">
          <div className="flex items-center gap-2.5">
            <span className="grid size-9 place-items-center rounded-2xl bg-firol-500 text-white shadow-[var(--shadow-glow)]">
              <Flame className="size-4" />
            </span>
            <span className="font-semibold tracking-tight text-ink-900">Firol</span>
          </div>
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

      <main className="mx-auto max-w-2xl px-4 py-5 sm:py-8">
        <Outlet />
      </main>

      <BottomTabBar />
    </div>
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
