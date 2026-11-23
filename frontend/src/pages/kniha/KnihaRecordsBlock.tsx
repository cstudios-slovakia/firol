import { useState } from 'react';
import { ChevronDown } from 'lucide-react';
import type { KnihaContext, KnihaRecord } from '@/api/kniha';
import { facilityNouns } from '@/lib/clientKind';
import { formatDateSk } from '@/lib/clientNoticeEmail';
import { cn } from '@/lib/cn';
import { zaznamKey, type KnihaZaznam } from '@/lib/kniha';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { CheckRow, Segmented } from '@/components/prehliadka/ui';

type Group = { key: string; title: string | null; records: KnihaRecord[] };

/**
 * Priložiť záznamy (spec 7): the records of the selected prevádzky — grouped
 * with a heading when there are several, the organisation's own trainings once
 * under the heading the server gives — the newest of each kind, the rest under
 * „Zobraziť aj staršie". A ticked record gets „Celým textom / Len odkaz" and
 * its paragraph, in italics, right below; ticking sets the full text.
 */
export function KnihaRecordsBlock({
  ctx,
  facilityIds,
  zaznamy,
  disabled,
  onChange,
}: {
  ctx: KnihaContext;
  /** The entry's prevádzky. */
  facilityIds: number[];
  zaznamy: KnihaZaznam[];
  disabled: boolean;
  onChange: (next: KnihaZaznam[]) => void;
}) {
  const [showOlder, setShowOlder] = useState(false);
  const ticked = new Map(zaznamy.map((z) => [zaznamKey(z), z]));

  const groups: Group[] = [];
  for (const f of ctx.facilities) {
    if (!facilityIds.includes(f.id)) continue;
    const records = ctx.records.filter((r) => r.facility_id === f.id);
    if (records.length > 0) groups.push({ key: `f${f.id}`, title: facilityIds.length > 1 ? f.name : null, records });
  }
  if (ctx.company_group_heading !== null) {
    const records = ctx.records.filter((r) => r.facility_id === null);
    if (records.length > 0) groups.push({ key: 'org', title: ctx.company_group_heading, records });
  }

  const anyOlder = groups.some((g) => g.records.some((r) => !r.is_latest));

  function toggle(r: KnihaRecord) {
    if (ticked.has(r.key)) onChange(zaznamy.filter((z) => zaznamKey(z) !== r.key));
    else onChange([...zaznamy, { kind: r.kind, id: r.id, forma: 'plny' }]);
  }

  function setForma(r: KnihaRecord, forma: 'plny' | 'odkaz') {
    onChange(zaznamy.map((z) => (zaznamKey(z) === r.key ? { ...z, forma } : z)));
  }

  return (
    <Card className="animate-fade-up flex flex-col gap-3 p-4">
      <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-500">Priložiť záznamy</h2>
      {groups.length === 0 ? (
        <p className="rounded-xl border border-dashed border-ink-200 px-3 py-2.5 text-sm text-ink-500">
          Z vybraných {facilityNouns(ctx.company.client_type).pluralGen} nie je čo priložiť.
        </p>
      ) : (
        groups.map((g) => {
          // A ticked older record stays in view while the older ones are hidden.
          const visible = g.records.filter((r) => showOlder || r.is_latest || ticked.has(r.key));
          if (visible.length === 0) return null;
          return (
            <section key={g.key} className="flex flex-col gap-2">
              {g.title !== null && (
                <h3 className="mt-1 text-[11px] font-semibold uppercase tracking-wide text-ink-400">{g.title}</h3>
              )}
              <ul className="flex flex-col gap-2">
                {visible.map((r) => {
                  const z = ticked.get(r.key);
                  return (
                    <li key={r.key} className={cn('flex flex-col gap-2', !r.is_latest && 'animate-fade-in')}>
                      <CheckRow
                        id={`kn-rec-${r.key}`}
                        checked={z !== undefined}
                        disabled={disabled}
                        onToggle={() => toggle(r)}
                        title={
                          <span className="inline-flex flex-wrap items-center gap-x-1.5 gap-y-1">
                            <span>{r.label}</span>
                            {r.tag === 'ppp' && <Badge tone="brand" className="px-2 text-[11px]">PPP</Badge>}
                            {r.tag === 'skolenie' && <Badge className="bg-emerald-50 px-2 text-[11px] text-emerald-700">Školenie</Badge>}
                          </span>
                        }
                        sub={[formatDateSk(r.date), r.number, r.meta].filter((v) => v !== '').join(' · ')}
                      />
                      {z && (
                        <div className="animate-fade-in ml-8 flex flex-col gap-1.5">
                          {!disabled && (
                            <Segmented
                              label={`Forma záznamu ${r.number}`}
                              value={z.forma}
                              options={[
                                { value: 'plny', label: 'Celým textom' },
                                { value: 'odkaz', label: 'Len odkaz' },
                              ]}
                              onChange={(v) => setForma(r, v)}
                            />
                          )}
                          <p key={z.forma} className="animate-fade-in text-xs italic leading-relaxed text-ink-600">
                            {z.forma === 'odkaz' ? r.text_ref : r.text_full}
                          </p>
                        </div>
                      )}
                    </li>
                  );
                })}
              </ul>
            </section>
          );
        })
      )}
      {anyOlder && (
        <Button
          type="button"
          id="kn-rec-older"
          variant="ghost"
          size="sm"
          className="self-start"
          rightIcon={<ChevronDown className={cn('size-4 transition-transform duration-200', showOlder && 'rotate-180')} />}
          onClick={() => setShowOlder((v) => !v)}
        >
          {showOlder ? 'Skryť staršie' : 'Zobraziť aj staršie'}
        </Button>
      )}
    </Card>
  );
}
