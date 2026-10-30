const EMAIL_IN_TEXT = /[^\s@,;<>()]+@[^\s@,;<>()]+\.[^\s@,;<>()]+/;

/**
 * The address to prefill when sending a client protocols. The dedicated
 * „Kontaktný e-mail" wins; older records only have an address typed into the
 * free-text „Kontakt" line, so the first one found there is used instead.
 */
export function companyRecipientEmail(company: {
  contact_email: string | null;
  contact: string | null;
}): string | null {
  if (company.contact_email) return company.contact_email;
  return company.contact?.match(EMAIL_IN_TEXT)?.[0] ?? null;
}

/**
 * Spec 3.2 — the address a send or a termín notice is prefilled with.
 * The prevádzka's own e-mail wins, then {@link companyRecipientEmail}.
 * The PHP twin is Firol\Support\ClientEmail.
 */
export function clientRecipientEmail(input: {
  facility_email?: string | null;
  contact_email: string | null;
  contact: string | null;
}): string | null {
  const facility = input.facility_email?.trim();
  if (facility) return facility;
  return companyRecipientEmail(input);
}

/**
 * Bulk send (spec 3.2): when every picked protocol belongs to one prevádzka
 * that has an e-mail, that address is the prefill. Otherwise the firm's.
 */
export function bulkRecipientEmail(
  picked: { facility_id: number | null; facility_email?: string | null }[],
  companyEmail: string | null,
): string | null {
  const ids = new Set(picked.map((d) => d.facility_id));
  if (ids.size !== 1) return companyEmail;
  const only = [...ids][0];
  if (only == null) return companyEmail;
  const facilityEmail = picked.find((d) => d.facility_email?.trim())?.facility_email?.trim();
  return facilityEmail || companyEmail;
}
