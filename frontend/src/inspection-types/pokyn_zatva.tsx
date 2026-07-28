import { useEffect, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import {
  CalendarDays, Edit2, ListChecks, Plus, RotateCcw, Save, Trash2, UserCheck, Wheat,
} from 'lucide-react';
import { type PokynSection, type PokynZatvaItemFields } from '@/api/inspections';
import { ApiError } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { ItemPhotoField, usePhotoStaging } from '@/components/ItemPhotos';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Field } from '@/components/ui/Field';
import { Button } from '@/components/ui/Button';
import { Spinner } from '@/components/ui/Spinner';
import { defaultPokynSections } from './pokynZatvaTemplate';
import { saveItemMessage, saveItemWithPhotos } from './saveItem';
import type {
  InspectionTypeModule,
  ItemRowProps,
  StatsBarProps,
  Step2FormProps,
} from './common';

/**
 * Pokyn — žatevné práce (change request 2.3).
 *
 * A single-record document rather than a list of inspected items: the form
 * prefills the client's template text and the technician adjusts it before
 * generating. The text is saved with the document, so re-opening an issued
 * Pokyn shows exactly what was printed.
 */
function PokynStep2Form({ inspectionId, initialItem, csrfToken, onSaved }: Step2FormProps) {
  const editing = initialItem !== null;
  const itemId = initialItem?.id ?? null;

  const [year, setYear] = useState<string>('');
  const [approver, setApprover] = useState('');
  const [sections, setSections] = useState<PokynSection[]>([]);

  const [submitting, setSubmitting] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [apiError, setApiError] = useState<string | null>(null);
  const toast = useToast();
  const photos = usePhotoStaging(initialItem?.photos);

  useEffect(() => {
    if (initialItem) {
      const f = initialItem.fields as Partial<PokynZatvaItemFields>;
      setYear(typeof f.year === 'number' ? String(f.year) : '');
      setApprover(typeof f.approver === 'string' ? f.approver : '');
      setSections(Array.isArray(f.sections) && f.sections.length > 0
        ? f.sections.map((s) => ({ title: String(s.title ?? ''), text: String(s.text ?? '') }))
        : defaultPokynSections());
    } else {
      // A Pokyn is issued before the harvest it covers, so the current year is
      // the right default — but the date of issue itself is never assumed.
      setYear(String(new Date().getFullYear()));
      setApprover('');
      setSections(defaultPokynSections());
    }
  }, [initialItem]);

  function updateSection(index: number, patch: Partial<PokynSection>) {
    setSections((prev) => prev.map((s, i) => (i === index ? { ...s, ...patch } : s)));
  }

  function removeSection(index: number) {
    setSections((prev) => prev.filter((_, i) => i !== index));
  }

  function addSection() {
    setSections((prev) => [...prev, { title: '', text: '' }]);
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
    if (Object.keys(errs).length > 0) { setFieldErrors(errs); return; }
    setFieldErrors({});
    setApiError(null);
    setSubmitting(true);
    try {
      const fields: PokynZatvaItemFields = {
        year: yn,
        approver: approver.trim() || null,
        sections: cleaned,
      };
      const saved = await saveItemWithPhotos({
        inspectionId,
        itemId: editing ? itemId : null,
        fields,
        csrfToken,
        photos,
      });
      onSaved('save-and-summary');
      toast.success(saveItemMessage(saved, 'Pokyn uložený'));
    } catch (err) {
      setApiError(err instanceof ApiError ? err.message : 'Niečo sa pokazilo.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Card className="p-5">
      <form className="flex flex-col gap-4" noValidate onSubmit={handleSubmit}>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Rok žatevných prác" required error={fieldErrors.year}>
            {(p) => (
              <Input {...p} required type="number" inputMode="numeric" min={1900} max={2200}
                leftIcon={<CalendarDays className="size-4" />}
                value={year}
                onChange={(e) => { setYear(e.target.value); if (fieldErrors.year) setFieldErrors((prev) => { const n = { ...prev }; delete n.year; return n; }); }}
                placeholder={String(new Date().getFullYear())} />
            )}
          </Field>
          <Field
            label="Schvaľujúca osoba"
            hint="Nepovinné — ak je prázdne, použije sa údaj z evidencie firmy."
          >
            {(p) => (
              <Input {...p} leftIcon={<UserCheck className="size-4" />}
                value={approver} onChange={(e) => setApprover(e.target.value)}
                placeholder="Ján Novák, konateľ" />
            )}
          </Field>
        </div>

        <div className="flex flex-col gap-2">
          <div className="flex items-baseline justify-between gap-2">
            <span className="text-xs font-semibold uppercase tracking-wide text-ink-500">
              Text pokynu
            </span>
            <button type="button" onClick={resetSections}
              className="inline-flex items-center gap-1 text-xs text-ink-500 transition-colors hover:text-firol-600">
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
                  <button type="button" onClick={() => removeSection(index)}
                    aria-label={`Odstrániť sekciu ${index + 1}`}
                    className="grid size-9 shrink-0 place-items-center rounded-xl text-[var(--color-status-bad)] transition-colors hover:bg-[var(--color-status-bad-bg)]">
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

          <Button type="button" variant="secondary" onClick={addSection}
            leftIcon={<Plus className="size-4" />} className="self-start">
            Pridať sekciu
          </Button>
          {fieldErrors.sections && (
            <p className="text-xs text-status-bad">{fieldErrors.sections}</p>
          )}
        </div>

        <ItemPhotoField photos={photos} />

        {apiError && (
          <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
            {apiError}
          </div>
        )}

        <div className="flex flex-col gap-2 pt-2 sm:flex-row sm:items-center sm:justify-end">
          <Button type="submit" loading={submitting}
            rightIcon={editing ? <Save className="size-4" /> : <ListChecks className="size-4" />}>
            {editing ? 'Uložiť zmeny' : 'Uložiť a prejsť na súhrn'}
          </Button>
        </div>
      </form>
    </Card>
  );
}

function PokynItemRow({ inspectionId, item, canEdit, deleting, onDelete }: ItemRowProps) {
  const f = item.fields as Partial<PokynZatvaItemFields>;
  const sectionCount = Array.isArray(f.sections) ? f.sections.length : 0;
  return (
    <div className="px-4 py-3">
      <div className="flex items-center gap-3">
        <span className="grid size-9 shrink-0 place-items-center rounded-2xl bg-firol-50 text-firol-700">
          <Wheat className="size-4" />
        </span>
        <div className="min-w-0 flex-1">
          <h3 className="truncate text-sm font-semibold text-ink-900">
            Žatevné práce v roku {f.year ?? '—'}
          </h3>
          <p className="mt-0.5 truncate text-xs text-ink-500">
            {sectionCount} {sectionCount === 1 ? 'sekcia' : sectionCount < 5 ? 'sekcie' : 'sekcií'} textu
            {f.approver && (
              <>
                <span className="mx-1.5 text-ink-300">·</span>
                Schválil: {f.approver}
              </>
            )}
          </p>
        </div>
        {canEdit && (
          <div className="flex shrink-0 items-center gap-3.5">
            <Link to={`/inspections/${inspectionId}/items/${item.id}`} aria-label="Opraviť"
              className="grid size-8 place-items-center rounded-xl text-[var(--color-status-warn)] transition-colors hover:bg-[var(--color-status-warn-bg)]">
              <Edit2 className="size-4" />
            </Link>
            <button type="button" onClick={onDelete} disabled={deleting} aria-label="Zmazať"
              className="grid size-8 place-items-center rounded-xl text-[var(--color-status-bad)] transition-colors hover:bg-[var(--color-status-bad-bg)] disabled:opacity-50">
              {deleting ? <Spinner size="sm" /> : <Trash2 className="size-4" />}
            </button>
          </div>
        )}
      </div>
    </div>
  );
}

/** The Pokyn is a single document — a count of "items" would say nothing. */
function PokynStatsBar(_: StatsBarProps) {
  return null;
}

export const pokynZatvaModule: InspectionTypeModule = {
  type: 'pokyn_zatva',
  Step2Form: PokynStep2Form,
  ItemRow: PokynItemRow,
  StatsBar: PokynStatsBar,
};
