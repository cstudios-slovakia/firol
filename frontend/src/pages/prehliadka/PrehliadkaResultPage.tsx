import { useCallback, useEffect, useState } from 'react';
import { Link, Navigate, useNavigate, useParams } from 'react-router-dom';
import {
  ArrowLeft,
  BookOpen,
  Building2,
  CheckCircle2,
  Download,
  FileText,
  ListTodo,
  PenLine,
  Plus,
  ShieldCheck,
  Undo2,
  Warehouse,
} from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useIsReadOnly } from '@/auth/useIsReadOnly';
import { Book, type BookOffer, type BookView } from '@/api/book';
import {
  INSPECTION_TYPE_LABELS,
  Inspections,
  documentDownloadUrl,
  type InspectionDetail,
  type InspectionDocument,
} from '@/api/inspections';
import { Prehliadka, type PrehliadkaConcept, type PrehliadkaResult, type ResultEntry } from '@/api/prehliadka';
import { ApiError } from '@/lib/api';
import { formatDateSk } from '@/lib/clientNoticeEmail';
import { useConfirm } from '@/lib/confirm';
import { offlineMessage } from '@/lib/offline';
import { prehliadkaPath, scopeLabel } from '@/lib/prehliadka';
import { useToast } from '@/lib/toast';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Skeleton } from '@/components/ui/Skeleton';
import { DEFECT_TASKS_CHANGED_EVENT, offerPrehliadkaDefectTasks } from '@/components/DefectTaskOffer';
import { EmailDocumentForm } from '@/components/EmailDocumentForm';
import { HandoverDialog } from '@/components/HandoverDialog';
import { VisitNextUkon } from '@/components/VisitNextUkon';
import { EntrySheet } from '@/components/book/BookSheets';

type Loaded = { result: PrehliadkaResult; detail: InspectionDetail | null };

function nedostatkyLabel(n: number): string {
  if (n === 1) return '1 nedostatok';
  if (n >= 2 && n <= 4) return `${n} nedostatky`;
  return `${n} nedostatkov`;
}

/**
 * Výsledok uzavretia (prototype `scrVysledok`): the records the prehliadka
 * produced — open, hand over for the client's signature, send by e-mail — the
 * entries it wrote into the books, or, when the požiarna kniha was left out,
 * the offer to add it with the sentence ready. „Upraviť" reopens the draft.
 */
export function PrehliadkaResultPage() {
  const { id: idStr } = useParams<{ id: string }>();
  const id = Number(idStr);

  const [loaded, setLoaded] = useState<Loaded | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const [result, detail] = await Promise.all([
        Prehliadka.result(id),
        Inspections.show(id).catch(() => null),
      ]);
      setLoaded({ result, detail });
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Výsledok prehliadky sa nepodarilo načítať.');
    }
  }, [id]);

  useEffect(() => {
    void load();
  }, [load]);

  // The task dialog creates tasks outside this screen — refresh what it offers.
  useEffect(() => {
    const onChanged = () => void load();
    window.addEventListener(DEFECT_TASKS_CHANGED_EVENT, onChanged);
    return () => window.removeEventListener(DEFECT_TASKS_CHANGED_EVENT, onChanged);
  }, [load]);

  if (error) {
    return (
      <div className="flex flex-col gap-4">
        <Link to="/" className="inline-flex items-center gap-1 self-start text-sm text-ink-500 hover:text-ink-700">
          <ArrowLeft className="size-4" />
          Späť
        </Link>
        <Card className="px-4 py-3 text-sm text-status-bad">{error}</Card>
      </div>
    );
  }
  if (!loaded) return <ResultSkeleton />;

  // A prehliadka that is open again has nothing to show yet — continue where it left off.
  if (loaded.result.inspection.status !== 'finalized') return <Navigate to={prehliadkaPath(id)} replace />;
  return <ResultView id={id} loaded={loaded} reload={load} />;
}

