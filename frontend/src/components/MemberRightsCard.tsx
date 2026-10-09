import { useState } from 'react';
import { ShieldCheck } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useIsMainUser } from '@/auth/useIsMainUser';
import { Team } from '@/api/team';
import { ApiError } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { cn } from '@/lib/cn';
import { Card } from '@/components/ui/Card';

/**
 * Práva členov — chapter 1.6. One switch for the whole account: on, every
 * technician may delete finished úkony and protocols (and unlock a protocol
 * with „Upraviť"), delete or retire sklad items and archive a firma or
 * prevádzka; off, only the main user may and the others don't see those
 * buttons. New accounts start off (migration 054). A technician can always delete their own draft. Bulk delete and
 * restore from a backup are the main user's alone either way.
 *
 * Only the main user sees this card.
 */
export function MemberRightsCard({ className }: { className?: string }) {
  const isMain = useIsMainUser();
  const { csrfToken, accounts, activeAccountId, refresh } = useAuth();
  const toast = useToast();
  const [saving, setSaving] = useState(false);

  const account = accounts.find((a) => a.id === activeAccountId) ?? null;
  if (!isMain || account === null) return null;

  const enabled = account.member_rights !== 'obmedzene';

  async function toggle() {
    setSaving(true);
    try {
      await Team.setMemberRights(enabled ? 'obmedzene' : 'plne', csrfToken);
      await refresh();
      toast.success('Nastavenie uložené');
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : 'Uloženie zlyhalo.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <Card className={cn('overflow-hidden', className)}>
      <div className="flex items-start gap-3 px-5 py-4">
        <span className="grid size-11 shrink-0 place-items-center rounded-2xl bg-firol-50 text-firol-600">
          <ShieldCheck className="size-5" />
        </span>
        <div className="min-w-0 flex-1">
          <h2 className="text-base font-semibold text-ink-900">Technici môžu mazať</h2>
          <p className="mt-0.5 text-xs text-ink-500">
            Mazanie hotových úkonov a protokolov, ich odomknutie na úpravu, mazanie položiek skladu
            a archivovanie firiem a prevádzok. Keď je vypnuté, technici tieto tlačidlá nevidia.
            Vlastné rozpracované úkony si technik môže zmazať vždy.
          </p>
          <p className="mt-2 text-xs font-medium text-ink-700">
            {enabled
              ? 'Teraz: technici môžu mazať. Platí pre všetkých technikov účtu okrem hlavného používateľa.'
              : 'Teraz: technici nemôžu mazať. Platí pre všetkých technikov účtu okrem hlavného používateľa.'}
            {' '}Nové účty začínajú s vypnutým nastavením.
          </p>
        </div>
        <button
          type="button"
          role="switch"
          aria-checked={enabled}
          aria-label="Technici môžu mazať"
          disabled={saving}
          onClick={toggle}
          className={cn(
            'relative mt-1 inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors duration-200',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300 focus-visible:ring-offset-2 disabled:opacity-60',
            enabled ? 'bg-firol-500' : 'bg-ink-200',
          )}
        >
          <span
            className={cn(
              'inline-block size-5 rounded-full bg-white shadow transition-transform duration-200',
              enabled ? 'translate-x-5' : 'translate-x-0.5',
            )}
          />
        </button>
      </div>
    </Card>
  );
}
