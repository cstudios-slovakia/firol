import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import {
  ArrowLeft, ArrowRight, Building2, CalendarDays, Check, FileText, Plus,
  ShieldAlert, Trash2, X,
} from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { Companies, type CompanyListItem, type FacilityListItem } from '@/api/companies';
import {
  Documentations, DocumentationSettings,
  type Documentation, type DocumentationData, type WaterUtility,
} from '@/api/documentations';
import { ApiError } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Field } from '@/components/ui/Field';
import { Select } from '@/components/ui/Select';
import { Button } from '@/components/ui/Button';
import { Spinner } from '@/components/ui/Spinner';
import { cn } from '@/lib/cn';

const STEPS = [
  'Firma a prevádzka',
  'Osoby a kontakty',
  'Objekty, miesta a evakuácia',
  'Zhrnutie a generovanie',
] as const;

const RELATION_OPTIONS = ['vlastné', 'prenajaté', 'vypožičané'];

// Quick-suggest chips for the title-page list (documents the app doesn't
// generate but that may belong in the documentation), per spec §4.1/§6.5.
const CUSTOM_SUGGESTIONS = ['Poriadok ohlasovne požiarov'];

function emptyData(): DocumentationData {
  return {
    konatel_funkcia: 'konateľ spoločnosti',
    ohlasovna_same_as_konatel: true,
    osoby_zapisy: [],
    dalsie_kontakty: [],
    objekty: [],
    miesta: [],
    ma_evakuacny_plan: false,
    custom_zoznam: [],
  };
}

