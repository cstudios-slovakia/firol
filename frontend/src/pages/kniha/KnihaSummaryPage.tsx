import { useEffect, useMemo, useState } from 'react';
import { Link, Navigate, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Building, FileText, Images, Pencil, Trash2 } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useIsReadOnly } from '@/auth/useIsReadOnly';
import { useMemberRights } from '@/auth/useMemberRights';
import { Inspections, documentDownloadUrl, type InspectionDetail } from '@/api/inspections';
import { invoicingOf } from '@/api/invoicing';
import { Kniha, type KnihaContext } from '@/api/kniha';
import { ApiError } from '@/lib/api';
import { DISCARD_BOOK_ENTRY_DIALOG, discardDoneMessage, discardNeedsRefresh } from '@/lib/discard';
import { useCertMissingToast } from '@/lib/certMissing';
import { facilityNouns, isBytoveDruzstvo } from '@/lib/clientKind';
import { formatDateSk } from '@/lib/clientNoticeEmail';
import { cn } from '@/lib/cn';
import { useConfirm } from '@/lib/confirm';
import {
  composeText,
  composedDefects,
  fixedSentence,
  isKnihaV2,
  knihaDefects,
  knihaPath,
  parseKnihaDetails,
  sk3,
  validUntilLikeServer,
  zaznamKey,
  type ComposedBlock,
} from '@/lib/kniha';
import { offlineMessage } from '@/lib/offline';
import { entityPendingMutations } from '@/lib/pendingEntity';
import { useToast } from '@/lib/toast';
import { useOnlineStatus } from '@/lib/useOnlineStatus';
import { usePendingMutations } from '@/lib/usePendingMutations';
import { visitTrail } from '@/lib/visits';
import { Breadcrumb } from '@/components/ui/Breadcrumb';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { Skeleton } from '@/components/ui/Skeleton';
import { InvoicingBlock } from '@/components/InvoicingBlock';
import { PendingSyncBanner } from '@/components/PendingSyncBanner';
import { VisitContinueButton } from '@/components/VisitContinueButton';
import { StepBars } from '@/components/prehliadka/ui';
import { entrySynced } from '@/pages/kniha/entrySynced';
import { useKnihaDetailsSaver } from '@/pages/kniha/useKnihaDetailsSaver';

/**
 * Krok 3 — Súhrn (spec 4): what the entry does to the deadlines, the counts,
 * the whole composed text, Schválil, and „Generovať PDF protokol" — which
 * locks the entry and gives it its number. A draft can be discarded here.
 */
