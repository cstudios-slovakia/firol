import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, Navigate, useNavigate, useParams } from 'react-router-dom';
import {
  ArrowLeft,
  Building,
  Camera,
  Check,
  ChevronRight,
  Download,
  FileText,
  Lock,
  LockOpen,
  Pencil,
  Plus,
  Repeat,
  Settings2,
  Trash2,
} from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useIsReadOnly } from '@/auth/useIsReadOnly';
import { useMemberRights } from '@/auth/useMemberRights';
import {
  Inspections,
  documentDownloadUrl,
  type InspectionDetail,
  type InspectionDocument,
  type InspectionFacility,
} from '@/api/inspections';
import { invoicingOf } from '@/api/invoicing';
import { Kniha, type KnihaContext } from '@/api/kniha';
import { ApiError } from '@/lib/api';
import { useCertMissingToast } from '@/lib/certMissing';
import { clientNouns, facilityNouns } from '@/lib/clientKind';
import { formatDateSk } from '@/lib/clientNoticeEmail';
import { cn } from '@/lib/cn';
import {
  ACTIVITIES_HEADING_NOW,
  KNIHA_TYPE,
  NA_ZAVER,
  appendSentence,
  composeText,
  composedDefects,
  deadlineText,
  fixedSentence,
  isKnihaV2,
  knihaDefectNewPath,
  knihaDefectPath,
  knihaDefects,
  knihaStep1Path,
  knihaSummaryPath,
  parseKnihaDetails,
  repeatPath,
  type KnihaDetails,
} from '@/lib/kniha';
import { offlineMessage } from '@/lib/offline';
import { useToast } from '@/lib/toast';
import { visitTrail } from '@/lib/visits';
import { Badge } from '@/components/ui/Badge';
import { Breadcrumb } from '@/components/ui/Breadcrumb';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Skeleton } from '@/components/ui/Skeleton';
import { EmailDocumentForm } from '@/components/EmailDocumentForm';
import { InvoicingBlock } from '@/components/InvoicingBlock';
import { PendingSyncBanner } from '@/components/PendingSyncBanner';
import { VisitNextUkon } from '@/components/VisitNextUkon';
import { CheckRow, Chip, StepBars, TEXTAREA_CLASS } from '@/components/prehliadka/ui';
import { KnihaRecordsBlock } from '@/pages/kniha/KnihaRecordsBlock';
import { ComposedText } from '@/pages/kniha/KnihaSummaryPage';
import { entrySynced } from '@/pages/kniha/entrySynced';
import { useKnihaDetailsSaver } from '@/pages/kniha/useKnihaDetailsSaver';

/** The entry's prevádzky; an entry cached before it had rows falls back to its one prevádzka. */
function entryFacilities(d: InspectionDetail): InspectionFacility[] {
  const i = d.inspection;
  return i.facilities && i.facilities.length > 0
    ? i.facilities
    : [{ facility_id: i.facility_id, name: i.facility_name, periodicity_value: null, periodicity_unit: null, valid_until: null, is_superseded: false }];
}

/**
 * /kniha/:id — a draft is Krok 2 (spec 4): spôsob 1 Úvod → Vykonané činnosti
 * → Priložiť záznamy → Nedostatky → Doplňujúci text → Na záver; spôsob 2
 * Text zápisu → Priložiť záznamy → Nedostatky → Na záver. A finalized entry is
 * read-only: the issued text, its document, „Upraviť" and „Opakovať". No
 * signing anywhere (spec 2 rule 7). An old-format entry goes to the generic page.
 */
