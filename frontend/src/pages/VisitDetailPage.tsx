import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import {
  ArrowLeft, ArrowRight, Building2, CalendarDays, Check, CheckCircle2, FileSignature,
  FileText, Plus, Route, Send, Warehouse,
} from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import {
  INSPECTION_TYPE_LABELS,
  documentDownloadUrl,
  type InspectionType,
} from '@/api/inspections';
import { Visits, type Visit, type VisitInspection } from '@/api/visits';
import { Companies } from '@/api/companies';
import { ApiError } from '@/lib/api';
import { offlineMessage } from '@/lib/offline';
import { useToast } from '@/lib/toast';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { CardBlockSkeleton, DetailHeaderSkeleton } from '@/components/ui/Skeleton';
import { BulkSendDialog } from '@/components/BulkSendDialog';
import { WorkConfirmationDialog } from '@/components/WorkConfirmationDialog';
import { SECTION_COLORS, sectionForInspectionType } from '@/lib/sections';
import { cn } from '@/lib/cn';

/**
 * A návšteva in progress — block 1 / chapter 9.
 *
 * The screen a technician keeps open while working: how far they are ("2 z 4
 * hotové"), what is left, and one tap into the next úkon. Company, prevádzka
 * and date were chosen once at the start and are not asked again.
 *
 * Three things close it out: all the protocols generated at once, all of them
 * sent to the client in one e-mail, and — for the technician's own employer —
 * a potvrdenie o vykonaní práce covering the whole visit rather than one sheet
 * per úkon.
 */
