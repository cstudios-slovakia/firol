import { useEffect, useState } from 'react';
import { BellRing } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useIsMainUser } from '@/auth/useIsMainUser';
import { Calendar, NOTICE_DAYS, type NoticeSettings } from '@/api/calendar';
import { ApiError } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { cn } from '@/lib/cn';
import { Card } from '@/components/ui/Card';

/**
 * Automatic client notice — chapter 11.3. Off after install; when switched
 * on, every client with a contact e-mail gets the same message as the manual
 * „Oznámiť klientovi e-mailom" the chosen number of days before the termín,
 * once. Sent by the daily job backend/bin/send-deadline-notices.php.
 *
 * Only the main user can change it (it writes to the firm's clients on the
 * whole team's behalf); a member sees nothing here.
 */
export function ClientNoticeSettingsCard({ className }: { className?: string }) {
  const isMain = useIsMainUser();
  const { csrfToken } = useAuth();
  const toast = useToast();
  const [settings, setSettings] = useState<NoticeSettings | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!isMain) return;
    Calendar.noticeSettings()
      .then((r) => setSettings(r.settings))
      .catch(() => undefined);
  }, [isMain]);

  if (!isMain || settings === null) return null;

  async function update(patch: Partial<NoticeSettings>) {
    setSaving(true);
    try {
      const r = await Calendar.updateNoticeSettings(patch, csrfToken);
      setSettings(r.settings);
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
          <BellRing className="size-5" />
        </span>
        <div className="min-w-0 flex-1">
          <h2 className="text-base font-semibold text-ink-900">Automatické oznámenie klientovi</h2>
          <p className="mt-0.5 text-xs text-ink-500">
            Klient s vyplneným kontaktným e-mailom dostane pred termínom rovnakú správu ako pri
            tlačidle „Oznámiť klientovi e-mailom". Každý termín sa oznámi len raz.
          </p>
        </div>
        <button
          type="button"
          role="switch"
          aria-checked={settings.enabled}
          aria-label="Automatické oznámenie klientovi"
          disabled={saving}
          onClick={() => update({ enabled: !settings.enabled })}
          className={cn(
            'relative mt-1 inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors duration-200',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300 focus-visible:ring-offset-2 disabled:opacity-60',
            settings.enabled ? 'bg-firol-500' : 'bg-ink-200',
          )}
        >
          <span
            className={cn(
              'inline-block size-5 rounded-full bg-white shadow transition-transform duration-200',
              settings.enabled ? 'translate-x-5' : 'translate-x-0.5',
            )}
          />
        </button>
      </div>

      {settings.enabled && (
        <div className="animate-fade-in flex flex-wrap items-center gap-2 border-t border-ink-100 px-5 py-3">
          <span className="text-xs font-semibold uppercase tracking-wider text-ink-500">
            Počet dní vopred
          </span>
          <div className="flex rounded-xl bg-ink-50 p-0.5">
            {NOTICE_DAYS.map((d) => {
              const active = settings.days_ahead === d;
              return (
                <button
                  key={d}
                  type="button"
                  aria-pressed={active}
                  disabled={saving}
                  onClick={() => !active && update({ days_ahead: d })}
                  className={cn(
                    'h-8 rounded-[10px] px-3 text-xs font-medium tabular-nums transition-[background-color,color,transform] duration-150 active:scale-[0.97]',
                    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300',
                    active ? 'bg-white text-ink-900 shadow-sm' : 'text-ink-500 hover:text-ink-800',
                  )}
                >
                  {d} dní
                </button>
              );
            })}
          </div>
        </div>
      )}
    </Card>
  );
}
