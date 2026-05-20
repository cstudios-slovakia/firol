import { useEffect, useState, type FormEvent } from 'react';
import {
  AtSign, Building2, CalendarDays, ChevronDown, ChevronRight, Hash, Loader2,
  Pencil, Phone, Search, Settings as SettingsIcon, Shield, ShieldCheck, ShieldOff,
  Trash2, User, Users,
} from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '@/auth/AuthContext';
import {
  Admin, AdminPanel,
  type AdminAccount, type AdminAccountsPage, type AdminUser, type SystemSettings,
} from '@/api/admin';
import { ApiError } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Field } from '@/components/ui/Field';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { CardBlockSkeleton } from '@/components/ui/Skeleton';

/**
 * App-admin control panel. Only admins (Admin::isAdmin on the backend)
 * can reach this page — RequireAdmin redirects everyone else home.
 *
 * Sections:
 *   1. Účty a používatelia — paginated 10/page, each account expands to
 *      its users; edit/delete for both, promote/demote app-admin flag.
 *   2. Systémové nastavenia — trial length + plan prices.
 */
export function AdminPage() {
  return (
    <div className="flex flex-col gap-5">
      <header>
        <div className="flex items-center gap-2">
          <Shield className="size-5 text-firol-600" />
          <h1 className="text-xl font-semibold tracking-tight text-ink-900">
            Administrácia
          </h1>
        </div>
        <p className="mt-0.5 text-sm text-ink-500">
          Globálna správa všetkých účtov a používateľov. Viditeľné iba pre
          adminov aplikácie.
        </p>
      </header>

      <AccountsSection />
      <AdminSettingsSection />
    </div>
  );
}

