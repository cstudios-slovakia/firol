import { useCallback, useEffect, useMemo, useState } from 'react';
import {
  ArrowRightLeft,
  Boxes,
  CheckCircle2,
  FileText,
  History,
  PackageMinus,
  PackagePlus,
  Plus,
  Receipt,
  WifiOff,
} from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import {
  ACTION_LABELS,
  STOCK_TEXTS,
  STOCK_UNITS,
  Stock,
  formatStockDate,
  movementRoute,
  type HolderRef,
  type StockAction,
  type StockHolder,
  type StockItem,
  type StockMovement,
  type StockUnit,
} from '@/api/stock';
import { Companies, type CompanyListItem } from '@/api/companies';
import {
  INSPECTION_TYPE_LABELS,
  Inspections,
  documentDownloadUrl,
  type InspectionListItem,
  type InspectionType,
} from '@/api/inspections';
import { offlineMessage } from '@/lib/offline';
import { useOnlineStatus } from '@/lib/useOnlineStatus';
import { useToast } from '@/lib/toast';
import { cn } from '@/lib/cn';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Dialog } from '@/components/ui/Dialog';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { SkeletonList } from '@/components/ui/Skeleton';
import { StockIssueDialog } from '@/components/stock/StockIssueDialog';

/**
 * Sklad — materiál a značenie (block 4 / chapter 21, postup 29.5).
 *
 * Položky: one row per item with a column for Sklad, one per technician and
 * Spolu. Tapping an item opens its panel — Nákup · Presun · Použité — and its
 * history. Pohyby: the journal, read-only. Na faktúru: použitý materiál added
 * to an invoice and not yet checked off (shown only when there is some).
 *
 * Not a warehouse system: no prices, batches or low-stock warnings. Writes are
 * online-only because the server has to check every balance.
 */

type Tab = 'polozky' | 'pohyby' | 'faktura';

const ACTION_TONES: Record<StockAction, 'ok' | 'warn' | 'bad'> = {
  nakup: 'ok',
  presun: 'warn',
  pouzite: 'bad',
};

