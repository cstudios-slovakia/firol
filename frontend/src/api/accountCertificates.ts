import { api } from '@/lib/api';

/**
 * Firemné oprávnenia — chapter 1.3.1. Bezpečnostnotechnická služba (BTS) and
 * výchova a vzdelávanie (VV) belong to the account, not to a technician: the
 * main user enters them once and the same number is printed on the protocols
 * of every technician (oboznámenie BOZP is issued under VV).
 */
export type CompanyCertificateType = 'bts' | 'vv';

export type CompanyCertificate = {
  type: CompanyCertificateType;
  /** opravnenia.json `nazov`. */
  label: string;
  legal_basis: string;
  number: string | null;
  valid_from: string | null;
  valid_to: string | null;
};

export type CompanyCertificateInput = {
  number: string | null;
  valid_from: string | null;
  valid_to: string | null;
};

export const AccountCertificates = {
  list: () => api<{ certificates: CompanyCertificate[] }>('/api/account/certificates'),
  /** Main user only. An empty number removes the certificate. */
  save: (
    certificates: Partial<Record<CompanyCertificateType, CompanyCertificateInput>>,
    csrfToken: string | null,
  ) =>
    api<{ certificates: CompanyCertificate[] }>('/api/account/certificates', {
      method: 'PATCH',
      body: { certificates },
      csrfToken,
      requireOnline: true,
    }),
};
