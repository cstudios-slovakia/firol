import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import {
  AlertTriangle, ArrowLeft, Building2, CalendarDays, ClipboardList,
  Download, Edit2, FileText, Hash, MapPin, Plus, Repeat, Tag, Trash2, Warehouse,
} from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import {
  INSPECTION_TYPE_LABELS,
  Inspections,
  RPHP_STATUS_LABELS,
  RPHP_STATUS_TONES,
  documentDownloadUrl,
  type InspectionDetail,
  type InspectionDocument,
  type InspectionItem,
  type RphpItemFields,
  type RphpStatus,
} from '@/api/inspections';
import { ApiError } from '@/lib/api';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Spinner } from '@/components/ui/Spinner';
import { cn } from '@/lib/cn';

/**
 * Step 3 — summary screen. Final review before PDF generation.
 *
 * The date sits in a highlighted orange box and is editable directly here:
 * critical for the Opakovať flow (Phase 3a-4) where the technician
 * clones a previous inspection and only changes the date.
 *
 * Statistics summarise the per-status counts (A / TS / O / V) so the
 * inspector sees at a glance which prístroje need follow-up.
 */
export function InspectionDetailPage() {
  const { id: idStr } = useParams<{ id: string }>();
  const id = Number(idStr);
  const { csrfToken } = useAuth();

  const navigate = useNavigate();
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
      // Reload everything: status flipped to finalized + a new document
      // exists. Cheaper than patching local state and easier to keep in
      // sync with future server-side changes.
      const [detail, docs] = await Promise.all([
        Inspections.show(id),
        Inspections.documents(id),
      ]);
      setData(detail);
      setDocuments(docs.items);
      // Open the freshly generated PDF in a new tab.
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
      setError(err instanceof ApiError ? err.message : 'Dátum sa nepodarilo uložiť.');
    } finally {
      setSavingDate(false);
    }
  }

  async function handleDeleteItem(itemId: number) {
    if (!window.confirm('Naozaj zmazať tento prístroj?')) return;
    setDeletingItemId(itemId);
    try {
      await Inspections.deleteItem(id, itemId, csrfToken);
      const fresh = await Inspections.show(id);
      setData(fresh);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Mazanie sa nepodarilo.');
    } finally {
      setDeletingItemId(null);
    }
  }

  const stats = useMemo(() => computeRphpStats(data?.items ?? []), [data?.items]);

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
      <div className="flex justify-center py-10 text-ink-400">
        <Spinner />
      </div>
    );
  }

  const { inspection: i, items } = data;
  const isDraft = i.status === 'draft';
  const isRphp = i.type === 'rphp';

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
        {isDraft ? (
          <Link
            to={`/inspections/${id}/items/new`}
            className="inline-flex h-10 shrink-0 items-center gap-1.5 rounded-2xl bg-firol-500 px-3 text-sm font-medium text-white shadow-[var(--shadow-glow)] hover:bg-firol-600"
          >
            <Plus className="size-4" />
            Pridať prístroj
          </Link>
        ) : i.type !== 'poziarna_kniha' ? (
          <Button
            type="button"
            onClick={handleRepeat}
            loading={repeating}
            leftIcon={<Repeat className="size-4" />}
            title="Vytvorí novú kontrolu s tými istými prístrojmi a prázdnym dátumom"
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

      {isRphp && items.length > 0 && (
        <StatsBar stats={stats} total={items.length} />
      )}

      {error && data && (
        <Card className="px-3 py-2 text-sm text-status-bad">{error}</Card>
      )}

      {items.length === 0 ? (
        <EmptyItems inspectionId={id} disabled={!isDraft} />
      ) : (
        <ul className="flex flex-col gap-2">
          {items.map((it, idx) => (
            <li key={it.id}>
              {isRphp
                ? <RphpItemRow
                    inspectionId={id}
                    index={idx + 1}
                    item={it}
                    deleting={deletingItemId === it.id}
                    onDelete={() => handleDeleteItem(it.id)}
                    canEdit={isDraft}
                  />
                : <UnknownItemRow item={it} />
              }
            </li>
          ))}
        </ul>
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
            : 'Pre vygenerovanie pridaj aspoň jeden prístroj a skontroluj dátum kontroly.'}
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

function StatsBar({
  stats,
  total,
}: {
  stats: Record<RphpStatus, number>;
  total: number;
}) {
  const entries: { key: RphpStatus; label: string; tone: 'ok' | 'warn' | 'bad' | 'neutral' }[] = [
    { key: 'A',  label: 'Akcieschopné',     tone: 'ok' },
    { key: 'TS', label: 'Tlaková skúška',  tone: 'warn' },
    { key: 'O',  label: 'Vyžaduje opravu', tone: 'bad' },
    { key: 'V',  label: 'Vyradené',         tone: 'neutral' },
  ];
  const toneClasses: Record<typeof entries[number]['tone'], string> = {
    ok:      'bg-[var(--color-status-ok-bg)] text-[var(--color-status-ok)]',
    warn:    'bg-[var(--color-status-warn-bg)] text-[var(--color-status-warn)]',
    bad:     'bg-[var(--color-status-bad-bg)] text-[var(--color-status-bad)]',
    neutral: 'bg-ink-100 text-ink-700',
  };
  return (
    <Card className="px-4 py-3">
      <div className="flex items-center justify-between gap-2 text-xs">
        <span className="font-semibold uppercase tracking-wider text-ink-500">
          Štatistika
        </span>
        <span className="text-ink-500">spolu {total}</span>
      </div>
      <div className="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
        {entries.map((e) => (
          <div
            key={e.key}
            className={cn(
              'flex items-center justify-between gap-2 rounded-xl px-3 py-2 text-sm',
              toneClasses[e.tone],
            )}
          >
            <span className="text-xs">{e.label}</span>
            <span className="text-base font-semibold tabular-nums">{stats[e.key]}</span>
          </div>
        ))}
      </div>
    </Card>
  );
}

function RphpItemRow({
  inspectionId,
  index,
  item,
  canEdit,
  deleting,
  onDelete,
}: {
  inspectionId: number;
  index: number;
  item: InspectionItem;
  canEdit: boolean;
  deleting: boolean;
  onDelete: () => void;
}) {
  const f = item.fields as Partial<RphpItemFields>;
  const status = isRphpStatus(f.status) ? f.status : null;
  return (
    <Card className="px-4 py-3">
      <div className="flex items-start gap-3">
        <span className="grid size-9 shrink-0 place-items-center rounded-2xl bg-firol-50 text-firol-700 text-sm font-semibold">
          {index}
        </span>
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <h3 className="truncate text-sm font-semibold text-ink-900">
              <Tag className="-mt-0.5 mr-1 inline size-3 text-ink-400" />
              {f.manufacturer} · {f.type}
            </h3>
            {status && (
              <Badge tone={RPHP_STATUS_TONES[status]}>
                {status} · {RPHP_STATUS_LABELS[status]}
              </Badge>
            )}
          </div>
          <p className="mt-0.5 truncate text-xs text-ink-500">
            <Hash className="-mt-0.5 mr-1 inline size-3" />
            {f.serial}
            <span className="mx-1.5 text-ink-300">·</span>
            r. {f.year}
            <span className="mx-1.5 text-ink-300">·</span>
            <MapPin className="-mt-0.5 mr-1 inline size-3" />
            {f.location}
          </p>
          {f.notes && (
            <p className="mt-1 line-clamp-2 text-xs text-ink-600">
              <AlertTriangle className="-mt-0.5 mr-1 inline size-3 text-status-warn" />
              {f.notes}
            </p>
          )}
        </div>
        {canEdit && (
          <div className="flex shrink-0 items-center gap-1">
            <Link
              to={`/inspections/${inspectionId}/items/${item.id}`}
              aria-label="Opraviť"
              className="grid size-9 place-items-center rounded-xl text-ink-500 transition-colors hover:bg-ink-100 hover:text-ink-700"
            >
              <Edit2 className="size-4" />
            </Link>
            <button
              type="button"
              onClick={onDelete}
              disabled={deleting}
              aria-label="Zmazať"
              className="grid size-9 place-items-center rounded-xl text-ink-500 transition-colors hover:bg-[var(--color-status-bad-bg)] hover:text-status-bad disabled:opacity-50"
            >
              {deleting ? <Spinner size="sm" /> : <Trash2 className="size-4" />}
            </button>
          </div>
        )}
      </div>
    </Card>
  );
}

function UnknownItemRow({ item }: { item: InspectionItem }) {
  return (
    <Card className="px-4 py-3 text-xs text-ink-500">
      <pre className="whitespace-pre-wrap break-words">{JSON.stringify(item.fields, null, 2)}</pre>
    </Card>
  );
}

function EmptyItems({ inspectionId, disabled }: { inspectionId: number; disabled: boolean }) {
  return (
    <Card className="flex flex-col items-center gap-3 px-6 py-10 text-center">
      <div className="grid size-12 place-items-center rounded-2xl bg-firol-50 text-firol-500">
        <ClipboardList className="size-5" />
      </div>
      <h2 className="text-sm font-semibold text-ink-900">Zatiaľ žiadne prístroje</h2>
      <p className="max-w-xs text-xs text-ink-500">
        Pridaj prvý prístroj. Po uložení sa zobrazí v zozname so štatistikou A / TS / O / V.
      </p>
      {!disabled && (
        <Link
          to={`/inspections/${inspectionId}/items/new`}
          className="inline-flex h-11 items-center gap-1.5 rounded-2xl bg-firol-500 px-4 text-sm font-medium text-white shadow-[var(--shadow-glow)] hover:bg-firol-600"
        >
          <Plus className="size-4" />
          Pridať prvý prístroj
        </Link>
      )}
    </Card>
  );
}

function computeRphpStats(items: InspectionItem[]): Record<RphpStatus, number> {
  const stats: Record<RphpStatus, number> = { A: 0, TS: 0, O: 0, V: 0 };
  for (const it of items) {
    const status = (it.fields as Partial<RphpItemFields>).status;
    if (isRphpStatus(status)) stats[status] += 1;
  }
  return stats;
}

function isRphpStatus(s: unknown): s is RphpStatus {
  return s === 'A' || s === 'TS' || s === 'O' || s === 'V';
}
