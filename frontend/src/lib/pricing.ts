/*
 * Subscription prices as configured by the admin in system settings
 * (`price_monthly_eur` / `price_yearly_eur`, without VAT). Read from the
 * public settings endpoint so every screen that quotes a price shows the
 * same figures as the admin form — never hardcode them in copy.
 *
 * The fetch is shared and cached for the page's lifetime; the admin
 * settings form calls `invalidatePricing()` after a save.
 */
import { useEffect, useState } from 'react';
import { buildUrl } from './api';

export type Pricing = {
  monthly: number;
  yearly: number;
  vatPercent: number;
};

let pending: Promise<Pricing | null> | null = null;

function loadPricing(): Promise<Pricing | null> {
  if (!pending) {
    pending = fetch(buildUrl('/api/public/settings'))
      .then((r) => (r.ok ? r.json() : null))
      .then((data) => {
        if (!data || typeof data.price_monthly_eur !== 'number' || typeof data.price_yearly_eur !== 'number') {
          return null;
        }
        return {
          monthly: data.price_monthly_eur,
          yearly: data.price_yearly_eur,
          vatPercent: typeof data.vat_rate_percent === 'number' ? data.vat_rate_percent : 23,
        };
      })
      .catch(() => null)
      .then((p) => {
        // Don't cache a failure — the next mount retries.
        if (p === null) pending = null;
        return p;
      });
  }
  return pending;
}

export function invalidatePricing(): void {
  pending = null;
}

/** Current prices, or null while loading / when the request failed. */
export function usePricing(): Pricing | null {
  const [pricing, setPricing] = useState<Pricing | null>(null);
  useEffect(() => {
    let cancelled = false;
    loadPricing().then((p) => { if (!cancelled) setPricing(p); });
    return () => { cancelled = true; };
  }, []);
  return pricing;
}

/** "27 €", "22,50 €" — whole amounts without decimals. */
export function formatPrice(n: number): string {
  const whole = Math.round(n * 100) % 100 === 0;
  return `${n.toLocaleString('sk-SK', {
    minimumFractionDigits: whole ? 0 : 2,
    maximumFractionDigits: 2,
  })} €`;
}

/** Amount in cents as "135,00 €" — always two decimals. */
export function formatCents(cents: number): string {
  return `${(cents / 100).toLocaleString('sk-SK', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })} €`;
}

/*
 * VAT wording (owner's decision of 18. 11. 2026: list prices are net, the
 * customer pays the price plus VAT). One wording everywhere:
 *   - a price:            "27 € + DPH / mesiac"
 *   - a charge announced: "33,21 € s DPH (27,00 € + DPH 23 %)"
 * The rate is VAT_RATE_PERCENT on the server (public settings /
 * account.vat_rate_percent).
 */

/** VAT of a net amount in cents, rounded like Stripe rounds one line. */
export function vatCents(netCents: number, vatPercent: number): number {
  return Math.round((netCents * vatPercent) / 100);
}

/** Net amount plus VAT, in cents. */
export function grossCents(netCents: number, vatPercent: number): number {
  return netCents + vatCents(netCents, vatPercent);
}

/** "23 %" / "20,5 %". */
export function vatPercentLabel(vatPercent: number): string {
  return `${vatPercent.toLocaleString('sk-SK', { maximumFractionDigits: 2 })} %`;
}

/** "33,21 € s DPH (27,00 € + DPH 23 %)" — what a charge costs. */
export function chargeLabel(netCents: number, vatPercent: number): string {
  return `${formatCents(grossCents(netCents, vatPercent))} s DPH (${formatCents(netCents)} + DPH ${vatPercentLabel(vatPercent)})`;
}

/** "27 € + DPH / mesiac" or "270 € + DPH / rok"; "—" while prices are loading. */
export function periodPriceLabel(pricing: Pricing | null, period: 'monthly' | 'yearly'): string {
  if (!pricing) return '—';
  return period === 'yearly'
    ? `${formatPrice(pricing.yearly)} + DPH / rok`
    : `${formatPrice(pricing.monthly)} + DPH / mesiac`;
}

/**
 * "Ekvivalent ~22,50 € + DPH / mesiac — ušetríš 2 mesiace." — the savings
 * clause is dropped when the yearly plan isn't cheaper than 12 × monthly.
 */
export function yearlyHint(pricing: Pricing | null, separator = ' —'): string {
  if (!pricing) return '';
  const perMonth = `Ekvivalent ~${formatPrice(pricing.yearly / 12)} + DPH / mesiac`;
  const saved = pricing.monthly > 0
    ? Math.round((pricing.monthly * 12 - pricing.yearly) / pricing.monthly)
    : 0;
  return saved > 0 ? `${perMonth}${separator} ušetríš ${saved} ${monthsWord(saved)}` : perMonth;
}

function monthsWord(n: number): string {
  if (n === 1) return 'mesiac';
  if (n >= 2 && n <= 4) return 'mesiace';
  return 'mesiacov';
}
