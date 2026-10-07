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