export function VisitDetailPage() {
  const { id: idStr } = useParams<{ id: string }>();
  const id = Number(idStr);
  const { csrfToken } = useAuth();
  const toast = useToast();
  const navigate = useNavigate();

  const [visit, setVisit] = useState<Visit | null>(null);
  const [companyEmail, setCompanyEmail] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [generating, setGenerating] = useState(false);
  const [startingType, setStartingType] = useState<InspectionType | null>(null);
  const [sendOpen, setSendOpen] = useState(false);
  const [confirmationOpen, setConfirmationOpen] = useState(false);

  const load = useCallback(async () => {
    const res = await Visits.show(id);
    setVisit(res.visit);
    return res.visit;
  }, [id]);

  useEffect(() => {
    let cancelled = false;
    load()
      .then(async (v) => {
        if (cancelled) return;
        // The client's recorded address, prefilled as the recipient of the
        // bulk send so the common case needs no typing.
        const company = await Companies.show(v.company_id).catch(() => null);
        if (!cancelled) setCompanyEmail(company?.company.contact_email ?? null);
      })
      .catch((err: unknown) => {
        if (!cancelled) {
          setError(err instanceof ApiError ? err.message : 'Návštevu sa nepodarilo načítať.');
        }
      });
    return () => {
      cancelled = true;
    };
  }, [load]);

  /**
   * Open the úkon for a planned type: continue the one already started, or
   * begin a new one with the visit's company, prevádzka and date already
   * filled in — Step 1 is what the visit exists to skip.
   */
  function openType(type: InspectionType, existing: VisitInspection | undefined) {
    if (existing) {
      navigate(`/inspections/${existing.id}`);
      return;
    }
    if (!visit) return;
    setStartingType(type);
    const params = new URLSearchParams({
      company_id: String(visit.company_id),
      facility_id: String(visit.facility_id),
      visit_id: String(visit.id),
      executed_on: visit.visit_date,
    });
    navigate(`/inspections/new/${type}/step-1?${params.toString()}`);
  }

  async function handleGenerateAll() {
    setGenerating(true);
    setError(null);
    try {
      const res = await Visits.generateDocuments(id, csrfToken);
      setVisit(res.visit);
      if (res.generated.length > 0) {
        toast.success(
          `Vygenerované: ${res.generated.length} ${res.generated.length === 1 ? 'protokol' : res.generated.length < 5 ? 'protokoly' : 'protokolov'}.`,
        );
      }
      if (res.skipped.length > 0) {
        // Naming what was skipped and why beats a silent partial success —
        // the technician can fix that one úkon and press the button again.
        setError(
          `Nevygenerované: ${res.skipped
            .map((s) => `${INSPECTION_TYPE_LABELS[s.type] ?? s.type} — ${s.reason}`)
            .join(' · ')}`,
        );
      }
    } catch (err) {
      setError(offlineMessage(err, 'Protokoly sa nepodarilo vygenerovať.'));
    } finally {
      setGenerating(false);
    }
  }

  async function handleComplete() {
    try {
      const res = await Visits.update(id, { status: 'dokoncena' }, csrfToken);
      setVisit(res.visit);
      toast.success('Návšteva uzavretá');
    } catch (err) {
      setError(offlineMessage(err, 'Návštevu sa nepodarilo uzavrieť.'));
    }
  }

  if (error && !visit) {
    return (
      <div className="flex flex-col gap-4">
        <BackLink />
        <Card className="px-4 py-3 text-sm text-status-bad">{error}</Card>
      </div>
    );
  }

  if (!visit) {
    return (
      <div className="flex flex-col gap-5">
        <BackLink />
        <DetailHeaderSkeleton />
        <CardBlockSkeleton rows={4} />
      </div>
    );
  }

  // A planned type is "done" once its úkon has a protocol; the planned list and
  // anything added on the spot both count.
  const byType = new Map(visit.inspections.map((ins) => [ins.type, ins]));
  const plannedTypes = [
    ...visit.planned_types,
    ...visit.inspections.map((ins) => ins.type).filter((t) => !visit.planned_types.includes(t)),
  ];
  const doneCount = plannedTypes.filter((t) => byType.get(t)?.status === 'finalized').length;
  const documentIds = visit.inspections
    .map((ins) => ins.document_id)
    .filter((docId): docId is number => docId !== null);
  const pendingCount = visit.inspections.filter((ins) => ins.status === 'draft').length;

  return (
    <div className="flex flex-col gap-5">
      <BackLink />

      <header className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex min-w-0 items-start gap-2.5">
          <span className="grid size-10 shrink-0 place-items-center rounded-2xl bg-firol-500 text-white shadow-[var(--shadow-glow)]">
            <Route className="size-5" />
          </span>
          <div className="min-w-0">
            <h1 className="text-xl font-semibold tracking-tight text-ink-900">Návšteva</h1>
            <p className="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-ink-500">
              <Building2 className="size-3" />
              <Link to={`/companies/${visit.company_id}`} className="hover:text-firol-600">
                {visit.company_name}
              </Link>
              <span className="text-ink-300">·</span>
              <Warehouse className="size-3" />
              <Link to={`/facilities/${visit.facility_id}`} className="hover:text-firol-600">
                {visit.facility_name}
              </Link>
              <span className="text-ink-300">·</span>
              <CalendarDays className="size-3" />
              {new Date(`${visit.visit_date}T00:00:00`).toLocaleDateString('sk-SK')}
              <span className="text-ink-300">·</span>
              {visit.status === 'dokoncena' ? (
                <Badge tone="ok">Dokončená</Badge>
              ) : (
                <Badge tone="warn">Prebieha</Badge>
              )}
            </p>
          </div>
        </div>
      </header>

      {/* Progress — "2 z 4 hotové" (chapter 9, step 4). */}
      <Card className="flex items-center gap-3 px-4 py-3">
        <div className="min-w-0 flex-1">
          <p className="text-sm font-medium text-ink-900">
            {doneCount} z {plannedTypes.length} hotové
          </p>
          <div className="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-ink-100">
            <div
              className="h-full rounded-full bg-firol-500 transition-[width] duration-500"
              style={{
                width: `${plannedTypes.length === 0 ? 0 : (doneCount / plannedTypes.length) * 100}%`,
              }}
            />
          </div>
        </div>
        {doneCount === plannedTypes.length && plannedTypes.length > 0 && (
          <CheckCircle2 className="size-5 shrink-0 text-status-ok" />
        )}
      </Card>

      <Card className="overflow-hidden">
        <div className="border-b border-ink-100 px-4 py-3">
          <h2 className="text-xs font-semibold uppercase tracking-wider text-ink-500">Úkony</h2>
        </div>
        <ul className="divide-y divide-ink-100">
          {plannedTypes.map((type) => (
            <li key={type}>
              <VisitTypeRow
                type={type}
                inspection={byType.get(type)}
                busy={startingType === type}
                onOpen={() => openType(type, byType.get(type))}
              />
            </li>
          ))}
        </ul>
        <Link
          to={`/inspections/new?company_id=${visit.company_id}&facility_id=${visit.facility_id}`}
          className="flex items-center justify-center gap-1.5 border-t border-ink-100 px-4 py-3 text-sm font-medium text-firol-600 transition-colors hover:bg-firol-50"
        >
          <Plus className="size-4" />
          Pridať ďalší úkon
        </Link>
      </Card>

      {error && <Card className="px-4 py-3 text-sm text-status-bad">{error}</Card>}

      <Card className="flex flex-col gap-3 p-4">
        <h2 className="text-xs font-semibold uppercase tracking-wider text-ink-500">
          Ukončenie návštevy
        </h2>
        <p className="text-xs text-ink-500">
          Každý úkon dostane vlastný protokol s vlastným číslom. Klientovi ich
          pošleš v jednom e-maile; potvrdenie o vykonaní práce je pre tvojho
          zamestnávateľa a neobsahuje žiadne zistenia.
        </p>
        <div className="flex flex-wrap gap-2">
          <Button
            type="button"
            loading={generating}
            disabled={pendingCount === 0}
            onClick={handleGenerateAll}
            leftIcon={<FileText className="size-4" />}
          >
            Generovať všetky protokoly
          </Button>
          <Button
            type="button"
            variant="secondary"
            disabled={documentIds.length === 0}
            onClick={() => setSendOpen(true)}
            leftIcon={<Send className="size-4" />}
          >
            Odoslať klientovi
          </Button>
          <Button
            type="button"
            variant="secondary"
            disabled={documentIds.length === 0}
            onClick={() => setConfirmationOpen(true)}
            leftIcon={<FileSignature className="size-4" />}
          >
            Potvrdenie o vykonaní práce
          </Button>
          {visit.status !== 'dokoncena' && (
            <Button type="button" variant="ghost" onClick={handleComplete} leftIcon={<Check className="size-4" />}>
              Uzavrieť návštevu
            </Button>
          )}
        </div>
      </Card>

      <BulkSendDialog
        open={sendOpen}
        onClose={() => setSendOpen(false)}
        companyId={visit.company_id}
        companyName={visit.company_name}
        defaultRecipient={companyEmail}
        preselectDocumentIds={documentIds}
        visitId={visit.id}
      />

      <WorkConfirmationDialog
        open={confirmationOpen}
        onClose={() => setConfirmationOpen(false)}
        visitId={visit.id}
        companyName={visit.company_name}
        facilityName={visit.facility_name}
        date={visit.visit_date}
        actCount={visit.inspections.filter((ins) => ins.status === 'finalized').length}
      />
    </div>
  );
}

