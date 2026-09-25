import { useEffect, useState } from 'react';
import { Pencil, Plus, Star, Trash2, UserCheck, X } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useIsReadOnly } from '@/auth/useIsReadOnly';
import {
  Companies,
  type CompanyPerson,
  type FacilityListItem,
} from '@/api/companies';
import { ApiError } from '@/lib/api';
import { useConfirm } from '@/lib/confirm';
import { useToast } from '@/lib/toast';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { SkeletonList } from '@/components/ui/Skeleton';

/**
 * People at a client entitled to sign a protocol — block 1 / chapter 13.2.
 *
 * A client is rarely one signature. A larger one has a konateľ at the
 * registered seat and a vedúci zamestnanec at each prevádzka, and which of them
 * signs depends on the document: a požiarna kniha entry is approved by the
 * vedúci zamestnanec, not by the konateľ. So a person is either pinned to one
 * prevádzka or valid for the whole company, and one of them can be marked as
 * the default the signature screen offers first.
 *
 * Removing a person archives them. A protocol signed last year records who
 * signed it, and that has to survive the person leaving the company.
 */
export function CompanyPersons({
  companyId,
  facilities,
}: {
  companyId: number;
  facilities: FacilityListItem[];
}) {
  const { csrfToken } = useAuth();
  const isReadOnly = useIsReadOnly();
  const confirm = useConfirm();
  const toast = useToast();

  const [persons, setPersons] = useState<CompanyPerson[] | null>(null);
  const [roleSuggestions, setRoleSuggestions] = useState<string[]>([]);
  const [editing, setEditing] = useState<CompanyPerson | 'new' | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    Companies.persons(companyId)
      .then((res) => {
        if (cancelled) return;
        setPersons(res.items);
        setRoleSuggestions(res.role_suggestions);
      })
      .catch(() => {
        if (!cancelled) setPersons([]);
      });
    return () => {
      cancelled = true;
    };
  }, [companyId]);

  async function reload() {
    const res = await Companies.persons(companyId);
    setPersons(res.items);
  }

  async function handleDelete(person: CompanyPerson) {
    const ok = await confirm({
      title: 'Odstrániť osobu?',
      description: `„${person.fullname}" sa prestane ponúkať pri podpisovaní protokolov. Už podpísané protokoly zostávajú nedotknuté.`,
      confirmLabel: 'Odstrániť',
    });
    if (!ok) return;
    try {
      await Companies.deletePerson(companyId, person.id, csrfToken);
      setPersons((prev) => prev?.filter((p) => p.id !== person.id) ?? null);
      toast.success('Osoba odstránená');
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : 'Osobu sa nepodarilo odstrániť.');
    }
  }

  return (
    <section>
      <header className="mb-3 flex items-center justify-between">
        <div>
          <h2 className="text-sm font-semibold uppercase tracking-wide text-ink-500">
            Osoby oprávnené podpisovať
          </h2>
          <p className="mt-0.5 text-xs text-ink-400">
            Ponúknu sa pri prevzatí protokolu. Osoba priradená k prevádzke má
            prednosť pred celofiremnou.
          </p>
        </div>
        {!isReadOnly && editing === null && (
          <Button type="button" size="sm" variant="secondary" onClick={() => setEditing('new')} leftIcon={<Plus className="size-3.5" />}>
            Pridať
          </Button>
        )}
      </header>

      {error && <Card className="mb-2 px-4 py-3 text-sm text-status-bad">{error}</Card>}

      {editing !== null && (
        <PersonForm
          companyId={companyId}
          facilities={facilities}
          roleSuggestions={roleSuggestions}
          person={editing === 'new' ? null : editing}
          onCancel={() => setEditing(null)}
          onSaved={async () => {
            setEditing(null);
            setError(null);
            await reload();
          }}
          onError={setError}
        />
      )}

      {persons === null ? (
        <SkeletonList count={2} />
      ) : persons.length === 0 ? (
        editing === null && (
          <Card className="flex flex-col items-center gap-2 px-4 py-8 text-center">
            <div className="grid size-12 place-items-center rounded-2xl bg-firol-50 text-firol-500">
              <UserCheck className="size-5" />
            </div>
            <p className="text-sm text-ink-700">Zatiaľ žiadne osoby.</p>
            <p className="max-w-sm text-xs text-ink-500">
              Pri podpisovaní protokolu sa dá meno a funkcia zadať aj priamo na
              mieste — tu ich uložíš, ak sa opakujú.
            </p>
          </Card>
        )
      ) : (
        <ul className="flex flex-col gap-2">
          {persons.map((person) => (
            <li key={person.id}>
              <Card className="flex items-center gap-3 px-4 py-3">
                <span className="grid size-9 shrink-0 place-items-center rounded-2xl bg-firol-50 text-firol-600">
                  <UserCheck className="size-4" />
                </span>
                <div className="min-w-0 flex-1">
                  <p className="flex items-center gap-1.5 truncate text-sm font-semibold text-ink-900">
                    {person.fullname}
                    {person.is_default && (
                      <span
                        title="Predvolená osoba — ponúkne sa ako prvá"
                        className="inline-flex items-center gap-0.5 rounded-full bg-firol-50 px-1.5 py-0.5 text-[10px] font-medium text-firol-700"
                      >
                        <Star className="size-2.5" />
                        predvolená
                      </span>
                    )}
                  </p>
                  <p className="truncate text-xs text-ink-500">
                    {person.role_title}
                    {' · '}
                    {person.facility_id
                      ? (facilities.find((f) => f.id === person.facility_id)?.name ?? 'prevádzka')
                      : 'celá firma'}
                    {person.email && ` · ${person.email}`}
                  </p>
                </div>
                {!isReadOnly && (
                  <div className="flex shrink-0 items-center gap-2">
                    <button
                      type="button"
                      aria-label={`Upraviť ${person.fullname}`}
                      onClick={() => setEditing(person)}
                      className="grid size-8 place-items-center rounded-xl text-[var(--color-status-warn)] transition-colors hover:bg-[var(--color-status-warn-bg)]"
                    >
                      <Pencil className="size-4" />
                    </button>
                    <button
                      type="button"
                      aria-label={`Odstrániť ${person.fullname}`}
                      onClick={() => handleDelete(person)}
                      className="grid size-8 place-items-center rounded-xl text-[var(--color-status-bad)] transition-colors hover:bg-[var(--color-status-bad-bg)]"
                    >
                      <Trash2 className="size-4" />
                    </button>
                  </div>
                )}
              </Card>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

function PersonForm({
  companyId,
  facilities,
  roleSuggestions,
  person,
  onCancel,
  onSaved,
  onError,
}: {
  companyId: number;
  facilities: FacilityListItem[];
  roleSuggestions: string[];
  person: CompanyPerson | null;
  onCancel: () => void;
  onSaved: () => void;
  onError: (message: string | null) => void;
}) {
  const { csrfToken } = useAuth();
  const [fullname, setFullname] = useState(person?.fullname ?? '');
  const [roleTitle, setRoleTitle] = useState(person?.role_title ?? '');
  const [email, setEmail] = useState(person?.email ?? '');
  const [facilityId, setFacilityId] = useState<number | null>(person?.facility_id ?? null);
  const [isDefault, setIsDefault] = useState(person?.is_default ?? false);
  const [saving, setSaving] = useState(false);

  async function handleSave() {
    if (!fullname.trim() || !roleTitle.trim()) {
      onError('Vyplň meno aj funkciu.');
      return;
    }
    setSaving(true);
    onError(null);
    try {
      const payload = {
        fullname: fullname.trim(),
        role_title: roleTitle.trim(),
        email: email.trim() || null,
        facility_id: facilityId,
        is_default: isDefault,
      };
      if (person) {
        await Companies.updatePerson(companyId, person.id, payload, csrfToken);
      } else {
        await Companies.createPerson(companyId, payload, csrfToken);
      }
      onSaved();
    } catch (err) {
      onError(err instanceof ApiError ? err.message : 'Osobu sa nepodarilo uložiť.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <Card className="mb-2 flex animate-fade-up flex-col gap-3 p-4">
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-semibold text-ink-900">
          {person ? 'Upraviť osobu' : 'Nová osoba'}
        </h3>
        <button
          type="button"
          aria-label="Zavrieť"
          onClick={onCancel}
          className="grid size-7 place-items-center rounded-lg text-ink-400 transition-colors hover:bg-ink-100 hover:text-ink-700"
        >
          <X className="size-4" />
        </button>
      </div>

      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Meno a priezvisko" required>
          {(p) => (
            <Input {...p} value={fullname} onChange={(e) => setFullname(e.target.value)} placeholder="Ján Novák" />
          )}
        </Field>
        <Field label="Funkcia" required hint="Klientom sú aj školy, obce a združenia.">
          {(p) => (
            <>
              <Input
                {...p}
                list="company-person-roles"
                value={roleTitle}
                onChange={(e) => setRoleTitle(e.target.value)}
                placeholder="konateľ"
              />
              <datalist id="company-person-roles">
                {roleSuggestions.map((r) => (
                  <option key={r} value={r} />
                ))}
              </datalist>
            </>
          )}
        </Field>
        <Field label="E-mail" hint="Voliteľný.">
          {(p) => (
            <Input {...p} type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
          )}
        </Field>
        <Field label="Platí pre" hint="Prázdne = pre celú firmu.">
          {(p) => (
            <Select
              id={p.id}
              value={facilityId === null ? '' : String(facilityId)}
              onChange={(v) => setFacilityId(v ? Number(v) : null)}
              placeholder="— celá firma —"
              options={facilities.map((f) => ({ value: String(f.id), label: f.name }))}
            />
          )}
        </Field>
      </div>

      <label className="flex cursor-pointer items-center gap-2 text-sm text-ink-700">
        <input
          type="checkbox"
          checked={isDefault}
          onChange={(e) => setIsDefault(e.target.checked)}
          className="size-4 accent-firol-500"
        />
        Ponúkať ako prvú pri podpisovaní
      </label>

      <div className="flex justify-end gap-2">
        <Button variant="ghost" size="sm" onClick={onCancel} disabled={saving}>
          Zrušiť
        </Button>
        <Button size="sm" onClick={handleSave} loading={saving}>
          Uložiť
        </Button>
      </div>
    </Card>
  );
}
