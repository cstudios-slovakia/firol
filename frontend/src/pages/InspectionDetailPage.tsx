import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import {
  ArrowLeft, ArrowRight, Building2, CalendarDays, ClipboardList, CopyPlus, Download,
  FileText, GitBranch, History, Images, Link2, Lock, LockOpen, NotebookPen, Pencil,
  PenLine, Plus, Repeat, Warehouse,
} from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import {
  INSPECTION_TYPE_LABELS,
  Inspections,
  documentDownloadUrl,
  isAuditType,
  periodicityOf,
  type InspectionDetail,
  type InspectionDocument,
  type InspectionType,
} from '@/api/inspections';
import { ApiError } from '@/lib/api';
import { cn } from '@/lib/cn';
import { handleOfflineSave, offlineMessage } from '@/lib/offline';
import { useToast } from '@/lib/toast';
import { useConfirm } from '@/lib/confirm';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { CardBlockSkeleton, DetailHeaderSkeleton } from '@/components/ui/Skeleton';
import { getTypeModule } from '@/inspection-types';
import { AuditSummaryBlock } from '@/components/AuditSummaryBlock';
import { clearDuplicateSeed } from '@/inspection-types/duplicateSeed';
import { EmailDocumentForm } from '@/components/EmailDocumentForm';
import { ItemPhotoStrip } from '@/components/ItemPhotos';
import { PendingSyncBanner } from '@/components/PendingSyncBanner';
import { InspectionStatusBadge } from '@/components/InspectionStatusBadge';
import { HandoverDialog } from '@/components/HandoverDialog';
import { PreviousStatusBadge, previousStatusOf } from '@/components/PreviousStatusBadge';
import { PeriodicityPicker } from '@/components/PeriodicityPicker';
import { periodicityLabel, type Periodicity } from '@/lib/periodicity';
import { sectionPathForType } from '@/lib/sections';

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
  const confirm = useConfirm();

  const [data, setData] = useState<InspectionDetail | null>(null);
  const [documents, setDocuments] = useState<InspectionDocument[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [pdfError, setPdfError] = useState<string | null>(null);
  const [savingDate, setSavingDate] = useState(false);
  const [savingPeriodicity, setSavingPeriodicity] = useState(false);
  const [localDate, setLocalDate] = useState<string>('');
  const [deletingItemId, setDeletingItemId] = useState<number | null>(null);
  const [generating, setGenerating] = useState(false);
  const [repeating, setRepeating] = useState(false);
  // "Upraviť" on a locked inspection asks first — unlocking throws the issued
  // protocol away, so it never happens on a single tap.
  const [unlockPrompt, setUnlockPrompt] = useState(false);
  const [unlocking, setUnlocking] = useState(false);
  const [creatingFollowUp, setCreatingFollowUp] = useState(false);
  // Chapter 12 — pulling last time's devices into an empty draft.
  const [carryingOver, setCarryingOver] = useState(false);
  // Chapter 13 — the document currently being handed over for signature.
  const [signingDocument, setSigningDocument] = useState<InspectionDocument | null>(null);
  // "Priložiť fotodokumentáciu" (change request 2.2) — on by default, and only
  // shown at all when the inspection actually has photos.
  const [includePhotos, setIncludePhotos] = useState(true);

  // Reaching the summary means the technician left the item-entry flow, so any
  // pending "Ďalší rovnaký" prefill is dropped — the next item they add starts
  // from a blank form rather than inheriting an item entered minutes ago.
  useEffect(() => { clearDuplicateSeed(id); }, [id]);

  useEffect(() => {
    let cancelled = false;
    // The inspection itself drives the page and is served from the local cache
    // when offline. Documents are a server-only side list (PDFs can't exist for
    // an unsynced draft), so a failed fetch must not blank out the whole page.
    Inspections.show(id)
      .then(async (detail) => {
        if (cancelled) return;
        setData(detail);
        const docs = await Inspections.documents(id).catch(
          () => ({ items: [] as InspectionDocument[] }),
        );
        if (!cancelled) setDocuments(docs.items);
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať kontrolu.');
      });
    return () => {
      cancelled = true;
    };
  }, [id]);

  // Sync localDate from API data; runs on initial load and after save confirms.
  const savedDate = data?.inspection.executed_on ?? '';
  useEffect(() => { setLocalDate(savedDate); }, [savedDate]);

  async function handleRepeat() {
    if (!data) return;
    setError(null);
    setRepeating(true);
    try {
      const res = await Inspections.repeat(id, csrfToken);
      // Devices disposed of last time are deliberately left behind (chapter
      // 12). Saying so turns a shorter list from a suspected bug into a
      // decision the technician can see was made.
      if (res.disposed_skipped > 0) {
        toast.success(disposedNotice(res.disposed_skipped));
      }
      navigate(`/inspections/${res.inspection.id}`, { replace: false });
    } catch (err) {
      setError(offlineMessage(err, 'Opakovať sa nepodarilo.'));
    } finally {
      setRepeating(false);
    }
  }

  /**
   * Fill an empty draft from the previous inspection at this prevádzka
   * (chapter 12). The devices come across; their stav does not — the
   * technician enters this year's results themselves.
   */
  async function handleCarryOver() {
    if (!data) return;
    setError(null);
    setCarryingOver(true);
    try {
      const res = await Inspections.carryOver(id, csrfToken);
      setData((prev) =>
        prev
          ? { ...prev, inspection: res.inspection, items: res.items, carry_over: null }
          : prev,
      );
      toast.success(
        res.disposed_skipped > 0
          ? `Položky prevzaté. ${disposedNotice(res.disposed_skipped)}`
          : 'Položky z minulej kontroly prevzaté — stav zadaj nanovo.',
      );
    } catch (err) {
      setError(offlineMessage(err, 'Prevzatie položiek sa nepodarilo.'));
    } finally {
      setCarryingOver(false);
    }
  }

  /** Reload the documents list after a signature produced a new version. */
  async function refreshDocuments() {
    const docs = await Inspections.documents(id).catch(
      () => ({ items: [] as InspectionDocument[] }),
    );
    setDocuments(docs.items);
  }

  /**
   * Reopen a locked inspection for editing. The server deletes the issued
   * protocol, so the documents list is refetched alongside the inspection —
   * the PDF block goes back to offering "Generovať PDF protokol".
   */
  async function handleUnlock() {
    setError(null);
    setUnlocking(true);
    try {
      await Inspections.unlock(id, csrfToken);
      const [detail, docs] = await Promise.all([
        Inspections.show(id),
        Inspections.documents(id).catch(() => ({ items: [] as InspectionDocument[] })),
      ]);
      setData(detail);
      setDocuments(docs.items);
      setUnlockPrompt(false);
      toast.success('Kontrola odomknutá — pôvodný protokol bol zrušený.');
    } catch (err) {
      setError(offlineMessage(err, 'Kontrolu sa nepodarilo odomknúť.'));
    } finally {
      setUnlocking(false);
    }
  }

  async function handleCreateFollowUp(targetType: InspectionType) {
    if (!data) return;
    setError(null);
    setCreatingFollowUp(true);
    try {
      const res = await Inspections.createFollowUp(id, targetType, csrfToken);
      toast.success(res.created ? 'Koncept vytvorený' : 'Koncept už existoval — otváram ho');
      navigate(`/inspections/${res.inspection_id}`);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Koncept sa nepodarilo vytvoriť.');
    } finally {
      setCreatingFollowUp(false);
    }
  }

  async function handleGeneratePdf() {
    if (!data) return;
    setPdfError(null);
    setGenerating(true);
    try {
      const res = await Inspections.generatePdf(id, csrfToken, includePhotos);
      const [detail, docs] = await Promise.all([
        Inspections.show(id),
        Inspections.documents(id),
      ]);
      setData(detail);
      setDocuments(docs.items);
      window.open(documentDownloadUrl(res.document.id), '_blank', 'noopener');
    } catch (err) {
      setPdfError(offlineMessage(err, 'PDF sa nepodarilo vygenerovať.'));
    } finally {
      setGenerating(false);
    }
  }

  /**
   * Change the period on a draft (chapter 5).
   *
   * Reachable here and not only in Step 1 because "Opakovať" skips Step 1
   * entirely: the repeat inherits last year's period, and without this the
   * technician would have no way to change it before issuing the protocol.
   */
  async function handlePeriodicityChange(next: Periodicity) {
    if (!data) return;
    setSavingPeriodicity(true);
    try {
      const res = await Inspections.update(
        id,
        { periodicity_value: next.value, periodicity_unit: next.unit },
        csrfToken,
      );
      setData((prev) =>
        prev ? { ...prev, inspection: { ...prev.inspection, ...res.inspection } } : prev,
      );
    } catch (err) {
      if (handleOfflineSave(err, toast)) {
        setData((prev) =>
          prev
            ? {
                ...prev,
                inspection: {
                  ...prev.inspection,
                  periodicity_value: next.value,
                  periodicity_unit: next.unit,
                },
              }
            : prev,
        );
        return;
      }
      setError(err instanceof ApiError ? err.message : 'Periodicitu sa nepodarilo uložiť.');
    } finally {
      setSavingPeriodicity(false);
    }
  }

  async function handleDateChange(value: string) {
    if (!data || !value) return;
    setSavingDate(true);
    try {
      const res = await Inspections.update(id, { executed_on: value }, csrfToken);
      setData((prev) => (prev ? { ...prev, inspection: { ...prev.inspection, ...res.inspection } } : prev));
    } catch (err) {
      if (handleOfflineSave(err, toast)) {
        // Queued offline — reflect the new date locally (cache is already patched).
        setData((prev) => (prev ? { ...prev, inspection: { ...prev.inspection, executed_on: value } } : prev));
        return;
      }
      setError(err instanceof ApiError ? err.message : 'Dátum sa nepodarilo uložiť.');
    } finally {
      setSavingDate(false);
    }
  }

  async function handleDeleteItem(itemId: number) {
    const ok = await confirm({
      title: 'Odstrániť položku?',
      description: 'Položka bude odstránená z tejto kontroly.',
      confirmLabel: 'Odstrániť',
    });
    if (!ok) return;
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
  // An audit shows its sections and findings instead of a list of devices —
  // 109 checklist rows on the summary screen would be a page of scrolling
  // that tells the technician nothing (block 3 / chapter 15).
  const isAudit = isAuditType(i.type);
  const photoCount = items.reduce((n, it) => n + (it.photos?.length ?? 0), 0);

  return (
    <div className="flex flex-col gap-5">
      <Link
        to={sectionPathForType(i.type)}
        className="inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-700 self-start"
      >
        <ArrowLeft className="size-4" />
        Späť na zoznam kontrol
      </Link>

      <PendingSyncBanner resource="inspections" id={id} />

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
            {isDraft ? (
              <Badge tone="warn">Rozpracovaná</Badge>
            ) : (
              <InspectionStatusBadge inspection={i} />
            )}
            {!isDraft && (
              <Badge tone="neutral">
                <Lock className="size-3" />
                Uzamknutá
              </Badge>
            )}
          </p>
        </div>
        {!isDraft ? (
          <div className="flex shrink-0 items-center gap-2">
            <Button
              type="button"
              id="unlock-inspection"
              variant="warn"
              onClick={() => setUnlockPrompt((open) => !open)}
              aria-expanded={unlockPrompt}
              aria-controls="unlock-prompt"
              leftIcon={<Pencil className="size-4" />}
              title="Odomkne kontrolu na úpravy — vystavený protokol sa pritom zruší"
            >
              Upraviť
            </Button>
            <Button
              type="button"
              onClick={handleRepeat}
              loading={repeating}
              leftIcon={<Repeat className="size-4" />}
              title="Vytvorí novú kontrolu s tými istými položkami a prázdnym dátumom"
            >
              Opakovať
            </Button>
          </div>
        ) : null}
      </header>

      {!isDraft && !unlockPrompt && (
        <Card className="flex items-start gap-3 bg-ink-50 px-4 py-3">
          <span className="mt-0.5 grid size-7 shrink-0 place-items-center rounded-full bg-white text-ink-500">
            <Lock className="size-3.5" />
          </span>
          <p className="text-xs text-ink-600">
            <span className="font-semibold text-ink-800">Kontrola je uzamknutá.</span>{' '}
            Má vystavený PDF protokol, preto sa záznamy ani dátum už nedajú meniť.
            Pre opravu použi „Upraviť", pre nový termín „Opakovať".
          </p>
        </Card>
      )}

      {!isDraft && unlockPrompt && (
        <Card
          id="unlock-prompt"
          role="alertdialog"
          aria-labelledby="unlock-prompt-title"
          className="flex animate-fade-up flex-col gap-3 border-status-warn/40 bg-[var(--color-status-warn-bg)]/60 p-4"
        >
          <div className="flex items-start gap-3">
            <span className="mt-0.5 grid size-7 shrink-0 place-items-center rounded-full bg-white text-status-warn">
              <LockOpen className="size-4" />
            </span>
            <div className="min-w-0">
              <p id="unlock-prompt-title" className="text-sm font-semibold text-ink-900">
                Odomknúť kontrolu?
              </p>
              <p className="mt-1 text-xs text-ink-600">
                Kontrola je dokončená a má vystavený PDF protokol
                {documents[0] ? ` ${documents[0].number}` : ''}. Odomknutím sa
                protokol zruší a natrvalo odstráni — po úprave bude treba
                vygenerovať nový, ktorý dostane nové číslo.
              </p>
              <p className="mt-1 text-xs text-ink-600">
                Ak chceš len zopakovať kontrolu s novým dátumom, použi
                „Opakovať" — pôvodný protokol tak zostane zachovaný.
              </p>
            </div>
          </div>
          <div className="flex flex-col gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="secondary"
              disabled={unlocking}
              onClick={() => setUnlockPrompt(false)}
            >
              Zrušiť
            </Button>
            <Button
              type="button"
              variant="warn"
              loading={unlocking}
              onClick={handleUnlock}
              leftIcon={<LockOpen className="size-4" />}
            >
              Odomknúť a upraviť
            </Button>
          </div>
        </Card>
      )}

      {!isDraft && i.is_superseded && (
        <Card className="flex items-start gap-2.5 bg-ink-50 px-4 py-3 text-xs text-ink-600">
          <History className="mt-0.5 size-4 shrink-0 text-ink-400" />
          <p>
            Túto kontrolu už nahradila novšia kontrola tej istej prevádzky
            a typu, preto sa neoznačuje ako „po termíne". Stále ju môžeš
            zobraziť aj znova opakovať.
          </p>
        </Card>
      )}

      {/* Locked inspections keep showing the date, but in a neutral tone:
          nothing here is actionable any more, and the warn color belongs to
          the unlock prompt above. */}
      <Card
        className={cn(
          'flex flex-col gap-3 p-4',
          isDraft
            ? 'border-status-warn/30 bg-[var(--color-status-warn-bg)]/40'
            : 'bg-ink-50/60',
        )}
      >
        <div className="flex items-start gap-3">
          <span
            className={cn(
              'mt-0.5 grid size-7 shrink-0 place-items-center rounded-full bg-white',
              isDraft ? 'text-status-warn' : 'text-ink-500',
            )}
          >
            <CalendarDays className="size-4" />
          </span>
          <div className="flex-1">
            <p
              className={cn(
                'text-xs font-semibold uppercase tracking-wider',
                isDraft ? 'text-status-warn' : 'text-ink-500',
              )}
            >
              Dátum kontroly
            </p>
            <p className="mt-0.5 text-xs text-ink-600">
              {isDraft
                ? 'Zmeň dátum, ak opakuješ staršiu kontrolu — nový PDF protokol bude vystavený s týmto dátumom.'
                : 'Dátum, s ktorým bol vystavený PDF protokol.'}
            </p>
          </div>
        </div>
        <input
          type="date"
          value={localDate}
          disabled={!isDraft || savingDate}
          onChange={(e) => setLocalDate(e.target.value)}
          aria-label="Dátum kontroly"
          className={cn(
            'h-11 w-full min-w-0 appearance-none rounded-xl border bg-white px-3 text-sm font-medium text-ink-900 transition-colors',
            'disabled:bg-ink-50 disabled:text-ink-500',
            isDraft
              ? 'border-status-warn/40 hover:border-status-warn focus:border-status-warn focus:outline-none focus:ring-2 focus:ring-status-warn/30'
              : 'border-ink-200',
          )}
        />
        {isDraft && localDate && localDate !== savedDate && (
          <Button
            type="button"
            loading={savingDate}
            onClick={() => handleDateChange(localDate)}
            className="w-full"
          >
            Uložiť dátum
          </Button>
        )}

        <div className="border-t border-ink-100/70 pt-3">
          <p
            className={cn(
              'text-xs font-semibold uppercase tracking-wider',
              isDraft ? 'text-status-warn' : 'text-ink-500',
            )}
          >
            Periodicita
          </p>
          {isDraft ? (
            <div className="mt-2">
              <PeriodicityPicker
                type={i.type}
                value={periodicityOf(i)}
                executedOn={localDate || null}
                disabled={savingPeriodicity}
                onChange={handlePeriodicityChange}
              />
            </div>
          ) : (
            <p className="mt-0.5 text-sm text-ink-700">{periodicityLabel(periodicityOf(i))}</p>
          )}
        </div>
      </Card>

      {i.notes && (
        <Card className="flex items-start gap-3 px-4 py-3">
          <span className="mt-0.5 grid size-8 shrink-0 place-items-center rounded-lg bg-ink-100 text-ink-500">
            <NotebookPen className="size-4" />
          </span>
          <div className="min-w-0">
            <p className="text-xs font-semibold uppercase tracking-wider text-ink-500">
              Poznámky k prevádzke
            </p>
            <p className="mt-0.5 whitespace-pre-line text-sm text-ink-700">{i.notes}</p>
          </div>
        </Card>
      )}

      {module && items.length > 0 && <module.StatsBar items={items} />}

      {error && data && (
        <Card className="px-3 py-2 text-sm text-status-bad">{error}</Card>
      )}

      {isDraft && items.length === 0 && data.carry_over && (
        <CarryOverOfferCard
          offer={data.carry_over}
          busy={carryingOver}
          onCarryOver={handleCarryOver}
        />
      )}

      {isAudit ? (
        <AuditSummaryBlock inspectionId={id} canEdit={isDraft} />
      ) : items.length === 0 ? (
        <EmptyItems inspectionId={id} disabled={!isDraft} />
      ) : (
        <Card className="overflow-hidden">
          <div className="flex items-center justify-between gap-2 border-b border-ink-100 px-4 py-3">
            <span className="text-xs font-semibold uppercase tracking-wider text-ink-500">
              {i.type === 'poziarna_kniha' ? 'Záznamy' : 'Položky'}
            </span>
            <span className="text-xs text-ink-500">spolu {items.length}</span>
          </div>
          {module ? (
            <ul className="divide-y divide-ink-100">
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
                  {/* Chapter 12 — what this device scored last time, so the
                      technician can see at a glance which one was on tlaková
                      skúška a year ago. Display only. */}
                  {previousStatusOf(it.fields) && (
                    <div className="px-4 pb-2">
                      <PreviousStatusBadge type={i.type} fields={it.fields} />
                    </div>
                  )}
                  <ItemPhotoStrip photos={it.photos} />
                </li>
              ))}
            </ul>
          ) : (
            <p className="px-4 py-3 text-xs text-ink-500">
              Pre tento typ kontroly ešte nemáme zobrazenie položiek.
            </p>
          )}
          {isDraft && !(i.type === 'poziarna_kniha' && items.length >= 1) && (
            <Link
              to={`/inspections/${id}/items/new`}
              className="flex items-center justify-center gap-1.5 border-t border-ink-100 px-4 py-3 text-sm font-medium text-firol-600 transition-colors hover:bg-firol-50"
            >
              <Plus className="size-4" />
              {i.type === 'poziarna_kniha' ? 'Pridať záznam' : 'Pridať položku'}
            </Link>
          )}
        </Card>
      )}

      <FollowUpBlock
        type={i.type}
        items={items}
        sourceInspectionId={i.source_inspection_id}
        followUps={data.follow_ups ?? []}
        creating={creatingFollowUp}
        onCreate={handleCreateFollowUp}
      />

      <DocumentsBlock
        documents={documents}
        canGenerate={isDraft && items.length > 0 && !!i.executed_on}
        generating={generating}
        onGenerate={handleGeneratePdf}
        pdfError={pdfError}
        photoCount={photoCount}
        includePhotos={includePhotos}
        onIncludePhotosChange={setIncludePhotos}
        onSign={setSigningDocument}
      />

      {signingDocument && (
        <HandoverDialog
          open
          onClose={() => setSigningDocument(null)}
          documentId={signingDocument.id}
          documentNumber={signingDocument.number}
          documentType={i.type}
          companyId={i.company_id}
          facilityId={i.facility_id}
          defaultPlace={i.facility_name}
          defaultDate={i.executed_on}
          onSigned={refreshDocuments}
        />
      )}
    </div>
  );
}

