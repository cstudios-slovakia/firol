import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { ArrowLeft, ArrowRight, Building2, CalendarDays, Route, Warehouse } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { Companies, type CompanyListItem, type FacilityListItem } from '@/api/companies';
import {
  INSPECTION_TYPE_LABELS,
  Inspections,
  periodicityOf,
  type InspectionListItem,
  type InspectionType,
} from '@/api/inspections';
import { Visits } from '@/api/visits';
import { ApiError } from '@/lib/api';
import { daysUntilNext } from '@/lib/periodicity';
import { SECTIONS, SECTION_COLORS, SECTION_INSPECTION_TYPES, SECTION_LABELS } from '@/lib/sections';
import { useToast } from '@/lib/toast';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { cn } from '@/lib/cn';

/** Term within this many days counts as due, and is ticked in advance. */
const DUE_SOON_DAYS = 30;

/**
 * Starting a návšteva — block 1 / chapter 9.
 *
 * A technician at a client normally does three or four things. Until now each
 * of them meant picking the company and the prevádzka again; here that happens
 * ONCE, and the app then walks through the úkony one after another.
 *
 * The types whose term falls due within a month are ticked in advance — while
 * someone is already standing in the building, the control that is due next
 * month is far cheaper to do now than to drive back for.
 *
 * A visit belongs to no section: it offers types from every odbor at once,
 * because it is an activity rather than a list.
 */