function BackLink() {
  return (
    <Link to="/" className="inline-flex items-center gap-1 self-start text-sm text-ink-500 hover:text-ink-700">
      <ArrowLeft className="size-4" />
      Späť na prehľad
    </Link>
  );
}

function VisitTypeRow({
  type,
  inspection,
  busy,
  onOpen,
}: {
  type: InspectionType;
  inspection: VisitInspection | undefined;
  busy: boolean;
  onOpen: () => void;
}) {
  const section = sectionForInspectionType(type);
  const done = inspection?.status === 'finalized';

  return (
    <div className="flex items-center gap-3 px-4 py-3">
      <span
        aria-hidden
        className="h-8 w-1 shrink-0 rounded-full"
        style={{ backgroundColor: section ? SECTION_COLORS[section] : 'var(--color-ink-200)' }}
      />
      <div className="min-w-0 flex-1">
        <p className={cn('truncate text-sm font-medium', done ? 'text-ink-500' : 'text-ink-900')}>
          {INSPECTION_TYPE_LABELS[type] ?? type}
        </p>
        <p className="text-xs text-ink-500">
          {!inspection
            ? 'Zatiaľ nezačaté'
            : done
              ? `Protokol ${inspection.document_number ?? '—'} · ${inspection.item_count} ${inspection.item_count === 1 ? 'položka' : inspection.item_count < 5 ? 'položky' : 'položiek'}`
              : `Rozpracované · ${inspection.item_count} ${inspection.item_count === 1 ? 'položka' : inspection.item_count < 5 ? 'položky' : 'položiek'}`}
        </p>
      </div>
      {done && inspection?.document_id ? (
        <a
          href={documentDownloadUrl(inspection.document_id)}
          target="_blank"
          rel="noopener"
          className="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-xl px-3 text-xs font-medium text-ink-600 transition-colors hover:bg-ink-100"
        >
          <FileText className="size-3.5" />
          PDF
        </a>
      ) : (
        <Button type="button" size="sm" variant="secondary" loading={busy} onClick={onOpen} rightIcon={<ArrowRight className="size-3.5" />}>
          {inspection ? 'Pokračovať' : 'Začať'}
        </Button>
      )}
    </div>
  );
}
