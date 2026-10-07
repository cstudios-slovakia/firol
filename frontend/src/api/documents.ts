import { api } from '@/lib/api';
import type { InspectionType } from '@/api/inspections';

/** What the client signed for when they took the protocol over (chapter 13). */
export type HandoverPayload = {
  /** A person saved on the company, when one was picked from the list. */
  person_id?: number;
  /** Otherwise the name and role typed on the spot. */
  fullname?: string;
  role_title?: string;
  place: string;
  signed_on: string;
  signed_at_time: string;
  /** The canvas signature as a PNG data URI. */
  signature: string;
};

/** A protocol available for a bulk send (chapter 9.1). */
export type SendableDocument = {
  id: number;
  /** An úkon's protocol, a výdajka materiálu (block 4 / chapter 21), or the protocol of a školenie PO. */
  type: InspectionType | 'vydajka' | 'skolenie';
  number: string;
  executed_on: string | null;
  /** Null for a výdajka not tied to one prevádzka. */
  facility_id: number | null;
  facility_name: string | null;
  /** Size of the PDF, so the technician can see what is filling the message. */
  byte_size: number;
  /** Already went out to this company in a send with status `odoslane`. */
  already_sent: boolean;
};

export type DocumentSendPayload = {
  document_ids: number[];
  recipients: string[];
  subject?: string;
  note?: string | null;
  visit_id?: number;
};

/** One recorded send, for the company's history. */
export type DocumentSend = {
  id: number;
  visit_id: number | null;
  documents: { id: number; number: string }[];
  recipients: string[];
  subject: string;
  note: string | null;
  status: 'caka' | 'odoslane' | 'chyba';
  sent_at: string | null;
  error_text: string | null;
  created_at: string;
  sent_by: string | null;
};

export const Documents = {
  email: (
    documentId: number,
    payload: { email: string; note?: string | null },
    csrfToken: string | null,
  ) =>
    api<{ sent: true; to: string }>(`/api/documents/${documentId}/email`, {
      method: 'POST',
      body: payload,
      csrfToken,
    }),

  /**
   * Capture the client's signature on an issued protocol (chapter 13). The
   * protocol is re-rendered under the SAME number as a new version — a new
   * number is never handed out, because the client may already hold version 1.
   */
  handover: (documentId: number, payload: HandoverPayload, csrfToken: string | null) =>
    api<{
      document: { id: number; number: string; version: number; download_url: string };
      handover: { fullname: string; role_title: string; place: string; signed_on: string };
    }>(`/api/documents/${documentId}/handover`, {
      method: 'POST',
      body: payload,
      csrfToken,
      requireOnline: true,
    }),

  /** Protocols of one company that can go into a bulk e-mail (chapter 9.1). */
  sendable: (companyId: number) =>
    api<{ items: SendableDocument[]; max_total_bytes: number }>(
      `/api/companies/${companyId}/sendable-documents`,
    ),

  /** Send history for a company — when, to whom, and which protocols. */
  sends: (companyId: number) =>
    api<{ items: DocumentSend[] }>(`/api/companies/${companyId}/sends`),

  /** One e-mail carrying several protocols. */
  send: (companyId: number, payload: DocumentSendPayload, csrfToken: string | null) =>
    api<{
      send_id: number;
      status: string;
      recipients: string[];
      documents: number;
      /** Numbers of the protocols whose photos went out shrunk to fit the limit. */
      shrunk: string[];
    }>(
      `/api/companies/${companyId}/sends`,
      { method: 'POST', body: payload, csrfToken, requireOnline: true },
    ),
};
