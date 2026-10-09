import { useCallback, useEffect, useState } from 'react';
import { FileText, Mail, PenLine } from 'lucide-react';
import { Stock, formatStockDate, type StockIssue } from '@/api/stock';
import { documentDownloadUrl } from '@/api/inspections';
import { Badge } from '@/components/ui/Badge';
import { EmailDocumentForm } from '@/components/EmailDocumentForm';
import { HandoverDialog } from '@/components/HandoverDialog';

/**
 * Výdajky materiálu of one client — block 4 / chapter 21.
 *
 * A výdajka is one of the client's documents like any protocol: it opens as
 * a PDF, goes out by e-mail (on its own here, or with the protocols through
 * „Odoslať protokoly"), and can be signed on the screen — the signature
 * re-renders it as a new version of the same number, as on a protocol.
 * The section is left out while the client has none.
 */
export function CompanyStockIssues({ companyId, readOnly }: { companyId: number; readOnly: boolean }) {
  const [issues, setIssues] = useState<StockIssue[]>([]);
  const [emailFor, setEmailFor] = useState<number | null>(null);
  const [signing, setSigning] = useState<StockIssue | null>(null);

  const load = useCallback(() => {
    Stock.issues(companyId)
      .then((res) => setIssues(res.items))
      .catch(() => setIssues([]));
  }, [companyId]);

  useEffect(() => {
    load();
  }, [load]);

  if (issues.length === 0) return null;

  return (
    <section>
      <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-500">Výdajky materiálu</h2>
      <ul className="divide-y divide-ink-100">
        {issues.map((issue) => (
          <li key={issue.id} className="flex flex-col gap-2 py-3">
            <div className="flex flex-wrap items-center gap-2">
              <span className="font-mono text-sm text-ink-900">{issue.document_number ?? '—'}</span>
              <span className="text-xs text-ink-500">
                {formatStockDate(issue.issued_on)}
                {issue.facility_name && ` · ${issue.facility_name}`} · {issue.line_count}{' '}
                {issue.line_count === 1 ? 'položka' : issue.line_count < 5 ? 'položky' : 'položiek'}
              </span>
              {issue.handover && (
                <Badge tone="ok" className="px-2 py-0 text-[11px]">
                  Prevzal: {issue.handover.fullname}
                </Badge>
              )}
            </div>
            {issue.document_id !== null && (
              <div className="flex flex-wrap gap-2">
                <a
                  href={documentDownloadUrl(issue.document_id)}
                  target="_blank"
                  rel="noopener"
                  className="inline-flex h-9 items-center gap-1.5 rounded-xl border border-ink-200 bg-white px-3 text-sm font-medium text-ink-700 transition-colors hover:border-ink-300 hover:bg-ink-50"
                >
                  <FileText className="size-4" />
                  PDF
                </a>
                {!readOnly && (
                  <>
                    <button
                      type="button"
                      onClick={() => setEmailFor((id) => (id === issue.id ? null : issue.id))}
                      className="inline-flex h-9 items-center gap-1.5 rounded-xl border border-ink-200 bg-white px-3 text-sm font-medium text-ink-700 transition-colors hover:border-ink-300 hover:bg-ink-50"
                    >
                      <Mail className="size-4" />
                      E-mail
                    </button>
                    <button
                      type="button"
                      onClick={() => setSigning(issue)}
                      className="inline-flex h-9 items-center gap-1.5 rounded-xl border border-ink-200 bg-white px-3 text-sm font-medium text-ink-700 transition-colors hover:border-ink-300 hover:bg-ink-50"
                    >
                      <PenLine className="size-4" />
                      {issue.handover ? 'Podpísať znova' : 'Podpis klienta'}
                    </button>
                  </>
                )}
              </div>
            )}
            {emailFor === issue.id && issue.document_id !== null && issue.document_number && (
              <EmailDocumentForm documentId={issue.document_id} documentNumber={issue.document_number} companyId={companyId} />
            )}
          </li>
        ))}
      </ul>

      {signing && signing.document_id !== null && (
        <HandoverDialog
          open
          onClose={() => setSigning(null)}
          documentId={signing.document_id}
          documentNumber={signing.document_number ?? ''}
          documentType="vydajka"
          companyId={companyId}
          facilityId={signing.facility_id ?? undefined}
          defaultDate={signing.issued_on}
          onSigned={() => {
            setSigning(null);
            load();
          }}
        />
      )}
    </section>
  );
}