export function StockPage() {
  const online = useOnlineStatus();
  const [tab, setTab] = useState<Tab>('polozky');
  const [holders, setHolders] = useState<StockHolder[]>([]);
  const [items, setItems] = useState<StockItem[] | null>(null);
  const [movements, setMovements] = useState<StockMovement[] | null>(null);
  const [toInvoice, setToInvoice] = useState<StockMovement[]>([]);
  const [error, setError] = useState<string | null>(null);

  const [newItemOpen, setNewItemOpen] = useState(false);
  // The item panel; `item` null = opened from „Nákup" in the header, where
  // the item is picked inside the panel.
  const [panel, setPanel] = useState<{ item: StockItem | null; action: StockAction } | null>(null);
  const [issueFor, setIssueFor] = useState<number | null>(null);

  const loadOverview = useCallback(() => {
    Stock.overview()
      .then((res) => {
        setHolders(res.holders);
        setItems(res.items);
        setError(null);
      })
      .catch((err: unknown) => setError(offlineMessage(err, 'Sklad sa nepodarilo načítať.')));
  }, []);

  const loadMovements = useCallback(() => {
    Stock.movements({ limit: 200 })
      .then((res) => setMovements(res.items))
      .catch(() => setMovements((prev) => prev ?? []));
    Stock.movements({ na_fakturu: true })
      .then((res) => setToInvoice(res.items))
      .catch(() => undefined);
  }, []);

  useEffect(() => {
    loadOverview();
    loadMovements();
  }, [loadOverview, loadMovements]);

  // Empty sections are not shown (POKYNY rule 8): the tab leaves with its
  // last entry.
  useEffect(() => {
    if (tab === 'faktura' && toInvoice.length === 0) setTab('polozky');
  }, [tab, toInvoice.length]);

  function refresh() {
    loadOverview();
    loadMovements();
  }

  return (
    <div className="flex flex-col gap-5">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold tracking-tight text-ink-900">{STOCK_TEXTS.nazov}</h1>
          {items && (
            <p className="mt-0.5 text-sm text-ink-500">
              {items.length} {plural(items.length, 'položka', 'položky', 'položiek')}
            </p>
          )}
        </div>
        <div className="flex flex-wrap gap-2">
          <Button
            variant="secondary"
            size="sm"
            leftIcon={<Plus className="size-4" />}
            onClick={() => setNewItemOpen(true)}
            disabled={!online}
          >
            {STOCK_TEXTS.nova_polozka}
          </Button>
          <Button
            size="sm"
            leftIcon={<PackagePlus className="size-4" />}
            onClick={() => setPanel({ item: null, action: 'nakup' })}
            disabled={!online || !items || items.length === 0}
          >
            {STOCK_TEXTS.nakup}
          </Button>
        </div>
      </header>

      {!online && (
        <Card className="flex items-start gap-3 px-4 py-3 text-sm text-ink-700">
          <WifiOff className="mt-0.5 size-4 shrink-0 text-status-warn" />
          <p>
            Pohyby v sklade sa zapisujú len s pripojením — server musí overiť, koľko má držiteľ u seba.
            Stav nižšie je z poslednej synchronizácie.
          </p>
        </Card>
      )}

      <div role="tablist" aria-label="Sklad" className="flex flex-wrap gap-1.5">
        <TabButton active={tab === 'polozky'} onClick={() => setTab('polozky')} icon={<Boxes className="size-4" />}>
          {STOCK_TEXTS.polozky}
        </TabButton>
        <TabButton active={tab === 'pohyby'} onClick={() => setTab('pohyby')} icon={<History className="size-4" />}>
          {STOCK_TEXTS.pohyby}
        </TabButton>
        {toInvoice.length > 0 && (
          <TabButton active={tab === 'faktura'} onClick={() => setTab('faktura')} icon={<Receipt className="size-4" />}>
            Na faktúru
            <span className="rounded-full bg-white/25 px-1.5 text-xs tabular-nums">{toInvoice.length}</span>
          </TabButton>
        )}
      </div>

      {error && <Card className="px-4 py-3 text-sm text-status-bad">{error}</Card>}

      {tab === 'polozky' &&
        (items === null ? (
          !error && <SkeletonList count={3} />
        ) : items.length === 0 ? (
          <Card className="flex flex-col items-center gap-2 px-4 py-8 text-center">
            <div className="grid size-12 place-items-center rounded-2xl bg-firol-50 text-firol-500">
              <Boxes className="size-5" />
            </div>
            <p className="text-sm text-ink-700">Sklad je zatiaľ prázdny.</p>
            <Button size="sm" leftIcon={<Plus className="size-4" />} onClick={() => setNewItemOpen(true)} disabled={!online}>
              {STOCK_TEXTS.nova_polozka}
            </Button>
          </Card>
        ) : (
          <ItemsTable items={items} holders={holders} onOpen={(item) => setPanel({ item, action: 'pouzite' })} />
        ))}

      {tab === 'pohyby' &&
        (movements === null ? (
          <SkeletonList count={4} />
        ) : movements.length === 0 ? (
          <Card className="px-4 py-6 text-center text-sm text-ink-500">Zatiaľ žiadne pohyby.</Card>
        ) : (
          <MovementList
            movements={movements}
            online={online}
            onChanged={refresh}
            onIssue={(id) => setIssueFor(id)}
          />
        ))}

      {tab === 'faktura' && <InvoiceList movements={toInvoice} online={online} onChanged={refresh} />}

      <NewItemDialog
        open={newItemOpen}
        onClose={() => setNewItemOpen(false)}
        onCreated={(item) => {
          setItems((prev) => [...(prev ?? []), item].sort((a, b) => a.name.localeCompare(b.name, 'sk')));
        }}
      />

      {panel && items && (
        <ItemPanel
          key={`${panel.item?.id ?? 'nakup'}-${panel.action}`}
          items={items}
          holders={holders}
          initialItem={panel.item}
          initialAction={panel.action}
          online={online}
          onClose={() => setPanel(null)}
          onRecorded={(item) => {
            setItems((prev) => prev?.map((i) => (i.id === item.id ? item : i)) ?? prev);
            loadMovements();
          }}
          onBillingChanged={loadMovements}
          onIssue={(id) => setIssueFor(id)}
        />
      )}

      <StockIssueDialog
        open={issueFor !== null}
        movementId={issueFor}
        onClose={() => setIssueFor(null)}
        onIssued={loadMovements}
      />
    </div>
  );
}