function ResultView({ id, loaded, reload }: { id: number; loaded: Loaded; reload: () => Promise<void> }) {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const navigate = useNavigate();
  const isReadOnly = useIsReadOnly();
  const { result, detail } = loaded;
  const i = result.inspection;
  const visitId = detail?.inspection.visit_id ?? null;

  const [signing, setSigning] = useState<{ doc: InspectionDocument; type: string } | null>(null);
  const [offerBusy, setOfferBusy] = useState(false);
  const [sheet, setSheet] = useState<{ view: BookView; offer: BookOffer } | null>(null);
  const [unlocking, setUnlocking] = useState(false);

  const facilityHref = `/facilities/${i.facility_id}`;
  const bookHref = (book: 'po' | 'bozp', entry: ResultEntry | null): string => {
    const q = new URLSearchParams({ book });
    if (entry?.facility_id) q.set('facility', String(entry.facility_id));
    return `/companies/${i.company_id}/kniha?${q.toString()}`;
  };

  /** The offer lives in the book view; open the same sheet the book screen uses. */
  async function openOffer() {
    setOfferBusy(true);
    try {
      const view = await Book.show(i.company_id, 'po', i.facility_id);
      const offer = view.offers.find((o) => o.inspection_ids.includes(id));
      if (!offer) {
        toast.error('Prehliadka už v požiarnej knihe je.');
        await reload();
        return;
      }
      setSheet({ view, offer });
    } catch (err) {
      toast.error(offlineMessage(err, 'Zápis do knihy sa nepodarilo pripraviť.'));
    } finally {
      setOfferBusy(false);
    }
  }

  async function unlock() {
    const ok = await confirm({
      title: 'Upraviť uzavretú prehliadku?',
      description:
        'Vydané dokumenty (záznam z prehliadky aj zápis BOZP) sa zrušia a po novom uzavretí dostanú nové čísla. ' +
        'Pôvodné čísla sa nepoužijú znova. Zápisy v knihách sa upravia, kým ich vedúci nepodpísal — podpísaný zápis ' +
        'dostane len dopísanie s novým číslom.',
      confirmLabel: 'Upraviť',
    });
    if (!ok) return;
    setUnlocking(true);
    try {
      await Inspections.unlock(id, csrfToken);
      toast.success('Prehliadka je znova otvorená na úpravy.');
      navigate(prehliadkaPath(id), { replace: true });
    } catch (err) {
      toast.error(offlineMessage(err, 'Prehliadku sa nepodarilo otvoriť.'));
      setUnlocking(false);
    }
  }

  // Defects with a termín that have no „Overiť odstránenie" task yet.
  const taskless = (result.task_defects ?? []).filter((d) => !d.task);

  const poDoc = result.po?.documents[0] ?? null;
  const bozpDoc = result.bozp?.documents[0] ?? null;

  return (
    <div className="flex flex-col gap-5">
      <Link to={facilityHref} className="inline-flex items-center gap-1 self-start text-sm text-ink-500 hover:text-ink-700">
        <ArrowLeft className="size-4" />
        Späť na prevádzku
      </Link>

      <header className="animate-fade-up">
        <p className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-status-ok">
          <CheckCircle2 className="size-3.5" />
          Uzavreté
        </p>
        <h1 className="mt-1 text-xl font-semibold tracking-tight text-ink-900">Prehliadka je uzavretá</h1>
        <p className="mt-1 flex flex-wrap items-center gap-x-1.5 text-xs text-ink-500">
          <Building2 className="size-3" />
          {detail?.inspection.company_name ?? ''}
          <span className="text-ink-300">·</span>
          <Warehouse className="size-3" />
          {i.facility_name}
          {i.executed_on && (
            <>
              <span className="text-ink-300">·</span>
              {formatDateSk(i.executed_on)}
            </>
          )}
          <span className="text-ink-300">·</span>
          {scopeLabel(i.scope)}
        </p>
      </header>

      {/* Záznam z preventívnej prehliadky */}
      {result.po && (
        <DocumentCard
          tag={<Badge tone="brand">PO</Badge>}
          title="Záznam z preventívnej prehliadky"
          meta={nedostatkyLabel(result.po.defects)}
          doc={poDoc}
          type="preventivna_prehliadka"
          companyId={i.company_id}
          facilityId={i.facility_id}
          canSign={!isReadOnly}
          onSign={(doc) => setSigning({ doc, type: 'preventivna_prehliadka' })}
        />
      )}

      {/* Zápis do knihy BOZP — zároveň záznam z prehliadky BOZP */}
      {result.bozp && (
        <DocumentCard
          tag={<Badge className="bg-[#E4EEF9] text-[#2D63A0]">BOZP</Badge>}
          title="Zápis do knihy BOZP"
          meta={`${nedostatkyLabel(result.bozp.defects)} · zároveň je to záznam z prehliadky BOZP`}
          doc={bozpDoc}
          type="kniha_bozp"
          companyId={i.company_id}
          facilityId={i.facility_id}
          canSign={!isReadOnly}
          onSign={(doc) => setSigning({ doc, type: 'kniha_bozp' })}
        />
      )}

      {/* Zápisy v knihách */}
      {(result.entries.po || result.entries.bozp || result.offer) && (
        <section className="flex flex-col gap-2 animate-fade-up">
          <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-500">Zápisy v knihách</h2>
          {result.entries.po && (
            <EntryLink
              to={bookHref('po', result.entries.po)}
              title="Zapísané do požiarnej knihy"
              entry={result.entries.po}
            />
          )}
          {result.entries.bozp && (
            <EntryLink to={bookHref('bozp', result.entries.bozp)} title="Zapísané do knihy BOZP" entry={result.entries.bozp} />
          )}
          {result.offer && !isReadOnly && (
            <button
              type="button"
              id="pp-offer-book"
              disabled={offerBusy}
              onClick={() => void openOffer()}
              className="flex items-center gap-3 rounded-2xl border border-[#D8CBE8] bg-[#F7F3FC] px-4 py-3.5 text-left transition-all duration-300 hover:border-[#C4B2DD] hover:shadow-sm active:scale-[0.99] disabled:opacity-60"
            >
              <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-[#EBE1F7] text-[#5B3E8C]">
                <Plus className="size-4" />
              </span>
              <span className="min-w-0 flex-1">
                <span className="block text-sm font-semibold text-ink-900">Pridať do požiarnej knihy</span>
                <span className="block text-xs text-ink-500">Appka ponúkne hotový bod aj s číslom záznamu.</span>
              </span>
            </button>
          )}
        </section>
      )}

      {/* Úlohy „Overiť odstránenie" — ponuka až po uzavretí, počas prehliadky by rušila (plán 8b) */}
      {taskless.length > 0 && !isReadOnly && (
        <section className="flex flex-col gap-2 animate-fade-up">
          <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-500">Nedostatky</h2>
          <button
            type="button"
            id="pp-offer-tasks"
            onClick={() => offerPrehliadkaDefectTasks(id, taskless)}
            className="flex items-center gap-3 rounded-2xl border border-ink-200 bg-white px-4 py-3.5 text-left transition-all duration-300 hover:border-ink-300 hover:shadow-sm active:scale-[0.99]"
          >
            <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-firol-50 text-firol-600">
              <ListTodo className="size-4" />
            </span>
            <span className="min-w-0 flex-1">
              <span className="block text-sm font-semibold text-ink-900">Úlohy na overenie odstránenia</span>
              <span className="block text-xs text-ink-500">
                {nedostatkyLabel(taskless.length)} s termínom bez úlohy — vytvoriť úlohy „Overiť odstránenie nedostatku“.
              </span>
            </span>
          </button>
        </section>
      )}

      {/* Koncepty riadnych kontrol — rozrobené úkony, nie hotové protokoly (spec 7.3) */}
      {(result.concepts ?? []).length > 0 && (
        <section className="flex flex-col gap-2 animate-fade-up">
          <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-500">Koncepty kontrol</h2>
          <p className="text-xs text-ink-500">
            Výsledky sú predvyplnené z prehliadky. Otvor koncept, dotiahni čo chýba, potvrď ho a vygeneruj protokol.
          </p>
          {(result.concepts ?? []).map((c) => (
            <ConceptLink key={c.inspection_id} concept={c} />
          ))}
        </section>
      )}

      {visitId !== null && <VisitNextUkon visitId={visitId} />}

      <div className="flex flex-col gap-2">
        {!isReadOnly && (
          <Button
            type="button"
            id="pp-unlock"
            variant="secondary"
            loading={unlocking}
            leftIcon={<Undo2 className="size-4" />}
            onClick={() => void unlock()}
          >
            Upraviť prehliadku
          </Button>
        )}
        <Link to={facilityHref}>
          <Button type="button" variant="ghost" className="w-full">
            Späť na prevádzku
          </Button>
        </Link>
      </div>

      {signing && (
        <HandoverDialog
          open
          onClose={() => setSigning(null)}
          documentId={signing.doc.id}
          documentNumber={signing.doc.number}
          documentType={signing.type}
          companyId={i.company_id}
          facilityId={i.facility_id}
          defaultPlace={i.facility_city ?? i.facility_name}
          defaultDate={i.executed_on}
          onSigned={() => {
            setSigning(null);
            void reload();
          }}
        />
      )}

      {sheet && (
        <EntrySheet
          view={sheet.view}
          mode={{ kind: 'offer', offer: sheet.offer }}
          onClose={() => setSheet(null)}
          onSaved={() => {
            setSheet(null);
            void reload();
          }}
        />
      )}
    </div>
  );
}

function DocumentCard({
  tag,
  title,
  meta,
  doc,
  type,
  companyId,
  facilityId,
  canSign,
  onSign,
}: {
  tag: React.ReactNode;
  title: string;
  meta: string;
  doc: InspectionDocument | null;
  type: string;
  companyId: number;
  facilityId: number;
  canSign: boolean;
  onSign: (doc: InspectionDocument) => void;
}) {
  if (!doc) {
    return (
      <Card className="animate-fade-up flex items-start gap-3 p-4">
        <span className="mt-0.5 shrink-0">{tag}</span>
        <div className="min-w-0">
          <p className="text-sm font-semibold text-ink-900">{title}</p>
          <p className="text-xs text-ink-500">Dokument sa nenašiel — otvor prehliadku a uzavri ju znova.</p>
        </div>
      </Card>
    );
  }
  return (
    <Card className="animate-fade-up overflow-hidden" id={`pp-doc-${type}`}>
      <a
        href={documentDownloadUrl(doc.id)}
        target="_blank"
        rel="noopener"
        className="flex items-center gap-3 px-4 py-3.5 transition-colors duration-300 hover:bg-ink-50"
      >
        <span className="grid size-10 shrink-0 place-items-center rounded-2xl bg-firol-50 text-firol-600">
          <FileText className="size-5" />
        </span>
        <div className="min-w-0 flex-1">
          <p className="flex flex-wrap items-center gap-1.5 text-sm font-semibold text-ink-900">
            {title}
            {tag}
          </p>
          <p className="mt-0.5 font-mono text-xs text-ink-700">
            {doc.number}
            {doc.version > 1 && <span className="ml-1.5 font-sans text-ink-400">verzia {doc.version}</span>}
          </p>
          <p className="text-xs text-ink-500">{meta}</p>
        </div>
        <Download className="size-4 shrink-0 text-ink-400" />
      </a>

      {doc.handover ? (
        <div className="flex items-start gap-2 border-t border-ink-100 bg-[var(--color-status-ok-bg)]/40 px-4 py-2.5 text-xs text-ink-600">
          <PenLine className="mt-0.5 size-3.5 shrink-0 text-status-ok" />
          <p>
            Prevzal <b className="text-ink-800">{doc.handover.fullname}</b>
            {doc.handover.role_title ? `, ${doc.handover.role_title}` : ''} — podpísané na displeji.
          </p>
        </div>
      ) : (
        canSign && (
          <div className="flex items-center justify-between gap-3 border-t border-ink-100 px-4 py-2.5">
            <p className="text-xs text-ink-500">Podpis klienta na displeji je voliteľný — dá sa podpísať aj po vytlačení.</p>
            <Button type="button" size="sm" variant="secondary" leftIcon={<PenLine className="size-4" />} onClick={() => onSign(doc)}>
              Podpis
            </Button>
          </div>
        )
      )}

      <EmailDocumentForm documentId={doc.id} documentNumber={doc.number} companyId={companyId} facilityId={facilityId} />
    </Card>
  );
}

function ConceptLink({ concept }: { concept: PrehliadkaConcept }) {
  const issued = concept.status === 'finalized';
  const state = issued ? 'protokol vydaný' : concept.confirmed ? 'potvrdený' : 'čaká na potvrdenie';
  return (
    <Link
      to={`/inspections/${concept.inspection_id}`}
      id={`pp-concept-${concept.type}`}
      className="flex items-center gap-3 rounded-2xl border border-ink-200 bg-white px-4 py-3.5 transition-all duration-300 hover:border-ink-300 hover:shadow-sm active:scale-[0.99]"
    >
      <span
        className={
          concept.failing > 0
            ? 'grid size-9 shrink-0 place-items-center rounded-xl bg-status-bad-bg text-status-bad'
            : 'grid size-9 shrink-0 place-items-center rounded-xl bg-firol-50 text-firol-600'
        }
      >
        <ShieldCheck className="size-4" />
      </span>
      <span className="min-w-0 flex-1">
        <span className="block text-sm font-semibold text-ink-900">
          {INSPECTION_TYPE_LABELS[concept.type] ?? concept.type}
        </span>
        <span className="block text-xs text-ink-500">
          {concept.items} {concept.items === 1 ? 'zariadenie' : concept.items < 5 ? 'zariadenia' : 'zariadení'}
          {' · '}
          {concept.failing} nevyhovuje · {state}
        </span>
      </span>
    </Link>
  );
}

function EntryLink({ to, title, entry }: { to: string; title: string; entry: ResultEntry }) {
  return (
    <Link
      to={to}
      className="flex items-center gap-3 rounded-2xl border border-ink-200 bg-white px-4 py-3.5 transition-all duration-300 hover:border-ink-300 hover:shadow-sm active:scale-[0.99]"
    >
      <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-firol-50 text-firol-600">
        <BookOpen className="size-4" />
      </span>
      <span className="min-w-0 flex-1">
        <span className="block text-sm font-semibold text-ink-900">{title}</span>
        <span className="block text-xs text-ink-500">
          Zápis č. {entry.seq} · {entry.signed_manager ? 'podpísané vedúcim' : 'čaká na podpis vedúceho'}
        </span>
      </span>
    </Link>
  );
}

function ResultSkeleton() {
  return (
    <div className="flex flex-col gap-4" aria-busy>
      <Skeleton className="h-4 w-32" />
      <Skeleton className="h-7 w-64" />
      <Skeleton className="h-28 w-full rounded-2xl" />
      <Skeleton className="h-28 w-full rounded-2xl" />
    </div>
  );
}