export function DocumentationWizardPage() {
  const params = useParams();
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const { csrfToken } = useAuth();
  const toast = useToast();

  const routeId = params.id ? Number(params.id) : null;
  const presetCompanyId = numericParam(searchParams.get('company_id'));
  const presetFacilityId = numericParam(searchParams.get('facility_id'));

  const [docId, setDocId] = useState<number | null>(routeId);
  const [step, setStep] = useState(0);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [generating, setGenerating] = useState(false);
  const [apiError, setApiError] = useState<string | null>(null);

  const [companies, setCompanies] = useState<CompanyListItem[]>([]);
  const [facilities, setFacilities] = useState<FacilityListItem[]>([]);
  const [signerFunctions, setSignerFunctions] = useState<string[]>(['konateľ spoločnosti']);
  const [waterUtilities, setWaterUtilities] = useState<WaterUtility[]>([]);

  const [companyId, setCompanyId] = useState<number | null>(presetCompanyId);
  const [facilityId, setFacilityId] = useState<number | null>(presetFacilityId);
  const [issuedOn, setIssuedOn] = useState('');
  const [status, setStatus] = useState<'draft' | 'finalized'>('draft');
  const [data, setData] = useState<DocumentationData>(emptyData());

  function patch(p: Partial<DocumentationData>) {
    setData((d) => ({ ...d, ...p }));
  }

  // ── Initial load: companies + settings + (existing) record ──────────────────
  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const [cs, settings] = await Promise.all([
          Companies.list(),
          DocumentationSettings.show().catch(() => ({ signer_functions: ['konateľ spoločnosti'], water_utilities: [] })),
        ]);
        if (cancelled) return;
        setCompanies(cs.items);
        setSignerFunctions(settings.signer_functions.length ? settings.signer_functions : ['konateľ spoločnosti']);
        setWaterUtilities(settings.water_utilities);

        if (routeId !== null) {
          const res = await Documentations.show(routeId);
          if (cancelled) return;
          hydrateFromRecord(res.documentation);
        } else if (presetCompanyId === null && cs.items.length === 1) {
          setCompanyId(cs.items[0].id);
        }
      } catch (err) {
        if (!cancelled) {
          setApiError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať dáta.');
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [routeId]);

  function hydrateFromRecord(rec: Documentation) {
    setDocId(rec.id);
    setCompanyId(rec.company_id);
    setFacilityId(rec.facility_id);
    setIssuedOn(rec.issued_on ?? '');
    setStatus(rec.status);
    setData({ ...emptyData(), ...(rec.data ?? {}) });
  }

  // ── Facilities for the chosen company ───────────────────────────────────────
  useEffect(() => {
    if (companyId === null) {
      setFacilities([]);
      return;
    }
    let cancelled = false;
    Companies.show(companyId)
      .then((res) => { if (!cancelled) setFacilities(res.facilities); })
      .catch(() => undefined);
    return () => { cancelled = true; };
  }, [companyId]);

  const company = companies.find((c) => c.id === companyId) ?? null;
  const facility = facilities.find((f) => f.id === facilityId) ?? null;

  // Prefill §5.1 values when empty (don't clobber edited/loaded values).
  useEffect(() => {
    if (!company) return;
    setData((d) => {
      const next = { ...d };
      if (!next.firma_nazov) next.firma_nazov = company.name;
      if (!next.firma_ico) next.firma_ico = company.ico ?? '';
      if (!next.firma_sidlo) next.firma_sidlo = company.address ?? '';
      const fAddr = facility?.address ?? company.address ?? '';
      const fCity = facility?.city ?? company.city ?? '';
      if (!next.prevadzka_adresa) next.prevadzka_adresa = fAddr;
      if (!next.prevadzka_nazov) next.prevadzka_nazov = facility?.name ?? company.name;
      if (!next.mesto) next.mesto = fCity;
      return next;
    });
    if (!issuedOn) setIssuedOn(today());
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companyId, facilityId, company, facility]);

  const maZvysene = (data.miesta?.length ?? 0) > 0;

  // ── Persistence ─────────────────────────────────────────────────────────────
  function payloadData(): DocumentationData {
    const ohlasovna = data.ohlasovna_same_as_konatel ? (data.konatel_tel ?? '') : (data.ohlasovna_tel ?? '');
    return { ...data, ohlasovna_tel: ohlasovna };
  }

  async function persist(): Promise<number | null> {
    if (companyId === null) {
      setApiError('Vyber firmu.');
      return null;
    }
    setSaving(true);
    setApiError(null);
    try {
      if (docId === null) {
        const res = await Documentations.create(
          {
            company_id: companyId,
            facility_id: facilityId ?? undefined,
            issued_on: issuedOn || undefined,
            title: facility?.name ?? company?.name ?? undefined,
            data: payloadData(),
          },
          csrfToken,
        );
        setDocId(res.documentation.id);
        // Switch the URL to the persisted record without a remount.
        navigate(`/documentation/${res.documentation.id}`, { replace: true });
        return res.documentation.id;
      }
      await Documentations.update(
        docId,
        {
          facility_id: facilityId ?? null,
          issued_on: issuedOn || null,
          title: data.prevadzka_nazov || facility?.name || company?.name || null,
          data: payloadData(),
        },
        csrfToken,
      );
      return docId;
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Uloženie zlyhalo.';
      setApiError(msg);
      toast.error(msg);
      return null;
    } finally {
      setSaving(false);
    }
  }

  async function goNext() {
    if (step === 0 && companyId === null) {
      setApiError('Vyber firmu.');
      return;
    }
    const id = await persist();
    if (id === null) return;
    setStep((s) => Math.min(s + 1, STEPS.length - 1));
  }

  async function goBack() {
    if (step === 0) {
      navigate('/documentation');
      return;
    }
    await persist();
    setStep((s) => Math.max(s - 1, 0));
  }

  async function onGenerate() {
    const id = await persist();
    if (id === null) return;
    if (!data.konatel_meno?.trim() || !data.konatel_priezvisko?.trim() || !data.konatel_tel?.trim()) {
      setApiError('Doplň údaje konateľa (meno, priezvisko, telefón) v kroku 2.');
      setStep(1);
      return;
    }
    if (!issuedOn) {
      setApiError('Doplň dátum vyhotovenia v kroku 1.');
      setStep(0);
      return;
    }
    setGenerating(true);
    setApiError(null);
    try {
      await Documentations.generate(id, csrfToken);
      setStatus('finalized');
      toast.success('Dokumentácia vygenerovaná');
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Generovanie zlyhalo.';
      setApiError(msg);
      toast.error(msg);
    } finally {
      setGenerating(false);
    }
  }

  if (loading) {
    return <div className="flex justify-center py-10 text-ink-400"><Spinner /></div>;
  }

  if (companies.length === 0) {
    return (
      <div className="flex flex-col gap-4">
        <BackLink />
        <Card className="flex flex-col items-center gap-3 px-6 py-10 text-center">
          <h2 className="text-base font-semibold text-ink-900">Najprv pridaj firmu</h2>
          <p className="max-w-xs text-sm text-ink-500">
            Dokumentácia sa viaže na firmu — potrebuješ aspoň jednu vytvorenú.
          </p>
          <Link to="/companies/new" className="inline-flex h-11 items-center rounded-2xl bg-firol-500 px-4 text-sm font-medium text-white shadow-[var(--shadow-glow)] hover:bg-firol-600">
            Pridať firmu
          </Link>
        </Card>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-5">
      <BackLink />

      <header>
        <p className="text-xs font-semibold uppercase tracking-wider text-firol-500">
          {docId ? 'Dokumentácia PO' : 'Nová dokumentácia PO'}
        </p>
        <h1 className="mt-1 text-xl font-semibold tracking-tight text-ink-900">{STEPS[step]}</h1>
      </header>

      <Stepper step={step} onJump={async (s) => { if (s < step) { await persist(); setStep(s); } }} />

      {apiError && (
        <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
          {apiError}
        </div>
      )}

      {step === 0 && (
        <Step1
          companies={companies}
          facilities={facilities}
          companyId={companyId}
          facilityId={facilityId}
          issuedOn={issuedOn}
          data={data}
          onCompany={(id) => { setCompanyId(id); setFacilityId(null); }}
          onFacility={setFacilityId}
          onIssuedOn={setIssuedOn}
          onField={patch}
        />
      )}
      {step === 1 && (
        <Step2
          data={data}
          signerFunctions={signerFunctions}
          waterUtilities={waterUtilities}
          onField={patch}
        />
      )}
      {step === 2 && (
        <Step3 data={data} maZvysene={maZvysene} onField={patch} />
      )}
      {step === 3 && (
        <Step4
          docId={docId}
          status={status}
          data={data}
          maZvysene={maZvysene}
          generating={generating}
          onGenerate={onGenerate}
          onField={patch}
        />
      )}

      <div className="flex items-center justify-between pt-1">
        <Button variant="secondary" onClick={goBack} leftIcon={<ArrowLeft className="size-4" />} disabled={saving}>
          {step === 0 ? 'Zrušiť' : 'Späť'}
        </Button>
        {step < STEPS.length - 1 && (
          <Button onClick={goNext} loading={saving} rightIcon={<ArrowRight className="size-4" />}>
            Pokračovať
          </Button>
        )}
      </div>
    </div>
  );
}

// ─── Step 1 — Firma a prevádzka ────────────────────────────────────────────────

function Step1(props: {
  companies: CompanyListItem[];
  facilities: FacilityListItem[];
  companyId: number | null;
  facilityId: number | null;
  issuedOn: string;
  data: DocumentationData;
  onCompany: (id: number | null) => void;
  onFacility: (id: number | null) => void;
  onIssuedOn: (v: string) => void;
  onField: (p: Partial<DocumentationData>) => void;
}) {
  const { companies, facilities, companyId, facilityId, issuedOn, data, onCompany, onFacility, onIssuedOn, onField } = props;
  return (
    <Card className="flex flex-col gap-4 p-5">
      <Field label="Firma" required>
        {(p) => (
          <Select
            id={p.id}
            value={companyId !== null ? String(companyId) : ''}
            onChange={(v) => onCompany(v ? Number(v) : null)}
            placeholder="— vyber firmu —"
            leftIcon={<Building2 className="size-4" />}
            searchable
            options={companies.map((c) => ({ value: String(c.id), label: c.name, description: c.ico ? `IČO ${c.ico}` : undefined }))}
          />
        )}
      </Field>

      <Field label="Prevádzka" hint="Voliteľné — ak nezvolíš, použije sa firma.">
        {(p) => (
          <Select
            id={p.id}
            value={facilityId !== null ? String(facilityId) : ''}
            onChange={(v) => onFacility(v ? Number(v) : null)}
            disabled={companyId === null}
            placeholder="— bez konkrétnej prevádzky —"
            searchable
            options={[
              { value: '', label: '— bez konkrétnej prevádzky —' },
              ...facilities.map((f) => ({ value: String(f.id), label: f.name })),
            ]}
          />
        )}
      </Field>

      <div className="rounded-2xl bg-ink-50/60 p-4">
        <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-ink-500">
          Predvyplnené údaje — skontroluj a v prípade potreby uprav
        </p>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Názov firmy">{(p) => <Input id={p.id} value={data.firma_nazov ?? ''} onChange={(e) => onField({ firma_nazov: e.target.value })} />}</Field>
          <Field label="IČO">{(p) => <Input id={p.id} value={data.firma_ico ?? ''} onChange={(e) => onField({ firma_ico: e.target.value })} />}</Field>
          <Field label="Sídlo firmy" className="sm:col-span-2">{(p) => <Input id={p.id} value={data.firma_sidlo ?? ''} onChange={(e) => onField({ firma_sidlo: e.target.value })} />}</Field>
          <Field label="Názov prevádzky / org. zložky">{(p) => <Input id={p.id} value={data.prevadzka_nazov ?? ''} onChange={(e) => onField({ prevadzka_nazov: e.target.value })} />}</Field>
          <Field label="Adresa prevádzky">{(p) => <Input id={p.id} value={data.prevadzka_adresa ?? ''} onChange={(e) => onField({ prevadzka_adresa: e.target.value })} />}</Field>
          <Field label="Mesto (pre doložku „V …, dňa“)">{(p) => <Input id={p.id} value={data.mesto ?? ''} onChange={(e) => onField({ mesto: e.target.value })} />}</Field>
          <Field label="Dátum vyhotovenia" required hint="Zadaj manuálne, nemusí byť dnešný dátum.">
            {(p) => <Input id={p.id} type="date" leftIcon={<CalendarDays className="size-4" />} value={issuedOn} onChange={(e) => onIssuedOn(e.target.value)} />}
          </Field>
        </div>
      </div>
    </Card>
  );
}

// ─── Step 2 — Osoby a kontakty ─────────────────────────────────────────────────

function Step2(props: {
  data: DocumentationData;
  signerFunctions: string[];
  waterUtilities: WaterUtility[];
  onField: (p: Partial<DocumentationData>) => void;
}) {
  const { data, signerFunctions, waterUtilities, onField } = props;
  const sameOhlasovna = data.ohlasovna_same_as_konatel ?? true;

  return (
    <div className="flex flex-col gap-4">
      <Card className="flex flex-col gap-4 p-5">
        <h2 className="text-sm font-semibold text-ink-900">Konateľ / štatutár</h2>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Meno" required>{(p) => <Input id={p.id} value={data.konatel_meno ?? ''} onChange={(e) => onField({ konatel_meno: e.target.value })} />}</Field>
          <Field label="Priezvisko" required>{(p) => <Input id={p.id} value={data.konatel_priezvisko ?? ''} onChange={(e) => onField({ konatel_priezvisko: e.target.value })} />}</Field>
          <Field label="Funkcia podpisujúceho">
            {(p) => (
              <Select id={p.id} value={data.konatel_funkcia ?? 'konateľ spoločnosti'} onChange={(v) => onField({ konatel_funkcia: v })}
                options={signerFunctions.map((f) => ({ value: f, label: f }))} />
            )}
          </Field>
          <Field label="Telefón konateľa" required>{(p) => <Input id={p.id} value={data.konatel_tel ?? ''} onChange={(e) => onField({ konatel_tel: e.target.value })} />}</Field>
        </div>
      </Card>

      <Card className="flex flex-col gap-4 p-5">
        <h2 className="text-sm font-semibold text-ink-900">Telefónne čísla</h2>
        <Field label="Telefón ohlasovne požiarov">
          {() => (
            <div className="flex flex-col gap-2">
              <label className="flex items-center gap-2 text-sm text-ink-700">
                <input type="checkbox" checked={sameOhlasovna} onChange={(e) => onField({ ohlasovna_same_as_konatel: e.target.checked })}
                  className="size-4 rounded border-ink-300 text-firol-500 focus:ring-firol-300" />
                Rovnaký ako konateľ
              </label>
              {!sameOhlasovna && (
                <Input value={data.ohlasovna_tel ?? ''} onChange={(e) => onField({ ohlasovna_tel: e.target.value })} placeholder="Telefón ohlasovne" />
              )}
            </div>
          )}
        </Field>

        <Field label="Pohotovosť vodární" hint="Predvyplň podľa regiónu, alebo zadaj vlastné číslo.">
          {(p) => (
            <div className="flex flex-col gap-2">
              {waterUtilities.length > 0 && (
                <Select
                  value=""
                  onChange={(v) => { if (v) onField({ vodarne_tel: v }); }}
                  placeholder="— predvyplniť podľa regiónu —"
                  options={waterUtilities
                    .filter((w) => w.phone)
                    .map((w) => ({ value: w.phone, label: w.region, description: w.phone }))}
                />
              )}
              <Input id={p.id} value={data.vodarne_tel ?? ''} onChange={(e) => onField({ vodarne_tel: e.target.value })} placeholder="Telefón vodární" />
            </div>
          )}
        </Field>
      </Card>

      <RepeatList<{ meno: string; funkcia: string }>
        title="Osoby oprávnené na zápisy do požiarnej knihy"
        rows={data.osoby_zapisy ?? []}
        empty={{ meno: '', funkcia: '' }}
        onChange={(rows) => onField({ osoby_zapisy: rows })}
        addLabel="Pridať osobu"
        columns={[
          { key: 'meno', label: 'Meno a priezvisko' },
          { key: 'funkcia', label: 'Funkcia / zaradenie' },
        ]}
      />

      <RepeatList<{ meno: string; funkcia: string; telefon: string }>
        title="Ďalšie interné kontakty"
        hint="Nad rámec konateľa a technika (tí sú v zozname automaticky)."
        rows={data.dalsie_kontakty ?? []}
        empty={{ meno: '', funkcia: '', telefon: '' }}
        onChange={(rows) => onField({ dalsie_kontakty: rows })}
        addLabel="Pridať kontakt"
        columns={[
          { key: 'meno', label: 'Meno a priezvisko' },
          { key: 'funkcia', label: 'Funkcia' },
          { key: 'telefon', label: 'Telefón' },
        ]}
      />

      <Card className="flex flex-col gap-3 p-5">
        <h2 className="text-sm font-semibold text-ink-900">Doplňujúce údaje</h2>
        <Field label="Spôsob zabezpečenia v mimopracovnom čase">
          {(p) => <Input id={p.id} value={data.mimopracovny_sposob ?? ''} onChange={(e) => onField({ mimopracovny_sposob: e.target.value })} placeholder="napr. objekt je zabezpečený alarmom" />}
        </Field>
        <Field label="Osoba zodpovedná za OPP v prevádzke" hint="Spravidla konateľ. Necháš prázdne = doplní sa konateľ.">
          {(p) => <Input id={p.id} value={data.zodpovedna_osoba ?? ''} onChange={(e) => onField({ zodpovedna_osoba: e.target.value })} />}
        </Field>
        <Field label="Objekty, na ktoré sa vzťahuje požiarna kniha" hint="Necháš prázdne = adresa prevádzky.">
          {(p) => <Input id={p.id} value={data.kniha_objekty ?? ''} onChange={(e) => onField({ kniha_objekty: e.target.value })} />}
        </Field>
        <Field label="Osoba, u ktorej je požiarna kniha uložená" hint="Necháš prázdne = konateľ.">
          {(p) => <Input id={p.id} value={data.kniha_ulozena_osoba ?? ''} onChange={(e) => onField({ kniha_ulozena_osoba: e.target.value })} />}
        </Field>
      </Card>
    </div>
  );
}

// ─── Step 3 — Objekty, miesta a evakuačný plán ─────────────────────────────────

function Step3(props: { data: DocumentationData; maZvysene: boolean; onField: (p: Partial<DocumentationData>) => void }) {
  const { data, maZvysene, onField } = props;
  const objekty = data.objekty ?? [];
  const miesta = data.miesta ?? [];

  return (
    <div className="flex flex-col gap-4">
      <Card className="flex flex-col gap-3 p-5">
        <div>
          <h2 className="text-sm font-semibold text-ink-900">Objekty v identifikačnej karte</h2>
          <p className="text-xs text-ink-500">Názov, vzťah a dva prepínače Áno/Nie.</p>
        </div>
        {objekty.map((row, i) => (
          <div key={i} className="flex flex-col gap-2 rounded-xl border border-ink-100 p-3">
            <div className="flex items-center justify-between">
              <span className="text-xs font-semibold text-ink-400">Objekt {i + 1}</span>
              <button type="button" onClick={() => onField({ objekty: objekty.filter((_, j) => j !== i) })} className="text-ink-400 hover:text-status-bad">
                <Trash2 className="size-4" />
              </button>
            </div>
            <Input value={row.nazov_objektu} placeholder="Názov objektu" onChange={(e) => onField({ objekty: replaceAt(objekty, i, { ...row, nazov_objektu: e.target.value }) })} />
            <Select value={row.vztah || 'vlastné'} onChange={(v) => onField({ objekty: replaceAt(objekty, i, { ...row, vztah: v }) })}
              options={RELATION_OPTIONS.map((r) => ({ value: r, label: r }))} />
            <div className="grid gap-2 sm:grid-cols-2">
              <Segmented label="Zvýšené nebezpečenstvo" value={row.ma_zvysene} onChange={(v) => onField({ objekty: replaceAt(objekty, i, { ...row, ma_zvysene: v }) })} />
              <Segmented label="Jednoduchá evakuácia" value={row.jednoducha_evakuacia} onChange={(v) => onField({ objekty: replaceAt(objekty, i, { ...row, jednoducha_evakuacia: v }) })} />
            </div>
          </div>
        ))}
        <AddButton label="Pridať objekt" onClick={() => onField({ objekty: [...objekty, { nazov_objektu: '', vztah: 'vlastné', ma_zvysene: false, jednoducha_evakuacia: false }] })} />
      </Card>

      <Card className="flex flex-col gap-3 p-5">
        <div className="flex items-start gap-2">
          <ShieldAlert className="mt-0.5 size-4 shrink-0 text-amber-500" />
          <div>
            <h2 className="text-sm font-semibold text-ink-900">Miesta so zvýšeným nebezpečenstvom</h2>
            <p className="text-xs text-ink-500">Zadaním aspoň jedného miesta sa automaticky doplní dokument „Určenie miest…“.</p>
          </div>
        </div>
        {miesta.map((row, i) => (
          <div key={i} className="flex flex-col gap-2 rounded-xl border border-ink-100 p-3">
            <div className="flex items-center justify-between">
              <span className="text-xs font-semibold text-ink-400">Miesto {i + 1}</span>
              <button type="button" onClick={() => onField({ miesta: miesta.filter((_, j) => j !== i) })} className="text-ink-400 hover:text-status-bad">
                <Trash2 className="size-4" />
              </button>
            </div>
            <Input value={row.subjekt_miesta} placeholder="Názov subjektu" onChange={(e) => onField({ miesta: replaceAt(miesta, i, { ...row, subjekt_miesta: e.target.value }) })} />
            <Input value={row.objekt_miesta} placeholder="Objekt / adresa" onChange={(e) => onField({ miesta: replaceAt(miesta, i, { ...row, objekt_miesta: e.target.value }) })} />
            <Input value={row.nazov_miesta} placeholder="Označenie miesta / priestoru" onChange={(e) => onField({ miesta: replaceAt(miesta, i, { ...row, nazov_miesta: e.target.value }) })} />
          </div>
        ))}
        <AddButton label="Pridať miesto" onClick={() => onField({ miesta: [...miesta, { subjekt_miesta: data.firma_nazov ?? '', objekt_miesta: '', nazov_miesta: '' }] })} />
      </Card>

      <Card className="flex items-center justify-between gap-3 p-5">
        <div>
          <h2 className="text-sm font-semibold text-ink-900">Požiarny evakuačný plán</h2>
          <p className="text-xs text-ink-500">Zapnuté = generuje sa textová časť; grafickú časť kreslíš samostatne.</p>
        </div>
        <Toggle value={data.ma_evakuacny_plan ?? false} onChange={(v) => onField({ ma_evakuacny_plan: v })} />
      </Card>

      {maZvysene && (
        <p className="text-xs text-ink-500">
          <Check className="mr-1 inline size-3.5 text-status-ok" />
          Dokument „Určenie miest so zvýšeným nebezpečenstvom“ bude zaradený automaticky.
        </p>
      )}
    </div>
  );
}

// ─── Step 4 — Zhrnutie a generovanie ───────────────────────────────────────────

function Step4(props: {
  docId: number | null;
  status: 'draft' | 'finalized';
  data: DocumentationData;
  maZvysene: boolean;
  generating: boolean;
  onGenerate: () => void;
  onField: (p: Partial<DocumentationData>) => void;
}) {
  const { docId, status, data, maZvysene, generating, onGenerate, onField } = props;
  const zoznam = useMemo(() => buildZoznamPreview(data.ma_evakuacny_plan ?? false, maZvysene, data.custom_zoznam ?? []), [data.ma_evakuacny_plan, maZvysene, data.custom_zoznam]);
  const [docs, setDocs] = useState<Awaited<ReturnType<typeof Documentations.documents>>['items']>([]);

  useEffect(() => {
    if (docId === null) return;
    Documentations.documents(docId).then((r) => setDocs(r.items)).catch(() => undefined);
  }, [docId, status, generating]);

  return (
    <div className="flex flex-col gap-4">
      <Card className="flex flex-col gap-3 p-5">
        <h2 className="text-sm font-semibold text-ink-900">Dokumentácia bude obsahovať</h2>
        <ol className="flex flex-col gap-1.5">
          {zoznam.map((z) => (
            <li key={z.cislo} className="flex gap-2 text-sm text-ink-700">
              <span className="w-5 shrink-0 text-right tabular-nums text-ink-400">{z.cislo}.</span>
              <span>{z.nazov}</span>
            </li>
          ))}
        </ol>
        <CustomItems
          items={data.custom_zoznam ?? []}
          onChange={(items) => onField({ custom_zoznam: items })}
        />
      </Card>

      <Card className="flex flex-col gap-3 p-5">
        <h2 className="text-sm font-semibold text-ink-900">Generovanie</h2>
        <p className="text-xs text-ink-500">Vyplnené PDF a editovateľný .docx vzniknú spolu. Môžeš generovať aj opakovane.</p>
        <Button onClick={onGenerate} loading={generating} leftIcon={<FileText className="size-4" />}>
          {docs.length > 0 ? 'Generovať znova' : 'Generovať dokumentáciu'}
        </Button>
      </Card>

      {docs.length > 0 && (
        <Card className="flex flex-col gap-2 p-5">
          <h2 className="text-sm font-semibold text-ink-900">Vygenerované dokumenty</h2>
          {docs.map((d) => (
            <div key={d.id} className="flex items-center justify-between gap-2 rounded-xl border border-ink-100 px-3 py-2 text-sm">
              <div className="min-w-0">
                <p className="truncate font-medium text-ink-800">{d.number}</p>
                <p className="text-xs text-ink-400">{new Date(d.generated_at).toLocaleString('sk-SK')}</p>
              </div>
              <div className="flex shrink-0 gap-2">
                <a href={d.download_url} target="_blank" rel="noreferrer" className="rounded-lg border border-ink-200 px-2.5 py-1.5 text-xs font-medium text-ink-700 hover:bg-ink-50">PDF</a>
                {d.docx_download_url && (
                  <a href={d.docx_download_url} className="rounded-lg border border-ink-200 px-2.5 py-1.5 text-xs font-medium text-ink-700 hover:bg-ink-50">DOCX</a>
                )}
              </div>
            </div>
          ))}
        </Card>
      )}
    </div>
  );
}

function CustomItems(props: { items: { nazov: string }[]; onChange: (items: { nazov: string }[]) => void }) {
  const { items, onChange } = props;
  const [draft, setDraft] = useState('');
  return (
    <div className="flex flex-col gap-2 border-t border-ink-100 pt-3">
      <p className="text-xs font-semibold uppercase tracking-wide text-ink-500">Vlastné položky do zoznamu</p>
      {items.map((it, i) => (
        <div key={i} className="flex items-center gap-2 rounded-lg bg-ink-50 px-2.5 py-1.5 text-sm">
          <span className="flex-1">{it.nazov}</span>
          <button type="button" onClick={() => onChange(items.filter((_, j) => j !== i))} className="text-ink-400 hover:text-status-bad"><X className="size-3.5" /></button>
        </div>
      ))}
      <div className="flex flex-wrap gap-1.5">
        {CUSTOM_SUGGESTIONS.filter((s) => !items.some((it) => it.nazov === s)).map((s) => (
          <button key={s} type="button" onClick={() => onChange([...items, { nazov: s }])} className="rounded-full border border-ink-200 px-2.5 py-1 text-xs text-ink-600 hover:border-firol-300 hover:text-firol-700">
            + {s}
          </button>
        ))}
      </div>
      <div className="flex gap-2">
        <Input value={draft} onChange={(e) => setDraft(e.target.value)} placeholder="Pridať dokument do zoznamu" onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); if (draft.trim()) { onChange([...items, { nazov: draft.trim() }]); setDraft(''); } } }} />
        <Button type="button" variant="secondary" onClick={() => { if (draft.trim()) { onChange([...items, { nazov: draft.trim() }]); setDraft(''); } }}>Pridať</Button>
      </div>
    </div>
  );
}