export function KnihaEntryPage() {
  const { id: idStr } = useParams<{ id: string }>();
  const id = Number(idStr);
  const [detail, setDetail] = useState<InspectionDetail | null>(null);
  const [ctx, setCtx] = useState<KnihaContext | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    await entrySynced(id);
    const d = await Inspections.show(id);
    const c = isKnihaV2(d.inspection.details) ? await Kniha.context(d.inspection.company_id).catch(() => null) : null;
    setDetail(d);
    setCtx(c);
    return d;
  }, [id]);

  useEffect(() => {
    setDetail(null);
    load().catch((err: unknown) => setError(err instanceof ApiError ? err.message : 'Zápis sa nepodarilo načítať.'));
  }, [load]);

  if (error) {
    return (
      <div className="flex flex-col gap-4">
        <Link to="/" className="inline-flex items-center gap-1 self-start text-sm text-ink-500 transition-colors hover:text-ink-700">
          <ArrowLeft className="size-4" />
          Späť
        </Link>
        <Card className="px-4 py-3 text-sm text-status-bad">{error}</Card>
      </div>
    );
  }
  if (!detail) return <EntrySkeleton />;
  if (detail.inspection.type !== KNIHA_TYPE || !isKnihaV2(detail.inspection.details)) {
    return <Navigate to={`/inspections/${id}`} replace />;
  }
  if (detail.inspection.status !== 'draft') {
    return <IssuedEntry key={`issued-${id}`} id={id} detail={detail} onReload={load} onDetail={setDetail} />;
  }
  return <DraftEntry key={`draft-${id}`} id={id} detail={detail} ctx={ctx} />;
}

/** Where „Späť" / „Prerušiť" leads: the visit, the one prevádzka, or the client. */
function leavePath(d: InspectionDetail): string {
  const i = d.inspection;
  if (i.visit_id !== null) return `/visits/${i.visit_id}`;
  return entryFacilities(d).length > 1 ? `/companies/${i.company_id}` : `/facilities/${i.facility_id}`;
}

function backLabel(d: InspectionDetail): string {
  const type = d.inspection.company_client_type;
  return `Späť na ${entryFacilities(d).length > 1 ? clientNouns(type).cases.acc : facilityNouns(type).cases.acc}`;
}

function BackNav({ detail, label }: { detail: InspectionDetail; label: string }) {
  const i = detail.inspection;
  if (i.visit_id !== null) {
    return (
      <Breadcrumb
        items={[...visitTrail({ id: i.visit_id, companyName: i.company_name, date: i.executed_on }), { label: 'Požiarna kniha' }]}
      />
    );
  }
  return (
    <Link to={leavePath(detail)} className="inline-flex items-center gap-1 self-start text-sm text-ink-500 transition-colors hover:text-ink-700">
      <ArrowLeft className="size-4" />
      {label}
    </Link>
  );
}

// ─── Krok 2 ─────────────────────────────────────────────────────────────────

