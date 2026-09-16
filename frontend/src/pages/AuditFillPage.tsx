import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import {
  AlertTriangle, ArrowLeft, ArrowRight, Check, ChevronDown, ChevronRight,
  CircleSlash, FileCheck2, Minus, Plus, Trash2, X,
} from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import {
  AUDIT_KIND_LABELS,
  AUDIT_SCOPE_LABELS,
  Audits,
  type AuditItem,
  type AuditResult,
  type AuditSectionSummary,
  type AuditSummary,
  type AuditView,
  type LinkedWork,
} from '@/api/audits';
import { ApiError, OfflineQueuedError } from '@/lib/api';
import { cn } from '@/lib/cn';
import { useToast } from '@/lib/toast';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Spinner } from '@/components/ui/Spinner';
import { ItemPhotoField, usePhotoStaging } from '@/components/ItemPhotos';

/**
 * Filling in an audit — block 3 / chapter 15.5.
 *
 * The screen is built around one fact: there are between 75 and 109 questions
 * and roughly five of them will be answered anything other than „vyhovuje".
 * So the shortest path through it is „Označiť všetko ako vyhovuje", then
 * correcting the handful that are not — and everything here is arranged so
 * that correcting one is quick and marking everything is one tap.
 *
 * Sections collapse once they are complete, so the screen shortens as the
 * technician walks the building instead of staying 109 rows long.
 */
