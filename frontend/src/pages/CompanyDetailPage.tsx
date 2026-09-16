import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Building2, ChevronRight, ClipboardList, Edit2, FileSignature, Hash, History, Mail, MapPin, Phone, Plus, Route, Send, Trash2, UserCheck, Warehouse } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useIsReadOnly } from '@/auth/useIsReadOnly';
import { Companies, type CompanyDetail } from '@/api/companies';
import { Facilities } from '@/api/facilities';
import { ApiError } from '@/lib/api';
import { useConfirm } from '@/lib/confirm';
import { useToast } from '@/lib/toast';
import { Card } from '@/components/ui/Card';
import { DetailHeaderSkeleton, SkeletonList } from '@/components/ui/Skeleton';
import { PendingSyncBanner } from '@/components/PendingSyncBanner';
import { BulkSendDialog } from '@/components/BulkSendDialog';
import { CompanyPersons } from '@/components/CompanyPersons';
import { WorkConfirmationDialog } from '@/components/WorkConfirmationDialog';
import { Documents, type DocumentSend } from '@/api/documents';

export function CompanyDetailPage() {
  const { id: idStr } = useParams<{ id: string }>();
  const id = Number(idStr);
  const navigate = useNavigate();
  const { csrfToken } = useAuth();
  const isReadOnly = useIsReadOnly();
  const confirm = useConfirm();
  const toast = useToast();

  const [data, setData] = useState<CompanyDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  // Chapter 9.1 — bulk send, reachable from the history and not only from a
  // visit: a client asking for "everything from last year" is answered here.
  const [sendOpen, setSendOpen] = useState(false);
  const [sends, setSends] = useState<DocumentSend[]>([]);
  // Chapter 10 — a potvrdenie can also be built after the fact from whatever
  // was finished at this client on a chosen day, not only from a visit.
  const [confirmationOpen, setConfirmationOpen] = useState(false);

  const loadSends = useCallback(() => {
    Documents.sends(id)
      .then((res) => setSends(res.items))
      .catch(() => setSends([]));
  }, [id]);

  useEffect(() => {
    loadSends();
  }, [loadSends]);

  async function onDeleteFacility(facilityId: number, name: string) {
    const ok = await confirm({
      title: 'Odstrániť prevádzku?',
      description: `Prevádzka „${name}“ bude odstránená spolu so svojimi kontrolami a školeniami. Táto akcia je nevratná.`,
      confirmLabel: 'Odstrániť',
    });
    if (!ok) return;
    try {
      await Facilities.archive(facilityId, csrfToken);
      setData((prev) =>
        prev ? { ...prev, facilities: prev.facilities.filter((f) => f.id !== facilityId) } : prev,
      );
      toast.success('Prevádzka odstránená');
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : 'Prevádzku sa nepodarilo odstrániť.');
    }
  }

  async function onDelete() {
    const ok = await confirm({
      title: 'Odstrániť firmu?',
      description: 'Táto akcia je nevratná. Firma bude odstránená spolu so všetkými prevádzkami.',
      confirmLabel: 'Odstrániť',
    });
    if (!ok) return;
    try {
      await Companies.archive(id, csrfToken);
      navigate('/companies', { replace: true });
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Firmu sa nepodarilo odstrániť.');
    }
  }

  useEffect(() => {
    let cancelled = false;
    Companies.show(id)
      .then((res) => {
        if (!cancelled) setData(res);
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setError(err instanceof ApiError ? err.message : 'Nepodarilo sa načítať firmu.');
      });
    return () => {
      cancelled = true;
    };
  }, [id]);

  if (error) {
    return (
      <div className="flex flex-col gap-4">
        <Link to="/companies" className="inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-700 self-start">
          <ArrowLeft className="size-4" />
          Späť na zoznam
        </Link>
        <Card className="px-4 py-3 text-sm text-status-bad">{error}</Card>
      </div>
    );
  }

  if (!data) {
    return (
      <div className="flex flex-col gap-5">
        <Link to="/companies" className="inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-700 self-start">
          <ArrowLeft className="size-4" />
          Späť na zoznam
        </Link>
        <DetailHeaderSkeleton />
        <SkeletonList count={2} />
      </div>
    );
  }

  const { company, facilities } = data;

  return (
    <div className="flex flex-col gap-5">
      <Link to="/companies" className="inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-700 self-start">
        <ArrowLeft className="size-4" />
        Späť na zoznam
      </Link>

      <PendingSyncBanner resource="companies" id={id} />

      <Card className="overflow-hidden">
        <div className="bg-gradient-to-br from-firol-50/60 to-transparent px-5 pt-5">
          <div className="flex items-start gap-3">
            <div className="grid size-12 place-items-center rounded-2xl bg-firol-500 text-white shadow-[var(--shadow-glow)]">
              <Building2 className="size-5" />
            </div>
            <div className="min-w-0 flex-1">
              <h1 className="truncate text-lg font-semibold tracking-tight text-ink-900">
                {company.name}
              </h1>
              <p className="text-xs text-ink-500">
                {facilities.length} {plural(facilities.length, 'prevádzka', 'prevádzky', 'prevádzok')}
              </p>
            </div>
            {!isReadOnly && (
              <div className="flex items-center gap-3">
                <Link
                  to={`/companies/${company.id}/edit`}
                  aria-label="Upraviť"
                  className="grid size-8 place-items-center rounded-xl text-[var(--color-status-warn)] transition-colors hover:bg-[var(--color-status-warn-bg)]"
                >
                  <Edit2 className="size-4" />
                </Link>
                <button
                  type="button"
                  aria-label="Odstrániť"
                  onClick={onDelete}
                  className="grid size-8 place-items-center rounded-xl text-[var(--color-status-bad)] transition-colors hover:bg-[var(--color-status-bad-bg)]"
                >
                  <Trash2 className="size-4" />
                </button>
              </div>
            )}
          </div>
        </div>

        <dl className="flex flex-col divide-y divide-ink-100 px-5 py-3 text-sm">
          {company.ico && (
            <DetailRow icon={<Hash className="size-4" />} label="IČO" value={company.ico} />
          )}
          {company.address && (
            <DetailRow icon={<MapPin className="size-4" />} label="Adresa" value={company.address} />
          )}
          {company.contact && (
            <DetailRow icon={<Phone className="size-4" />} label="Kontakt" value={company.contact} />
          )}
          {company.contact_email && (
            <DetailRow icon={<Mail className="size-4" />} label="Kontaktný e-mail" value={company.contact_email} />
          )}
          {company.approver && (
            <DetailRow icon={<UserCheck className="size-4" />} label="Schvaľujúca osoba" value={company.approver} />
          )}
          {!company.ico && !company.address && !company.contact && !company.contact_email && !company.approver && (
            <div className="py-2 text-ink-400">Žiadne ďalšie údaje. Doplň ich úpravou firmy.</div>
          )}
        </dl>

        {facilities.length > 0 && !isReadOnly && (
          <div className="flex flex-wrap gap-2 border-t border-ink-100 px-5 py-3">
            {/* A visit is the right start when several úkony are planned:
                firma and prevádzka get picked once instead of per úkon
                (chapter 9). */}
            <Link
              to={`/visits/new?company_id=${company.id}`}
              className="inline-flex h-10 items-center gap-1.5 rounded-2xl bg-firol-500 px-4 text-sm font-medium text-white shadow-[var(--shadow-glow)] transition-transform hover:bg-firol-600 active:scale-[0.98]"
            >
              <Route className="size-4" />
              Nová návšteva
            </Link>
            <Link
              to={`/inspections/new?company_id=${company.id}`}
              className="inline-flex h-10 items-center gap-1.5 rounded-2xl border border-ink-200 bg-white px-4 text-sm font-medium text-ink-700 transition-colors hover:border-ink-300 hover:bg-ink-50"
            >
              <ClipboardList className="size-4" />
              Jedna kontrola
            </Link>
            <button
              type="button"
              onClick={() => setSendOpen(true)}
              className="inline-flex h-10 items-center gap-1.5 rounded-2xl border border-ink-200 bg-white px-4 text-sm font-medium text-ink-700 transition-colors hover:border-ink-300 hover:bg-ink-50"
            >
              <Send className="size-4" />
              Odoslať protokoly
            </button>
            <button
              type="button"
              onClick={() => setConfirmationOpen(true)}
              className="inline-flex h-10 items-center gap-1.5 rounded-2xl border border-ink-200 bg-white px-4 text-sm font-medium text-ink-700 transition-colors hover:border-ink-300 hover:bg-ink-50"
            >
              <FileSignature className="size-4" />
              Potvrdenie o práci
            </button>
          </div>
        )}
      </Card>

      <CompanyPersons companyId={company.id} facilities={facilities} />

      <SendHistory sends={sends} />

      <section>
        <header className="mb-3 flex items-center justify-between">
          <h2 className="text-sm font-semibold uppercase tracking-wide text-ink-500">Prevádzky</h2>
          {!isReadOnly && (
            <Link
              to={`/companies/${company.id}/facilities/new`}
              className="inline-flex h-8 items-center gap-1 rounded-2xl bg-firol-500 px-3 text-xs font-medium text-white shadow-[var(--shadow-glow)] hover:bg-firol-600"
            >
              <Plus className="size-3.5" />
              Pridať prevádzku
            </Link>
          )}
        </header>

        {facilities.length === 0 ? (
          <Card className="flex flex-col items-center gap-2 px-4 py-8 text-center">
            <div className="grid size-12 place-items-center rounded-2xl bg-firol-50 text-firol-500">
              <Warehouse className="size-5" />
            </div>
            <p className="text-sm text-ink-700">Firma zatiaľ nemá prevádzky.</p>
            <p className="max-w-xs text-xs text-ink-500">
              Pridaj prvú prevádzku — napríklad sklad, výrobnú halu alebo pobočku.
            </p>
          </Card>
        ) : (
          <ul className="flex flex-col gap-2">
            {facilities.map((f) => (
              <li key={f.id}>
                <Card className="flex items-center gap-3 px-4 py-3 transition-shadow hover:shadow-[var(--shadow-lift)]">
                  <Link
                    to={`/facilities/${f.id}`}
                    className="flex flex-1 items-center gap-3 group min-w-0"
                  >
                    <div className="grid size-9 shrink-0 place-items-center rounded-2xl bg-firol-50 text-firol-600">
                      <Warehouse className="size-4" />
                    </div>
                    <div className="min-w-0 flex-1">
                      <h3 className="truncate text-sm font-semibold text-ink-900 group-hover:text-firol-700">{f.name}</h3>
                      {f.address && (
                        <p className="truncate text-xs text-ink-500">{f.address}</p>
                      )}
                    </div>
                  </Link>
                  {!isReadOnly ? (
                    <div className="flex shrink-0 items-center gap-2">
                      <Link
                        to={`/facilities/${f.id}/edit`}
                        title="Upraviť"
                        aria-label="Upraviť"
                        className="grid size-8 place-items-center rounded-xl text-[var(--color-status-warn)] transition-colors hover:bg-[var(--color-status-warn-bg)]"
                      >
                        <Edit2 className="size-4" />
                      </Link>
                      <button
                        type="button"
                        title="Odstrániť"
                        aria-label="Odstrániť"
                        onClick={() => onDeleteFacility(f.id, f.name)}
                        className="grid size-8 place-items-center rounded-xl text-[var(--color-status-bad)] transition-colors hover:bg-[var(--color-status-bad-bg)]"
                      >
                        <Trash2 className="size-4" />
                      </button>
                    </div>
                  ) : (
                    <ChevronRight className="size-4 shrink-0 text-ink-300" />
                  )}
                </Card>
              </li>
            ))}
          </ul>
        )}
      </section>

      <BulkSendDialog
        open={sendOpen}
        onClose={() => setSendOpen(false)}
        companyId={company.id}
        companyName={company.name}
        defaultRecipient={company.contact_email}
        onSent={loadSends}
      />

      <WorkConfirmationDialog
        open={confirmationOpen}
        onClose={() => setConfirmationOpen(false)}
        companyId={company.id}
        companyName={company.name}
        date={todayIso()}
      />
    </div>
  );
}

