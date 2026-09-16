/**
 * Wording of the client-side signature block — block 1 / chapter 13.1.
 *
 * Mirrors `Firol\Support\Handover` on the server, which is what actually prints
 * on the PDF; this copy is for the screen that asks for the signature, so the
 * technician sees the same sentence the client will.
 *
 * The heading is „Za organizáciu" on every document, never „Za spoločnosť": a
 * third of the clients are schools, obce and združenia, and calling a
 * starosta's office a spoločnosť reads as a mistake on a document that ends up
 * in an inspection file.
 *
 * What is being confirmed does differ by document, so the line above the
 * signature changes with it. A požiarna kniha entry says „Schválil", because
 * under § 29 vyhl. 121/2002 the record is approved by the vedúci zamestnanec —
 * approval, not acknowledgement. Everything else is handed over: the findings
 * are the technician's, and the client acknowledges receiving them rather than
 * agreeing with them.
 */
export const HANDOVER_ACTION_LABELS: Record<string, string> = {
  poziarna_kniha: 'Schválil',
  odovzdavaci: 'Prevzal',
  vydajka: 'Prevzal',
  rocny_plan: 'Odsúhlasil',
  potvrdenie_prace: 'Potvrdil vykonanie práce',
};

export const HANDOVER_COLUMN_TITLE = 'Za organizáciu';

/** Full heading, e.g. „Za organizáciu — prevzal na vedomie". */
export function handoverHeading(documentType: string): string {
  const action = HANDOVER_ACTION_LABELS[documentType] ?? 'Prevzal na vedomie';
  return `${HANDOVER_COLUMN_TITLE} — ${action.charAt(0).toLowerCase()}${action.slice(1)}`;
}
