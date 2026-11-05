import { useState, type FormEvent } from 'react';
import { Building, Building2, Droplets, Flame, Mail, Map, Mailbox, MapPin, NotebookPen, User, Warehouse, Zap } from 'lucide-react';
import { Facilities, type Facility, type FacilityPayload } from '@/api/facilities';
import { ApiError } from '@/lib/api';
import { facilityCreateOptimistic } from '@/lib/offlineEntities';
import { handleOfflineSave } from '@/lib/offline';
import { useToast } from '@/lib/toast';
import { Input } from '@/components/ui/Input';
import { Field } from '@/components/ui/Field';
import { Button } from '@/components/ui/Button';
import { useAuth } from '@/auth/AuthContext';
import { formatAddress } from '@/lib/address';
import { clientNouns, descriptionHint, facilityNouns, isBytoveDruzstvo, type ClientType } from '@/lib/clientKind';

/** The firm's seat, as far as the "Rovnaká adresa ako sídlo firmy" button needs it. */
export type CompanyAddress = {
  street: string | null;
  postal_code: string | null;
  city: string | null;
};

type FacilityFormProps = {
  /** Pre-fill an existing facility for edit mode. */
  initial?: Facility;
  /** When creating a new facility, the company it should attach to. */
  companyId?: number;
  /** The firm's address; without one the "same address as the seat" button is hidden. */
  companyAddress?: CompanyAddress | null;
  /** Decides the nouns and whether the floor fields are shown. Defaults to firma. */
  clientType?: ClientType | null;
  /** Called after a successful save with the resulting facility. */
  onSaved: (facility: Facility) => void;
  /** Optional cancel button — only rendered if provided. */
  onCancel?: () => void;
  /** Override the submit button text. */
  submitLabel?: string;
  /** Render mode — used for label switches. */
  mode?: 'create' | 'edit';
};

/**
 * Shared facility create/edit form. Used by the in-dropdown
 * "Pridať prevádzku" dialog so the validation, error handling and field
 * layout stay in one place. The standalone /facilities/:id/edit screen
 * keeps its own copy because it also handles archive — that's a
 * different surface, and inlining a destructive action into a quick-add
 * dialog would be a footgun.
 */