/**
 * What was sent to this client, and when — block 1 / chapter 9.1.
 *
 * "Poslali ste nám to?" is a question technicians get often enough that the
 * app has to be able to answer it, including when a send failed and why.
 */
function SendHistory({ sends }: { sends: DocumentSend[] }) {
  if (sends.length === 0) return null;
  return (
    <section>
      <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-500">
        Odoslané protokoly
      </h2>
      <Card className="overflow-hidden">
        <ul className="divide-y divide-ink-100">
          {sends.slice(0, 10).map((send) => (
            <li key={send.id} className="flex items-start gap-3 px-4 py-3">
              <span
                className={
                  send.status === 'odoslane'
                    ? 'mt-0.5 grid size-8 shrink-0 place-items-center rounded-xl bg-[var(--color-status-ok-bg)] text-status-ok'
                    : 'mt-0.5 grid size-8 shrink-0 place-items-center rounded-xl bg-[var(--color-status-bad-bg)] text-status-bad'
                }
              >
                <History className="size-4" />
              </span>
              <div className="min-w-0 flex-1">
                <p className="text-sm text-ink-900">
                  {send.documents.map((d) => d.number).join(', ')}
                </p>
                <p className="text-xs text-ink-500">
                  {send.recipients.join(', ')} ·{' '}
                  {new Date((send.sent_at ?? send.created_at).replace(' ', 'T')).toLocaleString('sk-SK')}
                  {send.sent_by && ` · ${send.sent_by}`}
                </p>
                {send.status === 'chyba' && send.error_text && (
                  <p className="mt-0.5 text-xs text-status-bad">{send.error_text}</p>
                )}
              </div>
            </li>
          ))}
        </ul>
      </Card>
    </section>
  );
}

function DetailRow({ icon, label, value }: { icon: React.ReactNode; label: string; value: string }) {
  return (
    <div className="flex items-start gap-3 py-2.5">
      <span className="grid size-6 shrink-0 place-items-center text-ink-400">{icon}</span>
      <div className="flex-1">
        <dt className="text-[10px] font-semibold uppercase tracking-wider text-ink-400">{label}</dt>
        <dd className="text-sm text-ink-800 break-words">{value}</dd>
      </div>
    </div>
  );
}

function todayIso(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function plural(n: number, one: string, few: string, many: string): string {
  if (n === 1) return one;
  if (n >= 2 && n <= 4) return few;
  return many;
}
