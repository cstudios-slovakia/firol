-- Chapter 11 — optional time on a vlastná udalosť.
--
--   calendar_events.time_from, calendar_events.time_to
--       Both NULL = an all-day event, exactly as before. time_to is only ever
--       set together with time_from (enforced by CalendarController).

ALTER TABLE calendar_events
    ADD COLUMN time_from TIME NULL DEFAULT NULL AFTER event_date,
    ADD COLUMN time_to   TIME NULL DEFAULT NULL AFTER time_from;
