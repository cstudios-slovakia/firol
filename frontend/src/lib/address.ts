/**
 * Joins the structured address parts into the single combined string used for
 * read-only display (lists, detail headers, PDFs). Mirrors the backend
 * Firol\Support\Address::format so optimistic offline entities render the same
 * way the server would.
 */
export function formatAddress(
  street: string | null | undefined,
  postalCode: string | null | undefined,
  city: string | null | undefined,
): string | null {
  const locality = `${(postalCode ?? '').trim()} ${(city ?? '').trim()}`.trim();
  const parts = [(street ?? '').trim(), locality].filter((p) => p !== '');
  const out = parts.join(', ');
  return out !== '' ? out : null;
}
