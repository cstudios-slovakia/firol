import { useCallback, useEffect, useState } from 'react';
import { FileText, Mail, PenLine } from 'lucide-react';
import { WorkConfirmations, type WorkConfirmation } from '@/api/workConfirmations';
import { documentDownloadUrl } from '@/api/inspections';
import { Badge } from '@/components/ui/Badge';
import { EmailDocumentForm } from '@/components/EmailDocumentForm';
import { HandoverDialog } from '@/components/HandoverDialog';

/**
 * Potvrdenia o vykonaní práce of one client — chapter 10.
 *
 * The sheet is for the technician's employer, but it is still handed to the
 * client to sign on site. Like a výdajka it stays on the firm: PDF, e-mail
 * and the on-screen signature. The section is left out while the client has
 * none. It is not part of the bulk „Odoslať protokoly" list (owner decision
 * 7. 10. 2026) — that mail is the client's protocols, this one is its own.
 */
export function CompanyWorkConfirmations({
  companyId,
  readOnly,
  reloadKey = 0,
}: {
  companyId: number;
  readOnly: boolean;
  reloadKey?: number;
}) {
  const [items, setItems] = useState<WorkConfirmation[]>([]);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    setError(null);
    WorkConfirmations.list(companyId)
      .then((res) => setItems(res.items))
      .catch(() => {
        setItems([]);
        setError('Potvrdenia sa nepodarilo načítať.');
      });
  }, [companyId]);

  useEffect(() => {
    load();
  }, [load, reloadKey]);

  if (items.length === 0 && !error) return null;

  return (
    <section>
      <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-500">
        Potvrdenia o vykonaní práce
      </h2>
      {error && items.length === 0 ? (
        <p className="text-sm text-status-bad">{error}</p>
      ) : (
        <ul className="divide-y divide-ink-100">
          {items.map((item) => (
            <li key={item.id} className="py-3">
              <ConfirmationRow item={item} companyId={companyId} readOnly={readOnly} onChanged={load} />
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

/** PDF, e-mail and signature for one confirmation — also shown right after it is generated. */
export function ConfirmationActions({
  item,
  companyId,
  readOnly,
  onChanged,
  onSigningChange,
}: {
  item: Pick<
    WorkConfirmation,
    'document_id' | 'document_number' | 'facility_id' | 'confirmed_on' | 'handover'
  >;
  companyId: number;
  readOnly: boolean;
  onChanged?: () => void;
  /** While the signature dialog is open, the parent dialog must ignore Escape. */
  onSigningChange?: (signing: boolean) => void;
}) {
  const [emailOpen, setEmailOpen] = useState(false);
  const [signing, setSigning] = useState(false);

  useEffect(() => {
    onSigningChange?.(signing);
  }, [signing, onSigningChange]);

  if (item.document_id === null || !item.document_number) return null;

  const documentId = item.document_id;
  const documentNumber = item.document_number;

  return (
    <>
      <div className="flex flex-wrap gap-2">
        <a
          href={documentDownloadUrl(documentId)}
          target="_blank"
          rel="noopener"
          className="inline-flex h-9 items-center gap-1.5 rounded-xl border border-ink-200 bg-white px-3 text-sm font-medium text-ink-700 transition-all duration-300 hover:border-ink-300 hover:bg-ink-50 active:scale-[0.98]"
        >
          <FileText className="size-4" />
          PDF
        </a>
        {!readOnly && (
          <>
            <button
              type="button"
              onClick={() => setEmailOpen((open) => !open)}
              className="inline-flex h-9 items-center gap-1.5 rounded-xl border border-ink-200 bg-white px-3 text-sm font-medium text-ink-700 transition-all duration-300 hover:border-ink-300 hover:bg-ink-50 active:scale-[0.98]"
            >
              <Mail className="size-4" />
              E-mail
            </button>
            <button
              type="button"
              onClick={() => setSigning(true)}
              className="inline-flex h-9 items-center gap-1.5 rounded-xl border border-ink-200 bg-white px-3 text-sm font-medium text-ink-700 transition-all duration-300 hover:border-ink-300 hover:bg-ink-50 active:scale-[0.98]"
            >
              <PenLine className="size-4" />
              {item.handover ? 'Podpísať znova' : 'Podpis klienta'}
            </button>
          </>
        )}
      </div>
      {emailOpen && (
        <EmailDocumentForm
          documentId={documentId}
          documentNumber={documentNumber}
          companyId={companyId}
          heading="Odoslať potvrdenie e-mailom"
          sentNoun="Potvrdenie"
          sentVerb="odoslané"
          className="rounded-2xl border border-ink-100 bg-ink-50/40 px-3 py-3"
        />
      )}
      {signing && (
        <HandoverDialog
          open
          onClose={() => setSigning(false)}
          documentId={documentId}
          documentNumber={documentNumber}
          documentType="potvrdenie_prace"
          companyId={companyId}
          facilityId={item.facility_id ?? undefined}
          defaultDate={item.confirmed_on}
          onSigned={() => {
            setSigning(false);
            onChanged?.();
          }}
        />
      )}
    </>
  );
}

function ConfirmationRow({
  item,
  companyId,
  readOnly,
  onChanged,
}: {
  item: WorkConfirmation;
  companyId: number;
  readOnly: boolean;
  onChanged: () => void;
}) {
  const actCount = item.inspection_ids.length + (item.training_ids?.length ?? 0);

  return (
    <div className="flex flex-col gap-2">
      <div className="flex flex-wrap items-center gap-2">
        <span className="font-mono text-sm text-ink-900">{item.document_number ?? '—'}</span>
        <span className="text-xs text-ink-500">
          {formatDay(item.confirmed_on)}
          {item.facility_name && ` · ${item.facility_name}`}
          {item.time_from && item.time_to && ` · ${item.time_from}–${item.time_to}`}
          {actCount > 0 &&
            ` · ${actCount} ${actCount === 1 ? 'úkon' : actCount < 5 ? 'úkony' : 'úkonov'}`}
        </span>
        {item.handover && (
          <Badge tone="ok" className="px-2 py-0 text-[11px]">
            Potvrdil: {item.handover.fullname}
          </Badge>
        )}
      </div>
      <ConfirmationActions item={item} companyId={companyId} readOnly={readOnly} onChanged={onChanged} />
    </div>
  );
}

function formatDay(value: string): string {
  const [y, m, d] = value.split('-').map(Number);
  if (!y || !m || !d) return value;
  return `${d}. ${m}. ${y}`;
}