// ─── Shared little components ───────────────────────────────────────────────────

function Stepper({ step, onJump }: { step: number; onJump: (s: number) => void }) {
  return (
    <div className="flex items-center gap-1.5">
      {STEPS.map((label, i) => (
        <button
          key={label}
          type="button"
          onClick={() => onJump(i)}
          disabled={i > step}
          className={cn(
            'h-1.5 flex-1 rounded-full transition-colors',
            i <= step ? 'bg-firol-500' : 'bg-ink-200',
            i > step && 'cursor-not-allowed',
          )}
          aria-label={`Krok ${i + 1}: ${label}`}
        />
      ))}
    </div>
  );
}

function Toggle({ value, onChange }: { value: boolean; onChange: (v: boolean) => void }) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={value}
      onClick={() => onChange(!value)}
      className={cn('relative h-7 w-12 shrink-0 rounded-full transition-colors', value ? 'bg-firol-500' : 'bg-ink-200')}
    >
      <span className={cn('absolute top-1 size-5 rounded-full bg-white shadow transition-all', value ? 'left-6' : 'left-1')} />
    </button>
  );
}

function Segmented({ label, value, onChange }: { label: string; value: boolean; onChange: (v: boolean) => void }) {
  return (
    <div className="flex flex-col gap-1">
      <span className="text-xs font-semibold uppercase tracking-wide text-ink-500">{label}</span>
      <div className="flex overflow-hidden rounded-xl border border-ink-200">
        {[{ v: true, t: 'Áno' }, { v: false, t: 'Nie' }].map((o) => (
          <button key={o.t} type="button" onClick={() => onChange(o.v)}
            className={cn('flex-1 px-3 py-2 text-sm transition-colors', value === o.v ? 'bg-firol-500 text-white' : 'bg-white text-ink-600 hover:bg-ink-50')}>
            {o.t}
          </button>
        ))}
      </div>
    </div>
  );
}

