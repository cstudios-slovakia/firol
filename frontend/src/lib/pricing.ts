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
          vatPercent: typeof data.vat_rate_percent === 'number' ? data.vat_rate_percent : 0,
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

/** "27 € / mesiac" or "270 € / rok"; "—" while prices are loading. */
export function periodPriceLabel(pricing: Pricing | null, period: 'monthly' | 'yearly'): string {
  if (!pricing) return '—';
  return period === 'yearly'
    ? `${formatPrice(pricing.yearly)} / rok`
    : `${formatPrice(pricing.monthly)} / mesiac`;
}

/**
 * "Ekvivalent ~22,50 € / mesiac — ušetríš 2 mesiace." — the savings clause
 * is dropped when the yearly plan isn't cheaper than 12 × monthly.
 */
export function yearlyHint(pricing: Pricing | null, separator = ' —'): string {
  if (!pricing) return '';
  const perMonth = `Ekvivalent ~${formatPrice(pricing.yearly / 12)} / mesiac`;
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