function DraftEntry({ id, detail, ctx }: { id: number; detail: InspectionDetail; ctx: KnihaContext | null }) {
  const navigate = useNavigate();
  const isReadOnly = useIsReadOnly();
  const i = detail.inspection;
  const initial = useMemo(() => parseKnihaDetails(i.details), [i.details]);
  const saver = useKnihaDetailsSaver(id, initial);
  const details = saver.details ?? initial;
  const canEdit = !isReadOnly;
  const [leaving, setLeaving] = useState<'step1' | 'summary' | null>(null);

  const facilities = entryFacilities(detail);
  const multi = facilities.length > 1;
  const names = new Map(facilities.map((f) => [f.facility_id, f.name]));
  const defects = knihaDefects(detail.items);
  const sposob = details.sposob;

  const set = (change: (d: KnihaDetails) => KnihaDetails) => saver.update(change);
  const type = (change: (d: KnihaDetails) => KnihaDetails) => saver.update(change, { typing: true });

  // The step-1 PATCH sends the whole header, so what is typed here gets there first.
  async function go(to: 'step1' | 'summary') {
    setLeaving(to);
    await saver.flush();
    navigate(to === 'step1' ? knihaStep1Path(id) : knihaSummaryPath(id));
  }

  const activities = ctx?.activities ?? details.cinnosti;

  return (
    <div className="flex flex-col gap-5">
      <BackNav detail={detail} label={backLabel(detail)} />

      <header className="animate-fade-up">
        <p className="text-xs font-semibold uppercase tracking-wider text-firol-500">
          Krok 2 z 3 · {sposob === 1 ? 'Záznam činností' : 'Text a prílohy'}
        </p>
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
        <StepBars step={2} />
      </header>

      <PendingSyncBanner resource="inspections" id={id} />

      {/* What this entry is — one tap back to step 1. */}
      <Card className="animate-fade-up flex items-start gap-3 p-4">
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-1.5">
            <Badge tone="brand">Spôsob {sposob}</Badge>
            <span className="text-sm font-semibold text-ink-900">
              {sposob === 1 ? 'Zápis je zároveň preventívkou' : 'Preventívky sú priložené'}
            </span>
          </div>
          <p className="mt-1.5 break-words text-xs text-ink-500">{facilities.map((f) => f.name).join(', ')}</p>
        </div>
        {canEdit && (
          <Button
            type="button"
            id="kn-edit-step1"
            variant="secondary"
            size="sm"
            className="shrink-0"
            loading={leaving === 'step1'}
            leftIcon={<Settings2 className="size-4" />}
            onClick={() => void go('step1')}
          >
            Zmeniť
          </Button>
        )}
      </Card>

      {sposob === 1 ? (
        <>
          <Card className="animate-fade-up flex flex-col gap-2 p-4">
            <label htmlFor="kn-uvod" className="text-xs font-semibold uppercase tracking-wide text-ink-500">
              Úvod
            </label>
            <textarea
              id="kn-uvod"
              rows={4}
              maxLength={4000}
              disabled={!canEdit}
              value={details.uvod ?? ''}
              onChange={(e) => {
                const v = e.target.value;
                type((d) => ({ ...d, uvod: v }));
              }}
              onBlur={saver.commit}
              className={TEXTAREA_CLASS}
            />
          </Card>

          <Card className="animate-fade-up flex flex-col gap-3 p-4">
            <div>
              <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-500">Vykonané činnosti</h2>
              <p className="mt-1 text-sm text-ink-700">{ACTIVITIES_HEADING_NOW}</p>
            </div>
            <ul className="flex flex-col gap-2">
              {activities.map((a, n) => {
                const on = details.cinnosti.includes(a);
                return (
                  <li key={a}>
                    <CheckRow
                      id={`kn-act-${n}`}
                      checked={on}
                      disabled={!canEdit}
                      onToggle={() =>
                        set((d) => ({
                          ...d,
                          cinnosti: on
                            ? d.cinnosti.filter((c) => c !== a)
                            : activities.filter((x) => x === a || d.cinnosti.includes(x)),
                        }))
                      }
                      title={<span className="font-medium">{a}</span>}
                    />
                  </li>
                );
              })}
              {details.vlastne_cinnosti.map((v, n) => (
                <li
                  key={`own-${n}`}
                  className="animate-fade-in flex items-center gap-2 rounded-2xl border border-firol-300 bg-firol-50 py-1.5 pl-3.5 pr-1.5 transition-colors focus-within:ring-2 focus-within:ring-firol-200"
                >
                  <span aria-hidden className="grid size-5 shrink-0 place-items-center rounded-md bg-firol-500 text-white">
                    <Check className="size-3.5" strokeWidth={3} />
                  </span>
                  <input
                    id={`kn-own-${n}`}
                    aria-label="Vlastná činnosť"
                    value={v}
                    maxLength={200}
                    disabled={!canEdit}
                    autoFocus={v === '' && n === details.vlastne_cinnosti.length - 1}
                    placeholder="Vlastná činnosť"
                    onChange={(e) => {
                      const text = e.target.value;
                      type((d) => ({ ...d, vlastne_cinnosti: d.vlastne_cinnosti.map((x, k) => (k === n ? text : x)) }));
                    }}
                    onBlur={saver.commit}
                    className="min-w-0 flex-1 bg-transparent py-1.5 text-sm font-medium text-ink-900 placeholder:text-ink-400 focus:outline-none"
                  />
                  {canEdit && (
                    <button
                      type="button"
                      aria-label="Odstrániť vlastnú činnosť"
                      onClick={() => set((d) => ({ ...d, vlastne_cinnosti: d.vlastne_cinnosti.filter((_, k) => k !== n) }))}
                      className="grid size-9 shrink-0 place-items-center rounded-xl text-status-bad transition-colors duration-200 hover:bg-status-bad/10 active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300"
                    >
                      <Trash2 className="size-4" />
                    </button>
                  )}
                </li>
              ))}
            </ul>
            {canEdit && (
              <Button
                type="button"
                id="kn-add-own"
                variant="secondary"
                size="sm"
                className="self-start border-dashed"
                leftIcon={<Plus className="size-4" />}
                onClick={() => set((d) => ({ ...d, vlastne_cinnosti: [...d.vlastne_cinnosti, ''] }))}
              >
                Pridať vlastnú činnosť
              </Button>
            )}
          </Card>
        </>
      ) : (
        <Card className="animate-fade-up flex flex-col gap-2 p-4">
          <label htmlFor="kn-text" className="text-xs font-semibold uppercase tracking-wide text-ink-500">
            Text zápisu
          </label>
          <textarea
            id="kn-text"
            rows={4}
            maxLength={5000}
            disabled={!canEdit}
            value={details.text_zapisu}
            onChange={(e) => {
              const v = e.target.value;
              type((d) => ({ ...d, text_zapisu: v }));
            }}
            onBlur={saver.commit}
            className={TEXTAREA_CLASS}
          />
        </Card>
      )}

      {ctx ? (
        <KnihaRecordsBlock
          ctx={ctx}
          facilityIds={facilities.map((f) => f.facility_id)}
          zaznamy={details.zaznamy}
          disabled={!canEdit}
          onChange={(next) => set((d) => ({ ...d, zaznamy: next }))}
        />
      ) : (
        <Card className="flex flex-col gap-2 p-4">
          <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-500">Priložiť záznamy</h2>
          <p className="text-sm text-ink-500">Záznamy na priloženie sa nepodarilo načítať. Skús to znova, keď budeš online.</p>
        </Card>
      )}

      {/* Nedostatky */}
      <Card className="animate-fade-up flex flex-col gap-3 p-4">
        <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-500">
          Nedostatky{defects.length > 0 && ` · ${defects.length}`}
        </h2>
        {defects.length > 0 && (
          <ul className="flex flex-col gap-2">
            {defects.map(({ itemId, defect, photos }) => {
              const deadline = deadlineText(defect, i.executed_on);
              const body = (
                <div className="flex items-start gap-2">
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-1.5">
                      {multi && (
                        <span className="max-w-full truncate rounded-full bg-ink-100 px-2 py-0.5 text-xs font-medium text-ink-600">
                          {names.get(defect.facility_id ?? i.facility_id) ?? ''}
                        </span>
                      )}
                      {photos > 0 && (
                        <span className="inline-flex items-center gap-1 rounded-full bg-ink-100 px-2 py-0.5 text-xs font-medium text-ink-600">
                          <Camera className="size-3" />
                          {photos}
                        </span>
                      )}
                      {defect.status === 'odstraneny' && <Badge tone="ok">Odstránený na mieste</Badge>}
                      {defect.status === 'zanik' && <Badge>Dôvod zanikol</Badge>}
                    </div>
                    <p className="mt-1.5 break-words text-sm font-semibold text-ink-900">{defect.title}</p>
                    <p className="mt-0.5 break-words text-xs text-ink-500">
                      {[defect.where.text, deadline ? (deadline === 'bezodkladne' ? deadline : `do ${deadline}`) : '']
                        .filter((x) => x !== '')
                        .join(' · ')}
                    </p>
                  </div>
                  {canEdit && <ChevronRight className="mt-1 size-4 shrink-0 text-ink-300" />}
                </div>
              );
              const cls = 'block w-full rounded-2xl border border-ink-200 bg-white px-4 py-3 text-left';
              return (
                <li key={itemId}>
                  {canEdit ? (
                    <Link
                      to={knihaDefectPath(id, itemId)}
                      className={cn(
                        cls,
                        'animate-fade-in transition-[border-color,background-color,transform,box-shadow] duration-200 hover:border-firol-300 hover:bg-firol-50/40 hover:shadow-[var(--shadow-soft)] active:scale-[0.99] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300',
                      )}
                    >
                      {body}
                    </Link>
                  ) : (
                    <div className={cls}>{body}</div>
                  )}
                </li>
              );
            })}
          </ul>
        )}
        {canEdit && (
          <Link to={knihaDefectNewPath(id)}>
            <Button type="button" id="kn-add-defect" variant="secondary" className="w-full border-dashed" leftIcon={<Plus className="size-4" />}>
              Pridať nedostatok
            </Button>
          </Link>
        )}
      </Card>

      {sposob === 1 && (
        <Card className="animate-fade-up flex flex-col gap-2 p-4">
          <label htmlFor="kn-doplnujuci" className="text-xs font-semibold uppercase tracking-wide text-ink-500">
            Doplňujúci text
          </label>
          <textarea
            id="kn-doplnujuci"
            rows={3}
            maxLength={5000}
            disabled={!canEdit}
            value={details.doplnujuci_text}
            onChange={(e) => {
              const v = e.target.value;
              type((d) => ({ ...d, doplnujuci_text: v }));
            }}
            onBlur={saver.commit}
            className={TEXTAREA_CLASS}
          />
        </Card>
      )}

      {/* Na záver */}
      <Card className="animate-fade-up flex flex-col gap-2.5 p-4">
        <label htmlFor="kn-zaver" className="text-xs font-semibold uppercase tracking-wide text-ink-500">
          Na záver
        </label>
        <textarea
          id="kn-zaver"
          rows={3}
          maxLength={5000}
          disabled={!canEdit}
          value={details.zaver}
          onChange={(e) => {
            const v = e.target.value;
            type((d) => ({ ...d, zaver: v }));
          }}
          onBlur={saver.commit}
          className={TEXTAREA_CLASS}
        />
        {canEdit && (
          <div className="flex flex-wrap gap-2">
            {(
              [
                ['kn-zaver-bez', '+ Bez nedostatkov', NA_ZAVER[0]],
                ['kn-zaver-odstranene', '+ Nedostatky odstránené', NA_ZAVER[1]],
              ] as const
            ).map(([chipId, label, sentence]) => (
              <Chip
                key={chipId}
                id={chipId}
                dashed
                disabled={details.zaver.includes(sentence)}
                onClick={() => set((d) => ({ ...d, zaver: appendSentence(d.zaver, sentence) }))}
              >
                {label}
              </Chip>
            ))}
          </div>
        )}
      </Card>

      <div className="flex gap-2">
        <Button type="button" id="kn-interrupt" variant="secondary" className="flex-1" onClick={() => navigate(leavePath(detail))}>
          Prerušiť
        </Button>
        <Button
          type="button"
          id="kn-continue"
          className="flex-1"
          loading={leaving === 'summary'}
          rightIcon={<ChevronRight className="size-4" />}
          onClick={() => void go('summary')}
        >
          Pokračovať
        </Button>
      </div>
    </div>
  );
}