export function FacilityForm({
  initial,
  companyId,
  companyAddress,
  clientType,
  onSaved,
  onCancel,
  submitLabel,
  mode = initial ? 'edit' : 'create',
}: FacilityFormProps) {
  const { csrfToken } = useAuth();
  const toast = useToast();

  const [name, setName] = useState(initial?.name ?? '');
  const [street, setStreet] = useState(initial?.street ?? '');
  const [postalCode, setPostalCode] = useState(initial?.postal_code ?? '');
  const [city, setCity] = useState(initial?.city ?? '');
  const [contactPerson, setContactPerson] = useState(initial?.contact_person ?? '');
  const [email, setEmail] = useState(initial?.email ?? '');
  const [notes, setNotes] = useState(initial?.notes ?? '');
  const [power, setPower] = useState(initial?.main_power_location ?? '');
  const [gas, setGas] = useState(initial?.main_gas_location ?? '');
  const [water, setWater] = useState(initial?.main_water_location ?? '');
  const [description, setDescription] = useState(initial?.description ?? '');
  const [vchody, setVchody] = useState(initial?.vchody ?? '');
  const bd = isBytoveDruzstvo(clientType);
  const [floorsAbove, setFloorsAbove] = useState(
    initial?.floors_above != null ? String(initial.floors_above) : bd ? '4' : '',
  );
  const [floorsBelow, setFloorsBelow] = useState(
    initial?.floors_below != null ? String(initial.floors_below) : bd ? '1' : '',
  );

  const [submitting, setSubmitting] = useState(false);
  const [nameError, setNameError] = useState<string | null>(null);
  const [emailError, setEmailError] = useState<string | null>(null);
  const [floorError, setFloorError] = useState<string | null>(null);
  const [apiError, setApiError] = useState<string | null>(null);
  const nouns = facilityNouns(clientType);
  const client = clientNouns(clientType);

  // Spec 5 — offered only when the firm has an address to take over (street
  // or obec, like the prototype).
  const seatAddress = formatAddress(companyAddress?.street, companyAddress?.postal_code, companyAddress?.city);
  const canTakeSeat = mode === 'create' && Boolean(companyAddress?.street?.trim() || companyAddress?.city?.trim());

  function takeSeatAddress() {
    setStreet(companyAddress?.street ?? '');
    setPostalCode(companyAddress?.postal_code ?? '');
    setCity(companyAddress?.city ?? '');
    toast.success(`Adresa prevzatá zo sídla ${client.cases.gen}`);
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (!name.trim()) {
      setNameError(nouns.nameRequired);
      return;
    }
    if (email.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) {
      setEmailError('Zadaj platnú e-mailovú adresu.');
      return;
    }
    let above: number | null = null;
    let below: number | null = null;
    if (bd) {
      above = Number(floorsAbove);
      below = Number(floorsBelow);
      if (!Number.isInteger(above) || above < 1 || above > 40 || !Number.isInteger(below) || below < 0 || below > 5) {
        setFloorError('Nadzemné podlažia sú 1–40, podzemné 0–5.');
        return;
      }
    }
    if (mode === 'create' && companyId === undefined) {
      setApiError('Najprv vyber klienta.');
      return;
    }
    setNameError(null);
    setEmailError(null);
    setFloorError(null);
    setApiError(null);
    setSubmitting(true);
    const payload: FacilityPayload = {
      name: name.trim(),
      street: street.trim() || undefined,
      postal_code: postalCode.trim() || undefined,
      city: city.trim() || undefined,
      email: email.trim() || undefined,
      contact_person: contactPerson.trim() || undefined,
      notes: notes.trim() || undefined,
      main_power_location: power.trim() || undefined,
      main_gas_location: gas.trim() || undefined,
      main_water_location: water.trim() || undefined,
      description: description.trim() || undefined,
      floors_above: bd ? above : null,
      floors_below: bd ? below : null,
      vchody: bd ? (vchody.trim() || undefined) : undefined,
    };
    try {
      const optimistic = facilityCreateOptimistic({
        companyId: companyId!,
        clientType,
        name: payload.name,
        street: payload.street ?? null,
        postal_code: payload.postal_code ?? null,
        city: payload.city ?? null,
        contact_person: payload.contact_person ?? null,
        notes: payload.notes ?? null,
        email: payload.email ?? null,
        main_power_location: payload.main_power_location ?? null,
        main_gas_location: payload.main_gas_location ?? null,
        main_water_location: payload.main_water_location ?? null,
        description: payload.description ?? null,
        floors_above: payload.floors_above ?? null,
        floors_below: payload.floors_below ?? null,
        vchody: payload.vchody ?? null,
      });
      const res = mode === 'edit' && initial
        ? await Facilities.update(initial.id, payload, csrfToken)
        : await Facilities.createUnderCompany(companyId!, payload, csrfToken, optimistic);
      onSaved(res.facility);
      toast.success(mode === 'edit' ? nouns.savedToast : nouns.createdToast);
    } catch (err) {
      // Offline edit: queued + cache patched, navigate on as if saved.
      if (handleOfflineSave(err, toast)) {
        if (initial) onSaved(initial);
        return;
      }
      const msg = err instanceof ApiError ? err.message : 'Niečo sa pokazilo.';
      setApiError(msg);
      toast.error(msg);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
      <Field label={nouns.nameLabel} required error={nameError}>
        {(p) => (
          <Input
            {...p}
            required
            leftIcon={bd ? <Building2 className="size-4" /> : <Warehouse className="size-4" />}
            value={name}
            onChange={(e) => { setName(e.target.value); if (nameError) setNameError(null); }}
            placeholder={nouns.namePlaceholder}
            autoFocus={mode === 'create'}
          />
        )}
      </Field>

      {canTakeSeat && (
        <div className="flex flex-col gap-1.5">
          <button
            type="button"
            onClick={takeSeatAddress}
            className="flex items-start gap-3 rounded-2xl border border-dashed border-firol-300 bg-firol-50/40 px-3.5 py-3 text-left transition-all duration-200 hover:border-firol-400 hover:bg-firol-50 active:scale-[0.99] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300"
          >
            <Building className="mt-0.5 size-4 shrink-0 text-firol-600" />
            <span className="min-w-0">
              <span className="block text-sm font-medium text-ink-800">Rovnaká adresa ako sídlo {client.cases.gen}</span>
              <span className="mt-0.5 block break-words text-xs text-ink-500">{seatAddress}</span>
            </span>
          </button>
        </div>
      )}

      <Field label="Ulica a číslo">
        {(p) => (
          <Input
            {...p}
            leftIcon={<MapPin className="size-4" />}
            value={street}
            onChange={(e) => setStreet(e.target.value)}
            placeholder="Priemyselná 5"
          />
        )}
      </Field>

      <div className="grid grid-cols-3 gap-3">
        <Field label="PSČ" className="col-span-1">
          {(p) => (
            <Input
              {...p}
              leftIcon={<Mailbox className="size-4" />}
              value={postalCode}
              onChange={(e) => setPostalCode(e.target.value)}
              placeholder="851 01"
            />
          )}
        </Field>

        <Field label="Obec" className="col-span-2">
          {(p) => (
            <Input
              {...p}
              leftIcon={<Map className="size-4" />}
              value={city}
              onChange={(e) => setCity(e.target.value)}
              placeholder="Bratislava"
            />
          )}
        </Field>
      </div>

      <Field
        label={`E-mail ${nouns.cases.gen}`}
        hint={`Protokoly a zápisy z ${nouns.demo.gen} sa posielajú sem. Má prednosť pred e-mailom ${client.cases.gen}.`}
        error={emailError}
      >
        {(p) => (
          <Input
            {...p}
            type="email"
            inputMode="email"
            autoComplete="off"
            leftIcon={<Mail className="size-4" />}
            value={email}
            onChange={(e) => { setEmail(e.target.value); if (emailError) setEmailError(null); }}
            placeholder="hala@agrofruct.sk"
          />
        )}
      </Field>

      <div className="flex flex-col gap-3 rounded-2xl border border-firol-200 bg-firol-50/60 p-3">
        <div>
          <p className="text-xs font-medium text-ink-700">Hlavné vypínače a uzávery</p>
          <p className="mt-1 text-xs leading-relaxed text-ink-500">
            Tlačí sa do záznamu z prehliadky.
          </p>
        </div>
        <Field label="Hlavný vypínač elektriny">
          {(p) => (
            <Input
              {...p}
              leftIcon={<Zap className="size-4" />}
              value={power}
              onChange={(e) => setPower(e.target.value)}
              placeholder="v ERZ na 1. PP"
            />
          )}
        </Field>
        <Field label="Hlavný uzáver plynu">
          {(p) => (
            <Input
              {...p}
              leftIcon={<Flame className="size-4" />}
              value={gas}
              onChange={(e) => setGas(e.target.value)}
              placeholder="v skrinke na fasáde pri vstupe"
            />
          )}
        </Field>
        <Field label="Hlavný uzáver vody">
          {(p) => (
            <Input
              {...p}
              leftIcon={<Droplets className="size-4" />}
              value={water}
              onChange={(e) => setWater(e.target.value)}
              placeholder="na 1. PP pri výťahovej šachte"
            />
          )}
        </Field>
      </div>

      <Field label="Kontaktná osoba">
        {(p) => (
          <Input
            {...p}
            leftIcon={<User className="size-4" />}
            value={contactPerson}
            onChange={(e) => setContactPerson(e.target.value)}
            placeholder="Peter Vedúci"
          />
        )}
      </Field>

      <Field label="Poznámky" hint="Napríklad prístup, otváracie hodiny, špecifiká">
        {(p) => (
          <div className="relative">
            <span className="pointer-events-none absolute left-3 top-3 text-ink-400">
              <NotebookPen className="size-4" />
            </span>
            <textarea
              id={p.id}
              aria-invalid={p['aria-invalid']}
              rows={3}
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              placeholder="Vstup len cez recepciu."
              className="w-full rounded-xl border border-ink-200 bg-white py-2.5 pl-10 pr-3 text-sm text-ink-800 placeholder:text-ink-400 transition-colors duration-150 hover:border-ink-300 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200"
            />
          </div>
        )}
      </Field>

      <div id="popis-objektu" className="flex flex-col gap-3 rounded-2xl border border-ink-200 bg-white p-3">
        <p className="text-xs font-medium text-ink-700">Pre preventívne prehliadky</p>
        <Field label="Popis objektu" hint={descriptionHint(clientType)}>
          {(p) => (
            <textarea
              id={p.id}
              aria-invalid={p['aria-invalid']}
              rows={3}
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              placeholder="Počet podlaží, garáže, kotolňa, spôsob vykurovania…"
              className="w-full rounded-xl border border-ink-200 bg-white px-3 py-2.5 text-sm text-ink-800 placeholder:text-ink-400 transition-colors duration-150 hover:border-ink-300 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200"
            />
          )}
        </Field>
        {bd && (
          <>
            <Field label="Podlažia" error={floorError}>
              {() => (
                <div className="grid grid-cols-2 gap-3">
                  <Field label="Nadzemných">
                    {(p) => (
                      <Input
                        {...p}
                        type="number"
                        inputMode="numeric"
                        min={1}
                        max={40}
                        value={floorsAbove}
                        onChange={(e) => { setFloorsAbove(e.target.value); if (floorError) setFloorError(null); }}
                      />
                    )}
                  </Field>
                  <Field label="Podzemných">
                    {(p) => (
                      <Input
                        {...p}
                        type="number"
                        inputMode="numeric"
                        min={0}
                        max={5}
                        value={floorsBelow}
                        onChange={(e) => { setFloorsBelow(e.target.value); if (floorError) setFloorError(null); }}
                      />
                    )}
                  </Field>
                </div>
              )}
            </Field>
            <Field
              label="Vchody (voliteľné)"
              hint="Z počtu alebo zoznamu sa vytvoria dlaždice pri zápise nedostatku."
            >
              {(p) => (
                <Input
                  {...p}
                  value={vchody}
                  maxLength={255}
                  placeholder="napr. 3 alebo 2, 4, 6; prázdne = dopíšete pri nedostatku"
                  onChange={(e) => setVchody(e.target.value)}
                />
              )}
            </Field>
          </>
        )}
      </div>

      {apiError && (
        <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
          {apiError}
        </div>
      )}

      <div className="flex flex-col gap-2 pt-1 sm:flex-row sm:items-center sm:justify-end">
        {onCancel && (
          <Button type="button" variant="ghost" onClick={onCancel} disabled={submitting}>
            Zrušiť
          </Button>
        )}
        <Button type="submit" loading={submitting}>
          {submitLabel ?? (mode === 'edit' ? 'Uložiť zmeny' : nouns.createLabel)}
        </Button>
      </div>
    </form>
  );
}
