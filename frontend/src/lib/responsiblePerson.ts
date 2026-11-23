import type { CompanyPerson } from '@/api/companies';

/**
 * Prefill for the zodpovedná osoba (spec 6.6). Always a suggestion — the
 * field that uses it stays editable. The PHP twin is Firol\Support\ResponsiblePerson.
 *
 * A požiarna kniha entry takes the company-level person. Every other document
 * takes the prevádzka-level person, then falls back to the company-level one.
 */

export type ResponsiblePrefill = {
  fullname: string;
  role_title: string | null;
};

export function responsibleForFireBook(
  approver: string | null | undefined,
  persons: CompanyPerson[],
): ResponsiblePrefill | null {
  const companyWide = persons.find((p) => p.facility_id === null && p.is_default);
  if (companyWide) {
    return { fullname: companyWide.fullname, role_title: companyWide.role_title || null };
  }
  const name = approver?.trim();
  return name ? { fullname: name, role_title: null } : null;
}

export function responsibleForFacility(
  facilityId: number,
  contactPerson: string | null | undefined,
  approver: string | null | undefined,
  persons: CompanyPerson[],
): ResponsiblePrefill | null {
  const pinned = persons
    .filter((p) => p.facility_id === facilityId)
    .sort((a, b) => Number(b.is_default) - Number(a.is_default) || a.fullname.localeCompare(b.fullname, 'sk'));
  if (pinned[0]) {
    return { fullname: pinned[0].fullname, role_title: pinned[0].role_title || null };
  }
  const contact = contactPerson?.trim();
  if (contact) return { fullname: contact, role_title: null };
  return responsibleForFireBook(approver, persons);
}
