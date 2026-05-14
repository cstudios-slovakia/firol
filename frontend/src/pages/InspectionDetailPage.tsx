import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import {
  ArrowLeft, Building2, CalendarDays, ClipboardList, Download, FileText,
  Plus, Repeat, Warehouse,
} from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import {
  INSPECTION_TYPE_LABELS,
  Inspections,
  documentDownloadUrl,
  type InspectionDetail,
  type InspectionDocument,
} from '@/api/inspections';
import { ApiError } from '@/lib/api';
import { handleOfflineSave } from '@/lib/offline';
import { useToast } from '@/lib/toast';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { CardBlockSkeleton, DetailHeaderSkeleton } from '@/components/ui/Skeleton';
import { getTypeModule } from '@/inspection-types';

/**
 * Step 3 — summary screen. Final review before PDF generation.
 *
 * The date sits in a highlighted orange box and is editable directly here:
 * critical for the Opakovať flow (3a-4) where the technician clones a
 * previous inspection and only changes the date. Items + stats render
 * via the per-type registry so this page stays type-agnostic.
 */
export function InspectionDetailPage() {
  const { id: idStr } = useParams<{ id: string }>();
  const id = Number(idStr);
  const { csrfToken } = useAuth();
  const navigate = useNavigate();
  const toast = useToast();

  const [data, setData] = useState<InspectionDetail | null>(null);
  const [documents, setDocuments] = useState<InspectionDocument[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [savingDate, setSavingDate] = useState(false);
  const [deletingItemId, setDeletingItemId] = useState<number | null>(null);
  const [generating, setGenerating] = useState(false);
  const [repeating, setRepeating] = useState(false);

  useEffect(() => {
    let cancelled = false;
    Promise.all([Inspections.show(id), Inspections.documents(id)])
      .then(([detail, docs]) => {
        if (cancelled) return;
        setData(detail);
        setDocuments(docs.items);
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať kontrolu.');
      });
    return () => {
      cancelled = true;
    };
  }, [id]);

  async function handleRepeat() {
    if (!data) return;
    setError(null);
    setRepeating(true);
    try {
      const res = await Inspections.repeat(id, csrfToken);
      navigate(`/inspections/${res.inspection.id}`, { replace: false });
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Opakovať sa nepodarilo.');
    } finally {
      setRepeating(false);
    }
  }

  async function handleGeneratePdf() {
    if (!data) return;
    setError(null);
    setGenerating(true);
    try {
      const res = await Inspections.generatePdf(id, csrfToken);
      const [detail, docs] = await Promise.all([
        Inspections.show(id),
        Inspections.documents(id),
      ]);
      setData(detail);
      setDocuments(docs.items);
      window.open(documentDownloadUrl(res.document.id), '_blank', 'noopener');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'PDF sa nepodarilo vygenerovať.');
    } finally {
      setGenerating(false);
    }
  }

  async function handleDateChange(value: string) {
    if (!data || !value) return;
    setSavingDate(true);
    try {
      const res = await Inspections.update(id, { executed_on: value }, csrfToken);
      setData((prev) => (prev ? { ...prev, inspection: { ...prev.inspection, ...res.inspection } } : prev));
    } catch (err) {
      if (handleOfflineSave(err, toast)) return;
      setError(err instanceof ApiError ? err.message : 'Dátum sa nepodarilo uložiť.');
    } finally {
      setSavingDate(false);
    }
  }

  async function handleDeleteItem(itemId: number) {
    if (!window.confirm('Naozaj zmazať túto položku?')) return;
    setDeletingItemId(itemId);
    try {
      await Inspections.deleteItem(id, itemId, csrfToken);
      const fresh = await Inspections.show(id);
      setData(fresh);
    } catch (err) {
      if (handleOfflineSave(err, toast)) return;
      setError(err instanceof ApiError ? err.message : 'Mazanie sa nepodarilo.');
    } finally {
      setDeletingItemId(null);
    }
  }

  if (error && !data) {
    return (
      <div className="flex flex-col gap-4">
        <Link to="/inspections" className="inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-700 self-start">
          <ArrowLeft className="size-4" />
          Späť
        </Link>
        <Card className="px-4 py-3 text-sm text-status-bad">{error}</Card>
      </div>
    );
  }

  if (!data) {
    return (
      <div className="flex flex-col gap-5">
        <Link to="/inspections" className="inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-700 self-start">
          <ArrowLeft className="size-4" />
          Späť
        </Link>
        <DetailHeaderSkeleton />
        <CardBlockSkeleton rows={4} />
        <CardBlockSkeleton rows={4} />
      </div>
    );
  }

  const { inspection: i, items } = data;
  const isDraft = i.status === 'draft';
  const module = getTypeModule(i.type);

  return (
    <div className="flex flex-col gap-5">
      <Link
        to={`/facilities/${i.facility_id}`}
        className="inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-700 self-start"
      >
        <ArrowLeft className="size-4" />
        Späť na prevádzku
      </Link>

      <header className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0">
          <p className="text-xs font-semibold uppercase tracking-wider text-firol-500">
            Krok 3 · súhrn
          </p>
          <h1 className="mt-1 text-xl font-semibold tracking-tight text-ink-900">
            {INSPECTION_TYPE_LABELS[i.type]}
          </h1>
          <p className="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-ink-500">
            <Building2 className="size-3" />
            <Link to={`/companies/${i.company_id}`} className="hover:text-firol-600">
              {i.company_name}
            </Link>
            <span className="text-ink-300">·</span>
            <Warehouse className="size-3" />
            <Link to={`/facilities/${i.facility_id}`} className="hover:text-firol-600">
              {i.facility_name}
            </Link>
            <span className="text-ink-300">·</span>
            <Badge tone={isDraft ? 'warn' : 'ok'}>
              {isDraft ? 'Rozpracovaná' : 'Dokončená'}
            </Badge>
          </p>
        </div>
        {isDraft && !(i.type === 'poziarna_kniha' && items.length >= 1) ? (
          <Link
            to={`/inspections/${id}/items/new`}
            className="inline-flex h-10 shrink-0 items-center gap-1.5 rounded-2xl bg-firol-500 px-3 text-sm font-medium text-white shadow-[var(--shadow-glow)] hover:bg-firol-600"
          >
            <Plus className="size-4" />
            {i.type === 'poziarna_kniha' ? 'Pridať záznam' : 'Pridať položku'}
          </Link>
        ) : !isDraft && i.type !== 'poziarna_kniha' ? (
          <Button
            type="button"
            onClick={handleRepeat}
            loading={repeating}
            leftIcon={<Repeat className="size-4" />}
            title="Vytvorí novú kontrolu s tými istými položkami a prázdnym dátumom"
            className="shrink-0"
          >
            Opakovať
          </Button>
        ) : null}
      </header>

      <Card className="flex flex-col gap-3 border-status-warn/30 bg-[var(--color-status-warn-bg)]/40 p-4">
        <div className="flex items-start gap-3">
          <span className="mt-0.5 grid size-7 shrink-0 place-items-center rounded-full bg-white text-status-warn">
            <CalendarDays className="size-4" />
          </span>
          <div className="flex-1">
            <p className="text-xs font-semibold uppercase tracking-wider text-status-warn">
              Dátum kontroly
            </p>
            <p className="mt-0.5 text-xs text-ink-600">
              Zmeň dátum, ak opakuješ staršiu kontrolu — nový PDF protokol bude vystavený s týmto dátumom.
            </p>
          </div>
        </div>
        <input
          type="date"
          value={i.executed_on ?? ''}
          disabled={!isDraft || savingDate}
          onChange={(e) => handleDateChange(e.target.value)}
          aria-label="Dátum kontroly"
          className="h-11 w-full rounded-xl border border-status-warn/40 bg-white px-3 text-sm font-medium text-ink-900 transition-colors hover:border-status-warn focus:border-status-warn focus:outline-none focus:ring-2 focus:ring-status-warn/30 disabled:bg-ink-50 disabled:text-ink-500"
        />
      </Card>

      {module && items.length > 0 && <module.StatsBar items={items} />}

      {error && data && (
        <Card className="px-3 py-2 text-sm text-status-bad">{error}</Card>
      )}

      {items.length === 0 ? (
        <EmptyItems inspectionId={id} disabled={!isDraft} />
      ) : module ? (
        <ul className="flex flex-col gap-2">
          {items.map((it, idx) => (
            <li key={it.id}>
              <module.ItemRow
                inspectionId={id}
                index={idx + 1}
                item={it}
                canEdit={isDraft}
                deleting={deletingItemId === it.id}
                onDelete={() => handleDeleteItem(it.id)}
              />
            </li>
          ))}
        </ul>
      ) : (
        <Card className="px-3 py-3 text-xs text-ink-500">
          Pre tento typ kontroly ešte nemáme zobrazenie položiek.
        </Card>
      )}

      <DocumentsBlock
        documents={documents}
        canGenerate={isDraft && items.length > 0 && !!i.executed_on}
        generating={generating}
        onGenerate={handleGeneratePdf}
        finalized={!isDraft}
      />
    </div>
  );
}