function AddButton({ label, onClick }: { label: string; onClick: () => void }) {
  return (
    <button type="button" onClick={onClick} className="flex items-center justify-center gap-2 rounded-xl border border-dashed border-ink-300 py-2.5 text-sm font-medium text-firol-700 transition-colors hover:border-firol-400 hover:bg-firol-50">
      <Plus className="size-4" /> {label}
    </button>
  );
}

type Col<T> = { key: keyof T & string; label: string };

function RepeatList<T extends Record<string, string>>(props: {
  title: string;
  hint?: string;
  rows: T[];
  empty: T;
  columns: Col<T>[];
  addLabel: string;
  onChange: (rows: T[]) => void;
}) {
  const { title, hint, rows, empty, columns, addLabel, onChange } = props;
  return (
    <Card className="flex flex-col gap-3 p-5">
      <div>
        <h2 className="text-sm font-semibold text-ink-900">{title}</h2>
        {hint && <p className="text-xs text-ink-500">{hint}</p>}
      </div>
      {rows.map((row, i) => (
        <div key={i} className="flex items-end gap-2">
          <div className="grid flex-1 gap-2 sm:grid-cols-2">
            {columns.map((c) => (
              <Input key={c.key} value={row[c.key]} placeholder={c.label}
                onChange={(e) => onChange(replaceAt(rows, i, { ...row, [c.key]: e.target.value }))} />
            ))}
          </div>
          <button type="button" onClick={() => onChange(rows.filter((_, j) => j !== i))} className="mb-2.5 text-ink-400 hover:text-status-bad">
            <Trash2 className="size-4" />
          </button>
        </div>
      ))}
      <AddButton label={addLabel} onClick={() => onChange([...rows, { ...empty }])} />
    </Card>
  );
}

