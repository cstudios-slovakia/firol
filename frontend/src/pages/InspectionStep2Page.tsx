import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Building2, Warehouse } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import {
  INSPECTION_TYPE_LABELS,
  Inspections,
  type InspectionDetail,
  type InspectionItem,
  type InspectionType,
} from '@/api/inspections';
import { ApiError } from '@/lib/api';
import { Card } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { cn } from '@/lib/cn';
import { getTypeModule } from '@/inspection-types';
import type { SubmitAction } from '@/inspection-types/common';

/**
 * Step 2 wrapper. Loads the inspection and dispatches the per-type form
 * via the registry. Common chrome (header, progress dots, navigation
 * after save) lives here so each per-type form stays focused on its own
 * fields. Same route serves both create (`/items/new`) and edit
 * (`/items/:itemId`) modes.
 */
export function InspectionStep2Page() {
  const { id: inspectionIdStr, itemId: itemIdStr } = useParams<{
    id: string;
    itemId?: string;
  }>();
  const inspectionId = Number(inspectionIdStr);
  const itemId = itemIdStr !== undefined ? Number(itemIdStr) : null;
  const editing = itemId !== null;

  const { csrfToken } = useAuth();
  const navigate = useNavigate();

  const [detail, setDetail] = useState<InspectionDetail | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    Inspections.show(inspectionId)
      .then((res) => {
        if (cancelled) return;
        setDetail(res);
        if (editing) {
          const found = res.items.find((it) => it.id === itemId);
          if (!found) {
            setLoadError('Prístroj sa nenašiel — možno bol medzitým zmazaný.');
          }
        }
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setLoadError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať kontrolu.');
      });
    return () => {
      cancelled = true;
    };
  }, [inspectionId, itemId, editing]);

  const initialItem = useMemo(() => {
    if (!detail || !editing) return null;
    return detail.items.find((it) => it.id === itemId) ?? null;
  }, [detail, editing, itemId]);

  const totalItems = detail?.items.length ?? 0;
  const currentIndex = editing
    ? Math.max(1, (detail?.items.findIndex((it) => it.id === itemId) ?? -1) + 1)
    : totalItems + 1;
  const totalForHeader = editing ? totalItems : totalItems + 1;

  async function handleSaved(action: SubmitAction) {
    if (action === 'save-and-summary') {
      navigate(`/inspections/${inspectionId}`, { replace: true });
      return;
    }
    // save-and-next: when editing, point at the create route so a fresh
    // form mounts; when adding, refetch the detail (so progress dots and
    // counts update) and let the form reset itself via the initialItem
    // change to null.
    if (editing) {
      navigate(`/inspections/${inspectionId}/items/new`, { replace: true });
      return;
    }
    try {
      const fresh = await Inspections.show(inspectionId);
      setDetail(fresh);
    } catch {
      // The save itself succeeded; a failure to refetch is non-blocking.
    }
  }

  if (loadError) {
    return (
      <div className="flex flex-col gap-4">
        <Link to={`/inspections/${inspectionId}`} className="inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-700 self-start">
          <ArrowLeft className="size-4" />
          Späť
        </Link>
        <Card className="px-4 py-3 text-sm text-status-bad">{loadError}</Card>
      </div>
    );
  }

  if (!detail) {
    return (
      <div className="flex justify-center py-10 text-ink-400">
        <Spinner />
      </div>
    );
  }

  const { inspection: i } = detail;
  const module = getTypeModule(i.type);

  if (!module) {
    return <UnsupportedTypeNotice type={i.type} inspectionId={inspectionId} />;
  }

  const FormComponent = module.Step2Form;

  return (
    <div className="flex flex-col gap-5">
      <Link
        to={`/inspections/${inspectionId}`}
        className="inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-700 self-start"
      >
        <ArrowLeft className="size-4" />
        Späť na súhrn
      </Link>

      <header>
        <p className="text-xs font-semibold uppercase tracking-wider text-firol-500">
          Krok 2 · zadávanie položiek
        </p>
        <h1 className="mt-1 text-xl font-semibold tracking-tight text-ink-900">
          {editing ? `Upraviť položku č. ${currentIndex}` : `Položka č. ${currentIndex}`}
        </h1>
        <p className="mt-1 flex items-center gap-1.5 text-xs text-ink-500">
          <span className="font-medium text-ink-600">
            {INSPECTION_TYPE_LABELS[i.type]}
          </span>
          <span className="text-ink-300">·</span>
          <Building2 className="size-3" />
          {i.company_name}
          <span className="text-ink-300">·</span>
          <Warehouse className="size-3" />
          {i.facility_name}
        </p>
      </header>

      <ProgressDots
        total={totalForHeader}
        currentIndex={currentIndex}
        items={detail.items}
        editingItemId={itemId}
        inspectionId={inspectionId}
      />

      <FormComponent
        key={editing ? `edit-${itemId}` : `new-${detail.items.length}`}
        inspectionId={inspectionId}
        initialItem={initialItem}
        csrfToken={csrfToken}
        onSaved={handleSaved}
      />
    </div>
  );
}