/** "Z minulej kontroly boli 2 prístroje vyradené — neprenášam ich." */
function disposedNotice(n: number): string {
  const word = n === 1 ? 'položka bola vyradená' : n < 5 ? 'položky boli vyradené' : 'položiek bolo vyradených';
  return `Z minulej kontroly ${n} ${word} — neprenášam ich.`;
}

/**
 * "Prevziať položky z poslednej kontroly" — block 1 / chapter 12, and the
 * single biggest time saver in the field: a technician standing in a boiler
 * room does not retype forty extinguishers they already typed a year ago.
 *
 * What travels is identification. Stav, poznámky, fotky and nedostatky start
 * empty, because carrying a verdict forward would let a protocol claim
 * something nobody checked this time.
 */
function CarryOverOfferCard({
  offer,
  busy,
  onCarryOver,
}: {
  offer: NonNullable<InspectionDetail['carry_over']>;
  busy: boolean;
  onCarryOver: () => void;
}) {
  const when = offer.executed_on
    ? new Date(`${offer.executed_on}T00:00:00`).toLocaleDateString('sk-SK')
    : null;
  return (
    <Card className="flex flex-col gap-3 border-firol-200 bg-firol-50/60 p-4">
      <div className="flex items-start gap-3">
        <span className="mt-0.5 grid size-8 shrink-0 place-items-center rounded-xl bg-white text-firol-600">
          <CopyPlus className="size-4" />
        </span>
        <div className="min-w-0">
          <p className="text-sm font-semibold text-ink-900">
            Prevziať položky z poslednej kontroly{when ? ` (${when})` : ''}?
          </p>
          <p className="mt-1 text-xs text-ink-600">
            Prenesie sa {offer.item_count}{' '}
            {offer.item_count === 1 ? 'položka' : offer.item_count < 5 ? 'položky' : 'položiek'} aj
            s označením a umiestnením. Stav, poznámky a fotky zadáš nanovo — pri
            každej položke uvidíš, aký stav mala minule.
            {offer.disposed > 0 && ` ${disposedNotice(offer.disposed)}`}
          </p>
        </div>
      </div>
      <Button
        type="button"
        className="self-start"
        loading={busy}
        onClick={onCarryOver}
        leftIcon={<CopyPlus className="size-4" />}
      >
        Prevziať položky
      </Button>
    </Card>
  );
}

