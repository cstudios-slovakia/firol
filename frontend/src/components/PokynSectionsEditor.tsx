import { useEffect, useState, type FormEvent } from 'react';
import { CalendarDays, Plus, RotateCcw, Save, Trash2, UserCheck } from 'lucide-react';
import { Trainings, type PokynSection, type PokynZatvaFields } from '@/api/trainings';
import { ApiError } from '@/lib/api';
import { handleOfflineSave } from '@/lib/offline';
import { defaultPokynSections } from '@/lib/pokynZatvaTemplate';
import { useToast } from '@/lib/toast';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Field } from '@/components/ui/Field';
import { Button } from '@/components/ui/Button';

/**
 * Text of the "Pokyn — žatevné práce" (change request 2.3), edited in place on
 * the training detail page — it takes the slot the attendee list occupies for
 * the other training types.
 *
 * The fixed template text is only a starting point: the technician adjusts it
 * before generating, and what they saved is what the PDF prints. Once the
 * document is issued the whole block turns read-only, so the record keeps
 * matching the numbered PDF.
 */
export function PokynSectionsEditor({
  trainingId,
  fields,
  companyApprover,
  csrfToken,
  editable,
  onSaved,
}: {
  trainingId: number;
  fields: PokynZatvaFields | null;
  companyApprover: string | null;
  csrfToken: string | null;
  editable: boolean;
  onSaved: () => Promise<void> | void;
}) {
  const toast = useToast();

  const [year, setYear] = useState('');
  const [approver, setApprover] = useState('');
  const [sections, setSections] = useState<PokynSection[]>([]);
  const [submitting, setSubmitting] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [apiError, setApiError] = useState<string | null>(null);

  useEffect(() => {
    // A Pokyn covers the harvest of a given year — the app seeds the current
    // one, but the date of issue itself is never assumed.
    setYear(String(fields?.year ?? new Date().getFullYear()));
    setApprover(fields?.approver ?? '');
    setSections(
      fields && fields.sections.length > 0
        ? fields.sections.map((s) => ({ ...s }))
        : defaultPokynSections(),
    );
  }, [fields]);

  function updateSection(index: number, patch: Partial<PokynSection>) {
    setSections((prev) => prev.map((s, i) => (i === index ? { ...s, ...patch } : s)));
  }

  function removeSection(index: number) {
    setSections((prev) => prev.filter((_, i) => i !== index));
  }

  function resetSections() {
    setSections(defaultPokynSections());
    toast.success('Text obnovený zo šablóny');
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    const errs: Record<string, string> = {};
    const yn = Number(year);
    if (!Number.isInteger(yn) || yn < 1900 || yn > 2200) {
      errs.year = 'Rok žatevných prác musí byť v rozsahu 1900–2200.';
    }
    const cleaned = sections
      .map((s) => ({ title: s.title.trim(), text: s.text.trim() }))
      .filter((s) => s.title !== '' || s.text !== '');
    if (cleaned.length === 0) {
      errs.sections = 'Pokyn musí obsahovať aspoň jednu sekciu textu.';
    }
    if (Object.keys(errs).length > 0) {
      setFieldErrors(errs);
      return;
    }
    setFieldErrors({});
    setApiError(null);
    setSubmitting(true);
    try {
      await Trainings.update(
        trainingId,
        { fields: { year: yn, approver: approver.trim() || null, sections: cleaned } },
        csrfToken,
      );
      await onSaved();
      toast.success('Text pokynu uložený');
    } catch (err) {
      if (handleOfflineSave(err, toast)) {
        await onSaved();
        return;
      }
      const msg = err instanceof ApiError ? err.message : 'Niečo sa pokazilo.';
      setApiError(msg);
      toast.error(msg);
    } finally {
      setSubmitting(false);
    }
  }

  if (!editable) {
    return (
      <Card className="flex flex-col gap-3 p-5">
        <div className="grid gap-1 sm:grid-cols-2">
          <ReadOnlyRow label="Rok žatevných prác" value={fields ? String(fields.year) : '—'} />
          <ReadOnlyRow
            label="Schválil"
            value={fields?.approver ?? companyApprover ?? '—'}
          />
        </div>
        <ul className="flex flex-col gap-3">
          {(fields?.sections ?? []).map((section, index) => (
            <li key={index} className="rounded-2xl border border-ink-100 bg-ink-50/40 p-3">
              {section.title && (
                <h3 className="text-sm font-semibold text-ink-900">{section.title}</h3>
              )}
              <p className="mt-1 whitespace-pre-line text-xs leading-relaxed text-ink-600">
                {section.text}
              </p>
            </li>
          ))}
        </ul>
      </Card>
    );
  }

  return (
    <Card className="p-5">
      <form className="flex flex-col gap-4" noValidate onSubmit={handleSubmit}>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Rok žatevných prác" required error={fieldErrors.year}>
            {(p) => (
              <Input
                {...p}
                required
                type="number"
                inputMode="numeric"
                min={1900}
                max={2200}
                leftIcon={<CalendarDays className="size-4" />}
                value={year}
                onChange={(e) => {
                  setYear(e.target.value);
                  if (fieldErrors.year) {
                    setFieldErrors((prev) => {
                      const next = { ...prev };
                      delete next.year;
                      return next;
                    });
                  }
                }}
                placeholder={String(new Date().getFullYear())}
              />
            )}
          </Field>
          <Field
            label="Schvaľujúca osoba"
            hint={
              companyApprover
                ? `Nepovinné — ak je prázdne, použije sa „${companyApprover}“ z evidencie firmy.`
                : 'Nepovinné — ak je prázdne, použije sa údaj z evidencie firmy.'
            }
          >
            {(p) => (
              <Input
                {...p}
                leftIcon={<UserCheck className="size-4" />}
                value={approver}
                onChange={(e) => setApprover(e.target.value)}
                placeholder={companyApprover ?? 'Ján Novák, konateľ'}
              />
            )}
          </Field>
        </div>

        <div className="flex flex-col gap-2">
          <div className="flex items-baseline justify-between gap-2">
            <span className="text-xs font-semibold uppercase tracking-wide text-ink-500">
              Text pokynu
            </span>
            <button
              type="button"
              onClick={resetSections}
              className="inline-flex items-center gap-1 text-xs text-ink-500 transition-colors hover:text-firol-600"
            >
              <RotateCcw className="size-3" />
              Obnoviť zo šablóny
            </button>
          </div>
          <p className="text-xs text-ink-400">
            Predvyplnené zo šablóny — uprav podľa potreby. Riadky začínajúce pomlčkou sa
            v PDF vysádzajú ako odrážky.
          </p>

          <ul className="flex flex-col gap-3">
            {sections.map((section, index) => (
              <li key={index} className="rounded-2xl border border-ink-200 bg-white p-3">
                <div className="flex items-center gap-2">
                  <Input
                    value={section.title}
                    onChange={(e) => updateSection(index, { title: e.target.value })}
                    placeholder="Nadpis sekcie"
                    aria-label={`Nadpis sekcie ${index + 1}`}
                  />
                  <button
                    type="button"
                    onClick={() => removeSection(index)}
                    aria-label={`Odstrániť sekciu ${index + 1}`}
                    className="grid size-9 shrink-0 place-items-center rounded-xl text-[var(--color-status-bad)] transition-colors hover:bg-[var(--color-status-bad-bg)]"
                  >
                    <Trash2 className="size-4" />
                  </button>
                </div>
                <textarea
                  rows={5}
                  value={section.text}
                  onChange={(e) => updateSection(index, { text: e.target.value })}
                  aria-label={`Text sekcie ${index + 1}`}
                  className="mt-2 w-full rounded-xl border border-ink-200 bg-white px-3 py-2.5 text-sm text-ink-800 placeholder:text-ink-400 transition-colors duration-150 hover:border-ink-300 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200"
                />
              </li>
            ))}
          </ul>

          <Button
            type="button"
            variant="secondary"
            onClick={() => setSections((prev) => [...prev, { title: '', text: '' }])}
            leftIcon={<Plus className="size-4" />}
            className="self-start"
          >
            Pridať sekciu
          </Button>
          {fieldErrors.sections && (
            <p className="text-xs text-status-bad">{fieldErrors.sections}</p>
          )}
        </div>

        {apiError && (
          <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
            {apiError}
          </div>
        )}

        <div className="flex justify-end pt-1">
          <Button type="submit" loading={submitting} leftIcon={<Save className="size-4" />}>
            Uložiť text pokynu
          </Button>
        </div>
      </form>
    </Card>
  );
}

function ReadOnlyRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="py-1">
      <p className="text-[10px] font-semibold uppercase tracking-wider text-ink-400">{label}</p>
      <p className="text-sm text-ink-800">{value}</p>
    </div>
  );
}