export function AuditFillPage() {
  const { id } = useParams<{ id: string }>();
  const inspectionId = Number(id);
  const { csrfToken } = useAuth();
  const toast = useToast();
  const navigate = useNavigate();

  const [view, setView] = useState<AuditView | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [open, setOpen] = useState<Record<string, boolean>>({});
  const [carryDismissed, setCarryDismissed] = useState(false);
  // Chapter 16's automatic pass runs once per visit to this screen: an úkon
  // finished ten minutes ago in the same visit should already be reflected
  // when the technician gets here.
  const linksApplied = useRef(false);

  const load = useCallback(async () => {
    try {
      const res = await Audits.show(inspectionId);
      setView(res);
      setError(null);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Audit sa nepodarilo načítať.');
    } finally {
      setLoading(false);
    }
  }, [inspectionId]);

  useEffect(() => {
    void load();
  }, [load]);

  useEffect(() => {
    if (!view || view.audit.status !== 'draft' || linksApplied.current) return;
    const hasSameDay = Object.values(view.linked).some((l) => l.state === 'same_day');
    if (!hasSameDay) return;
    linksApplied.current = true;
    Audits.takeOver(inspectionId, csrfToken)
      .then((res) => {
        if (res.applied.length === 0) return;
        setView((prev) => (prev ? { ...prev, items: res.items, summary: res.summary } : prev));
        toast.success(
          `Prevzaté z dnešných protokolov: ${res.applied.length} ${plural(res.applied.length)}`,
        );
      })
      .catch(() => {
        // Nothing is lost — the offers stay on screen and can be taken
        // over by hand.
      });
  }, [view, inspectionId, csrfToken, toast]);

  const sections = view?.summary.sections ?? [];
  const itemsBySection = useMemo(() => {
    const map = new Map<string, AuditItem[]>();
    for (const item of view?.items ?? []) {
      const code = item.fields.section_code;
      const list = map.get(code);
      if (list) list.push(item);
      else map.set(code, [item]);
    }
    return map;
  }, [view?.items]);

  // Open the first section that still has unanswered questions; leave the
  // finished ones collapsed with their tally showing (chapter 15.5). Runs once
  // per audit — after that, which sections are open is the technician's.
  const initialOpenDone = useRef(false);
  useEffect(() => {
    if (!view || initialOpenDone.current) return;
    initialOpenDone.current = true;
    const first = sections.find((s) => !s.excluded && s.unanswered > 0);
    if (first) setOpen({ [first.code]: true });
  }, [view, sections]);

  const applyResult = useCallback((items: AuditItem[], summary: AuditSummary) => {
    setView((prev) => (prev ? { ...prev, items, summary } : prev));
  }, []);

  const patchItem = useCallback((item: AuditItem) => {
    setView((prev) =>
      prev
        ? { ...prev, items: prev.items.map((i) => (i.id === item.id ? item : i)) }
        : prev,
    );
  }, []);

  async function runBulk(
    action: Parameters<typeof Audits.bulk>[1],
    sectionCode?: string,
    successMessage?: string,
  ) {
    setBusy(true);
    try {
      const res = await Audits.bulk(inspectionId, action, csrfToken, sectionCode);
      applyResult(res.items, res.summary);
      if (successMessage) toast.success(successMessage);
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : 'Operáciu sa nepodarilo vykonať.');
    } finally {
      setBusy(false);
    }
  }

  async function runCarryOver() {
    setBusy(true);
    try {
      const res = await Audits.carryOver(inspectionId, csrfToken);
      applyResult(res.items, res.summary);
      setView((prev) => (prev ? { ...prev, previous: res.previous } : prev));
      setCarryDismissed(true);
      toast.success(
        res.added > 0
          ? `Prevzaté: ${res.carried} položiek, pridaných ${res.added} vlastných`
          : `Prevzaté: ${res.carried} položiek`,
      );
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : 'Prevzatie sa nepodarilo.');
    } finally {
      setBusy(false);
    }
  }

  if (loading) {
    return (
      <div className="flex justify-center py-10 text-ink-400">
        <Spinner />
      </div>
    );
  }

  if (error || !view) {
    return (
      <div className="flex flex-col gap-4">
        <Link to="/inspections" className="inline-flex items-center gap-1 self-start text-sm text-ink-500 hover:text-ink-700">
          <ArrowLeft className="size-4" />
          Späť
        </Link>
        <Card className="px-6 py-10 text-center text-sm text-ink-500">
          {error ?? 'Audit sa nenašiel.'}
        </Card>
      </div>
    );
  }

  const { audit, summary, linked, previous } = view;
  const locked = audit.status !== 'draft';
  const remaining = summary.total - summary.answered;
  const offerCarryOver =
    !locked && !carryDismissed && previous !== null && summary.answered === 0;

  return (
    <div className="flex flex-col gap-4">
      <Link
        to={`/inspections/${inspectionId}`}
        className="inline-flex items-center gap-1 self-start text-sm text-ink-500 hover:text-ink-700"
      >
        <ArrowLeft className="size-4" />
        Späť na súhrn
      </Link>

      {/* Sticky because the progress and „Označiť všetko" are what the
          technician reaches for from anywhere in a 109-row list. */}
      <div className="sticky top-0 z-20 -mx-4 border-b border-ink-100 bg-white/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-2xl sm:border sm:px-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div className="min-w-0">
            <h1 className="truncate text-base font-semibold text-ink-900">
              {AUDIT_KIND_LABELS[audit.kind]}
            </h1>
            <p className="text-xs text-ink-500">
              {audit.scope ? AUDIT_SCOPE_LABELS[audit.scope] : '—'}
              {' · '}
              Vyplnené {summary.answered} z {summary.total}
            </p>
          </div>
          {!locked && (
            <Button
              size="sm"
              variant={remaining > 0 ? 'primary' : 'secondary'}
              loading={busy}
              leftIcon={<Check className="size-4" />}
              onClick={() =>
                void runBulk('mark_all_ok', undefined, 'Nevyplnené položky označené ako vyhovuje')
              }
              disabled={remaining === 0}
            >
              Označiť všetko
            </Button>
          )}
        </div>
        <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-ink-100">
          <div
            className="h-full rounded-full bg-firol-500 transition-[width] duration-300"
            style={{ width: `${summary.total === 0 ? 0 : (summary.answered / summary.total) * 100}%` }}
          />
        </div>
      </div>

      {locked && (
        <Card className="flex items-center gap-2 px-4 py-3 text-sm text-ink-600">
          <FileCheck2 className="size-4 shrink-0 text-ink-400" />
          Audit je uzamknutý — protokol už bol vystavený. Hodnotenie sa nedá meniť.
        </Card>
      )}

      {offerCarryOver && (
        <Card className="flex flex-col gap-3 border-firol-200 bg-firol-50/60 p-4">
          <div>
            <p className="text-sm font-semibold text-ink-900">
              Prevziať z auditu {previous.executed_on ? `z ${skDate(previous.executed_on)}` : 'z minula'}?
            </p>
            <p className="mt-1 text-xs text-ink-600">
              Prenesú sa neaplikovateľné položky, vylúčené sekcie a tvoje vlastné
              položky. Výsledky, poznámky ani nedostatky sa neprenášajú — tie
              zadávaš nanovo.
            </p>
          </div>
          <div className="flex gap-2">
            <Button size="sm" loading={busy} onClick={() => void runCarryOver()}>
              Prevziať
            </Button>
            <Button size="sm" variant="secondary" onClick={() => setCarryDismissed(true)}>
              Začať odznova
            </Button>
          </div>
        </Card>
      )}

      {sections.map((section) => (
        <AuditSectionCard
          key={section.code}
          section={section}
          items={itemsBySection.get(section.code) ?? []}
          inspectionId={inspectionId}
          locked={locked}
          busy={busy}
          open={open[section.code] ?? false}
          onToggle={() =>
            setOpen((prev) => ({ ...prev, [section.code]: !(prev[section.code] ?? false) }))
          }
          linked={linked}
          previous={previous}
          onItemSaved={patchItem}
          onBulk={runBulk}
          onRefresh={load}
        />
      ))}

      <div className="flex justify-end pb-2">
        <Button
          rightIcon={<ArrowRight className="size-4" />}
          onClick={() => navigate(`/inspections/${inspectionId}`)}
        >
          Prejsť na súhrn
        </Button>
      </div>
    </div>
  );
}