function ProgressDots({
  total,
  currentIndex,
  items,
  editingItemId,
  inspectionId,
}: {
  total: number;
  currentIndex: number;
  items: InspectionItem[];
  editingItemId: number | null;
  inspectionId: number;
}) {
  if (total === 0) return null;
  const dots: { key: string; state: 'saved' | 'current'; href?: string }[] = [];
  items.forEach((it) => {
    const isCurrent = editingItemId === it.id;
    dots.push({
      key: `i-${it.id}`,
      state: isCurrent ? 'current' : 'saved',
      href: isCurrent ? undefined : `/inspections/${inspectionId}/items/${it.id}`,
    });
  });
  if (editingItemId === null) {
    dots.push({ key: 'new', state: 'current' });
  }

  return (
    <div className="flex items-center gap-1.5 overflow-x-auto py-1" role="list" aria-label="Postup zadávania">
      {dots.map((d, idx) => {
        const className = cn(
          'shrink-0 size-2.5 rounded-full transition-all',
          d.state === 'saved' && 'bg-firol-500',
          d.state === 'current' && 'bg-firol-500 ring-4 ring-firol-100',
        );
        if (d.href) {
          return (
            <Link
              key={d.key}
              to={d.href}
              role="listitem"
              aria-label={`Položka č. ${idx + 1}`}
              className={className}
              title={`Položka č. ${idx + 1}`}
            />
          );
        }
        return (
          <span
            key={d.key}
            role="listitem"
            aria-current={d.state === 'current' ? 'step' : undefined}
            aria-label={`Položka č. ${idx + 1}${d.state === 'current' ? ' (aktuálna)' : ''}`}
            className={className}
          />
        );
      })}
      <span className="ml-2 shrink-0 text-xs text-ink-500">
        {currentIndex} / {total}
      </span>
    </div>
  );
}

function UnsupportedTypeNotice({
  type,
  inspectionId,
}: {
  type: InspectionType;
  inspectionId: number;
}) {
  return (
    <div className="flex flex-col gap-4">
      <Link to={`/inspections/${inspectionId}`} className="inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-700 self-start">
        <ArrowLeft className="size-4" />
        Späť
      </Link>
      <Card className="flex flex-col items-center gap-2 px-6 py-10 text-center">
        <h2 className="text-base font-semibold text-ink-900">Tento typ ešte nemá formulár</h2>
        <p className="max-w-sm text-sm text-ink-500">
          „{INSPECTION_TYPE_LABELS[type]}" pribudne v ďalšej fáze. Inšpekcia sa
          dá zatiaľ otvoriť, ale položky sa do nej nedajú pridať.
        </p>
      </Card>
    </div>
  );
}