// ── Položky ────────────────────────────────────────────────────────────────

function ItemsTable({
  items,
  holders,
  onOpen,
}: {
  items: StockItem[];
  holders: StockHolder[];
  onOpen: (item: StockItem) => void;
}) {
  return (
    <>
      {/* Desktop: the table from the mockup — Sklad, one column per
          technician, Spolu. Scrolls sideways inside its card when the team is
          large, never the page. */}
      <Card className="hidden overflow-x-auto sm:block">
        <table className="w-full min-w-[520px] text-sm">
          <thead>
            <tr className="border-b border-ink-100 text-left text-[11px] font-semibold uppercase tracking-wide text-ink-500">
              <th className="px-4 py-2.5">Položka</th>
              <th className="px-3 py-2.5 text-right">{STOCK_TEXTS.sklad}</th>
              {holders.map((h) => (
                <th key={h.user_id} className="px-3 py-2.5 text-right">
                  <span className={cn(!h.is_active && 'text-ink-400')}>{h.name}</span>
                </th>
              ))}
              <th className="px-4 py-2.5 text-right">{STOCK_TEXTS.spolu}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-ink-100">
            {items.map((item) => (
              <tr
                key={item.id}
                onClick={() => onOpen(item)}
                className="cursor-pointer transition-colors duration-150 hover:bg-ink-50"
              >
                <td className="px-4 py-3">
                  <button type="button" className="text-left font-medium text-ink-900 hover:text-firol-700">
                    {item.name}
                  </button>
                  <div className="text-xs text-ink-400">{item.unit}</div>
                </td>
                <td className="px-3 py-3 text-right tabular-nums text-ink-700">{item.warehouse}</td>
                {holders.map((h) => (
                  <td key={h.user_id} className="px-3 py-3 text-right tabular-nums text-ink-700">
                    {item.balances[String(h.user_id)] ?? 0}
                  </td>
                ))}
                <td className="px-4 py-3 text-right font-semibold tabular-nums text-ink-900">{item.total}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </Card>

      {/* Mobile: a card per item, holders as chips. */}
      <ul className="flex flex-col gap-2 sm:hidden">
        {items.map((item) => (
          <li key={item.id}>
            <button
              type="button"
              onClick={() => onOpen(item)}
              className="w-full rounded-3xl border border-ink-100 bg-white px-4 py-3 text-left shadow-[var(--shadow-soft)] transition-all duration-200 active:scale-[0.99]"
            >
              <div className="flex items-baseline justify-between gap-3">
                <span className="min-w-0 truncate font-medium text-ink-900">{item.name}</span>
                <span className="shrink-0 text-sm font-semibold tabular-nums text-ink-900">
                  {item.total} {item.unit}
                </span>
              </div>
              <div className="mt-2 flex flex-wrap gap-1.5">
                <HolderChip name={STOCK_TEXTS.sklad} qty={item.warehouse} />
                {holders.map((h) => (
                  <HolderChip key={h.user_id} name={h.name} qty={item.balances[String(h.user_id)] ?? 0} />
                ))}
              </div>
            </button>
          </li>
        ))}
      </ul>
    </>
  );
}

function HolderChip({ name, qty }: { name: string; qty: number }) {
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs',
        qty > 0 ? 'bg-ink-100 text-ink-700' : 'bg-ink-50 text-ink-400',
      )}
    >
      {name} <span className="font-semibold tabular-nums">{qty}</span>
    </span>
  );
}

// ── Item panel: Nákup · Presun · Použité (postup 29.5) ─────────────────────

type HolderOption = { ref: HolderRef; name: string; qty: number };

function holderOptions(item: StockItem | null, holders: StockHolder[]): HolderOption[] {
  return [
    { ref: 'sklad', name: STOCK_TEXTS.sklad, qty: item?.warehouse ?? 0 },
    ...holders.map((h) => ({ ref: h.user_id as HolderRef, name: h.name, qty: item?.balances[String(h.user_id)] ?? 0 })),
  ];
}

