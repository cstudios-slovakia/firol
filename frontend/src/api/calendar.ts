import { api } from '@/lib/api';
import type { InspectionType } from '@/api/inspections';

/**
 * Calendar API (change request 2.5). Statutory deadlines are computed
 * server-side from inspections; only planned dates and custom events persist.
 */

export type CalendarDeadline = {
  inspection_id: number;
  type: InspectionType;
  company_id: number;
  company_name: string;
  /** Company contact e-mail — recipient of the client notice (2.5.4). */
  company_email: string | null;
  facility_id: number;
  facility_name: string;
  /** Legal deadline — executed_on + periodicity. Not directly editable. */
  statutory_date: string;
  /** Optional planned visit date set by the technician. */
  planned_date: string | null;
};

export type CalendarEvent = {
  id: number;
  title: string;
  event_date: string;
  note: string | null;
  company_id: number | null;
  company_name: string | null;
  facility_id: number | null;
  facility_name: string | null;
};

export type CalendarData = {
  deadlines: CalendarDeadline[];
  events: CalendarEvent[];
};

export type CalendarEventInput = {
  title: string;
  event_date: string;
  note?: string | null;
  company_id?: number | null;
  facility_id?: number | null;
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
};
