import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { FileText, NotebookPen, PenLine, Printer } from 'lucide-react';
import {
  Inspections,
  documentDownloadUrl,
  type Inspection,
  type InspectionDocument,
  type InspectionItem,
} from '@/api/inspections';
import {
  FORM_VARIANT_LABELS,
  PersonList,
  isTestType,
  personFields,
  summaryBlockers,
  type FormVariant,
  type PersonListType,
} from '@/api/personList';
import { offlineMessage } from '@/lib/offline';
import { useToast } from '@/lib/toast';
import { cn } from '@/lib/cn';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';

/**
 * Issuing the protocol of a person list — chapters 8 and 8.1 (walkthrough
 * 29.3, steps 4–6).
 *
 * A test offers tlac_formulara: the filled record, or a blank form for
 * handwriting with the names printed and the time / value / result /
 * signature columns empty. Both are the same protocol under ONE number: the
 * blank form is version 1; when the handwritten results are later typed in
 * („Doplniť výsledky"), the filled record is issued as the next version of the
 * same number, and the blank file stays in the protocol's history.
 *
 * States:
 *   draft, nothing issued        choose the variant, generate
 *   locked, last issued blank    „Doplniť výsledky" reopens the list WITHOUT
 *                                discarding the issued form
 *   draft, blank already issued  generate the filled record (same number)
 *   locked, filled               nothing here — the documents list says it all
 */