/** Plural forms for "N prístrojov má stav …". */
function deviceCountPhrase(n: number): string {
  if (n === 1) return '1 prístroj má';
  if (n < 5) return `${n} prístroje majú`;
  return `${n} prístrojov má`;
}

/**
 * Linked protocols (change request 2.1). Two things live here:
 *  - a "created from" back-link when this inspection is itself a follow-up draft;
 *  - existing follow-up drafts spawned from this inspection, and — when the
 *    items qualify — offers to create them: PHP → Oprava/TS (status TS) and
 *    PHP → Vyraďovací protokol (status V), Hydranty → TS hadíc.
 *
 * A PHP inspection can qualify for both of its follow-ups at once, so the
 * offers are a list rather than a single one.
 */
function FollowUpBlock({
  type,
  items,
  sourceInspectionId,
  followUps,
  creating,
  onCreate,
}: {
  type: InspectionType;
  items: InspectionDetail['items'];
  sourceInspectionId: number | null;
  followUps: NonNullable<InspectionDetail['follow_ups']>;
  creating: boolean;
  onCreate: (targetType: InspectionType) => void;
}) {
  const candidates: { targetType: InspectionType; qualifying: number; offerText: string }[] = [];
  if (type === 'php') {
    const forTest = items.filter((it) => it.fields.status === 'TS').length;
    candidates.push({
      targetType: 'oprava_ts_php',
      qualifying: forTest,
      offerText: `${deviceCountPhrase(forTest)} stav „Tlaková skúška"`,
    });
    const forDisposal = items.filter((it) => it.fields.status === 'V').length;
    candidates.push({
      targetType: 'vyradenie',
      qualifying: forDisposal,
      offerText: `${deviceCountPhrase(forDisposal)} stav „Vyradený"`,
    });
  } else if (type === 'hydranty') {
    candidates.push({
      targetType: 'ts_hadic',
      qualifying: items.length,
      offerText: `${items.length} ${items.length === 1 ? 'kontrolovaný hydrant' : 'kontrolovaných hydrantov'}`,
    });
  }

  // Don't offer a follow-up that already exists for this source.
  const offers = candidates.filter(
    (c) => c.qualifying > 0 && !followUps.some((f) => f.type === c.targetType),
  );

  if (!sourceInspectionId && followUps.length === 0 && offers.length === 0) return null;

  return (
    <Card className="overflow-hidden">
      <div className="flex items-center gap-3 border-b border-ink-100 px-4 py-3">
        <div className="grid size-9 place-items-center rounded-2xl bg-firol-50 text-firol-600">
          <GitBranch className="size-4" />
        </div>
        <div className="min-w-0 flex-1">
          <h3 className="text-sm font-semibold text-ink-900">Prepojené protokoly</h3>
          <p className="text-xs text-ink-500">Nadväzujúce kontroly vytvorené z tejto kontroly.</p>
        </div>
      </div>

      <div className="flex flex-col gap-3 px-4 py-3">
        {sourceInspectionId && (
          <Link
            to={`/inspections/${sourceInspectionId}`}
            className="inline-flex items-center gap-1.5 text-xs text-ink-500 hover:text-firol-600"
          >
            <Link2 className="size-3.5" />
            Tento koncept vznikol z inej kontroly — zobraziť zdroj
          </Link>
        )}

        {followUps.map((f) => (
          <Link
            key={f.id}
            to={`/inspections/${f.id}`}
            className="flex items-center gap-3 rounded-xl border border-ink-100 px-3 py-2.5 transition-colors hover:bg-ink-50"
          >
            <span className="grid size-8 shrink-0 place-items-center rounded-lg bg-firol-50 text-firol-600">
              <ArrowRight className="size-4" />
            </span>
            <div className="min-w-0 flex-1">
              <p className="truncate text-sm font-medium text-ink-900">{INSPECTION_TYPE_LABELS[f.type]}</p>
              <p className="text-xs text-ink-500">
                {f.status === 'draft' ? 'Rozpracovaný koncept' : 'Dokončená kontrola'}
              </p>
            </div>
          </Link>
        ))}

        {offers.map((offer) => (
          <div key={offer.targetType} className="rounded-xl border border-firol-200 bg-firol-50/60 p-3.5">
            <p className="text-sm text-ink-800">
              {offer.offerText} — vytvoriť koncept{' '}
              <strong>{INSPECTION_TYPE_LABELS[offer.targetType]}</strong> s týmito položkami?
            </p>
            <Button
              type="button"
              className="mt-3"
              loading={creating}
              onClick={() => onCreate(offer.targetType)}
              leftIcon={<Plus className="size-4" />}
            >
              Vytvoriť koncept
            </Button>
          </div>
        ))}
      </div>
    </Card>
  );
}