export function VisitNewPage() {
  const { user, csrfToken } = useAuth();
  const toast = useToast();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();

  const presetCompanyId = numericParam(searchParams.get('company_id'));
  const presetFacilityId = numericParam(searchParams.get('facility_id'));

  const [companies, setCompanies] = useState<CompanyListItem[] | null>(null);
  const [facilities, setFacilities] = useState<FacilityListItem[]>([]);
  const [companyId, setCompanyId] = useState<number | null>(presetCompanyId);
  const [facilityId, setFacilityId] = useState<number | null>(presetFacilityId);
  const [visitDate, setVisitDate] = useState(todayIso());
  const [types, setTypes] = useState<Set<InspectionType>>(new Set());
  const [history, setHistory] = useState<InspectionListItem[]>([]);
  const [touchedTypes, setTouchedTypes] = useState(false);

  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    Companies.list()
      .then((res) => {
        if (cancelled) return;
        setCompanies(res.items);
        if (presetCompanyId === null && res.items.length === 1) {
          setCompanyId(res.items[0].id);
        }
      })
      .catch((err: unknown) => {
        if (!cancelled) {
          setError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať firmy.');
        }
      });
    return () => {
      cancelled = true;
    };
  }, [presetCompanyId]);

  useEffect(() => {
    if (companyId === null) {
      setFacilities([]);
      setFacilityId(null);
      return;
    }
    let cancelled = false;
    Companies.show(companyId)
      .then((res) => {
        if (cancelled) return;
        setFacilities(res.facilities);
        setFacilityId((current) => {
          if (current !== null && res.facilities.some((f) => f.id === current)) return current;
          if (presetFacilityId !== null && res.facilities.some((f) => f.id === presetFacilityId)) {
            return presetFacilityId;
          }
          return res.facilities.length === 1 ? res.facilities[0].id : null;
        });
      })
      .catch(() => {
        if (!cancelled) setFacilities([]);
      });
    return () => {
      cancelled = true;
    };
  }, [companyId, presetFacilityId]);

  // This prevádzka's history, so the app can work out what is due.
  useEffect(() => {
    if (facilityId === null) {
      setHistory([]);
      return;
    }
    let cancelled = false;
    Inspections.list({ facility_id: facilityId })
      .then((res) => {
        if (!cancelled) setHistory(res.items);
      })
      .catch(() => {
        if (!cancelled) setHistory([]);
      });
    return () => {
      cancelled = true;
    };
  }, [facilityId]);

  /** Types whose term is due within a month, or already past. */
  const dueTypes = useMemo(() => {
    const due = new Set<InspectionType>();
    const latest = new Map<InspectionType, InspectionListItem>();
    for (const it of history) {
      if (it.status !== 'finalized' || !it.executed_on) continue;
      const current = latest.get(it.type);
      if (!current || (current.executed_on ?? '') < it.executed_on) latest.set(it.type, it);
    }
    for (const [type, it] of latest) {
      const days = daysUntilNext(it.executed_on, periodicityOf(it));
      if (days !== null && days <= DUE_SOON_DAYS) due.add(type);
    }
    return due;
  }, [history]);

  // Tick the due ones — until the technician makes their own selection, at
  // which point the app stops second-guessing them.
  useEffect(() => {
    if (touchedTypes) return;
    setTypes(new Set(dueTypes));
  }, [dueTypes, touchedTypes]);

  function toggleType(type: InspectionType) {
    setTouchedTypes(true);
    setTypes((prev) => {
      const next = new Set(prev);
      if (next.has(type)) next.delete(type);
      else next.add(type);
      return next;
    });
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (!companyId || !facilityId) {
      setError('Vyber firmu aj prevádzku.');
      return;
    }
    if (types.size === 0) {
      setError('Vyber aspoň jeden typ úkonu.');
      return;
    }
    setError(null);
    setSubmitting(true);
    try {
      const res = await Visits.create(
        {
          company_id: companyId,
          facility_id: facilityId,
          visit_date: visitDate,
          planned_types: [...types],
          technician_user_id: user?.id,
        },
        csrfToken,
      );
      toast.success('Návšteva založená');
      navigate(`/visits/${res.visit.id}`, { replace: true });
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Návštevu sa nepodarilo založiť.';
      setError(msg);
      toast.error(msg);
    } finally {
      setSubmitting(false);
    }
  }

  if (companies === null) {
    return (
      <div className="flex justify-center py-10 text-ink-400">
        <Spinner />
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-5">
      <Link
        to={presetCompanyId ? `/companies/${presetCompanyId}` : '/'}
        className="inline-flex items-center gap-1 self-start text-sm text-ink-500 hover:text-ink-700"
      >
        <ArrowLeft className="size-4" />
        Späť
      </Link>

      <header className="flex items-start gap-2.5">
        <span className="grid size-10 shrink-0 place-items-center rounded-2xl bg-firol-500 text-white shadow-[var(--shadow-glow)]">
          <Route className="size-5" />
        </span>
        <div>
          <h1 className="text-xl font-semibold tracking-tight text-ink-900">Nová návšteva</h1>
          <p className="mt-0.5 text-sm text-ink-500">
            Firmu a prevádzku vyberieš raz — potom ťa appka prevedie úkonmi jedným po druhom.
          </p>
        </div>
      </header>

      <Card className="p-5">
        <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
          <Field label="Spoločnosť" required>
            {(p) => (
              <Select
                id={p.id}
                value={companyId !== null ? String(companyId) : ''}
                onChange={(v) => setCompanyId(v ? Number(v) : null)}
                placeholder="— vyber firmu —"
                leftIcon={<Building2 className="size-4" />}
                searchable
                options={companies.map((c) => ({
                  value: String(c.id),
                  label: c.name,
                  description: c.ico ? `IČO ${c.ico}` : undefined,
                }))}
              />
            )}
          </Field>

          <Field label="Prevádzka" required>
            {(p) => (
              <Select
                id={p.id}
                value={facilityId !== null ? String(facilityId) : ''}
                onChange={(v) => setFacilityId(v ? Number(v) : null)}
                disabled={companyId === null}
                placeholder={companyId === null ? '— najprv vyber firmu —' : '— vyber prevádzku —'}
                leftIcon={<Warehouse className="size-4" />}
                searchable
                options={facilities.map((f) => ({ value: String(f.id), label: f.name }))}
              />
            )}
          </Field>

          <Field label="Dátum návštevy" required hint="Dá sa zadať aj spätne.">
            {(p) => (
              <Input
                {...p}
                type="date"
                value={visitDate}
                onChange={(e) => setVisitDate(e.target.value)}
                leftIcon={<CalendarDays className="size-4" />}
              />
            )}
          </Field>

          <Field
            label="Úkony na tejto návšteve"
            hint={
              dueTypes.size > 0
                ? 'Predvybrané sú tie, ktorých termín je splatný do 30 dní. Výber môžeš kedykoľvek zmeniť.'
                : 'Odškrtni, čo na prevádzke urobíš. Ponuka je zo všetkých sekcií naraz.'
            }
          >
            {() => (
              <div className="flex flex-col gap-3">
                {SECTIONS.filter((s) => SECTION_INSPECTION_TYPES[s].length > 0).map((section) => (
                  <div key={section}>
                    <p
                      className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide"
                      style={{ color: SECTION_COLORS[section] }}
                    >
                      {SECTION_LABELS[section]}
                    </p>
                    <div className="flex flex-wrap gap-1.5">
                      {SECTION_INSPECTION_TYPES[section].map((type) => {
                        const active = types.has(type);
                        const due = dueTypes.has(type);
                        return (
                          <button
                            key={type}
                            type="button"
                            onClick={() => toggleType(type)}
                            style={active ? { backgroundColor: SECTION_COLORS[section] } : undefined}
                            className={cn(
                              'inline-flex items-center gap-1.5 rounded-xl border px-3 py-2 text-sm font-medium transition-all duration-150 active:scale-[0.98]',
                              active
                                ? 'border-transparent text-white shadow-sm'
                                : 'border-ink-200 bg-white text-ink-700 hover:border-ink-300 hover:bg-ink-50',
                            )}
                          >
                            {INSPECTION_TYPE_LABELS[type]}
                            {due && !active && (
                              <span className="rounded-full bg-[var(--color-status-warn-bg)] px-1.5 py-0.5 text-[10px] text-[var(--color-status-warn)]">
                                splatné
                              </span>
                            )}
                          </button>
                        );
                      })}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </Field>

          {error && (
            <p className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
              {error}
            </p>
          )}

          <div className="flex justify-end pt-1">
            <Button type="submit" loading={submitting} rightIcon={<ArrowRight className="size-4" />}>
              Začať návštevu
            </Button>
          </div>
        </form>
      </Card>
    </div>
  );
}

function numericParam(raw: string | null): number | null {
  if (raw === null || raw === '') return null;
  const n = Number(raw);
  return Number.isFinite(n) && n > 0 ? n : null;
}

function todayIso(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