type SectionCardProps = {
  section: AuditSectionSummary;
  items: AuditItem[];
  inspectionId: number;
  locked: boolean;
  busy: boolean;
  open: boolean;
  onToggle: () => void;
  linked: Record<string, LinkedWork>;
  previous: AuditView['previous'];
  onItemSaved: (item: AuditItem) => void;
  onBulk: (
    action: Parameters<typeof Audits.bulk>[1],
    sectionCode?: string,
    successMessage?: string,
  ) => Promise<void>;
  onRefresh: () => Promise<void>;
};

function AuditSectionCard({
  section, items, inspectionId, locked, busy, open, onToggle,
  linked, previous, onItemSaved, onBulk, onRefresh,
}: SectionCardProps) {
  const [adding, setAdding] = useState(false);

  return (
    <Card className={cn('overflow-hidden', section.excluded && 'opacity-60')}>
      <button
        type="button"
        onClick={onToggle}
        className="flex w-full items-start gap-3 px-4 py-3 text-left transition-colors hover:bg-ink-50"
      >
        <span className="mt-0.5 text-ink-400">
          {open ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
        </span>
        <span className="min-w-0 flex-1">
          <span className="block text-sm font-semibold text-ink-900">
            {section.code} — {section.name}
          </span>
          <span className="mt-0.5 block text-xs text-ink-500">
            {section.excluded ? (
              'Neaplikovateľná — do protokolu sa nevypíše'
            ) : (
              <>
                {section.vyhovuje} vyhovuje
                {section.nevyhovuje > 0 && (
                  <span className="text-status-bad"> · {section.nevyhovuje} nevyhovuje</span>
                )}
                {section.neaplikovatelne > 0 && ` · ${section.neaplikovatelne} neaplikovateľné`}
                {section.unanswered > 0 && ` · ${section.unanswered} nevyplnené`}
              </>
            )}
          </span>
        </span>
      </button>

      {open && (
        <div className="border-t border-ink-100">
          {!locked && (
            <div className="flex flex-wrap gap-2 border-b border-ink-100 bg-ink-50/60 px-4 py-2">
              {!section.excluded && (
                <Button
                  size="sm"
                  variant="secondary"
                  disabled={busy || section.unanswered === 0}
                  leftIcon={<Check className="size-3.5" />}
                  onClick={() =>
                    void onBulk('mark_section_ok', section.code, `Sekcia ${section.code} označená`)
                  }
                >
                  Označiť sekciu
                </Button>
              )}
              <Button
                size="sm"
                variant="secondary"
                disabled={busy}
                leftIcon={<CircleSlash className="size-3.5" />}
                onClick={() =>
                  void onBulk(
                    section.excluded ? 'include_section' : 'exclude_section',
                    section.code,
                    section.excluded
                      ? `Sekcia ${section.code} je opäť súčasťou auditu`
                      : `Sekcia ${section.code} vylúčená z auditu`,
                  )
                }
              >
                {section.excluded ? 'Vrátiť do auditu' : 'Neaplikovateľná'}
              </Button>
              {!section.excluded && (
                <Button
                  size="sm"
                  variant="ghost"
                  leftIcon={<Plus className="size-3.5" />}
                  onClick={() => setAdding(true)}
                >
                  Vlastná položka
                </Button>
              )}
            </div>
          )}

          <ol className="divide-y divide-ink-100">
            {items.map((item, index) => (
              <AuditItemRow
                key={item.id}
                item={item}
                number={`${section.code}.${index + 1}`}
                inspectionId={inspectionId}
                locked={locked || section.excluded}
                link={item.fields.linked_type ? linked[item.fields.linked_type] ?? null : null}
                previousFinding={previous?.findings[item.id] ?? null}
                previousDate={previous?.executed_on ?? null}
                onSaved={onItemSaved}
                onRemoved={onRefresh}
              />
            ))}
          </ol>

          {adding && (
            <NewAuditItemForm
              inspectionId={inspectionId}
              section={section}
              onClose={() => setAdding(false)}
              onAdded={onRefresh}
            />
          )}
        </div>
      )}
    </Card>
  );
}

type ItemRowProps = {
  item: AuditItem;
  number: string;
  inspectionId: number;
  locked: boolean;
  link: LinkedWork | null;
  previousFinding: { description: string | null; deadline: string | null } | null;
  previousDate: string | null;
  onSaved: (item: AuditItem) => void;
  onRemoved: () => Promise<void>;
};

const RESULT_BUTTONS: { value: AuditResult; label: string; icon: React.ReactNode; tone: string }[] = [
  {
    value: 'vyhovuje',
    label: 'Vyhovuje',
    icon: <Check className="size-4" />,
    tone: 'bg-status-ok text-white border-status-ok',
  },
  {
    value: 'nevyhovuje',
    label: 'Nevyhovuje',
    icon: <X className="size-4" />,
    tone: 'bg-status-bad text-white border-status-bad',
  },
  {
    value: 'neaplikovatelne',
    label: 'Neaplikovateľné',
    icon: <Minus className="size-4" />,
    tone: 'bg-ink-500 text-white border-ink-500',
  },
];

function AuditItemRow({
  item, number, inspectionId, locked, link, previousFinding, previousDate, onSaved, onRemoved,
}: ItemRowProps) {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const f = item.fields;

  const [note, setNote] = useState(f.note ?? '');
  const [description, setDescription] = useState(f.defect_description ?? '');
  const [measure, setMeasure] = useState(f.measure ?? '');
  const [deadline, setDeadline] = useState(f.deadline ?? '');
  const [extrasOpen, setExtrasOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [rowError, setRowError] = useState<string | null>(null);

  // Keep the local drafts in step when the row is replaced from the server —
  // a bulk action or a carry-over rewrites every row underneath us.
  useEffect(() => {
    setNote(f.note ?? '');
    setDescription(f.defect_description ?? '');
    setMeasure(f.measure ?? '');
    setDeadline(f.deadline ?? '');
  }, [f.note, f.defect_description, f.measure, f.deadline]);

  const save = useCallback(
    async (overrides: Partial<{
      result: AuditResult | null;
      note: string;
      defect_description: string;
      measure: string;
      deadline: string;
    }>) => {
      const next = {
        result: overrides.result !== undefined ? overrides.result : f.result,
        note: overrides.note !== undefined ? overrides.note : note,
        defect_description:
          overrides.defect_description !== undefined ? overrides.defect_description : description,
        measure: overrides.measure !== undefined ? overrides.measure : measure,
        deadline: overrides.deadline !== undefined ? overrides.deadline : deadline,
      };
      if (next.result === 'nevyhovuje' && next.defect_description.trim() === '') {
        // Not an error yet — the technician has only just tapped the button and
        // the field they need is now in front of them.
        setRowError('Doplň popis nedostatku.');
        setExtrasOpen(true);
        onSaved({ ...item, fields: { ...f, result: 'nevyhovuje' } });
        return;
      }
      setSaving(true);
      setRowError(null);
      try {
        const res = await Audits.answer(
          inspectionId,
          item.id,
          {
            result: next.result,
            note: next.note.trim() || null,
            defect_description: next.defect_description.trim() || null,
            measure: next.measure.trim() || null,
            deadline: next.deadline || null,
          },
          csrfToken,
        );
        onSaved(res.item);
      } catch (err) {
        if (err instanceof OfflineQueuedError) {
          // Queued in the outbox; the answer is not lost, so the row reflects
          // it right away rather than pretending nothing happened.
          onSaved({
            ...item,
            fields: {
              ...f,
              result: next.result,
              note: next.note.trim() || null,
              defect_description: next.defect_description.trim() || null,
              measure: next.measure.trim() || null,
              deadline: next.deadline || null,
            },
          });
          return;
        }
        const msg = err instanceof ApiError ? err.message : 'Uloženie zlyhalo.';
        setRowError(msg);
        toast.error(msg);
      } finally {
        setSaving(false);
      }
    },
    [csrfToken, deadline, description, f, inspectionId, item, measure, note, onSaved, toast],
  );

  const hasExtras = (f.note ?? '') !== '' || (item.photos?.length ?? 0) > 0;

  return (
    <li className="px-4 py-3">
      <div className="flex items-start gap-2">
        <span className="mt-0.5 w-10 shrink-0 text-xs font-semibold tabular-nums text-ink-400">
          {number}
        </span>
        <div className="min-w-0 flex-1">
          <p className="text-sm text-ink-900">{f.text}</p>
          {f.legal_basis && <p className="mt-0.5 text-xs text-ink-400">{f.legal_basis}</p>}

          {previousFinding && (
            <p className="mt-2 flex items-start gap-1.5 rounded-xl bg-status-warn/10 px-2.5 py-1.5 text-xs text-ink-700">
              <AlertTriangle className="mt-0.5 size-3.5 shrink-0 text-status-warn" />
              <span>
                Minulý audit{previousDate ? ` (${skDate(previousDate)})` : ''}: nevyhovovalo
                {previousFinding.description ? ` — „${previousFinding.description}"` : ''}
                {previousFinding.deadline ? `, termín bol ${skDate(previousFinding.deadline)}` : ''}
              </span>
            </p>
          )}

          {link && <LinkedWorkLine link={link} item={item} locked={locked} inspectionId={inspectionId} onSaved={onSaved} />}

          {!locked && (
            <div className="mt-2 grid grid-cols-3 gap-1.5">
              {RESULT_BUTTONS.map((button) => {
                const active = f.result === button.value;
                return (
                  <button
                    key={button.value}
                    type="button"
                    disabled={saving}
                    onClick={() => void save({ result: active ? null : button.value })}
                    className={cn(
                      'inline-flex items-center justify-center gap-1 rounded-xl border px-2 py-2 text-xs font-medium',
                      'transition-colors duration-150 disabled:opacity-60',
                      active ? button.tone : 'border-ink-200 bg-white text-ink-600 hover:bg-ink-50',
                    )}
                  >
                    {button.icon}
                    <span className="truncate">{button.label}</span>
                  </button>
                );
              })}
            </div>
          )}

          {locked && (
            <p className="mt-2 text-xs font-medium text-ink-600">
              {f.result ? RESULT_BUTTONS.find((b) => b.value === f.result)?.label : 'Nevyplnené'}
            </p>
          )}

          {f.result === 'nevyhovuje' && !locked && (
            <div className="mt-2 flex flex-col gap-2 rounded-xl border border-status-bad/30 bg-status-bad/5 p-3">
              <label className="flex flex-col gap-1 text-xs font-medium text-ink-600">
                Popis nedostatku *
                <textarea
                  rows={2}
                  value={description}
                  onChange={(e) => setDescription(e.target.value)}
                  onBlur={() => void save({})}
                  className="rounded-xl border border-ink-200 bg-white px-3 py-2 text-sm text-ink-800 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200"
                />
              </label>
              <label className="flex flex-col gap-1 text-xs font-medium text-ink-600">
                Navrhované opatrenie
                <textarea
                  rows={2}
                  value={measure}
                  onChange={(e) => setMeasure(e.target.value)}
                  onBlur={() => void save({})}
                  className="rounded-xl border border-ink-200 bg-white px-3 py-2 text-sm text-ink-800 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200"
                />
              </label>
              <label className="flex flex-col gap-1 text-xs font-medium text-ink-600">
                Termín odstránenia
                <Input
                  type="date"
                  value={deadline}
                  onChange={(e) => setDeadline(e.target.value)}
                  onBlur={() => void save({})}
                />
              </label>
            </div>
          )}

          {rowError && <p className="mt-1.5 text-xs text-status-bad">{rowError}</p>}

          <button
            type="button"
            onClick={() => setExtrasOpen((prev) => !prev)}
            className="mt-2 inline-flex items-center gap-1 text-xs font-medium text-ink-500 hover:text-ink-700"
          >
            {extrasOpen ? <ChevronDown className="size-3.5" /> : <ChevronRight className="size-3.5" />}
            Poznámka a foto
            {hasExtras && !extrasOpen && (
              <span className="ml-1 rounded-full bg-firol-100 px-1.5 text-[10px] font-semibold text-firol-700">
                {(item.photos?.length ?? 0) > 0 ? `${item.photos?.length} foto` : 'poznámka'}
              </span>
            )}
          </button>

          {extrasOpen && (
            <AuditItemExtras
              item={item}
              inspectionId={inspectionId}
              locked={locked}
              note={note}
              onNoteChange={setNote}
              onNoteBlur={() => void save({})}
            />
          )}

          {f.is_custom && !locked && (
            <button
              type="button"
              onClick={async () => {
                try {
                  await Audits.removeItem(inspectionId, item.id, csrfToken);
                  await onRemoved();
                } catch {
                  toast.error('Položku sa nepodarilo zmazať.');
                }
              }}
              className="mt-2 inline-flex items-center gap-1 text-xs text-status-bad hover:underline"
            >
              <Trash2 className="size-3.5" />
              Zmazať vlastnú položku
            </button>
          )}
        </div>
      </div>
    </li>
  );
}

/**
 * Note and photos. Mounted only when the technician opens the disclosure —
 * the photo staging hook is not something to run 109 times for a list where
 * five rows will ever carry a picture.
 */
function AuditItemExtras({
  item, inspectionId, locked, note, onNoteChange, onNoteBlur,
}: {
  item: AuditItem;
  inspectionId: number;
  locked: boolean;
  note: string;
  onNoteChange: (value: string) => void;
  onNoteBlur: () => void;
}) {
  const { csrfToken } = useAuth();
  const photos = usePhotoStaging(item.photos);
  const pending = photos.staged.length;
  const pendingRef = useRef(0);

  // The item already exists, so there is nothing to wait for: commit as soon
  // as a photo is staged. Anything queued offline replays from the outbox.
  useEffect(() => {
    if (pending === 0 || pending === pendingRef.current) {
      pendingRef.current = pending;
      return;
    }
    pendingRef.current = pending;
    void photos.commit(inspectionId, item.id, csrfToken);
  }, [pending, photos, inspectionId, item.id, csrfToken]);

  return (
    <div className="mt-2 flex flex-col gap-3 rounded-xl border border-ink-100 bg-ink-50/50 p-3">
      <label className="flex flex-col gap-1 text-xs font-medium text-ink-600">
        Poznámka
        <textarea
          rows={2}
          value={note}
          disabled={locked}
          onChange={(e) => onNoteChange(e.target.value)}
          onBlur={onNoteBlur}
          className="rounded-xl border border-ink-200 bg-white px-3 py-2 text-sm text-ink-800 focus:border-firol-400 focus:outline-none focus:ring-2 focus:ring-firol-200 disabled:bg-ink-100"
        />
      </label>
      <ItemPhotoField
        photos={photos}
        disabled={locked}
        helpText="Fotka vyhovujúcej položky je dôkaz o stave — prikladá sa rovnako ako pri nedostatku."
      />
    </div>
  );
}

/** Chapter 16 — the line showing what a separate protocol already covers. */
function LinkedWorkLine({
  link, item, locked, inspectionId, onSaved,
}: {
  link: LinkedWork;
  item: AuditItem;
  locked: boolean;
  inspectionId: number;
  onSaved: (item: AuditItem) => void;
}) {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const [busy, setBusy] = useState(false);

  if (link.state === 'never') {
    return (
      <p className="mt-2 rounded-xl bg-ink-50 px-2.5 py-1.5 text-xs text-ink-500">
        {link.label}: v tejto prevádzke zatiaľ nebola vykonaná.
      </p>
    );
  }

  const found = link.found;
  if (!found) return null;

  const date = found.executed_on ? skDate(found.executed_on) : '—';
  // The period is shown always, never only the date: „checked a month ago"
  // means one thing at a monthly period and another at a yearly one, and that
  // difference is exactly what an inspector looks at.
  const period = found.periodicity_label ? ` · perióda ${found.periodicity_label}` : '';
  const validity = found.valid_until ? ` · platí do ${skDate(found.valid_until)}` : '';

  const tone =
    link.state === 'expired'
      ? 'bg-status-warn/10 text-ink-700'
      : link.state === 'offer'
        ? 'bg-firol-50 text-ink-700'
        : 'bg-status-ok/10 text-ink-700';

  return (
    <div className={cn('mt-2 flex flex-wrap items-center gap-2 rounded-xl px-2.5 py-1.5 text-xs', tone)}>
      <span className="min-w-0 flex-1">
        {link.label} {date}
        {period}
        {validity}
        {found.document_number && ` · protokol ${found.document_number}`}
        {link.state === 'expired' && ' — platnosť uplynula'}
        {link.state === 'no_period' && ' — bez opakovania'}
      </span>
      {link.state === 'offer' && !locked && item.fields.result === null && (
        <Button
          size="sm"
          variant="secondary"
          loading={busy}
          onClick={async () => {
            setBusy(true);
            try {
              const res = await Audits.takeOver(inspectionId, csrfToken, [item.id]);
              const updated = res.items.find((i) => i.id === item.id);
              if (updated) onSaved(updated);
            } catch {
              toast.error('Prevzatie sa nepodarilo.');
            } finally {
              setBusy(false);
            }
          }}
        >
          Prevziať
        </Button>
      )}
    </div>
  );
}

/** Chapter 17 — an item of one's own, added to this audit only. */
function NewAuditItemForm({
  inspectionId, section, onClose, onAdded,
}: {
  inspectionId: number;
  section: AuditSectionSummary;
  onClose: () => void;
  onAdded: () => Promise<void>;
}) {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const [text, setText] = useState('');
  const [legalBasis, setLegalBasis] = useState('');
  const [saving, setSaving] = useState(false);

  return (
    <form
      className="flex flex-col gap-2 border-t border-ink-100 bg-firol-50/40 px-4 py-3"
      onSubmit={async (e) => {
        e.preventDefault();
        if (text.trim() === '') return;
        setSaving(true);
        try {
          await Audits.addItem(
            inspectionId,
            {
              section_code: section.code,
              section_name: section.name,
              section_position: section.position,
              text: text.trim(),
              legal_basis: legalBasis.trim() || null,
            },
            csrfToken,
          );
          await onAdded();
          onClose();
        } catch (err) {
          toast.error(err instanceof ApiError ? err.message : 'Položku sa nepodarilo pridať.');
        } finally {
          setSaving(false);
        }
      }}
    >
      <label className="flex flex-col gap-1 text-xs font-medium text-ink-600">
        Znenie položky
        <Input value={text} onChange={(e) => setText(e.target.value)} autoFocus />
      </label>
      <label className="flex flex-col gap-1 text-xs font-medium text-ink-600">
        Právny základ (nepovinné)
        <Input
          value={legalBasis}
          onChange={(e) => setLegalBasis(e.target.value)}
          placeholder="Nechaj prázdne, ak žiadny nie je"
        />
      </label>
      <div className="flex gap-2">
        <Button size="sm" type="submit" loading={saving}>
          Pridať
        </Button>
        <Button size="sm" type="button" variant="ghost" onClick={onClose}>
          Zrušiť
        </Button>
      </div>
    </form>
  );
}

function skDate(iso: string): string {
  return new Date(`${iso}T00:00:00`).toLocaleDateString('sk-SK');
}

function plural(n: number): string {
  if (n === 1) return 'položka';
  return n < 5 ? 'položky' : 'položiek';
}