function DocumentsBlock({
  documents,
  canGenerate,
  generating,
  onGenerate,
  pdfError,
  photoCount,
  includePhotos,
  onIncludePhotosChange,
  onSign,
}: {
  documents: InspectionDocument[];
  canGenerate: boolean;
  generating: boolean;
  onGenerate: () => void;
  pdfError?: string | null;
  photoCount: number;
  includePhotos: boolean;
  onIncludePhotosChange: (value: boolean) => void;
  onSign: (doc: InspectionDocument) => void;
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
            ? 'Po vygenerovaní sa kontrola uzamkne a dostane svoje číslo (napr. PHP-2026-001).'
            : 'Pre vygenerovanie pridaj aspoň jednu položku a skontroluj dátum kontroly.'}
        </p>
        {photoCount > 0 && (
          <IncludePhotosToggle
            photoCount={photoCount}
            checked={includePhotos}
            disabled={generating}
            onChange={onIncludePhotosChange}
          />
        )}
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
        {pdfError && (
          <p className="text-xs text-status-bad">{pdfError}</p>
        )}
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
            Vygenerované {documents.length}{' '}
            {documents.length === 1 ? 'protokol' : 'protokoly'}.
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
                <p className="font-mono text-sm text-ink-900">
                  {doc.number}
                  {doc.version > 1 && (
                    <span className="ml-1.5 text-xs font-sans text-ink-400">
                      verzia {doc.version}
                    </span>
                  )}
                </p>
                <p className="text-xs text-ink-500">
                  Vystavený {new Date(doc.generated_at.replace(' ', 'T')).toLocaleString('sk-SK')}
                </p>
              </div>
              <Download className="size-4 shrink-0 text-ink-400" />
            </a>
            <HandoverRow doc={doc} onSign={() => onSign(doc)} />
            <EmailDocumentForm documentId={doc.id} documentNumber={doc.number} />
          </li>
        ))}
      </ul>
    </Card>
  );
}