// ─── The issued entry ───────────────────────────────────────────────────────

function IssuedEntry({
  id,
  detail,
  onReload,
  onDetail,
}: {
  id: number;
  detail: InspectionDetail;
  onReload: () => Promise<InspectionDetail>;
  onDetail: (d: InspectionDetail) => void;
}) {
  const { csrfToken } = useAuth();
  const navigate = useNavigate();
  const toast = useToast();
  const certMissing = useCertMissingToast();
  const isReadOnly = useIsReadOnly();
  const { canDelete: canUnlock } = useMemberRights();
  const [documents, setDocuments] = useState<InspectionDocument[]>([]);
  const [unlockPrompt, setUnlockPrompt] = useState(false);
  const [unlocking, setUnlocking] = useState(false);
  const [repeating, setRepeating] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    Inspections.documents(id)
      .then((r) => {
        if (!cancelled) setDocuments(r.items);
      })
      .catch(() => undefined);
    return () => {
      cancelled = true;
    };
  }, [id]);

  const i = detail.inspection;
  const details = parseKnihaDetails(i.details);
  const issued = details.issued;
  const facilities = issued?.facilities.map((f) => ({ id: f.id, name: f.name })) ??
    entryFacilities(detail).map((f) => ({ id: f.facility_id, name: f.name }));
  const names = new Map(facilities.map((f) => [f.id, f.name]));
  const defects = knihaDefects(detail.items);
  const composed = composeText({
    sposob: details.sposob,
    uvod: details.uvod ?? '',
    fixedSentence: issued?.fixed_sentence ?? fixedSentence(i.executed_on, facilities.map((f) => f.name), i.company_client_type),
    cinnosti: details.cinnosti,
    vlastne: details.vlastne_cinnosti,
    textZapisu: details.text_zapisu,
    paragraphs: issued?.paragraphs.map((p) => p.text) ?? [],
    doplnujuciText: details.doplnujuci_text,
    defects: composedDefects(defects, names, i.facility_id, i.executed_on),
    multi: facilities.length > 1,
    zaver: details.zaver,
  });
  const schvalil = (issued?.schvalil ?? details.schvalil ?? '').trim();
  const number = documents[0]?.number ?? null;

  async function unlock() {
    setError(null);
    setUnlocking(true);
    try {
      await Inspections.unlock(id, csrfToken);
      toast.success('Zápis odomknutý — pôvodný protokol bol zrušený.');
      await onReload();
    } catch (err) {
      setError(offlineMessage(err, 'Zápis sa nepodarilo odomknúť.'));
      setUnlocking(false);
    }
  }

  async function repeat() {
    setError(null);
    setRepeating(true);
    try {
      const res = await Inspections.repeat(id, csrfToken);
      navigate(repeatPath(KNIHA_TYPE, res.inspection.id));
    } catch (err) {
      setRepeating(false);
      if (!certMissing(err, { returnLabel: 'Späť na zápis' })) setError(offlineMessage(err, 'Opakovať sa nepodarilo.'));
    }
  }

  return (
    <div className="flex flex-col gap-5">
      <BackNav detail={detail} label={backLabel(detail)} />

      <header className="flex flex-col gap-3 animate-fade-up">
        <div className="min-w-0">
          <p className="text-xs font-semibold uppercase tracking-wider text-firol-500">Požiarna kniha</p>
          <h1 className="mt-1 text-xl font-semibold tracking-tight text-ink-900">Zápis do požiarnej knihy</h1>
          <p className="mt-1 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs text-ink-500">
            <Building className="size-3" />
            <Link to={`/companies/${i.company_id}`} className="transition-colors hover:text-firol-600">
              {i.company_name}
            </Link>
            {i.executed_on && (
              <>
                <span className="text-ink-300">·</span>
                {formatDateSk(i.executed_on)}
              </>
            )}
            {number && (
              <>
                <span className="text-ink-300">·</span>
                <span className="font-mono text-ink-700">{number}</span>
              </>
            )}
            <Badge tone="neutral" className="ml-0.5">
              <Lock className="size-3" />
              Uzamknutý
            </Badge>
          </p>
        </div>
        {!isReadOnly && (
          <div className="flex gap-2">
            {canUnlock && (
              <Button
                type="button"
                id="kn-unlock"
                variant="warn"
                className="flex-1"
                aria-expanded={unlockPrompt}
                aria-controls="kn-unlock-prompt"
                leftIcon={<Pencil className="size-4" />}
                onClick={() => setUnlockPrompt((v) => !v)}
              >
                Upraviť
              </Button>
            )}
            <Button
              type="button"
              id="kn-repeat"
              className="flex-1"
              loading={repeating}
              leftIcon={<Repeat className="size-4" />}
              onClick={() => void repeat()}
            >
              Opakovať
            </Button>
          </div>
        )}
      </header>

      {unlockPrompt && (
        <Card
          id="kn-unlock-prompt"
          role="alertdialog"
          aria-labelledby="kn-unlock-title"
          className="flex animate-fade-up flex-col gap-3 border-status-warn/40 bg-[var(--color-status-warn-bg)]/60 p-4"
        >
          <div className="flex items-start gap-3">
            <span className="mt-0.5 grid size-7 shrink-0 place-items-center rounded-full bg-white text-status-warn">
              <LockOpen className="size-4" />
            </span>
            <div className="min-w-0">
              <p id="kn-unlock-title" className="text-sm font-semibold text-ink-900">
                Odomknúť zápis?
              </p>
              <p className="mt-1 text-xs text-ink-600">
                Vystavený protokol{number ? ` ${number}` : ''} sa zruší. Po úprave treba vygenerovať nový, ktorý dostane nové číslo.
              </p>
            </div>
          </div>
          <div className="flex flex-col gap-2 sm:flex-row sm:justify-end">
            <Button type="button" variant="secondary" disabled={unlocking} onClick={() => setUnlockPrompt(false)}>
              Zrušiť
            </Button>
            <Button
              type="button"
              id="kn-unlock-confirm"
              variant="warn"
              loading={unlocking}
              leftIcon={<LockOpen className="size-4" />}
              onClick={() => void unlock()}
            >
              Odomknúť a upraviť
            </Button>
          </div>
        </Card>
      )}

      {error && (
        <p role="alert" className="rounded-2xl bg-[var(--color-status-bad-bg)] px-4 py-3 text-sm text-[var(--color-status-bad)]">
          {error}
        </p>
      )}

      <Card className="animate-fade-up flex flex-col gap-3 p-4">
        <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-500">Zápis</h2>
        <p className="break-words text-xs text-ink-500">{facilities.map((f) => f.name).join(', ')}</p>
        <ComposedText blocks={composed} />
        {schvalil !== '' && (
          <p className="border-t border-ink-100 pt-3 text-xs text-ink-500">
            Schválil za klienta: <span className="font-medium text-ink-800">{schvalil}</span>
          </p>
        )}
      </Card>

      {documents.length > 0 && (
        <Card className="animate-fade-up overflow-hidden">
          <div className="flex items-center gap-3 border-b border-ink-100 bg-gradient-to-br from-firol-50/60 to-transparent px-4 py-3">
            <div className="grid size-9 place-items-center rounded-2xl bg-firol-500 text-white shadow-[var(--shadow-glow)]">
              <FileText className="size-4" />
            </div>
            <h3 className="text-sm font-semibold text-ink-900">PDF protokol</h3>
          </div>
          <ul className="divide-y divide-ink-100">
            {documents.map((doc) => (
              <li key={doc.id}>
                <a
                  href={documentDownloadUrl(doc.id)}
                  target="_blank"
                  rel="noopener"
                  id={`kn-doc-${doc.id}`}
                  className="flex items-center gap-3 px-4 py-3 transition-colors duration-200 hover:bg-ink-50 active:bg-ink-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-firol-300"
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
                <EmailDocumentForm documentId={doc.id} documentNumber={doc.number} companyId={i.company_id} facilityId={i.facility_id} />
              </li>
            ))}
          </ul>
        </Card>
      )}

      {i.visit_id !== null && <VisitNextUkon visitId={i.visit_id} />}

      <InvoicingBlock
        target="inspections"
        id={id}
        value={invoicingOf(i)}
        disabled={isReadOnly}
        onChange={(next) => onDetail({ ...detail, inspection: { ...detail.inspection, ...next } })}
      />
    </div>
  );
}

function EntrySkeleton() {
  return (
    <div className="flex flex-col gap-4" aria-busy>
      <Skeleton className="h-4 w-32" />
      <Skeleton className="h-7 w-64" />
      <Skeleton className="h-20 w-full rounded-2xl" />
      <Skeleton className="h-40 w-full rounded-2xl" />
      <Skeleton className="h-28 w-full rounded-2xl" />
    </div>
  );
}
