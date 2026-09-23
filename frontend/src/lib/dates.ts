/**
 * Today's date as YYYY-MM-DD in the device's local time zone. Not
 * `toISOString()` — that is UTC and would hand a technician working after
 * midnight yesterday's date.
 */
export function todayIso(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