/**
 * Whether the client has taken this protocol over on the screen (chapter 13).
 *
 * An unsigned protocol is a finished protocol: printing it and collecting the
 * signature on paper is ordinary practice, and the PDF carries an empty line
 * for exactly that. So this row offers the signature rather than demanding it.
 */
function HandoverRow({
  doc,
  onSign,
}: {
  doc: InspectionDocument;
  onSign: () => void;
}) {
  if (doc.handover) {
    return (
      <div className="flex items-start gap-2 border-t border-ink-100 bg-[var(--color-status-ok-bg)]/40 px-4 py-2.5 text-xs text-ink-600">
        <PenLine className="mt-0.5 size-3.5 shrink-0 text-status-ok" />
        <p>
          Prevzal <span className="font-medium text-ink-800">{doc.handover.fullname}</span>
          {doc.handover.role_title && ` — ${doc.handover.role_title}`},{' '}
          {doc.handover.place},{' '}
          {new Date(`${doc.handover.signed_on}T00:00:00`).toLocaleDateString('sk-SK')}
        </p>
      </div>
    );
  }
  return (
    <div className="flex items-center justify-between gap-2 border-t border-ink-100 px-4 py-2.5">
      <p className="text-xs text-ink-500">
        Nepodpísané — protokol sa dá odovzdať aj na podpis po vytlačení.
      </p>
      <Button type="button" size="sm" variant="secondary" onClick={onSign} leftIcon={<PenLine className="size-3.5" />}>
        Dať podpísať
      </Button>
    </div>
  );
}

