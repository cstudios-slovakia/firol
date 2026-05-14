import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Link, useParams } from 'react-router-dom';
import {
  ArrowLeft, Briefcase, Building2, CalendarDays, CheckCircle2, Clock,
  Download, FileText, GraduationCap, NotebookPen, Plus, Trash2, User, Users,
  Warehouse,
} from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import {
  TRAINING_TYPE_LABELS,
  Trainings,
  traineeSignatureUrl,
  trainingDocumentDownloadUrl,
  type Trainee,
  type TrainingDetail,
  type TrainingDocument,
} from '@/api/trainings';
import { ApiError } from '@/lib/api';
import { handleOfflineSave } from '@/lib/offline';
import { useToast } from '@/lib/toast';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Field } from '@/components/ui/Field';
import { Spinner } from '@/components/ui/Spinner';
import { CardBlockSkeleton, DetailHeaderSkeleton } from '@/components/ui/Skeleton';
import { SignaturePad, type SignaturePadHandle } from '@/components/SignaturePad';

export function TrainingDetailPage() {
  const { id: idStr } = useParams<{ id: string }>();
  const id = Number(idStr);
  const { csrfToken } = useAuth();
  const toast = useToast();

  const [data, setData] = useState<TrainingDetail | null>(null);
  const [documents, setDocuments] = useState<TrainingDocument[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [adding, setAdding] = useState(false);
  const [deletingId, setDeletingId] = useState<number | null>(null);
  const [showAddForm, setShowAddForm] = useState(false);
  const [generating, setGenerating] = useState(false);

  useEffect(() => {
    let cancelled = false;
    Promise.all([Trainings.show(id), Trainings.documents(id)])
      .then(([detail, docs]) => {
        if (cancelled) return;
        setData(detail);
        setDocuments(docs.items);
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať školenie.');
      });
    return () => {
      cancelled = true;
    };
  }, [id]);

  async function refreshDetail() {
    const [fresh, docs] = await Promise.all([
      Trainings.show(id),
      Trainings.documents(id),
    ]);
    setData(fresh);
    setDocuments(docs.items);
  }

  async function handleGeneratePdf() {
    if (!data) return;
    setError(null);
    setGenerating(true);
    try {
      const res = await Trainings.generatePdf(id, csrfToken);
      await refreshDetail();
      toast.success('PDF protokol vygenerovaný');
      window.open(trainingDocumentDownloadUrl(res.document.id), '_blank', 'noopener');
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'PDF sa nepodarilo vygenerovať.';
      setError(msg);
      toast.error(msg);
    } finally {
      setGenerating(false);
    }
  }

  async function handleAdd(payload: { fullname: string; position: string; signature: Blob }) {
    setAdding(true);
    setError(null);
    try {
      await Trainings.addTrainee(
        id,
        {
          fullname: payload.fullname,
          position: payload.position || null,
          signature: payload.signature,
        },
        csrfToken,
      );
      await refreshDetail();
      setShowAddForm(false);
      toast.success('Účastník uložený');
    } catch (err) {
      if (handleOfflineSave(err, toast)) {
        setShowAddForm(false);
        return;
      }
      const apiErr = err instanceof ApiError
        ? err
        : new ApiError(0, 'Účastníka sa nepodarilo pridať.', null);
      toast.error(apiErr.message);
      // Surface the error inside the add form so the user keeps their
      // typed fullname/position; throw to let the form know not to clear.
      throw apiErr;
    } finally {
      setAdding(false);
    }
  }

  async function handleDelete(traineeId: number) {
    if (!window.confirm('Naozaj zmazať účastníka? Jeho podpis sa stratí.')) return;
    setDeletingId(traineeId);
    try {
      await Trainings.deleteTrainee(id, traineeId, csrfToken);
      await refreshDetail();
      toast.success('Účastník zmazaný');
    } catch (err) {
      if (handleOfflineSave(err, toast)) return;
      const msg = err instanceof ApiError ? err.message : 'Mazanie sa nepodarilo.';
      setError(msg);
      toast.error(msg);
    } finally {
      setDeletingId(null);
    }
  }

  if (error && !data) {
    return (
      <div className="flex flex-col gap-4">
        <Link to="/trainings" className="inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-700 self-start">
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
        <Link to="/trainings" className="inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-700 self-start">
          <ArrowLeft className="size-4" />
          Späť na zoznam
        </Link>
        <DetailHeaderSkeleton />
        <CardBlockSkeleton rows={4} />
        <CardBlockSkeleton rows={3} />
      </div>
    );
  }

  const { training: t, trainees } = data;
  const isDraft = t.status === 'draft';

  return (
    <div className="flex flex-col gap-5">
      <Link to="/trainings" className="inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-700 self-start">
        <ArrowLeft className="size-4" />
        Späť na zoznam
      </Link>

      <Card className="overflow-hidden">
        <div className="bg-gradient-to-br from-firol-50/60 to-transparent px-5 pt-5">
          <div className="flex items-start gap-3">
            <div className="grid size-12 place-items-center rounded-2xl bg-firol-500 text-white shadow-[var(--shadow-glow)]">
              <GraduationCap className="size-5" />
            </div>
            <div className="min-w-0 flex-1">
              <p className="text-[10px] font-semibold uppercase tracking-wider text-firol-500">
                Školenie
              </p>
              <h1 className="text-lg font-semibold tracking-tight text-ink-900">
                {TRAINING_TYPE_LABELS[t.type]}
              </h1>
              <div className="mt-1 flex items-center gap-2">
                <Badge tone={isDraft ? 'warn' : 'ok'}>
                  {isDraft ? 'Rozpracované' : 'Dokončené'}
                </Badge>
                <span className="text-xs text-ink-500">
                  {trainees.length} {trainees.length === 1 ? 'účastník' : 'účastníkov'}
                </span>
              </div>
            </div>
          </div>
        </div>

        <dl className="flex flex-col divide-y divide-ink-100 px-5 py-3 text-sm">
          <DetailRow icon={<Building2 className="size-4" />} label="Firma">
            <Link to={`/companies/${t.company_id}`} className="text-firol-700 hover:underline">
              {t.company_name}
            </Link>
            {t.company_ico && <span className="ml-2 text-ink-400">IČO {t.company_ico}</span>}
          </DetailRow>
          {t.facility_id !== null && t.facility_name && (
            <DetailRow icon={<Warehouse className="size-4" />} label="Prevádzka">
              <Link to={`/facilities/${t.facility_id}`} className="text-firol-700 hover:underline">
                {t.facility_name}
              </Link>
            </DetailRow>
          )}
          <DetailRow icon={<CalendarDays className="size-4" />} label="Dátum školenia">
            {t.date ?? '—'}
          </DetailRow>
          {t.duration_min !== null && (
            <DetailRow icon={<Clock className="size-4" />} label="Dĺžka">
              {t.duration_min} min
            </DetailRow>
          )}
          <DetailRow icon={<User className="size-4" />} label="Školiteľ">
            {t.trainer_name ?? <span className="text-ink-400 italic">— nenastavené —</span>}
            {t.trainer_certification_number && (
              <span className="ml-2 text-xs text-ink-500">{t.trainer_certification_number}</span>
            )}
          </DetailRow>
          {t.topics && (
            <DetailRow icon={<NotebookPen className="size-4" />} label="Témy">
              <span className="whitespace-pre-wrap">{t.topics}</span>
            </DetailRow>
          )}
        </dl>
      </Card>

      <section>
        <header className="mb-3 flex items-center justify-between">
          <h2 className="text-sm font-semibold uppercase tracking-wide text-ink-500">
            Účastníci ({trainees.length})
          </h2>
          {isDraft && !showAddForm && (
            <button
              type="button"
              onClick={() => setShowAddForm(true)}
              className="inline-flex h-8 items-center gap-1 rounded-2xl bg-firol-500 px-3 text-xs font-medium text-white shadow-[var(--shadow-glow)] hover:bg-firol-600"
            >
              <Plus className="size-3.5" />
              Pridať účastníka
            </button>
          )}
        </header>

        {showAddForm && (
          <AddTraineeForm
            onCancel={() => setShowAddForm(false)}
            onSubmit={handleAdd}
            submitting={adding}
          />
        )}

        {error && data && (
          <Card className="mb-2 px-3 py-2 text-sm text-status-bad">{error}</Card>
        )}

        {trainees.length === 0 ? (
          !showAddForm && (
            <Card className="flex flex-col items-center gap-2 px-6 py-10 text-center">
              <div className="grid size-12 place-items-center rounded-2xl bg-firol-50 text-firol-500">
                <Users className="size-5" />
              </div>
              <p className="text-sm text-ink-700">Zatiaľ žiadny účastník.</p>
              <p className="max-w-xs text-xs text-ink-500">
                Po pridaní prvého účastníka uvidíš tu jeho podpis a v ďalšom kroku vznikne PDF protokol.
              </p>
            </Card>
          )
        ) : (
          <ul className="flex flex-col gap-2">
            {trainees.map((tr, idx) => (
              <li key={tr.id}>
                <TraineeRow
                  index={idx + 1}
                  trainee={tr}
                  canEdit={isDraft}
                  deleting={deletingId === tr.id}
                  onDelete={() => handleDelete(tr.id)}
                />
              </li>
            ))}
          </ul>
        )}
      </section>

      <DocumentsBlock
        documents={documents}
        canGenerate={
          isDraft &&
          trainees.length > 0 &&
          !!t.date &&
          t.trainer_id !== null
        }
        canGenerateHint={cannotGenerateHint(t, trainees, isDraft)}
        generating={generating}
        onGenerate={handleGeneratePdf}
        finalized={!isDraft}
      />
    </div>
  );
}

function cannotGenerateHint(
  t: TrainingDetail['training'],
  trainees: Trainee[],
  isDraft: boolean,
): string | null {
  if (!isDraft) return null;
  if (!t.date) return 'Doplň dátum školenia.';
  if (t.trainer_id === null) return 'Vyber školiteľa v Nastaveniach a priraď ho školeniu.';
  if (trainees.length === 0) return 'Pridaj aspoň jedného účastníka.';
  return null;
}

function DocumentsBlock({
  documents,
  canGenerate,
  canGenerateHint,
  generating,
  onGenerate,
  finalized,
}: {
  documents: TrainingDocument[];
  canGenerate: boolean;
  canGenerateHint: string | null;
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
            ? 'Po vygenerovaní sa školenie uzamkne a dostane svoje číslo (napr. SKO-2026-001).'
            : (canGenerateHint ?? 'Pre vygenerovanie sú potrebné všetky údaje.')}
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
              ? 'Školenie je uzamknuté. Účastníkov a podpisy už nemožno meniť.'
              : `Vygenerované ${documents.length} ${documents.length === 1 ? 'protokol' : 'protokoly'}.`}
          </p>
        </div>
      </div>
      <ul className="divide-y divide-ink-100">
        {documents.map((doc) => (
          <li key={doc.id}>
            <a
              href={trainingDocumentDownloadUrl(doc.id)}
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

function AddTraineeForm({
  onCancel,
  onSubmit,
  submitting,
}: {
  onCancel: () => void;
  onSubmit: (payload: { fullname: string; position: string; signature: Blob }) => Promise<void>;
  submitting: boolean;
}) {
  const padRef = useRef<SignaturePadHandle>(null);
  const [fullname, setFullname] = useState('');
  const [position, setPosition] = useState('');
  const [empty, setEmpty] = useState(true);
  const [error, setError] = useState<string | null>(null);

  async function handle(e: FormEvent) {
    e.preventDefault();
    if (!fullname.trim()) {
      setError('Doplň meno a priezvisko účastníka.');
      return;
    }
    const blob = await padRef.current?.toBlob();
    if (!blob) {
      setError('Účastník sa musí podpísať na obrazovku.');
      return;
    }
    setError(null);
    try {
      await onSubmit({ fullname: fullname.trim(), position: position.trim(), signature: blob });
      // Form is unmounted by the parent on success — no further state to reset.
    } catch (err) {
      const message = err instanceof ApiError ? err.message : 'Účastníka sa nepodarilo pridať.';
      setError(message);
    }
  }

  return (
    <Card className="mb-3 p-5">
      <form onSubmit={handle} className="flex flex-col gap-4" noValidate>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Meno a priezvisko" required>
            {(p) => (
              <Input {...p} required leftIcon={<User className="size-4" />}
                value={fullname} onChange={(e) => setFullname(e.target.value)}
                placeholder="Mária Novotná" />
            )}
          </Field>
          <Field label="Pracovné zaradenie" hint='Voliteľné — napr. „Vedúci skladu".'>
            {(p) => (
              <Input {...p} leftIcon={<Briefcase className="size-4" />}
                value={position} onChange={(e) => setPosition(e.target.value)}
                placeholder="Vedúci zmeny" />
            )}
          </Field>
        </div>

        <Field label="Podpis" required hint="Podpíšte sa prstom alebo myšou. Rovnaký podpis pôjde na PDF.">
          {() => <SignaturePad ref={padRef} heightPx={180} onEmptyChange={setEmpty} />}
        </Field>

        {error && (
          <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
            {error}
          </div>
        )}

        <div className="flex flex-col gap-2 pt-1 sm:flex-row sm:items-center sm:justify-end">
          <Button type="button" variant="ghost" onClick={onCancel}>
            Zrušiť
          </Button>
          <Button
            type="submit"
            loading={submitting}
            leftIcon={<CheckCircle2 className="size-4" />}
            disabled={empty || !fullname.trim()}
            title={empty ? 'Najprv sa podpíšte' : ''}
          >
            Uložiť účastníka
          </Button>
        </div>
      </form>
    </Card>
  );
}

function TraineeRow({
  index,
  trainee,
  canEdit,
  deleting,
  onDelete,
}: {
  index: number;
  trainee: Trainee;
  canEdit: boolean;
  deleting: boolean;
  onDelete: () => void;
}) {
  return (
    <Card className="flex items-center gap-3 px-4 py-3">
      <span className="grid size-9 shrink-0 place-items-center rounded-2xl bg-firol-50 text-firol-700 text-sm font-semibold">
        {index}
      </span>
      <div className="min-w-0 flex-1">
        <h3 className="truncate text-sm font-semibold text-ink-900">
          <User className="-mt-0.5 mr-1 inline size-3 text-ink-400" />
          {trainee.fullname}
        </h3>
        {trainee.position && (
          <p className="mt-0.5 truncate text-xs text-ink-500">
            <Briefcase className="-mt-0.5 mr-1 inline size-3" />
            {trainee.position}
          </p>
        )}
      </div>
      <div className="flex h-12 w-32 shrink-0 items-center justify-center rounded-xl border border-ink-100 bg-white p-1">
        {trainee.has_signature ? (
          <img
            src={traineeSignatureUrl(trainee.id, trainee.signed_at ?? trainee.updated_at)}
            alt={`Podpis: ${trainee.fullname}`}
            className="max-h-full max-w-full object-contain"
          />
        ) : (
          <span className="text-[10px] text-ink-400">bez podpisu</span>
        )}
      </div>
      {canEdit && (
        <button
          type="button"
          onClick={onDelete}
          disabled={deleting}
          aria-label="Zmazať"
          className="grid size-9 place-items-center rounded-xl text-ink-500 transition-colors hover:bg-[var(--color-status-bad-bg)] hover:text-status-bad disabled:opacity-50"
        >
          {deleting ? <Spinner size="sm" /> : <Trash2 className="size-4" />}
        </button>
      )}
    </Card>
  );
}

function DetailRow({
  icon,
  label,
  children,
}: {
  icon: React.ReactNode;
  label: string;
  children: React.ReactNode;
}) {
  return (
    <div className="flex items-start gap-3 py-2.5">
      <span className="grid size-6 shrink-0 place-items-center text-ink-400">{icon}</span>
      <div className="flex-1">
        <dt className="text-[10px] font-semibold uppercase tracking-wider text-ink-400">{label}</dt>
        <dd className="text-sm text-ink-800 break-words">{children}</dd>
      </div>
    </div>
  );
}
