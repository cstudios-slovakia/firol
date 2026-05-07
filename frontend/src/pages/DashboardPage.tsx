import { Building2, Flame, LogOut, Plus, Search } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { AccountSwitcher } from '@/components/AccountSwitcher';

/**
 * Placeholder dashboard for Phase 1. Real dashboard (companies list with
 * status badges, search, "+ Nová kontrola") lands in Phase 2.
 */
export function DashboardPage() {
  const { user, logout } = useAuth();

  return (
    <div className="bg-app min-h-screen">
      <div className="mx-auto max-w-md px-4 py-5 sm:max-w-2xl sm:py-8">
        <header className="mb-6 flex items-center justify-between gap-3">
          <div className="flex items-center gap-2.5">
            <span className="grid size-9 place-items-center rounded-2xl bg-firol-500 text-white shadow-[var(--shadow-glow)]">
              <Flame className="size-4" />
            </span>
            <span className="font-semibold tracking-tight text-ink-900">Firol</span>
          </div>
          <div className="flex items-center gap-2">
            <AccountSwitcher />
            <Button variant="ghost" size="sm" onClick={logout} leftIcon={<LogOut className="size-4" />}>
              <span className="hidden sm:inline">Odhlásiť</span>
            </Button>
          </div>
        </header>

        <section className="mb-6">
          <h1 className="text-xl font-semibold tracking-tight text-ink-900">
            Ahoj, {user?.fullname.split(' ')[0]} 👋
          </h1>
          <p className="mt-1 text-sm text-ink-500">
            Tu uvidíš zoznam svojich firiem a najbližšie termíny kontrol.
          </p>
        </section>

        <Card className="flex items-center gap-2 px-3 py-2 mb-4">
          <Search className="size-4 text-ink-400" />
          <input
            disabled
            placeholder="Vyhľadať firmu alebo IČO"
            className="w-full bg-transparent text-sm placeholder:text-ink-400 focus:outline-none"
          />
        </Card>

        <Card className="flex flex-col items-center gap-3 px-6 py-12 text-center">
          <div className="grid size-14 place-items-center rounded-2xl bg-firol-50 text-firol-500">
            <Building2 className="size-6" />
          </div>
          <h2 className="text-base font-semibold text-ink-900">Zatiaľ žiadne firmy</h2>
          <p className="max-w-xs text-sm text-ink-500">
            Pridaj prvú firmu, ku ktorej budeš zaznamenávať revízie a kontroly. V ďalšej fáze sa tu objaví zoznam s farebnými statusmi a tlačidlom na novú kontrolu.
          </p>
          <Button leftIcon={<Plus className="size-4" />} disabled>
            Pridať firmu (čoskoro)
          </Button>
        </Card>
      </div>
    </div>
  );
}
