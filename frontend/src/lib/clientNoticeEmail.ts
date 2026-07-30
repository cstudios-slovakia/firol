import type { InspectionType } from '@/api/inspections';
import type { FacilityDayGroup } from '@/lib/calendarGrouping';

/**
 * Client notice e-mail (change request 2.5.4).
 *
 * The app never sends anything — it only builds a `mailto:` link that opens the
 * technician's own mail client with the message pre-filled, so the text can
 * still be edited before it goes out. One grouped calendar event = one message
 * listing every control due at that prevádzka on that day, so a client never
 * receives several mails at once.
 */

/**
 * The controls named in the sentence "dňa … u vás vykonáme: …", in the
 * accusative case the sentence requires. Požiarna kniha is announced as
 * "preventívna protipožiarna prehliadka"; every other type is announced by the
 * name of its control (2.5.4).
 */
export const INSPECTION_TYPE_ACCUSATIVE: Record<InspectionType, string> = {
  php: 'kontrolu hasiacich prístrojov',
  hydranty: 'kontrolu požiarnych hydrantov',
  oprava_ts_php: 'opravu, plnenie a tlakovú skúšku hasiacich prístrojov',
  poziarna_kniha: 'preventívnu protipožiarnu prehliadku',
  pu_akcieschopnost: 'kontrolu akcieschopnosti požiarnych uzáverov',
  pu_udrzba: 'údržbu požiarnych uzáverov',
  nudzove_osvetlenie: 'kontrolu núdzového osvetlenia',
  ts_hadic: 'tlakovú skúšku hadíc',
  vyradenie: 'vyradenie hasiacich prístrojov',
};

/** "2026-08-15" → "15. 8. 2026" (no leading zeros, as the spec's sample). */
export function formatDateSk(isoDate: string): string {
  const [y, m, d] = isoDate.split('-');
  return `${Number(d)}. ${Number(m)}. ${y}`;
}

/** "a, b, c" → "a, b a c" — the Slovak enumeration in the sample text. */
function joinSk(parts: string[]): string {
  if (parts.length <= 1) return parts[0] ?? '';
  return `${parts.slice(0, -1).join(', ')} a ${parts[parts.length - 1]}`;
}

export type NoticeSender = {
  /** Technician's name — the signature line. */
  fullname: string;
  phone: string | null;
};

export type ClientNotice = {
  to: string | null;
  subject: string;
  body: string;
};

/**
 * The pre-filled message for one grouped calendar event. `to` is null when the
 * company has no contact e-mail recorded — the mail then opens with an empty
 * recipient rather than not opening at all.
 */
export function buildClientNotice(group: FacilityDayGroup, sender: NoticeSender): ClientNotice {
  const date = formatDateSk(group.date);
  // Deadlines are unique per facility+type, but dedupe anyway so a repeated
  // label could never read as "kontrolu … a kontrolu …".
  const controls = joinSk([
    ...new Set(group.deadlines.map((d) => INSPECTION_TYPE_ACCUSATIVE[d.type])),
  ]);
  const signature = [sender.fullname, sender.phone].filter(Boolean).join(', ');

  return {
    to: group.company_email,
    subject: `Oznámenie termínu kontroly — ${date}`,
    body: [
      'Dobrý deň,',
      '',
      `dňa ${date} u vás vykonáme: ${controls}.`,
      '',
      'V prípade potreby zmeny termínu nás prosím kontaktujte.',
      '',
      'S pozdravom,',
      signature,
    ].join('\r\n'),
  };
}

/** `mailto:` URL for a notice, with the subject and body pre-filled. */
export function mailtoUrl(notice: ClientNotice): string {
  const query = `subject=${encodeURIComponent(notice.subject)}&body=${encodeURIComponent(notice.body)}`;
  return `mailto:${notice.to ?? ''}?${query}`;
}