function DocumentsBlock({
  documents,
  canGenerate,
  generating,
  onGenerate,
  finalized,
}: {
  documents: InspectionDocument[];
  canGenerate: boolean;
  generating: boolean;
  onGenerate: () => void;
  finalized: boolean;
}) {
  if (documents.length === 0) {
    return (
      <Card className="flex flex-col items-center gap-2 px-5 py-6 text-center">
        <div className="grid size-11 place-items-center rounded-2xl bg-firol-50 text-firol-500">
          <FileText className="size-5" />
        </div>
        <h2 className="text-sm font-semibold text-ink-900">PDF protokol</h2>
        <p className="max-w-sm text-xs text-ink-500">
          {canGenerate
            ? 'Po vygenerovaní sa kontrola uzamkne a dostane svoje číslo (napr. RPHP-2026-001).'
            : 'Pre vygenerovanie pridaj aspoň jednu položku a skontroluj dátum kontroly.'}
        </p>
        <Button
          type="button"
          variant="primary"
          loading={generating}
          disabled={!canGenerate}
          onClick={onGenerate}
          leftIcon={<FileText className="size-4" />}
          className="bg-status-bad hover:brightness-110"
        >
          Generovať PDF protokol
        </Button>
      </Card>
    );
  }

  return (
    <Card className="overflow-hidden">
      <div className="flex items-center gap-3 border-b border-ink-100 bg-gradient-to-br from-firol-50/60 to-transparent px-4 py-3">
        <div className="grid size-9 place-items-center rounded-2xl bg-firol-500 text-white shadow-[var(--shadow-glow)]">
          <FileText className="size-4" />
        </div>
        <div className="min-w-0 flex-1">
          <h3 className="text-sm font-semibold text-ink-900">PDF protokoly</h3>
          <p className="text-xs text-ink-500">
            {finalized
              ? 'Kontrola je uzamknutá. Pre nový dátum použi tlačidlo „Opakovať" hore.'
              : `Vygenerované ${documents.length} ${documents.length === 1 ? 'protokol' : 'protokoly'}.`}
          </p>
        </div>
      </div>
      <ul className="divide-y divide-ink-100">
        {documents.map((doc) => (
          <li key={doc.id}>
            <a
              href={documentDownloadUrl(doc.id)}
              target="_blank"
              rel="noopener"
              className="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-ink-50"
            >
              <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-firol-50 text-firol-600">
                <FileText className="size-4" />
              </span>
              <div className="min-w-0 flex-1">
                <p className="font-mono text-sm text-ink-900">{doc.number}</p>
                <p className="text-xs text-ink-500">
                  Vystavený {new Date(doc.generated_at.replace(' ', 'T')).toLocaleString('sk-SK')}
                </p>
              </div>
              <Download className="size-4 shrink-0 text-ink-400" />
            </a>
          </li>
        ))}
      </ul>
    </Card>
  );
}

function EmptyItems({ inspectionId, disabled }: { inspectionId: number; disabled: boolean }) {
  return (
    <Card className="flex flex-col items-center gap-3 px-6 py-10 text-center">
      <div className="grid size-12 place-items-center rounded-2xl bg-firol-50 text-firol-500">
        <ClipboardList className="size-5" />
      </div>
      <h2 className="text-sm font-semibold text-ink-900">Zatiaľ žiadne položky</h2>
      <p className="max-w-xs text-xs text-ink-500">
        Pridaj prvú položku — po uložení sa zobrazí v zozname so štatistikou.
      </p>
      {!disabled && (
        <Link
          to={`/inspections/${inspectionId}/items/new`}
          className="inline-flex h-11 items-center gap-1.5 rounded-2xl bg-firol-500 px-4 text-sm font-medium text-white shadow-[var(--shadow-glow)] hover:bg-firol-600"
        >
          <Plus className="size-4" />
          Pridať prvú položku
        </Link>
      )}
    </Card>
  );
}