/**
 * "Priložiť fotodokumentáciu" (change request 2.2). Default on, so the common
 * case is one tap; unticking produces the protocol without the appendix, which
 * is exactly how it looked before photos existed.
 */
function IncludePhotosToggle({
  photoCount,
  checked,
  disabled,
  onChange,
}: {
  photoCount: number;
  checked: boolean;
  disabled: boolean;
  onChange: (value: boolean) => void;
}) {
  return (
    <label
      className={cn(
        'flex w-full max-w-sm cursor-pointer items-center gap-3 rounded-2xl border px-3 py-2.5 text-left',
        'transition-all duration-200',
        checked
          ? 'border-firol-300 bg-firol-50'
          : 'border-ink-200 bg-white hover:border-ink-300',
        disabled && 'pointer-events-none opacity-60',
      )}
    >
      <input
        type="checkbox"
        checked={checked}
        disabled={disabled}
        onChange={(e) => onChange(e.target.checked)}
        className="size-4 shrink-0 accent-firol-500"
      />
      <span className="min-w-0 flex-1">
        <span className="flex items-center gap-1.5 text-sm font-medium text-ink-900">
          <Images className="size-3.5 text-firol-500" />
          Priložiť fotodokumentáciu
        </span>
        <span className="mt-0.5 block text-xs text-ink-500">
          {photoCount} {photoCount === 1 ? 'fotka' : photoCount < 5 ? 'fotky' : 'fotiek'} ako
          samostatná príloha na konci protokolu.
        </span>
      </span>
    </label>
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