function ItemPanel({
  items,
  holders,
  initialItem,
  initialAction,
  online,
  onClose,
  onRecorded,
  onBillingChanged,
  onIssue,
}: {
  items: StockItem[];
  holders: StockHolder[];
  initialItem: StockItem | null;
  initialAction: StockAction;
  online: boolean;
  onClose: () => void;
  onRecorded: (item: StockItem) => void;
  onBillingChanged: () => void;
  onIssue: (movementId: number) => void;
}) {
  const { csrfToken, user } = useAuth();
  const toast = useToast();

  const [itemId, setItemId] = useState<number | null>(initialItem?.id ?? null);
  const item = items.find((i) => i.id === itemId) ?? null;
  const [action, setAction] = useState<StockAction>(initialAction);

  // „Kto vydáva" starts at the technician using the app when they carry some
  // of this item — the usual case in the field — and at Sklad otherwise.
  const [from, setFrom] = useState<string>(() =>
    user && initialItem && (initialItem.balances[String(user.id)] ?? 0) > 0 ? String(user.id) : 'sklad',
  );
  const [to, setTo] = useState<string>('sklad');
  const [qty, setQty] = useState('');
  const [companyId, setCompanyId] = useState('');
  const [inspectionId, setInspectionId] = useState('');
  const [note, setNote] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  // After a použitie at a firm: the offer of chapter 21.
  const [recorded, setRecorded] = useState<StockMovement | null>(null);
  const [invoiceSaving, setInvoiceSaving] = useState(false);

  const [companies, setCompanies] = useState<CompanyListItem[] | null>(null);
  const [inspections, setInspections] = useState<InspectionListItem[]>([]);
  const [history, setHistory] = useState<StockMovement[] | null>(null);

  const activeHolderIds = new Set(holders.filter((h) => h.is_active).map((h) => String(h.user_id)));
  const options = holderOptions(item, holders);
  const fromOption = options.find((o) => String(o.ref) === from);

  useEffect(() => {
    if (action !== 'pouzite' || companies !== null) return;
    Companies.list()
      .then((res) => setCompanies(res.items))
      .catch(() => setCompanies([]));
  }, [action, companies]);

  useEffect(() => {
    setInspectionId('');
    if (!companyId) {
      setInspections([]);
      return;
    }
    let cancelled = false;
    Inspections.list({ company_id: Number(companyId) })
      .then((res) => {
        if (!cancelled) setInspections(res.items);
      })
      .catch(() => {
        if (!cancelled) setInspections([]);
      });
    return () => {
      cancelled = true;
    };
  }, [companyId]);

  useEffect(() => {
    if (!itemId) {
      setHistory(null);
      return;
    }
    let cancelled = false;
    Stock.movements({ item_id: itemId, limit: 20 })
      .then((res) => {
        if (!cancelled) setHistory(res.items);
      })
      .catch(() => {
        if (!cancelled) setHistory([]);
      });
    return () => {
      cancelled = true;
    };
  }, [itemId, recorded]);

  // A presun never goes to the holder it comes from.
  useEffect(() => {
    if (action === 'presun' && to === from) {
      const other = options.find((o) => String(o.ref) !== from && (o.ref === 'sklad' || activeHolderIds.has(String(o.ref))));
      if (other) setTo(String(other.ref));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [action, from]);

  function toRef(value: string): HolderRef {
    return value === 'sklad' ? 'sklad' : Number(value);
  }

  async function save() {
    if (!item) {
      setError('Vyber položku.');
      return;
    }
    const n = Number(qty);
    if (!Number.isInteger(n) || n < 1) {
      setError('Zadaj počet väčší ako 0.');
      return;
    }
    // Same check and the same sentence as the server (texty_ui.json →
    // sklad.nedostatok_zasob), so the technician learns it before tapping
    // Uložiť twice. The server still decides.
    if (action !== 'nakup' && fromOption && n > fromOption.qty) {
      setError(`Na ${fromOption.name} toľko nie je (${fromOption.qty} ${item.unit})`);
      return;
    }
    setSaving(true);
    setError(null);
    try {
      const res = await Stock.record(
        {
          item_id: item.id,
          action,
          from: action === 'nakup' ? undefined : toRef(from),
          to: action === 'pouzite' ? undefined : toRef(to),
          qty: n,
          company_id: action === 'pouzite' && companyId ? Number(companyId) : undefined,
          inspection_id: action === 'pouzite' && inspectionId ? Number(inspectionId) : undefined,
          note: note.trim() || undefined,
        },
        csrfToken,
      );
      onRecorded(res.item);
      if (res.movement.action === 'pouzite' && res.movement.company_id !== null) {
        setRecorded(res.movement);
      } else {
        toast.success('Pohyb zapísaný.');
        setQty('');
        setNote('');
      }
    } catch (err) {
      setError(offlineMessage(err, 'Pohyb sa nepodarilo zapísať.'));
    } finally {
      setSaving(false);
    }
  }

  async function addToInvoice() {
    if (!recorded) return;
    setInvoiceSaving(true);
    try {
      const res = await Stock.billing(recorded.id, { to_invoice: true }, csrfToken);
      setRecorded(res.movement);
      onBillingChanged();
      toast.success('Pridané na faktúru.');
    } catch (err) {
      toast.error(offlineMessage(err, 'Nepodarilo sa pridať na faktúru.'));
    } finally {
      setInvoiceSaving(false);
    }
  }

  const title = item ? item.name : STOCK_TEXTS.nakup;

  return (
    <Dialog
      open
      onClose={() => {
        if (!saving) onClose();
      }}
      title={title}
      description={item ? `${STOCK_TEXTS.spolu}: ${item.total} ${item.unit}` : undefined}
      maxWidthClassName="max-w-lg"
      dismissible={!saving}
    >
      {recorded ? (
        <div className="flex flex-col gap-4">
          <div className="flex items-start gap-3 rounded-2xl bg-[var(--color-status-ok-bg)] px-3 py-3 text-sm text-ink-800">
            <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-status-ok" />
            <p>
              Zapísané: {recorded.qty} {recorded.unit} · {recorded.item_name} ·{' '}
              <span className="font-medium">{recorded.company_name}</span>
            </p>
          </div>
          <div className="grid gap-2 sm:grid-cols-2">
            <Button
              variant="secondary"
              leftIcon={recorded.to_invoice ? <CheckCircle2 className="size-4 text-status-ok" /> : <Receipt className="size-4" />}
              onClick={addToInvoice}
              loading={invoiceSaving}
              disabled={recorded.to_invoice || !online}
            >
              {recorded.to_invoice ? 'Na faktúre' : STOCK_TEXTS.pridat_na_fakturu}
            </Button>
            <Button
              leftIcon={<FileText className="size-4" />}
              onClick={() => onIssue(recorded.id)}
              disabled={!online}
            >
              {STOCK_TEXTS.vystavit_vydajku}
            </Button>
          </div>
          <div className="flex justify-end">
            <Button variant="ghost" onClick={onClose}>
              Hotovo
            </Button>
          </div>
        </div>
      ) : (
        <div className="flex flex-col gap-4">
          <div role="tablist" aria-label="Druh pohybu" className="grid grid-cols-3 gap-1 rounded-2xl bg-ink-100 p-1">
            {(['nakup', 'presun', 'pouzite'] as StockAction[]).map((a) => (
              <button
                key={a}
                type="button"
                role="tab"
                aria-selected={action === a}
                onClick={() => {
                  setAction(a);
                  setError(null);
                }}
                className={cn(
                  'inline-flex h-9 items-center justify-center gap-1.5 rounded-xl text-sm font-medium transition-all duration-200',
                  action === a ? 'bg-white text-ink-900 shadow-sm' : 'text-ink-500 hover:text-ink-800',
                )}
              >
                {a === 'nakup' && <PackagePlus className="size-4" />}
                {a === 'presun' && <ArrowRightLeft className="size-4" />}
                {a === 'pouzite' && <PackageMinus className="size-4" />}
                {ACTION_LABELS[a]}
              </button>
            ))}
          </div>

          {!initialItem && (
            <Field label="Položka" required>
              {(p) => (
                <Select
                  {...p}
                  value={itemId ? String(itemId) : ''}
                  onChange={(v) => setItemId(v ? Number(v) : null)}
                  options={items.map((i) => ({ value: String(i.id), label: i.name, description: i.unit }))}
                  searchable
                />
              )}
            </Field>
          )}

          {action !== 'nakup' && (
            <Field label={action === 'pouzite' ? 'Kto vydáva' : 'Odkiaľ'} required>
              {(p) => (
                <Select
                  {...p}
                  value={from}
                  onChange={setFrom}
                  options={options.map((o) => ({
                    value: String(o.ref),
                    label: o.name,
                    description: `má ${o.qty} ${item?.unit ?? 'ks'}`,
                  }))}
                />
              )}
            </Field>
          )}

          {action !== 'pouzite' && (
            <Field label={action === 'nakup' ? 'Komu' : 'Kam'} required>
              {(p) => (
                <Select
                  {...p}
                  value={to}
                  onChange={setTo}
                  options={options
                    .filter((o) => o.ref === 'sklad' || activeHolderIds.has(String(o.ref)))
                    .filter((o) => action !== 'presun' || String(o.ref) !== from)
                    .map((o) => ({ value: String(o.ref), label: o.name }))}
                />
              )}
            </Field>
          )}

          <Field label="Počet" required>
            {(p) => (
              <Input
                {...p}
                type="number"
                inputMode="numeric"
                min={1}
                step={1}
                value={qty}
                onChange={(e) => setQty(e.target.value)}
                suffix={item?.unit}
              />
            )}
          </Field>

          {action === 'pouzite' && (
            <>
              <Field label="Firma" hint="Voliteľné">
                {(p) => (
                  <Select
                    {...p}
                    value={companyId}
                    onChange={setCompanyId}
                    placeholder="— bez firmy —"
                    searchable
                    options={[
                      { value: '', label: '— bez firmy —' },
                      ...(companies ?? []).map((c) => ({
                        value: String(c.id),
                        label: c.name,
                        description: c.ico ?? undefined,
                      })),
                    ]}
                  />
                )}
              </Field>
              {companyId && inspections.length > 0 && (
                <Field label="Úkon" hint="Voliteľné — ak sa materiál použil pri úkone">
                  {(p) => (
                    <Select
                      {...p}
                      value={inspectionId}
                      onChange={setInspectionId}
                      placeholder="— bez úkonu —"
                      options={[
                        { value: '', label: '— bez úkonu —' },
                        ...inspections.map((i) => ({
                          value: String(i.id),
                          label: INSPECTION_TYPE_LABELS[i.type as InspectionType] ?? i.type,
                          description: [i.facility_name, i.executed_on ? formatStockDate(i.executed_on) : null]
                            .filter(Boolean)
                            .join(' · '),
                        })),
                      ]}
                    />
                  )}
                </Field>
              )}
            </>
          )}

          <Field label="Poznámka" hint="Voliteľné">
            {(p) => <Input {...p} value={note} maxLength={500} onChange={(e) => setNote(e.target.value)} />}
          </Field>

          {error && <p className="text-sm text-status-bad">{error}</p>}
          {!online && (
            <p className="text-xs text-ink-500">Zápis pohybu vyžaduje pripojenie na internet.</p>
          )}

          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={onClose} disabled={saving}>
              Zrušiť
            </Button>
            <Button onClick={save} loading={saving} disabled={!online || !item}>
              Uložiť
            </Button>
          </div>

          {history && history.length > 0 && (
            <section className="border-t border-ink-100 pt-3">
              <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-500">História položky</h3>
              <ul className="flex max-h-56 flex-col divide-y divide-ink-100 overflow-y-auto">
                {history.map((m) => (
                  <li key={m.id} className="flex items-center gap-2 py-1.5 text-xs">
                    <span className="w-16 shrink-0 tabular-nums text-ink-500">{formatStockDate(m.created_at)}</span>
                    <Badge tone={ACTION_TONES[m.action]} className="px-2 py-0 text-[11px]">
                      {ACTION_LABELS[m.action]}
                    </Badge>
                    <span className="min-w-0 flex-1 truncate text-ink-700">
                      {movementRoute(m)}
                      {m.company_name && ` · ${m.company_name}`}
                    </span>
                    <span className="shrink-0 tabular-nums font-medium text-ink-800">{m.qty}</span>
                  </li>
                ))}
              </ul>
            </section>
          )}
        </div>
      )}
    </Dialog>
  );
}

// ── Pohyby (read-only journal) ─────────────────────────────────────────────

function MovementList({
  movements,
  online,
  onChanged,
  onIssue,
}: {
  movements: StockMovement[];
  online: boolean;
  onChanged: () => void;
  onIssue: (movementId: number) => void;
}) {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const [busy, setBusy] = useState<number | null>(null);

  async function addToInvoice(m: StockMovement) {
    setBusy(m.id);
    try {
      await Stock.billing(m.id, { to_invoice: true }, csrfToken);
      toast.success('Pridané na faktúru.');
      onChanged();
    } catch (err) {
      toast.error(offlineMessage(err, 'Nepodarilo sa pridať na faktúru.'));
    } finally {
      setBusy(null);
    }
  }

  return (
    <div className="flex flex-col gap-2">
      <Card className="overflow-hidden">
        <ul className="divide-y divide-ink-100">
          {movements.map((m) => {
            const atFirm = m.action === 'pouzite' && m.company_id !== null;
            return (
              <li key={m.id} className="flex flex-col gap-1.5 px-4 py-3">
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                  <span className="text-xs tabular-nums text-ink-500">{formatStockDate(m.created_at)}</span>
                  <Badge tone={ACTION_TONES[m.action]}>{ACTION_LABELS[m.action]}</Badge>
                  <span className="min-w-0 flex-1 truncate text-sm font-medium text-ink-900">{m.item_name}</span>
                  <span className="shrink-0 text-sm font-semibold tabular-nums text-ink-900">
                    {m.qty} {m.unit}
                  </span>
                </div>
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-ink-500">
                  <span>{movementRoute(m)}</span>
                  {m.company_name && <span>· {m.company_name}</span>}
                  {m.inspection_number && <span>· {m.inspection_number}</span>}
                  {m.note && <span className="text-ink-400">· {m.note}</span>}
                  {m.issue_document_id && m.issue_number && (
                    <a
                      href={documentDownloadUrl(m.issue_document_id)}
                      target="_blank"
                      rel="noopener"
                      className="font-medium text-firol-600 hover:text-firol-700"
                    >
                      · {m.issue_number}
                    </a>
                  )}
                  {m.to_invoice && (
                    <Badge tone={m.invoiced ? 'ok' : 'neutral'} className="px-2 py-0 text-[11px]">
                      {m.invoiced ? 'Vyfakturované' : 'Na faktúre'}
                    </Badge>
                  )}
                </div>
                {atFirm && online && (!m.to_invoice || !m.issue_id) && (
                  <div className="flex flex-wrap gap-2 pt-0.5">
                    {!m.to_invoice && (
                      <Button
                        variant="secondary"
                        size="sm"
                        leftIcon={<Receipt className="size-3.5" />}
                        loading={busy === m.id}
                        onClick={() => addToInvoice(m)}
                      >
                        {STOCK_TEXTS.pridat_na_fakturu}
                      </Button>
                    )}
                    {!m.issue_id && (
                      <Button
                        variant="secondary"
                        size="sm"
                        leftIcon={<FileText className="size-3.5" />}
                        onClick={() => onIssue(m.id)}
                      >
                        {STOCK_TEXTS.vystavit_vydajku}
                      </Button>
                    )}
                  </div>
                )}
              </li>
            );
          })}
        </ul>
      </Card>
      <p className="px-1 text-xs text-ink-400">Denník pohybov je iba na čítanie.</p>
    </div>
  );
}

// ── Na faktúru ─────────────────────────────────────────────────────────────

/**
 * Použitý materiál added to an invoice and not yet invoiced. Ticking
 * „Vyfakturované" is the same check-off as on an úkon (chapter 22): the app
 * issues no invoices and tracks no payments.
 */
function InvoiceList({
  movements,
  online,
  onChanged,
}: {
  movements: StockMovement[];
  online: boolean;
  onChanged: () => void;
}) {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const [busy, setBusy] = useState<number | null>(null);

  const byCompany = useMemo(() => {
    const groups = new Map<string, StockMovement[]>();
    for (const m of movements) {
      const key = m.company_name ?? '—';
      groups.set(key, [...(groups.get(key) ?? []), m]);
    }
    return [...groups.entries()];
  }, [movements]);

  async function markInvoiced(m: StockMovement) {
    setBusy(m.id);
    try {
      await Stock.billing(m.id, { invoiced: true }, csrfToken);
      toast.success('Označené ako vyfakturované.');
      onChanged();
    } catch (err) {
      toast.error(offlineMessage(err, 'Nepodarilo sa uložiť.'));
    } finally {
      setBusy(null);
    }
  }

  return (
    <div className="flex flex-col gap-3">
      {byCompany.map(([company, rows]) => (
        <Card key={company} className="overflow-hidden">
          <h2 className="border-b border-ink-100 px-4 py-2.5 text-sm font-semibold text-ink-900">{company}</h2>
          <ul className="divide-y divide-ink-100">
            {rows.map((m) => (
              <li key={m.id} className="flex items-center gap-3 px-4 py-2.5">
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm text-ink-900">
                    {m.item_name} · <span className="font-semibold tabular-nums">{m.qty} {m.unit}</span>
                  </p>
                  <p className="text-xs text-ink-500">
                    {formatStockDate(m.created_at)}
                    {m.issue_number && ` · ${m.issue_number}`}
                  </p>
                </div>
                <Button
                  variant="secondary"
                  size="sm"
                  leftIcon={<CheckCircle2 className="size-3.5" />}
                  loading={busy === m.id}
                  disabled={!online}
                  onClick={() => markInvoiced(m)}
                >
                  Vyfakturované
                </Button>
              </li>
            ))}
          </ul>
        </Card>
      ))}
    </div>
  );
}

// ── Nová položka ───────────────────────────────────────────────────────────

function NewItemDialog({
  open,
  onClose,
  onCreated,
}: {
  open: boolean;
  onClose: () => void;
  onCreated: (item: StockItem) => void;
}) {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const [name, setName] = useState('');
  const [unit, setUnit] = useState<StockUnit>('ks');
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!open) return;
    setName('');
    setUnit('ks');
    setError(null);
  }, [open]);

  async function save() {
    if (!name.trim()) {
      setError('Zadaj názov položky.');
      return;
    }
    setSaving(true);
    setError(null);
    try {
      const res = await Stock.createItem({ name: name.trim(), unit }, csrfToken);
      onCreated(res.item);
      toast.success('Položka pridaná.');
      onClose();
    } catch (err) {
      setError(offlineMessage(err, 'Položku sa nepodarilo pridať.'));
    } finally {
      setSaving(false);
    }
  }

  return (
    <Dialog
      open={open}
      onClose={() => {
        if (!saving) onClose();
      }}
      title={STOCK_TEXTS.nova_polozka}
      dismissible={!saving}
    >
      <div className="flex flex-col gap-4">
        <Field label="Názov" required>
          {(p) => (
            <Input
              {...p}
              value={name}
              maxLength={191}
              onChange={(e) => setName(e.target.value)}
              placeholder="napr. Nálepka kontroly PHP"
            />
          )}
        </Field>
        <Field label="Jednotka" required>
          {(p) => (
            <Select
              {...p}
              value={unit}
              onChange={(v) => setUnit(v as StockUnit)}
              options={STOCK_UNITS.map((u) => ({ value: u, label: u }))}
            />
          )}
        </Field>
        {error && <p className="text-sm text-status-bad">{error}</p>}
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Zrušiť
          </Button>
          <Button onClick={save} loading={saving}>
            Pridať
          </Button>
        </div>
      </div>
    </Dialog>
  );
}

// ── Bits ───────────────────────────────────────────────────────────────────

function TabButton({
  active,
  onClick,
  icon,
  children,
}: {
  active: boolean;
  onClick: () => void;
  icon: React.ReactNode;
  children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      role="tab"
      aria-selected={active}
      onClick={onClick}
      className={cn(
        'inline-flex h-9 items-center gap-1.5 rounded-2xl px-3.5 text-sm font-medium transition-all duration-150 active:scale-[0.98]',
        active ? 'bg-ink-800 text-white shadow-sm' : 'bg-ink-100 text-ink-600 hover:bg-ink-200',
      )}
    >
      {icon}
      {children}
    </button>
  );
}

function plural(n: number, one: string, few: string, many: string): string {
  if (n === 1) return one;
  if (n >= 2 && n <= 4) return few;
  return many;
}
