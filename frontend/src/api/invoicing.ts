import { api } from '@/lib/api';

/**
 * Fakturácia úkonu — block 4 / chapter 22.
 *
 * Not the SaaS subscription (api/billing.ts): this is the technician's own
 * record of which úkony they still have to invoice to their clients. Four
 * fields on every úkon — inspections and trainings alike. There is no
 * payment status on purpose: the spec rules it out.
 */

/** ciselniky.json `rezim_fakturacie` codes. */
export type BillingMode = 'pausal' | 'na_fakturu' | 'nefakturuje_sa';

/** What a firm can be set to — the spec offers only these two there. */
export type CompanyBillingMode = Extract<BillingMode, 'pausal' | 'na_fakturu'>;

/** ciselniky.json `rezim_fakturacie` names, verbatim. */
export const BILLING_MODE_LABELS: Record<BillingMode, string> = {
  pausal: 'Paušál',
  na_fakturu: 'Na faktúru',
  nefakturuje_sa: 'Nefakturuje sa',
};

export const BILLING_MODES: BillingMode[] = ['pausal', 'na_fakturu', 'nefakturuje_sa'];
export const COMPANY_BILLING_MODES: CompanyBillingMode[] = ['pausal', 'na_fakturu'];

/** Column default for a firm that never had the setting chosen (migration 046). */
export const COMPANY_BILLING_DEFAULT: CompanyBillingMode = 'na_fakturu';

/** texty_ui.json `dnes.karta_nefakturovane`. */
export const UNINVOICED_LABEL = 'Nevyfakturované';

/**
 * URL switch that opens an úkon list already filtered to „Nevyfakturované"
 * — e.g. `/revizie?nevyfakturovane=1`, `/inspections?nevyfakturovane=1`,
 * `/opp?tab=skolenia&nevyfakturovane=1` — so the Dnes card (chapter 18) can link
 * straight to the rows it counts.
 */
export const UNINVOICED_PARAM = 'nevyfakturovane';

/**
 * The four fields as the API returns them on an inspection or training.
 * `billing_mode` is null on úkony recorded before the app tracked invoicing.
 */
export type InvoicingFields = {
  billing_mode: BillingMode | null;
  invoiced: boolean;
  /** YYYY-MM-DD; only ever set while `billing_mode` is `na_fakturu`. */
  invoiced_at: string | null;
  billing_note: string | null;
};

export type InvoicingPatch = {
  billing_mode?: BillingMode;
  invoiced?: boolean;
  invoiced_at?: string | null;
  billing_note?: string | null;
};

/** Summary behind the Dnes card „Nevyfakturované" (chapter 18, row 6). */
export type UninvoicedSummary = {
  inspections: number;
  trainings: number;
  total: number;
};

/** Exactly the server's rule — chapters 18 and 22. */
export function isUninvoiced(f: Partial<InvoicingFields>): boolean {
  return f.billing_mode === 'na_fakturu' && !f.invoiced;
}

function today(): string {
  const d = new Date();
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

/**
 * Client-side mirror of Firol\Support\Invoicing::merge — used to show a change
 * immediately and to patch the offline cache while the PATCH waits in the
 * outbox. The server's answer replaces it as soon as there is one.
 */
export function mergeInvoicing(
  current: Partial<InvoicingFields>,
  patch: InvoicingPatch,
): InvoicingFields {
  const mode = patch.billing_mode !== undefined ? patch.billing_mode : current.billing_mode ?? null;
  let invoiced = patch.invoiced !== undefined ? patch.invoiced : current.invoiced ?? false;
  let invoicedAt = current.invoiced_at ?? null;
  if (patch.invoiced === false) invoicedAt = null;
  if (patch.invoiced_at !== undefined && invoiced) invoicedAt = patch.invoiced_at;
  let note = patch.billing_note !== undefined ? patch.billing_note : current.billing_note ?? null;
  note = note?.trim() ? note.trim() : null;

  if (mode !== 'na_fakturu') {
    invoiced = false;
    invoicedAt = null;
  } else if (invoiced && !invoicedAt) {
    invoicedAt = today();
  }
  return { billing_mode: mode, invoiced, invoiced_at: invoicedAt, billing_note: note };
}

/** Pull the four fields off a row that may predate them (old offline cache). */
export function invoicingOf(row: Partial<InvoicingFields>): InvoicingFields {
  return {
    billing_mode: row.billing_mode ?? null,
    invoiced: row.invoiced ?? false,
    invoiced_at: row.invoiced_at ?? null,
    billing_note: row.billing_note ?? null,
  };
}

export type InvoicingTarget = 'inspections' | 'trainings';

export const Invoicing = {
  /**
   * Partial update of an úkon's invoicing. Works on a locked úkon too —
   * invoicing is not part of the protocol, and nothing here touches it.
   * Queued offline like any other edit of an existing record.
   */
  update: (target: InvoicingTarget, id: number, body: InvoicingPatch, csrfToken: string | null) =>
    api<{ invoicing: InvoicingFields }>(`/api/${target}/${id}/invoicing`, {
      method: 'PATCH',
      body,
      csrfToken,
      label: 'Fakturácia úkonu',
    }),
  /**
   * Count of úkony with režim na faktúru not yet checked off. `mine` narrows
   * it to the logged-in technician's own úkony (Moje / Celý tím, chapter 18).
   */
  summary: (mine = false) =>
    api<UninvoicedSummary>(`/api/invoicing/summary${mine ? '?scope=mine' : ''}`),
};