function BackLink() {
  return (
    <Link to="/documentation" className="inline-flex items-center gap-1 self-start text-sm text-ink-500 hover:text-ink-700">
      <ArrowLeft className="size-4" /> Späť na dokumentáciu
    </Link>
  );
}

// ─── helpers ────────────────────────────────────────────────────────────────────

function replaceAt<T>(arr: T[], i: number, v: T): T[] {
  const next = arr.slice();
  next[i] = v;
  return next;
}

function today(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function numericParam(raw: string | null): number | null {
  if (raw === null || raw === '') return null;
  const n = Number(raw);
  return Number.isFinite(n) && n > 0 ? n : null;
}

const FIXED_BEFORE = ['Požiarna kniha', 'Požiarna identifikačná karta', 'Zoznam dôležitých telefónnych čísel', 'Požiarne poplachové smernice'];
const EVAC_ITEMS = ['Požiarny evakuačný plán — textová časť', 'Požiarny evakuačný plán — grafická časť'];
const FIXED_AFTER = [
  'Zriadenie ohlasovne požiarov',
  'Dokumentácia ohlasovne požiarov',
  'Pokyn na zabezpečenie ochrany pred požiarmi v mimopracovnom čase',
  'Tematický plán vstupného školenia vedúcich a ostatných zamestnancov',
  'Tematický plán opakovaného školenia vedúcich a ostatných zamestnancov',
  'Tematický plán školenia novoprijatých zamestnancov',
  'Tematický plán školenia osôb zdržujúcich sa v objekte',
];

function buildZoznamPreview(evac: boolean, highRisk: boolean, custom: { nazov: string }[]): { cislo: number; nazov: string }[] {
  let names = [...FIXED_BEFORE];
  if (evac) names = names.concat(EVAC_ITEMS);
  names = names.concat(FIXED_AFTER);
  if (highRisk) names.push('Určenie miest so zvýšeným nebezpečenstvom vzniku požiaru');
  names = names.concat(custom.map((c) => c.nazov).filter(Boolean));
  return names.map((nazov, i) => ({ cislo: i + 1, nazov }));
}
