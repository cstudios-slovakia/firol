import { useState, useRef, useEffect } from 'react';
import { Check, ChevronDown } from 'lucide-react';
import { useAuth, type Account } from '@/auth/AuthContext';
import { cn } from '@/lib/cn';

/**
 * Renders nothing when the user belongs to a single account — there is no
 * decision to make. With multiple accounts a dropdown lets them pick the
 * active one; switch hits POST /api/me/switch-account and re-fetches /me.
 */
export function AccountSwitcher() {
  const { accounts, activeAccountId, switchAccount } = useAuth();
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const wrap = useRef<HTMLDivElement>(null);

  useEffect(() => {
    function onClick(e: MouseEvent) {
      if (wrap.current && !wrap.current.contains(e.target as Node)) {
        setOpen(false);
      }
    }
    if (open) document.addEventListener('mousedown', onClick);
    return () => document.removeEventListener('mousedown', onClick);
  }, [open]);

  if (accounts.length <= 1) {
    return accounts[0] ? <CurrentLabel account={accounts[0]} /> : null;
  }

  const active = accounts.find((a) => a.id === activeAccountId) ?? accounts[0];

  async function pick(id: number) {
    if (id === activeAccountId) {
      setOpen(false);
      return;
    }
    setBusy(true);
    try {
      await switchAccount(id);
    } finally {
      setBusy(false);
      setOpen(false);
    }
  }

  return (
    <div ref={wrap} className="relative">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        disabled={busy}
        className="flex items-center gap-2 rounded-2xl border border-ink-200 bg-white px-3 py-1.5 text-sm font-medium text-ink-700 hover:border-ink-300 disabled:opacity-60"
      >
        <span className="truncate max-w-[10rem]">{active.invoice_company_name}</span>
        <ChevronDown className="size-4 text-ink-400" />
      </button>

      {open && (
        <div className="absolute right-0 z-20 mt-2 w-72 overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-[var(--shadow-lift)]">
          <div className="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-ink-400">
            Prepnúť účet
          </div>
          {accounts.map((acc) => (
            <button
              key={acc.id}
              type="button"
              onClick={() => pick(acc.id)}
              className={cn(
                'flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm transition-colors',
                acc.id === active.id
                  ? 'bg-firol-50/70 text-firol-700'
                  : 'text-ink-700 hover:bg-ink-50',
              )}
            >
              <span className="truncate">{acc.invoice_company_name}</span>
              {acc.id === active.id && <Check className="size-4 shrink-0" />}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

function CurrentLabel({ account }: { account: Account }) {
  return (
    <div className="rounded-2xl border border-transparent px-3 py-1.5 text-sm font-medium text-ink-700">
      {account.invoice_company_name}
    </div>
  );
}
