import { useEffect, useState } from 'react';
import { BadgeCheck, CalendarDays, Hash, Lock, Save } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useIsMainUser } from '@/auth/useIsMainUser';
import {
  AccountCertificates,
  type CompanyCertificate,
  type CompanyCertificateInput,
  type CompanyCertificateType,
} from '@/api/accountCertificates';
import { ApiError } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { Card } from '@/components/ui/Card';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { Button } from '@/components/ui/Button';
import { CardBlockSkeleton } from '@/components/ui/Skeleton';
import { SectionBack } from '@/pages/SettingsPage';

type Draft = Record<CompanyCertificateType, CompanyCertificateInput>;

function toDraft(list: CompanyCertificate[]): Draft {
  const empty: CompanyCertificateInput = { number: null, valid_from: null, valid_to: null };
  const out: Draft = { bts: { ...empty }, vv: { ...empty } };
  for (const c of list) {
    out[c.type] = { number: c.number, valid_from: c.valid_from, valid_to: c.valid_to };
  }
  return out;
}

/**
 * Nastavenia → Firemné oprávnenia (chapter 1.3.1).
 *
 * BTS and výchova a vzdelávanie belong to the firm: entered here once by the
 * main user and printed the same on every technician's protocols (an
 * oboznámenie BOZP carries the VV number). Personal oprávnenia stay in each
 * technician's own profile, and a member neither sees nor edits these —
 * which is why the page is only offered to the main user.
 */
export function CompanyCertificatesPage() {
  const { csrfToken } = useAuth();
  const isMain = useIsMainUser();
  const toast = useToast();
  const [list, setList] = useState<CompanyCertificate[] | null>(null);
  const [draft, setDraft] = useState<Draft | null>(null);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!isMain) return;
    let cancelled = false;
    AccountCertificates.list()
      .then((res) => {
        if (cancelled) return;
        setList(res.certificates);
        setDraft(toDraft(res.certificates));
      })
      .catch((err: unknown) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať oprávnenia.');
      });
    return () => { cancelled = true; };
  }, [isMain]);

  if (!isMain) {
    return (
      <div className="flex flex-col gap-4">
        <SectionBack label="Firemné oprávnenia" />
        <Card className="flex items-start gap-3 px-4 py-3">
          <Lock className="mt-0.5 size-4 shrink-0 text-ink-400" />
          <p className="text-sm text-ink-600">
            Firemné oprávnenia spravuje hlavný používateľ účtu.
          </p>
        </Card>
      </div>
    );
  }

  const dirty = list !== null && draft !== null && JSON.stringify(toDraft(list)) !== JSON.stringify(draft);

  async function save() {
    if (!draft) return;
    setSaving(true);
    setError(null);
    const clean = (c: CompanyCertificateInput): CompanyCertificateInput => ({
      number: c.number?.trim() || null,
      valid_from: c.valid_from || null,
      valid_to: c.valid_to || null,
    });
    try {
      const res = await AccountCertificates.save({ bts: clean(draft.bts), vv: clean(draft.vv) }, csrfToken);
      setList(res.certificates);
      setDraft(toDraft(res.certificates));
      toast.success('Firemné oprávnenia uložené');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Uloženie sa nepodarilo.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="flex flex-col gap-4">
      <SectionBack label="Firemné oprávnenia" />
      <Card className="px-4 py-3 text-xs text-ink-600">
        Oprávnenia firmy sa zadávajú raz pre celý účet a na protokoloch všetkých technikov sú rovnaké.
        Osobné oprávnenia si každý technik vedie vo svojom profile.
      </Card>

      {!list || !draft ? (
        error ? <Card className="px-4 py-3 text-sm text-status-bad">{error}</Card> : <CardBlockSkeleton rows={4} />
      ) : (
        <>
          {list.map((c) => {
            const d = draft[c.type];
            const set = (patch: Partial<CompanyCertificateInput>) =>
              setDraft({ ...draft, [c.type]: { ...d, ...patch } });
            return (
              <Card key={c.type} className="flex flex-col gap-3 p-4">
                <div className="flex items-start gap-3">
                  <span className="grid size-9 shrink-0 place-items-center rounded-2xl bg-blue-50 text-blue-600">
                    <BadgeCheck className="size-4" />
                  </span>
                  <div className="min-w-0">
                    <h2 className="text-sm font-semibold text-ink-900">{c.label}</h2>
                    <p className="text-xs text-ink-500">{c.legal_basis}</p>
                  </div>
                </div>
                <Field label="Číslo oprávnenia">
                  {(p) => (
                    <Input {...p} leftIcon={<Hash className="size-4" />} value={d.number ?? ''}
                      onChange={(e) => set({ number: e.target.value })} />
                  )}
                </Field>
                <div className="grid grid-cols-2 gap-3">
                  <Field label="Platnosť od">
                    {(p) => (
                      <Input {...p} type="date" leftIcon={<CalendarDays className="size-4" />}
                        value={d.valid_from ?? ''} onChange={(e) => set({ valid_from: e.target.value || null })} />
                    )}
                  </Field>
                  <Field label="Platnosť do">
                    {(p) => (
                      <Input {...p} type="date" leftIcon={<CalendarDays className="size-4" />}
                        value={d.valid_to ?? ''} onChange={(e) => set({ valid_to: e.target.value || null })} />
                    )}
                  </Field>
                </div>
              </Card>
            );
          })}
          {error && <p className="text-sm text-status-bad">{error}</p>}
          <Button type="button" className="self-end" disabled={!dirty} loading={saving} onClick={save}
            leftIcon={<Save className="size-4" />}>
            Uložiť
          </Button>
        </>
      )}
    </div>
  );
}