export function PersonProtocolBlock({
  type,
  inspection,
  items,
  documents,
  csrfToken,
  onChanged,
}: {
  type: PersonListType;
  inspection: Inspection;
  items: InspectionItem[];
  documents: InspectionDocument[];
  csrfToken: string | null;
  /** Reload the inspection and its documents after something was issued. */
  onChanged: () => Promise<void>;
}) {
  const navigate = useNavigate();
  const toast = useToast();
  const isTest = isTestType(type);
  const isDraft = inspection.status === 'draft';
  const latest = documents[0] ?? null;
  const blankIssued = latest?.form_variant === 'prazdny';
  const missingResults = items.filter((it) => personFields(it).result === null).length;

  const [variant, setVariant] = useState<FormVariant>(missingResults > 0 && isTest ? 'prazdny' : 'vyplneny');
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const blockers = summaryBlockers(type, inspection.details, items);
  const canGenerate = isDraft && blockers.length === 0 && !!inspection.executed_on;

  async function generate(v: FormVariant) {
    setError(null);
    setBusy(v);
    try {
      const res = await Inspections.generatePdf(inspection.id, csrfToken, undefined, isTest ? v : undefined);
      await onChanged();
      window.open(documentDownloadUrl(res.document.id), '_blank', 'noopener');
    } catch (err) {
      setError(offlineMessage(err, 'PDF sa nepodarilo vygenerovať.'));
    } finally {
      setBusy(null);
    }
  }

  async function fillResults() {
    setError(null);
    setBusy('fill');
    try {
      await PersonList.fillResults(inspection.id, csrfToken);
      await onChanged();
      toast.success('Doplň výsledky z papiera — vyplnený záznam dostane rovnaké číslo.');
      navigate(`/inspections/${inspection.id}/osoby`);
    } catch (err) {
      setError(offlineMessage(err, 'Úkon sa nepodarilo otvoriť.'));
    } finally {
      setBusy(null);
    }
  }

  // Locked with the filled record (or an oboznámenie that is done) — the
  // documents list below carries everything.
  if (!isDraft && !(isTest && blankIssued)) return null;

  if (!isDraft) {
    return (
      <Card className="flex flex-col gap-3 border-firol-200 bg-firol-50/50 p-4">
        <div className="flex items-start gap-3">
          <span className="mt-0.5 grid size-8 shrink-0 place-items-center rounded-xl bg-white text-firol-600">
            <Printer className="size-4" />
          </span>
          <div className="min-w-0">
            <p className="text-sm font-semibold text-ink-900">Vytlačený prázdny formulár {latest?.number}</p>
            <p className="mt-1 text-xs text-ink-600">
              Výsledky sa dopisujú perom na mieste. Po skúške ich môžeš doplniť aj do appky a
              vygenerovať vyplnený záznam do evidencie — dostane rovnaké číslo ako ďalšia verzia.
            </p>
          </div>
        </div>
        {error && <p className="text-xs text-status-bad">{error}</p>}
        <Button type="button" className="self-start" loading={busy === 'fill'} onClick={fillResults}
          leftIcon={<NotebookPen className="size-4" />}>
          Doplniť výsledky
        </Button>
      </Card>
    );
  }

  if (isTest && blankIssued) {
    return (
      <Card className="flex flex-col gap-3 p-4">
        <div className="flex items-start gap-3">
          <span className="mt-0.5 grid size-8 shrink-0 place-items-center rounded-xl bg-firol-50 text-firol-600">
            <FileText className="size-4" />
          </span>
          <div className="min-w-0">
            <p className="text-sm font-semibold text-ink-900">Vyplnený záznam k {latest?.number}</p>
            <p className="mt-1 text-xs text-ink-600">
              Prázdny formulár už je vytlačený. Keď sú výsledky doplnené pri všetkých osobách,
              vygeneruj vyplnený záznam — bude to verzia {(latest?.version ?? 1) + 1} toho istého čísla.
            </p>
            {missingResults > 0 && (
              <p className="mt-1 text-xs font-medium text-status-warn">
                Výsledok chýba pri {missingResults} {missingResults === 1 ? 'osobe' : 'osobách'}.
              </p>
            )}
          </div>
        </div>
        {error && <p className="text-xs text-status-bad">{error}</p>}
        <div className="flex flex-col gap-2 sm:flex-row">
          <Button type="button" loading={busy === 'vyplneny'} disabled={!canGenerate || missingResults > 0}
            onClick={() => generate('vyplneny')} leftIcon={<FileText className="size-4" />}>
            Generovať vyplnený záznam
          </Button>
          <Button type="button" variant="secondary" loading={busy === 'prazdny'} disabled={!canGenerate}
            onClick={() => generate('prazdny')} leftIcon={<Printer className="size-4" />}>
            Znova vytlačiť prázdny formulár
          </Button>
        </div>
      </Card>
    );
  }

  return (
    <Card className="flex flex-col gap-3 p-4">
      <div className="flex items-start gap-3">
        <span className="mt-0.5 grid size-8 shrink-0 place-items-center rounded-xl bg-firol-50 text-firol-600">
          <FileText className="size-4" />
        </span>
        <div className="min-w-0">
          <h2 className="text-sm font-semibold text-ink-900">PDF protokol</h2>
          <p className="mt-0.5 text-xs text-ink-500">
            Po vygenerovaní sa úkon uzamkne a dostane číslo.
          </p>
        </div>
      </div>

      {isTest && (
        <div className="grid gap-2 sm:grid-cols-2" role="radiogroup" aria-label="Tlač formulára">
          {(['vyplneny', 'prazdny'] as FormVariant[]).map((v) => {
            const active = variant === v;
            return (
              <button key={v} type="button" role="radio" aria-checked={active} onClick={() => setVariant(v)}
                className={cn(
                  'flex min-h-16 flex-col items-start gap-0.5 rounded-2xl border px-3 py-2.5 text-left transition-all duration-200 active:scale-[0.99]',
                  active ? 'border-firol-400 bg-firol-50 ring-2 ring-firol-200' : 'border-ink-200 bg-white hover:border-firol-300',
                )}>
                <span className="flex items-center gap-1.5 text-sm font-semibold text-ink-900">
                  {v === 'prazdny' ? <PenLine className="size-4 text-firol-500" /> : <FileText className="size-4 text-firol-500" />}
                  {v === 'vyplneny' ? 'Vyplnený formulár' : FORM_VARIANT_LABELS.prazdny}
                </span>
                <span className="text-xs text-ink-500">
                  {v === 'vyplneny'
                    ? 'Časy, hodnoty a výsledky tak, ako sú zadané v appke.'
                    : 'Mená a zaradenia; čas, výsledok a podpis sa dopíšu perom.'}
                </span>
              </button>
            );
          })}
        </div>
      )}

      {blockers.length > 0 && (
        <div className="flex flex-col gap-0.5 text-xs text-status-bad">
          {blockers.map((b) => <p key={b}>{b}</p>)}
        </div>
      )}
      {isTest && variant === 'vyplneny' && missingResults > 0 && (
        <p className="text-xs text-status-warn">
          Výsledok chýba pri {missingResults} {missingResults === 1 ? 'osobe' : 'osobách'} — doplň ho,
          alebo vytlač prázdny formulár na ručné doplnenie.
        </p>
      )}
      {error && <p className="text-xs text-status-bad">{error}</p>}

      <Button type="button" variant="primary" className="bg-status-bad hover:brightness-110"
        loading={busy !== null}
        disabled={!canGenerate || (isTest && variant === 'vyplneny' && missingResults > 0)}
        onClick={() => generate(isTest ? variant : 'vyplneny')} leftIcon={<FileText className="size-4" />}>
        Generovať PDF protokol
      </Button>
    </Card>
  );
}