export function KnihaSummaryPage() {
  const { id: idStr } = useParams<{ id: string }>();
  const id = Number(idStr);
  const [detail, setDetail] = useState<InspectionDetail | null>(null);
  const [ctx, setCtx] = useState<KnihaContext | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    setDetail(null);
    (async () => {
      try {
        await entrySynced(id);
        const d = await Inspections.show(id);
        const c = isKnihaV2(d.inspection.details) ? await Kniha.context(d.inspection.company_id).catch(() => null) : null;
        if (cancelled) return;
        setDetail(d);
        setCtx(c);
      } catch (err) {
        if (!cancelled) setError(err instanceof ApiError ? err.message : 'Zápis sa nepodarilo načítať.');
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [id]);

  if (error) return <SimpleError message={error} to="/" />;
  if (!detail) return <SummarySkeleton />;
  if (!isKnihaV2(detail.inspection.details)) return <Navigate to={`/inspections/${id}`} replace />;
  if (detail.inspection.status !== 'draft') return <Navigate to={knihaPath(id)} replace />;
  return <SummaryForm key={id} id={id} detail={detail} ctx={ctx} onDetail={setDetail} />;
}

function SummaryForm({
  id,
  detail,
  ctx,
  onDetail,
}: {
  id: number;
  detail: InspectionDetail;
  ctx: KnihaContext | null;
  onDetail: (d: InspectionDetail) => void;
}) {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const certMissing = useCertMissingToast();
  const navigate = useNavigate();
  const isReadOnly = useIsReadOnly();
  const { canDeleteUkon } = useMemberRights();
  const pendingMutations = usePendingMutations();
  const online = useOnlineStatus();

  const i = detail.inspection;
  const initial = useMemo(() => parseKnihaDetails(i.details), [i.details]);
  const saver = useKnihaDetailsSaver(id, initial);
  const details = saver.details ?? initial;
  const canEdit = !isReadOnly;

  const [includePhotos, setIncludePhotos] = useState(true);
  const [generating, setGenerating] = useState(false);
  const [pdfError, setPdfError] = useState<string | null>(null);
  const [discarding, setDiscarding] = useState(false);

  const nouns = facilityNouns(i.company_client_type);
  const rows = i.facilities && i.facilities.length > 0
    ? i.facilities
    : [{ facility_id: i.facility_id, name: i.facility_name, periodicity_value: null, periodicity_unit: null, valid_until: null, is_superseded: false }];
  const names = new Map(rows.map((r) => [r.facility_id, r.name]));
  const defects = knihaDefects(detail.items);
  const photoCount = detail.items.reduce((n, it) => n + (it.photos?.length ?? 0), 0);
  const tickedKeys = new Set(details.zaznamy.map(zaznamKey));
  const formaOf = new Map(details.zaznamy.map((z) => [zaznamKey(z), z.forma]));
  const paragraphs = (ctx?.records ?? [])
    .filter((r) => tickedKeys.has(r.key))
    .map((r) => (formaOf.get(r.key) === 'odkaz' ? r.text_ref : r.text_full));
  const composed = composeText({
    sposob: details.sposob,
    uvod: details.uvod ?? '',
    fixedSentence: fixedSentence(i.executed_on, rows.map((r) => r.name), i.company_client_type),
    cinnosti: details.cinnosti,
    vlastne: details.vlastne_cinnosti,
    textZapisu: details.text_zapisu,
    paragraphs,
    doplnujuciText: details.doplnujuci_text,
    defects: composedDefects(defects, names, i.facility_id, i.executed_on),
    multi: rows.length > 1,
    zaver: details.zaver,
  });

  const missingPeriodicity = details.sposob === 1 && rows.some((r) => r.periodicity_value === null);
  // Only writes parked in the outbox block generating; a text still waiting for
  // the typing pause is written by generate() itself (saver.flush()).
  const unsynced = entityPendingMutations(pendingMutations, { resource: 'inspections', id }).length > 0;
  const blockedReason = !i.executed_on
    ? 'Doplň dátum zápisu v kroku 1.'
    : missingPeriodicity
      ? isBytoveDruzstvo(i.company_client_type)
        ? 'Vyber periodicitu pri každom bytovom dome v kroku 1.'
        : 'Vyber periodicitu pri každej prevádzke v kroku 1.'
      : null;

  async function generate() {
    setPdfError(null);
    setGenerating(true);
    try {
      await saver.flush();
      const res = await Inspections.generatePdf(id, csrfToken, photoCount > 0 ? includePhotos : undefined);
      window.open(documentDownloadUrl(res.document.id), '_blank', 'noopener');
      onDetail(await Inspections.show(id));
      navigate(knihaPath(id), { replace: true });
    } catch (err) {
      setGenerating(false);
      const kept = certMissing(err, {
        saved: 'Nič sa nestratilo — zápis je uložený ako rozpracovaný. Po doplnení čísla sa k nemu vráť a vygeneruj PDF.',
        returnLabel: 'Späť na zápis',
      });
      if (!kept) setPdfError(offlineMessage(err, 'PDF sa nepodarilo vygenerovať.'));
    }
  }

  async function discard() {
    // Online only, as the prehliadka (DiscardPrehliadka): a discarded draft must
    // vanish at once, and an entry made offline would otherwise replay its
    // create, edits and the delete after the sync.
    if (!online) {
      toast.error('Zahodiť zápis sa dá len s pripojením na internet.');
      return;
    }
    const ok = await confirm(DISCARD_BOOK_ENTRY_DIALOG);
    if (!ok) return;
    setDiscarding(true);
    let applied: string | undefined;
    try {
      // A permanent delete never waits in the offline queue.
      applied = (await Inspections.archive(id, csrfToken, { requireOnline: true, expectDelete: true }))?.mode;
    } catch (err) {
      toast.error(
        err instanceof ApiError ? err.message : offlineMessage(err, 'Zahodiť zápis sa dá len s pripojením na internet.'),
      );
      setDiscarding(false);
      // Issued meanwhile (409, round 6): read it again and show the entry as it
      // is now, as generate() does, instead of the stale draft.
      if (discardNeedsRefresh(err)) {
        try {
          onDetail(await Inspections.show(id));
          navigate(knihaPath(id), { replace: true });
        } catch {
          // The toast above already said why; the screen stays as it was.
        }
      }
      return;
    }
    // The toast follows what the server did, not the cached `discard`.
    toast.success(applied === 'archive' ? discardDoneMessage('archive') : 'Zápis natrvalo zmazaný');
    navigate(
      i.visit_id !== null ? `/visits/${i.visit_id}` : rows.length > 1 ? `/companies/${i.company_id}` : `/facilities/${i.facility_id}`,
      { replace: true },
    );
  }

  return (
    <div className="flex flex-col gap-5">
      {i.visit_id !== null ? (
        <Breadcrumb
          items={[
            ...visitTrail({ id: i.visit_id, companyName: i.company_name, date: i.executed_on }),
            { label: 'Požiarna kniha', to: knihaPath(id) },
            { label: 'Súhrn' },
          ]}
        />
      ) : (
        <Link to={knihaPath(id)} className="inline-flex items-center gap-1 self-start text-sm text-ink-500 transition-colors hover:text-ink-700">
          <ArrowLeft className="size-4" />
          Späť na zápis
        </Link>
      )}

      <header className="animate-fade-up">
        <p className="text-xs font-semibold uppercase tracking-wider text-firol-500">Krok 3 z 3 · Súhrn</p>
        <h1 className="mt-1 text-xl font-semibold tracking-tight text-ink-900">Zápis do požiarnej knihy</h1>
        <p className="mt-1 flex flex-wrap items-center gap-x-1.5 text-xs text-ink-500">
          <Building className="size-3" />
          {i.company_name}
          {i.executed_on && (
            <>
              <span className="text-ink-300">·</span>
              {formatDateSk(i.executed_on)}
            </>
          )}
        </p>
        <StepBars step={3} />
      </header>

      <PendingSyncBanner resource="inspections" id={id} />

      {/* Spôsob and what it does to the deadlines */}
      <Card className="animate-fade-up flex flex-col gap-2.5 p-4">
        <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-500">Spôsob</h2>
        <p className="text-sm font-semibold text-ink-900">
          {details.sposob === 1 ? 'Zápis je zároveň preventívkou' : 'Preventívky sú priložené'}
        </p>
        {details.sposob === 1 ? (
          <ul className="flex flex-col gap-1 text-sm text-ink-700">
            {rows.map((r) => {
              const until = r.periodicity_value !== null ? validUntilLikeServer(i.executed_on, r.periodicity_value) : null;
              return (
                <li key={r.facility_id} className="min-w-0 break-words">
                  {r.name} —{' '}
                  {until ? (
                    <>
                      ďalšia prehliadka do <b className="font-semibold text-ink-900">{formatDateSk(until)}</b>
                    </>
                  ) : (
                    <span className="text-[var(--color-status-warn)]">bez periodicity</span>
                  )}
                </li>
              );
            })}
          </ul>
        ) : (
          <p className="text-sm text-ink-700">Termín sa nemení.</p>
        )}
      </Card>

      {/* Counts */}
      <Card className="animate-fade-up grid grid-cols-3 gap-2 p-4">
        <Count n={rows.length} label={sk3(rows.length, nouns.cases.nom, nouns.pluralNom, nouns.pluralGen)} />
        <Count n={paragraphs.length} label={sk3(paragraphs.length, 'príloha', 'prílohy', 'príloh')} />
        <Count
          n={defects.length}
          label={sk3(defects.length, 'nedostatok', 'nedostatky', 'nedostatkov')}
          tone={defects.length > 0 ? 'bad' : 'ok'}
        />
      </Card>

      {/* The composed text */}
      <Card className="animate-fade-up flex flex-col gap-3 p-4">
        <div className="flex items-center justify-between gap-3">
          <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-500">Zápis</h2>
          <Link to={knihaPath(id)} className="shrink-0">
            <Button type="button" id="kn-sum-edit" variant="secondary" size="sm" leftIcon={<Pencil className="size-3.5" />}>
              Upraviť
            </Button>
          </Link>
        </div>
        <ComposedText blocks={composed} />
      </Card>

      {/* Schválil */}
      <Card className="animate-fade-up flex flex-col gap-3 p-4">
        <Field label="Schválil za klienta">
          {(p) => (
            <Input
              {...p}
              id="kn-schvalil"
              value={details.schvalil ?? ''}
              disabled={!canEdit}
              maxLength={191}
              onChange={(e) => {
                const v = e.target.value;
                saver.update((d) => ({ ...d, schvalil: v }), { typing: true });
              }}
              onBlur={saver.commit}
            />
          )}
        </Field>
      </Card>

      {/* PDF */}
      <Card className="animate-fade-up flex flex-col items-center gap-2.5 px-5 py-6 text-center">
        <div className="grid size-11 place-items-center rounded-2xl bg-firol-50 text-firol-500">
          <FileText className="size-5" />
        </div>
        <h2 className="text-sm font-semibold text-ink-900">PDF protokol</h2>
        <p className="max-w-sm text-xs text-ink-500">
          {blockedReason ?? 'Po vygenerovaní sa zápis uzamkne a dostane číslo.'}
        </p>
        {photoCount > 0 && (
          <label
            className={cn(
              'flex w-full max-w-sm cursor-pointer items-center gap-3 rounded-2xl border px-3 py-2.5 text-left transition-all duration-200',
              includePhotos ? 'border-firol-300 bg-firol-50' : 'border-ink-200 bg-white hover:border-ink-300',
              generating && 'pointer-events-none opacity-60',
            )}
          >
            <input
              type="checkbox"
              checked={includePhotos}
              disabled={generating}
              onChange={(e) => setIncludePhotos(e.target.checked)}
              className="size-4 shrink-0 accent-firol-500"
            />
            <span className="min-w-0 flex-1">
              <span className="flex items-center gap-1.5 text-sm font-medium text-ink-900">
                <Images className="size-3.5 text-firol-500" />
                Priložiť fotodokumentáciu
              </span>
              <span className="mt-0.5 block text-xs text-ink-500">
                {photoCount} {sk3(photoCount, 'fotka', 'fotky', 'fotiek')} ako samostatná príloha na konci protokolu.
              </span>
            </span>
          </label>
        )}
        {unsynced && canEdit && (
          <p className="w-full max-w-sm rounded-2xl bg-[var(--color-status-warn-bg)] px-4 py-3 text-xs font-medium text-[var(--color-status-warn)]">
            Niektoré zmeny sa ešte neodoslali na server. Generovať sa dá, keď budeš online a všetko sa uloží.
          </p>
        )}
        {canEdit && (
          <Button
            type="button"
            id="kn-generate"
            loading={generating}
            disabled={blockedReason !== null || unsynced}
            leftIcon={<FileText className="size-4" />}
            className="bg-status-bad hover:brightness-110"
            onClick={() => void generate()}
          >
            {i.visit_id !== null ? 'Generovať PDF protokol teraz' : 'Generovať PDF protokol'}
          </Button>
        )}
        {canEdit && i.visit_id !== null && (
          <VisitContinueButton visitId={i.visit_id} ukon={{ inspection_id: id }} disabled={blockedReason !== null} />
        )}
        {pdfError && (
          <p role="alert" className="text-xs text-status-bad">
            {pdfError}
          </p>
        )}
      </Card>

      <InvoicingBlock
        target="inspections"
        id={id}
        value={invoicingOf(i)}
        disabled={isReadOnly}
        onChange={(next) => onDetail({ ...detail, inspection: { ...detail.inspection, ...next } })}
      />

      <div className="flex flex-col items-center gap-2">
        {/* A draft never issued is deleted for good; one issued before (opened
            again with „Upraviť") or one something was made from is refused by
            the server, which says so in `discard`. */}
        {canEdit && i.discard !== null && i.discard !== 'archive' && canDeleteUkon(i, i.inspector_user_id) && (
          <Button
            type="button"
            id="kn-discard"
            variant="danger-outline"
            size="sm"
            loading={discarding}
            leftIcon={<Trash2 className="size-4" />}
            onClick={() => void discard()}
          >
            Zahodiť zápis
          </Button>
        )}
      </div>
    </div>
  );
}

function Count({ n, label, tone }: { n: number; label: string; tone?: 'ok' | 'bad' }) {
  return (
    <div className="min-w-0">
      <p
        className={cn(
          'text-xl font-bold tabular-nums text-ink-900',
          tone === 'bad' && 'text-[var(--color-status-bad)]',
          tone === 'ok' && 'text-[var(--color-status-ok)]',
        )}
      >
        {n}
      </p>
      <p className="truncate text-xs text-ink-500">{label}</p>
    </div>
  );
}

/** The entry as it prints, section by section (lib/kniha.ts composeText). */
export function ComposedText({ blocks }: { blocks: ComposedBlock[] }) {
  if (blocks.length === 0) return <p className="text-sm text-ink-400">—</p>;
  return (
    <div className="flex flex-col gap-2">
      {blocks.map((b, n) => {
        if (b.kind === 'band') {
          return (
            <h3
              key={n}
              className={cn('text-[11px] font-semibold uppercase tracking-wide text-firol-600', n > 0 && 'mt-2 border-t border-ink-100 pt-3')}
            >
              {b.title}
            </h3>
          );
        }
        if (b.kind === 'p') {
          return (
            <p key={n} className="whitespace-pre-line break-words text-sm leading-relaxed text-ink-800">
              {b.text}
            </p>
          );
        }
        if (b.kind === 'list') {
          return (
            <div key={n} className="text-sm leading-relaxed text-ink-800">
              <p className="font-semibold">{b.heading}</p>
              <ul className="mt-1 list-disc pl-5 marker:text-ink-400">
                {b.items.map((it) => (
                  <li key={it} className="break-words">
                    {it}
                  </li>
                ))}
              </ul>
            </div>
          );
        }
        return (
          <ol key={n} className="flex flex-col gap-2">
            {b.rows.map((d) => (
              <li key={d.n} className="flex gap-2 text-sm text-ink-800">
                <span className="w-5 shrink-0 font-semibold tabular-nums text-ink-500">{d.n}.</span>
                <span className="min-w-0 flex-1">
                  <span className="block break-words font-semibold">{d.title}</span>
                  {(b.multi || d.deadline) && (
                    <span className="block text-xs text-ink-500">
                      {[b.multi ? d.facility : '', d.deadline ? (d.deadline === 'bezodkladne' ? d.deadline : `do ${d.deadline}`) : '']
                        .filter((v) => v !== '')
                        .join(' · ')}
                    </span>
                  )}
                  {d.removedOnSite && <span className="block text-xs text-ink-500">Zistený a odstránený na mieste.</span>}
                  {d.reasonGone !== null && (
                    <span className="block break-words text-xs text-ink-500">
                      {d.reasonGone !== '' ? `Dôvod zanikol: ${d.reasonGone}` : 'Dôvod zanikol.'}
                    </span>
                  )}
                </span>
              </li>
            ))}
          </ol>
        );
      })}
    </div>
  );
}

function SimpleError({ message, to }: { message: string; to: string }) {
  return (
    <div className="flex flex-col gap-4">
      <Link to={to} className="inline-flex items-center gap-1 self-start text-sm text-ink-500 transition-colors hover:text-ink-700">
        <ArrowLeft className="size-4" />
        Späť
      </Link>
      <Card className="px-4 py-3 text-sm text-status-bad">{message}</Card>
    </div>
  );
}

function SummarySkeleton() {
  return (
    <div className="flex flex-col gap-4" aria-busy>
      <Skeleton className="h-4 w-32" />
      <Skeleton className="h-7 w-64" />
      <Skeleton className="h-24 w-full rounded-2xl" />
      <Skeleton className="h-48 w-full rounded-2xl" />
    </div>
  );
}