function AccountsSection() {
  const { csrfToken, activeAccountId } = useAuth();
  const toast = useToast();
  const [page, setPage] = useState<AdminAccountsPage | null>(null);
  // Tracks how many items we've fetched from the server (independent of local deletions).
  // Used to determine the correct offset for the next loadMore call and whether more exist.
  const [serverLoadedCount, setServerLoadedCount] = useState(0);
  const [listLoading, setListLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [expanded, setExpanded] = useState<Set<number>>(new Set());
  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');

  useEffect(() => {
    const t = setTimeout(() => setDebouncedSearch(search.trim()), 300);
    return () => clearTimeout(t);
  }, [search]);

  useEffect(() => {
    let cancelled = false;
    setListLoading(true);
    setError(null);
    AdminPanel.listAccounts(0, debouncedSearch || undefined)
      .then((res) => {
        if (!cancelled) {
          setPage(res);
          setServerLoadedCount(res.items.length);
          setListLoading(false);
        }
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať účty.');
        setListLoading(false);
      });
    return () => { cancelled = true; };
  }, [debouncedSearch]);

  async function loadMore() {
    if (!page) return;
    if (serverLoadedCount >= page.total) return;
    setLoadingMore(true);
    try {
      const res = await AdminPanel.listAccounts(serverLoadedCount, debouncedSearch || undefined);
      setPage((prev) => prev ? {
        ...res,
        items: [...prev.items, ...res.items],
      } : res);
      setServerLoadedCount((c) => c + res.items.length);
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Načítanie zlyhalo.';
      toast.error(msg);
    } finally {
      setLoadingMore(false);
    }
  }

  function toggle(id: number) {
    setExpanded((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  function patchAccount(id: number, patch: Partial<AdminAccount>) {
    setPage((prev) => prev ? {
      ...prev,
      items: prev.items.map((a) => a.id === id ? { ...a, ...patch } : a),
    } : prev);
  }

  function removeAccount(id: number) {
    setPage((prev) => prev ? {
      ...prev,
      items: prev.items.filter((a) => a.id !== id),
      total: prev.total - 1,
    } : prev);
  }

  function patchUser(accountId: number, userId: number, patch: Partial<AdminUser>) {
    setPage((prev) => prev ? {
      ...prev,
      items: prev.items.map((a) => a.id === accountId ? {
        ...a,
        users: a.users.map((u) => u.id === userId ? { ...u, ...patch } : u),
      } : a),
    } : prev);
  }

  function removeUser(accountId: number, userId: number) {
    setPage((prev) => prev ? {
      ...prev,
      items: prev.items.map((a) => a.id === accountId ? {
        ...a,
        users: a.users.filter((u) => u.id !== userId),
      } : a),
    } : prev);
  }

  async function onDeleteAccount(a: AdminAccount) {
    if (!window.confirm(
      `Naozaj zmazať účet „${a.invoice_company_name}"? Vymažú sa všetky firmy, kontroly, školenia, faktúry a používatelia tohto účtu. Akcia je nezvratná.`,
    )) return;
    try {
      await AdminPanel.deleteAccount(a.id, csrfToken);
      removeAccount(a.id);
      toast.success('Účet zmazaný');
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : 'Zmazanie zlyhalo.');
    }
  }

  async function onDeleteUser(accountId: number, u: AdminUser) {
    if (!window.confirm(`Naozaj zmazať používateľa „${u.fullname}"? Akcia je nezvratná.`)) return;
    try {
      await AdminPanel.deleteUser(u.id, csrfToken);
      removeUser(accountId, u.id);
      toast.success('Používateľ zmazaný');
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : 'Zmazanie zlyhalo.');
    }
  }

  async function onToggleAdmin(accountId: number, u: AdminUser) {
    const next = !u.is_admin;
    try {
      await AdminPanel.updateUser(u.id, { is_admin: next }, csrfToken);
      patchUser(accountId, u.id, { is_admin: next });
      toast.success(next ? 'Admin práva pridelené' : 'Admin práva odobraté');
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : 'Operácia zlyhala.');
    }
  }

  // First load — show skeleton for the whole block
  if (page === null) {
    if (error) {
      return (
        <Card className="px-5 py-4">
          <p className="text-sm text-status-bad">{error}</p>
        </Card>
      );
    }
    return <CardBlockSkeleton rows={6} />;
  }

  const hasMore = !listLoading && serverLoadedCount < page.total;
  const searching = debouncedSearch !== '';

  return (
    <Card className="overflow-hidden">
      <div className="flex items-center gap-3 border-b border-ink-100 bg-gradient-to-br from-firol-50/60 to-transparent px-5 py-4">
        <div className="grid size-11 place-items-center rounded-2xl bg-firol-500 text-white shadow-[var(--shadow-glow)]">
          <Users className="size-5" />
        </div>
        <div className="min-w-0 flex-1">
          <h2 className="text-base font-semibold text-ink-900">Účty a používatelia</h2>
          <p className="text-xs text-ink-500">
            {listLoading
              ? 'Hľadám…'
              : page.total === 0
                ? (searching ? 'Žiadne výsledky pre daný výraz.' : 'Zatiaľ tu nie sú žiadne účty.')
                : `${page.total} ${pluralAccounts(page.total)}${searching ? ' nájdených' : ' v systéme'} · zobrazených ${page.items.length}`}
          </p>
        </div>
      </div>

      <div className="border-b border-ink-100 px-3 py-2.5">
        <div className="relative">
          <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-ink-400" />
          <input
            type="search"
            placeholder="Hľadaj podľa názvu účtu, mena alebo e-mailu…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="w-full rounded-xl border border-ink-200 bg-white py-2 pl-9 pr-3 text-sm text-ink-900 placeholder:text-ink-400 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-400/20 transition-colors"
          />
        </div>
      </div>

      {listLoading ? (
        <div className="flex justify-center py-8">
          <Loader2 className="size-5 animate-spin text-firol-500" />
        </div>
      ) : (
        <>
          <ul className="flex flex-col gap-2 px-3 py-3">
            {page.items.map((a) => (
              <AccountRow
                key={a.id}
                account={a}
                isActive={a.id === activeAccountId}
                expanded={expanded.has(a.id) || searching}
                onToggle={() => toggle(a.id)}
                onAccountSaved={(patch) => patchAccount(a.id, patch)}
                onAccountDelete={() => onDeleteAccount(a)}
                onUserSaved={(uid, patch) => patchUser(a.id, uid, patch)}
                onUserDelete={(u) => onDeleteUser(a.id, u)}
                onToggleAdmin={(u) => onToggleAdmin(a.id, u)}
              />
            ))}
          </ul>

          {hasMore && (
            <div className="flex justify-center border-t border-ink-100 px-5 py-4">
              <Button
                type="button"
                variant="secondary"
                onClick={loadMore}
                loading={loadingMore}
                leftIcon={<ChevronDown className="size-4" />}
              >
                Zobraziť ďalších {Math.min(page.limit, page.total - serverLoadedCount)}
              </Button>
            </div>
          )}
        </>
      )}
    </Card>
  );
}

function pluralAccounts(n: number): string {
  if (n === 1) return 'účet';
  if (n >= 2 && n <= 4) return 'účty';
  return 'účtov';
}

function AccountRow({
  account, isActive, expanded, onToggle,
  onAccountSaved, onAccountDelete,
  onUserSaved, onUserDelete, onToggleAdmin,
}: {
  account: AdminAccount;
  isActive: boolean;
  expanded: boolean;
  onToggle: () => void;
  onAccountSaved: (patch: Partial<AdminAccount>) => void;
  onAccountDelete: () => void;
  onUserSaved: (userId: number, patch: Partial<AdminUser>) => void;
  onUserDelete: (u: AdminUser) => void;
  onToggleAdmin: (u: AdminUser) => void;
}) {
  const [editing, setEditing] = useState(false);

  return (
    <li className="rounded-2xl border border-ink-100">
      <div className="flex items-center gap-3 px-3 py-3">
        <button
          type="button"
          onClick={onToggle}
          aria-label={expanded ? 'Skryť používateľov' : 'Zobraziť používateľov'}
          className="grid size-9 shrink-0 place-items-center rounded-xl text-ink-500 transition-colors hover:bg-ink-100 hover:text-ink-700"
        >
          {expanded ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
        </button>
        <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-firol-50 text-firol-600">
          <Building2 className="size-4" />
        </span>
        <div className="min-w-0 flex-1">
          <p className="flex items-center gap-1.5 truncate text-sm font-semibold text-ink-900">
            <span className="truncate">{account.invoice_company_name}</span>
            {isActive && <Badge tone="ok">Aktuálne prihlásený</Badge>}
          </p>
          <p className="mt-0.5 flex items-center gap-1.5 truncate text-xs text-ink-500">
            <span>#{account.id}</span>
            <span className="text-ink-300">·</span>
            <span>{account.users.length} {account.users.length === 1 ? 'používateľ' : 'používateľov'}</span>
            {account.subscription_end_date && (
              <>
                <span className="text-ink-300">·</span>
                <span>do {account.subscription_end_date}</span>
              </>
            )}
            {account.stripe_status && !account.users.some((u) => u.is_admin || u.is_env_seed) && (
              <>
                <span className="text-ink-300">·</span>
                <Badge tone={account.stripe_status === 'active' ? 'ok' : 'warn'}>
                  {account.stripe_status}
                </Badge>
              </>
            )}
          </p>
        </div>
        <button
          type="button"
          onClick={() => setEditing((v) => !v)}
          title="Upraviť účet"
          className="grid size-9 place-items-center rounded-xl text-ink-500 transition-colors hover:bg-ink-100 hover:text-ink-700"
        >
          <Pencil className="size-4" />
        </button>
        <button
          type="button"
          onClick={onAccountDelete}
          title="Zmazať účet"
          className="ml-2.5 grid size-9 place-items-center rounded-xl text-ink-500 transition-colors hover:bg-[var(--color-status-bad-bg)] hover:text-status-bad"
        >
          <Trash2 className="size-4" />
        </button>
      </div>

      {editing && (
        <AccountEditForm
          account={account}
          onClose={() => setEditing(false)}
          onSaved={(patch) => { onAccountSaved(patch); setEditing(false); }}
        />
      )}

      {expanded && (
        <ul className="flex flex-col gap-2 border-t border-ink-100 bg-ink-50/40 px-3 py-3">
          {account.users.length === 0 ? (
            <li className="px-3 py-2 text-xs italic text-ink-400">
              Účet nemá priradených používateľov.
            </li>
          ) : account.users.map((u) => (
            <UserRow
              key={u.id}
              user={u}
              isMain={u.id === account.main_user_id}
              onSaved={(patch) => onUserSaved(u.id, patch)}
              onDelete={() => onUserDelete(u)}
              onToggleAdmin={() => onToggleAdmin(u)}
            />
          ))}
        </ul>
      )}
    </li>
  );
}

function AccountEditForm({
  account, onClose, onSaved,
}: {
  account: AdminAccount;
  onClose: () => void;
  onSaved: (patch: Partial<AdminAccount>) => void;
}) {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const [name, setName]   = useState(account.invoice_company_name);
  const [end, setEnd]     = useState(account.subscription_end_date ?? '');
  const [included, setIncluded] = useState(String(account.included_technicians));
  const [saving, setSaving] = useState(false);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    try {
      const patch: Partial<AdminAccount> = {};
      const update: Record<string, string | number> = {};
      if (name.trim() !== account.invoice_company_name) {
        update.invoice_company_name = name.trim();
        patch.invoice_company_name = name.trim();
      }
      if (end !== (account.subscription_end_date ?? '')) {
        update.subscription_end_date = end;
        patch.subscription_end_date = end;
      }
      const includedNum = Number(included);
      if (Number.isFinite(includedNum) && includedNum >= 1 && includedNum !== account.included_technicians) {
        update.included_technicians = includedNum;
        patch.included_technicians = includedNum;
      }
      if (Object.keys(update).length === 0) {
        onClose();
        return;
      }
      await AdminPanel.updateAccount(account.id, update, csrfToken);
      onSaved(patch);
      toast.success('Účet upravený');
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : 'Uloženie zlyhalo.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <form
      onSubmit={onSubmit}
      className="flex flex-col gap-3 border-t border-ink-100 bg-ink-50/40 px-3 py-3 sm:px-5"
    >
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Názov firmy">
          {(p) => (
            <Input {...p} leftIcon={<Building2 className="size-4" />}
              value={name} onChange={(e) => setName(e.target.value)} />
          )}
        </Field>
        <Field label="Predplatné platí do">
          {(p) => (
            <Input {...p} type="date" leftIcon={<CalendarDays className="size-4" />}
              value={end} onChange={(e) => setEnd(e.target.value)} />
          )}
        </Field>
      </div>
      <Field
        label="Technici zahrnutí v predplatnom (vrátane account admina)"
        hint={`Aktuálne extra technici: ${account.extra_technicians}. Zmena ihneď preráta Stripe (proratované).`}
      >
        {(p) => (
          <Input {...p} type="number" inputMode="numeric" min={1} max={1000}
            leftIcon={<Hash className="size-4" />}
            value={included} onChange={(e) => setIncluded(e.target.value)} />
        )}
      </Field>
      <div className="flex justify-end gap-2">
        <Button type="button" variant="secondary" onClick={onClose}>Zrušiť</Button>
        <Button type="submit" loading={saving}>Uložiť</Button>
      </div>
    </form>
  );
}

function UserRow({
  user, isMain, onSaved, onDelete, onToggleAdmin,
}: {
  user: AdminUser;
  isMain: boolean;
  onSaved: (patch: Partial<AdminUser>) => void;
  onDelete: () => void;
  onToggleAdmin: () => void;
}) {
  const [editing, setEditing] = useState(false);

  return (
    <li className="rounded-xl border border-ink-100 bg-white">
      <div className="flex items-center gap-3 px-3 py-2.5">
        <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-firol-50 text-firol-600">
          <User className="size-4" />
        </span>
        <div className="min-w-0 flex-1">
          <p className="truncate text-sm font-semibold text-ink-900">
            {user.fullname}
            {user.is_admin && (
              <ShieldCheck className="ml-1.5 inline-block size-3.5 text-firol-600" aria-label="Admin" />
            )}
          </p>
          <p className="mt-0.5 flex flex-wrap items-center gap-1.5 truncate text-xs text-ink-500">
            <span className="truncate">{user.email}</span>
            {isMain && <Badge tone="ok">Hlavný</Badge>}
            {(user.is_admin || user.is_env_seed) && <Badge tone="warn">Admin</Badge>}
            {!user.is_active && <Badge tone="neutral">Neaktívny</Badge>}
          </p>
        </div>
        <button
          type="button"
          onClick={onToggleAdmin}
          disabled={user.is_env_seed}
          title={user.is_env_seed ? 'Env seed admin — nedá sa zmeniť cez UI' : user.is_admin ? 'Odobrať admin práva' : 'Prideliť admin práva'}
          className="grid size-9 place-items-center rounded-xl text-ink-500 transition-colors hover:bg-ink-100 hover:text-ink-700 disabled:opacity-40"
        >
          {(user.is_admin || user.is_env_seed) ? <ShieldOff className="size-4" /> : <ShieldCheck className="size-4" />}
        </button>
        <button
          type="button"
          onClick={() => setEditing((v) => !v)}
          title="Upraviť používateľa"
          className="grid size-9 place-items-center rounded-xl text-ink-500 transition-colors hover:bg-ink-100 hover:text-ink-700"
        >
          <Pencil className="size-4" />
        </button>
        <button
          type="button"
          onClick={onDelete}
          title="Zmazať používateľa"
          className="ml-2.5 grid size-9 place-items-center rounded-xl text-ink-500 transition-colors hover:bg-[var(--color-status-bad-bg)] hover:text-status-bad"
        >
          <Trash2 className="size-4" />
        </button>
      </div>

      {editing && (
        <UserEditForm
          user={user}
          onClose={() => setEditing(false)}
          onSaved={(patch) => { onSaved(patch); setEditing(false); }}
        />
      )}
    </li>
  );
}

function UserEditForm({
  user, onClose, onSaved,
}: {
  user: AdminUser;
  onClose: () => void;
  onSaved: (patch: Partial<AdminUser>) => void;
}) {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const [fullname, setFullname] = useState(user.fullname);
  const [email, setEmail]       = useState(user.email);
  const [phone, setPhone]       = useState(user.phone ?? '');
  const [saving, setSaving]     = useState(false);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    try {
      const patch: Partial<AdminUser> = {};
      const update: Record<string, string | null> = {};
      if (fullname.trim() !== user.fullname)            { update.fullname = fullname.trim(); patch.fullname = fullname.trim(); }
      if (email.trim().toLowerCase() !== user.email)     { update.email    = email.trim().toLowerCase(); patch.email = email.trim().toLowerCase(); }
      const phoneTrim = phone.trim();
      const phoneVal  = phoneTrim === '' ? null : phoneTrim;
      if (phoneVal !== (user.phone ?? null))             { update.phone    = phoneVal; patch.phone = phoneVal; }
      if (Object.keys(update).length === 0) { onClose(); return; }
      await AdminPanel.updateUser(user.id, update, csrfToken);
      onSaved(patch);
      toast.success('Používateľ upravený');
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : 'Uloženie zlyhalo.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={onSubmit} className="flex flex-col gap-3 border-t border-ink-100 bg-ink-50/40 px-3 py-3">
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Meno a priezvisko">
          {(p) => (
            <Input {...p} leftIcon={<User className="size-4" />}
              value={fullname} onChange={(e) => setFullname(e.target.value)} />
          )}
        </Field>
        <Field label="E-mail">
          {(p) => (
            <Input {...p} type="email" leftIcon={<AtSign className="size-4" />}
              value={email} onChange={(e) => setEmail(e.target.value)} />
          )}
        </Field>
      </div>
      <Field label="Telefón">
        {(p) => (
          <Input {...p} leftIcon={<Phone className="size-4" />}
            value={phone} onChange={(e) => setPhone(e.target.value)} />
        )}
      </Field>
      <div className="flex justify-end gap-2">
        <Button type="button" variant="secondary" onClick={onClose}>Zrušiť</Button>
        <Button type="submit" loading={saving}>Uložiť</Button>
      </div>
    </form>
  );
}

function AdminSettingsSection() {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const [settings, setSettings] = useState<SystemSettings | null>(null);
  const [loading, setLoading]   = useState(true);
  const [saving, setSaving]     = useState(false);

  const [trialDays, setTrialDays]   = useState('');
  const [priceMonthly, setMonthly]  = useState('');
  const [priceYearly, setYearly]    = useState('');
  const [defaultIncluded, setDefaultIncluded] = useState('');
  const [extraTechEur, setExtraTechEur]       = useState('');
  const [maxSelfService, setMaxSelfService]   = useState('');

  useEffect(() => {
    let cancelled = false;
    Admin.settings()
      .then((res) => {
        if (cancelled) return;
        setSettings(res.settings);
        setTrialDays(res.settings.trial_days);
        setMonthly(res.settings.price_monthly_eur);
        setYearly(res.settings.price_yearly_eur);
        setDefaultIncluded(res.settings.default_included_technicians);
        // Persist as cents on the backend; display as EUR (×.×× allowed).
        const cents = Number(res.settings.price_per_extra_technician_cents);
        setExtraTechEur(Number.isFinite(cents) ? (cents / 100).toFixed(2) : '');
        setMaxSelfService(res.settings.max_self_service_technicians);
      })
      .catch(() => undefined)
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, []);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    try {
      const extraCents = Math.round(Number(extraTechEur) * 100);
      const res = await Admin.updateSettings({
        trial_days:        Number(trialDays),
        price_monthly_eur: Number(priceMonthly),
        price_yearly_eur:  Number(priceYearly),
        default_included_technicians:     Number(defaultIncluded),
        price_per_extra_technician_cents: Number.isFinite(extraCents) ? extraCents : 0,
        max_self_service_technicians:     Number(maxSelfService),
      }, csrfToken);
      setSettings(res.settings);
      toast.success('Systémové nastavenia uložené.');
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : 'Uloženie zlyhalo.');
    } finally {
      setSaving(false);
    }
  }

  if (loading) return <CardBlockSkeleton rows={4} />;
  if (!settings) return null;

  return (
    <Card className="overflow-hidden border-firol-200">
      <div className="flex items-center gap-3 border-b border-ink-100 bg-gradient-to-br from-firol-100/60 to-transparent px-5 py-4">
        <div className="grid size-11 place-items-center rounded-2xl bg-ink-900 text-white shadow-[var(--shadow-glow)]">
          <SettingsIcon className="size-5" />
        </div>
        <div className="min-w-0 flex-1">
          <h2 className="text-base font-semibold text-ink-900">Systémové nastavenia</h2>
          <p className="text-xs text-ink-500">
            Skúšobné obdobie a ceny — dotýka sa všetkých účtov.
          </p>
        </div>
        <Badge tone="warn">Admin</Badge>
      </div>

      <form onSubmit={onSubmit} className="flex flex-col gap-3 px-5 py-5">
        <Field label="Skúšobné obdobie (dni)" hint="Uplatní sa len pri nových registráciách.">
          {(p) => (
            <Input {...p} type="number" inputMode="numeric" min={0} max={365}
                   leftIcon={<Hash className="size-4" />}
                   value={trialDays} onChange={(e) => setTrialDays(e.target.value)} />
          )}
        </Field>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label="Cena mesačne (EUR)">
            {(p) => (
              <Input {...p} type="number" inputMode="numeric" min={0}
                     value={priceMonthly} onChange={(e) => setMonthly(e.target.value)} />
            )}
          </Field>
          <Field label="Cena ročne (EUR)">
            {(p) => (
              <Input {...p} type="number" inputMode="numeric" min={0}
                     value={priceYearly} onChange={(e) => setYearly(e.target.value)} />
            )}
          </Field>
        </div>
        <p className="text-xs text-ink-400">
          Pozn.: ceny tu sú len informatívne pre UI/copy. Skutočné sumy účtuje
          Stripe podľa <code>STRIPE_PRICE_MONTHLY</code> / <code>STRIPE_PRICE_YEARLY</code>.
        </p>

        <div className="mt-2 border-t border-ink-100 pt-4">
          <h3 className="mb-1 text-xs font-semibold uppercase tracking-wider text-ink-500">
            Predplatné — technici
          </h3>
          <p className="mb-3 text-xs text-ink-400">
            Koľko technikov je zahrnutých v základnom pláne (vrátane account admina) a koľko
            stojí každý ďalší. Nad nastavený limit aplikácia skryje self-service tlačidlo
            a navrhne kontakt pre individuálnu ponuku.
          </p>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <Field label="Zahrnutých technikov" hint="Nové účty">
              {(p) => (
                <Input {...p} type="number" inputMode="numeric" min={1} max={100}
                  leftIcon={<Hash className="size-4" />}
                  value={defaultIncluded} onChange={(e) => setDefaultIncluded(e.target.value)} />
              )}
            </Field>
            <Field label="Cena za extra (EUR / mesiac)">
              {(p) => (
                <Input {...p} type="number" inputMode="decimal" min={0} step="0.01"
                  value={extraTechEur} onChange={(e) => setExtraTechEur(e.target.value)} />
              )}
            </Field>
            <Field label="Limit self-service" hint="Nad tento počet ponukneme individuálnu cenu.">
              {(p) => (
                <Input {...p} type="number" inputMode="numeric" min={1} max={1000}
                  value={maxSelfService} onChange={(e) => setMaxSelfService(e.target.value)} />
              )}
            </Field>
          </div>
          <p className="mt-2 text-xs text-ink-400">
            Cena za extra technika sa účtuje cez Stripe ako druhá položka v predplatnom
            (ročne = mesačne × 12), s proráciou pri každej zmene počtu.
          </p>
        </div>
        <div className="flex justify-end pt-1">
          <Button type="submit" loading={saving} leftIcon={<SettingsIcon className="size-4" />}>
            Uložiť nastavenia
          </Button>
        </div>
      </form>
    </Card>
  );
}

/**
 * Route guard — non-admins get bounced to the dashboard. We let the actual
 * admin endpoints return 403 too, so this is just to keep the URL hygienic
 * and avoid flashing the page shell.
 */
export function RequireAdmin({ children }: { children: React.ReactNode }) {
  const { isAdmin, status } = useAuth();
  const navigate = useNavigate();

  useEffect(() => {
    if (status === 'authed' && !isAdmin) navigate('/', { replace: true });
  }, [status, isAdmin, navigate]);

  if (status === 'loading') {
    return (
      <div className="grid place-items-center py-20">
        <Loader2 className="size-6 animate-spin text-firol-500" />
      </div>
    );
  }
  if (!isAdmin) return null;
  return <>{children}</>;
}

void Spinner;
