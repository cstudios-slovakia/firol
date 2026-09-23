import { api } from '@/lib/api';
import type { InspectionType } from '@/api/inspections';
import type { Section } from '@/lib/sections';

/**
 * Calendar API (change request 2.5, BOZP extension chapter 11).
 *
 * One endpoint feeds the calendar, the Časová os and the Dnes screen:
 * deadlines are computed server-side from úkony (never stored); only planned
 * dates, vlastné udalosti and the record of sent notices persist.
 */

/**
 * Where a termín comes from (11.2) — shown on every termín as
 * {@link ZDROJ_LABELS}. No termín is ever presented as statutory (POKYNY rule 1).
 */
export type TerminZdroj = 'kontrola' | 'vlastny' | 'technik';

/** planovany (neutral) · po_termine (red, always on top) · splneny (greyed). */
export type TerminState = 'planovany' | 'po_termine' | 'splneny';

/** The technician a termín belongs to, ready for `<TechnicianAvatar>`. */
export type TerminTechnician = {
  id: number;
  fullname: string;
  initials: string;
  avatar_color: string;
};

/**
 * A deadline computed from the úkon that defines it (chapter 1.6.1a): the
 * latest finalized úkon of a type at a prevádzka, executed_on + its own
 * periodicity. Exactly one open deadline per type and prevádzka; fulfilled
 * ones (`splneny`) are reported for a year back.
 */
export type CalendarDeadline = {
  /** Stable key across all termíny, e.g. `kontrola-123`. */
  key: string;
  zdroj: 'kontrola';
  /** The úkon defining the deadline (opens the detail; plans attach to it). */
  inspection_id: number;
  type: InspectionType;
  /** Odbor — decides the colour (revizie #C75B45, opp #E8433A, bozp #3D7FC1). */
  section: Section | null;
  company_id: number;
  company_name: string;
  /** Company contact e-mail — recipient of the client notice (11.3). */
  company_email: string | null;
  facility_id: number;
  facility_name: string;
  /** Obec from the prevádzka's address; null when not filled in. */
  facility_city: string | null;
  /** Date the defining úkon was performed („posledná kontrola"). */
  last_done_on: string;
  /** executed_on + periodicity — the „predpripravený termín". */
  due_date: string;
  /** Optional planned visit date set by the technician. */
  planned_date: string | null;
  state: TerminState;
  /** For `splneny`: when and by which úkon it was fulfilled. */
  done_on: string | null;
  done_inspection_id: number | null;
  /** Who performed the defining úkon; null only for legacy data. */
  technician: TerminTechnician | null;
  /** When the automatic client notice went out (termin.oznamenie_odoslane). */
  notice_sent_at: string | null;
};

export type CalendarEvent = {
  id: number;
  key: string;
  zdroj: 'vlastny';
  title: string;
  event_date: string;
  note: string | null;
  company_id: number | null;
  company_name: string | null;
  facility_id: number | null;
  facility_name: string | null;
  facility_city: string | null;
  /** Who created it; null for events recorded before chapter 11. */
  technician: TerminTechnician | null;
};

/**
 * „Tvoj termín" — the signed-in technician's own certificate validity date
 * (and, for the main user, the firm's certificates). Never someone else's.
 */
export type OwnTerm = {
  key: string;
  zdroj: 'technik';
  /** po_technik | php_kontrola | php_oprava | bt | bts | vv */
  code: string;
  level: 'osobne' | 'firemne';
  title: string;
  section: Section;
  date: string;
  state: Exclude<TerminState, 'splneny'>;
};

export type CalendarData = {
  deadlines: CalendarDeadline[];
  events: CalendarEvent[];
  own_terms: OwnTerm[];
};

export type CalendarEventInput = {
  title: string;
  event_date: string;
  note?: string | null;
  company_id?: number | null;
  facility_id?: number | null;
};

/** Automatic client notice settings (11.3) — off after install. */
export type NoticeSettings = {
  enabled: boolean;
  days_ahead: 7 | 14 | 30;
};

export const NOTICE_DAYS: NoticeSettings['days_ahead'][] = [7, 14, 30];

/** How each zdroj reads in the UI (11.2). */
export const ZDROJ_LABELS: Record<TerminZdroj, string> = {
  kontrola: 'Predpripravený termín',
  vlastny: 'Vlastná udalosť',
  technik: 'Tvoj termín',
};

export const Calendar = {
  get: () => api<CalendarData>('/api/calendar'),

  setPlan: (inspectionId: number, plannedDate: string, csrfToken: string | null) =>
    api<{ ok: true }>(`/api/calendar/plans/${inspectionId}`, {
      method: 'PATCH',
      body: { planned_date: plannedDate },
      csrfToken,
    }),

  clearPlan: (inspectionId: number, csrfToken: string | null) =>
    api<void>(`/api/calendar/plans/${inspectionId}`, { method: 'DELETE', csrfToken }),

  createEvent: (body: CalendarEventInput, csrfToken: string | null) =>
    api<{ event: CalendarEvent }>('/api/calendar/events', { method: 'POST', body, csrfToken }),

  updateEvent: (id: number, body: CalendarEventInput, csrfToken: string | null) =>
    api<{ event: CalendarEvent }>(`/api/calendar/events/${id}`, { method: 'PATCH', body, csrfToken }),

  deleteEvent: (id: number, csrfToken: string | null) =>
    api<void>(`/api/calendar/events/${id}`, { method: 'DELETE', csrfToken }),

  noticeSettings: () => api<{ settings: NoticeSettings }>('/api/calendar/notice-settings'),

  updateNoticeSettings: (body: Partial<NoticeSettings>, csrfToken: string | null) =>
    api<{ settings: NoticeSettings }>('/api/calendar/notice-settings', {
      method: 'PATCH',
      body,
      csrfToken,
    }),
};
