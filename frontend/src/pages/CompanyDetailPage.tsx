import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { ArrowLeft, Building2, ChevronRight, ClipboardList, Edit2, FileSignature, FileText, Hash, History, Mail, MapPin, Phone, Plus, Route, Send, Trash2, UserCheck, Warehouse } from 'lucide-react';
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
import { CompanyStockIssues } from '@/components/stock/CompanyStockIssues';
import { Documents, type DocumentSend } from '@/api/documents';
import { documentDownloadUrl } from '@/api/inspections';
import { cn } from '@/lib/cn';
import { companyRecipientEmail } from '@/lib/companyEmail';

type CompanyTab = 'general' | 'facilities' | 'protocols';

const TABS: { key: CompanyTab; label: string }[] = [
  { key: 'general', label: 'Všeobecné' },
  { key: 'facilities', label: 'Prevádzky' },
  { key: 'protocols', label: 'Protokoly' },
];

function parseTab(value: string | null): CompanyTab {
  return TABS.some((t) => t.key === value) ? (value as CompanyTab) : 'general';
}

export function CompanyDetailPage() {
  const { id: idStr } = useParams<{ id: string }>();
  const id = Number(idStr);
  const navigate = useNavigate();
  const { csrfToken } = useAuth();
  const isReadOnly = useIsReadOnly();
  const confirm = useConfirm();
  const toast = useToast();
  const [params, setParams] = useSearchParams();
  const tab = parseTab(params.get('tab'));

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
  const canStart = facilities.length > 0 && !isReadOnly;

  function selectTab(next: CompanyTab) {
    // The default tab keeps the URL clean; replace so tab hops don't pile up in history.
    setParams(next === 'general' ? {} : { tab: next }, { replace: true });
  }

  return (
    <div className="flex flex-col gap-5">
      <Link to="/companies" className="inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-700 self-start">
        <ArrowLeft className="size-4" />
        Späť na zoznam
      </Link>

      <PendingSyncBanner resource="companies" id={id} />

      {/* One block: header, actions, tabs and the active tab's content. */}
      <Card className="overflow-hidden">
        <div className="bg-gradient-to-br from-firol-50/60 to-transparent px-5 pb-4 pt-5">
          <div className="flex items-start gap-3">
            <div className="grid size-12 shrink-0 place-items-center rounded-2xl bg-firol-500 text-white shadow-[var(--shadow-glow)]">
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

          {canStart && (
            <div className="mt-4 flex flex-wrap gap-2">
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
                Nová kontrola / nové školenie
              </Link>
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
        </div>

        <div
          role="tablist"
          aria-label="Sekcie firmy"
          className="flex gap-1 overflow-x-auto border-b border-ink-100 px-3"
        >
          {TABS.map((t) => (
            <button
              key={t.key}
              type="button"
              role="tab"
              id={`company-tab-${t.key}`}
              aria-selected={tab === t.key}
              aria-controls={`company-panel-${t.key}`}
              onClick={() => selectTab(t.key)}
              className={cn(
                '-mb-px inline-flex min-h-11 shrink-0 items-center gap-1.5 border-b-2 px-3 text-sm font-medium transition-colors duration-200',
                tab === t.key
                  ? 'border-firol-500 text-firol-700'
                  : 'border-transparent text-ink-500 hover:text-ink-800',
              )}
            >
              {t.label}
              {t.key === 'facilities' && (
                <span
                  className={cn(
                    'rounded-full px-1.5 text-[11px] tabular-nums transition-colors',
                    tab === t.key ? 'bg-firol-50 text-firol-700' : 'bg-ink-100 text-ink-600',
                  )}
                >
                  {facilities.length}
                </span>
              )}
            </button>
          ))}
        </div>

        <div className="px-5 py-5">
          {tab === 'general' && (
            <div role="tabpanel" id="company-panel-general" aria-labelledby="company-tab-general" className="flex animate-fade-up flex-col gap-6">
              <dl className="flex flex-col divide-y divide-ink-100 text-sm">
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
              <CompanyPersons companyId={company.id} facilities={facilities} />
            </div>
          )}

          {tab === 'facilities' && (
            <div role="tabpanel" id="company-panel-facilities" aria-labelledby="company-tab-facilities" className="animate-fade-up">
              {!isReadOnly && (
                <div className="mb-3 flex justify-end">
                  <Link
                    to={`/companies/${company.id}/facilities/new`}
                    className="inline-flex h-8 items-center gap-1 rounded-2xl bg-firol-500 px-3 text-xs font-medium text-white shadow-[var(--shadow-glow)] hover:bg-firol-600"
                  >
                    <Plus className="size-3.5" />
                    Pridať prevádzku
                  </Link>
                </div>
              )}

              {facilities.length === 0 ? (
                <div className="flex flex-col items-center gap-2 px-4 py-8 text-center">
                  <div className="grid size-12 place-items-center rounded-2xl bg-firol-50 text-firol-500">
                    <Warehouse className="size-5" />
                  </div>
                  <p className="text-sm text-ink-700">Firma zatiaľ nemá prevádzky.</p>
                  <p className="max-w-xs text-xs text-ink-500">
                    Pridaj prvú prevádzku — napríklad sklad, výrobnú halu alebo pobočku.
                  </p>
                </div>
              ) : (
                <ul className="flex flex-col divide-y divide-ink-100">
                  {facilities.map((f) => (
                    <li key={f.id} className="flex items-center gap-3 py-3">
                      <Link
                        to={`/facilities/${f.id}`}
                        className="group flex min-w-0 flex-1 items-center gap-3"
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
                    </li>
                  ))}
                </ul>
              )}
            </div>
          )}

          {tab === 'protocols' && (
            <div role="tabpanel" id="company-panel-protocols" aria-labelledby="company-tab-protocols" className="flex animate-fade-up flex-col gap-6">
              <SendHistory sends={sends} onSend={canStart ? () => setSendOpen(true) : undefined} />

              {/* Chapter 21 — výdajky of this client; hidden while there are none. */}
              <CompanyStockIssues companyId={company.id} readOnly={isReadOnly} />
            </div>
          )}
        </div>
      </Card>

      <BulkSendDialog
        open={sendOpen}
        onClose={() => setSendOpen(false)}
        companyId={company.id}
        companyName={company.name}
        defaultRecipient={companyRecipientEmail(company)}
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
function SendHistory({ sends, onSend }: { sends: DocumentSend[]; onSend?: () => void }) {
  return (
    <section>
      <header className="mb-3 flex items-center justify-between gap-3">
        <h2 className="text-sm font-semibold uppercase tracking-wide text-ink-500">
          Odoslané protokoly
        </h2>
        {onSend && (
          <button
            type="button"
            onClick={onSend}
            className="inline-flex h-8 items-center gap-1 rounded-2xl bg-firol-500 px-3 text-xs font-medium text-white shadow-[var(--shadow-glow)] hover:bg-firol-600"
          >
            <Send className="size-3.5" />
            Odoslať protokoly
          </button>
        )}
      </header>

      {sends.length === 0 ? (
        <div className="flex flex-col items-center gap-2 px-4 py-8 text-center">
          <div className="grid size-12 place-items-center rounded-2xl bg-firol-50 text-firol-500">
            <FileText className="size-5" />
          </div>
          <p className="text-sm text-ink-700">Klientovi zatiaľ neboli odoslané žiadne protokoly.</p>
        </div>
      ) : (
        <ul className="flex flex-col divide-y divide-ink-100">
          {sends.slice(0, 10).map((send) => {
            const ok = send.status === 'odoslane';
            return (
              <li key={send.id} className="flex flex-col gap-3 py-3.5">
                <div className="flex items-start gap-3">
                  <span
                    className={cn(
                      'grid size-9 shrink-0 place-items-center rounded-xl',
                      ok
                        ? 'bg-[var(--color-status-ok-bg)] text-status-ok'
                        : 'bg-[var(--color-status-bad-bg)] text-status-bad',
                    )}
                  >
                    {ok ? <Send className="size-4" /> : <History className="size-4" />}
                  </span>
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium text-ink-900">
                      {send.recipients.join(', ')}
                    </p>
                    <p className="text-xs text-ink-500">
                      {new Date((send.sent_at ?? send.created_at).replace(' ', 'T')).toLocaleString('sk-SK')}
                      {send.sent_by && ` · ${send.sent_by}`}
                    </p>
                  </div>
                  <span className="shrink-0 rounded-full bg-ink-100 px-2 py-0.5 text-[11px] font-medium tabular-nums text-ink-600">
                    {send.documents.length}
                  </span>
                </div>

                <ul className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                  {send.documents.map((d) => (
                    <li key={d.id}>
                      <a
                        href={documentDownloadUrl(d.id)}
                        target="_blank"
                        rel="noopener noreferrer"
                        title="Otvoriť PDF protokol"
                        className="group flex items-center gap-2 rounded-xl bg-ink-50 px-2.5 py-2 transition-all duration-200 hover:-translate-y-px hover:bg-firol-50 hover:shadow-md active:scale-[0.98]"
                      >
                        <FileText className="size-4 shrink-0 text-ink-400 transition-colors group-hover:text-firol-600" />
                        <span className="min-w-0 truncate font-mono text-xs text-ink-800 group-hover:text-firol-700">
                          {d.number}
                        </span>
                      </a>
                    </li>
                  ))}
                </ul>

                {send.status === 'chyba' && send.error_text && (
                  <p className="text-xs text-status-bad">{send.error_text}</p>
                )}
              </li>
            );
          })}
        </ul>
      )}
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
