import { useCallback, useEffect, useMemo, useState } from 'react';
import {
  ArrowDown, ArrowUp, ChevronDown, ChevronRight, Copy, Pencil, Plus,
  RotateCcw, Trash2,
} from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import {
  AUDIT_ITEM_SCOPE_LABELS,
  AUDIT_KIND_LABELS,
  AuditTemplates,
  type AuditItemScope,
  type AuditKind,
  type AuditTemplate,
  type AuditTemplateSection,
} from '@/api/audits';
import { ApiError } from '@/lib/api';
import { cn } from '@/lib/cn';
import { useConfirm } from '@/lib/confirm';
import { useToast } from '@/lib/toast';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { SectionBack } from '@/pages/SettingsPage';

const KINDS: AuditKind[] = ['bozp', 'opp'];

/**
 * Editing the audit checklists — block 3 / chapter 17.
 *
 * The two lists that ship with the app are a starting point. A technician who
 * has audited the same three factories for a decade knows which question they
 * never ask and which one they always add, and a checklist they cannot change
 * is a checklist they will keep on paper beside the phone.
 *
 * Nothing done here touches an audit that already exists: an audit copies the
 * checklist when it is created, so a protocol keeps reading the way it read
 * the day it was issued.
 */
export function AuditTemplatesPage() {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();

  const [kind, setKind] = useState<AuditKind>('bozp');
  const [templates, setTemplates] = useState<AuditTemplate[] | null>(null);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(
    async (nextKind: AuditKind) => {
      setTemplates(null);
      try {
        const res = await AuditTemplates.list(nextKind);
        setTemplates(res.items);
        setSelectedId((prev) =>
          prev !== null && res.items.some((t) => t.id === prev) ? prev : res.items[0]?.id ?? null,
        );
      } catch (err) {
        toast.error(err instanceof ApiError ? err.message : 'Kontrolné listy sa nepodarilo načítať.');
        setTemplates([]);
      }
    },
    [toast],
  );

  useEffect(() => {
    void load(kind);
  }, [kind, load]);

  const selected = useMemo(
    () => templates?.find((t) => t.id === selectedId) ?? null,
    [templates, selectedId],
  );

  const replace = useCallback((template: AuditTemplate) => {
    setTemplates((prev) => (prev ? prev.map((t) => (t.id === template.id ? template : t)) : prev));
  }, []);

  async function run<T>(fn: () => Promise<T>, onOk?: (result: T) => void, message?: string) {
    setBusy(true);
    try {
      const result = await fn();
      onOk?.(result);
      if (message) toast.success(message);
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : 'Zmenu sa nepodarilo uložiť.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="flex flex-col gap-4">
      <SectionBack label="Kontrolné listy auditu" />

      <Card className="p-4">
        <p className="text-sm text-ink-600">
          Kontrolné listy dodané s aplikáciou sú predvoľba, nie pevný zoznam.
          Ktorúkoľvek položku môžeš prepísať, zmazať alebo pridať vlastnú, a
          dodaný list kedykoľvek vrátiť do pôvodného stavu. Zmeny sa prejavia
          len na nových auditoch — už vystavené protokoly zostávajú nezmenené.
        </p>
      </Card>

      <div className="flex flex-wrap items-center gap-2">
        {KINDS.map((k) => (
          <button
            key={k}
            type="button"
            onClick={() => setKind(k)}
            className={cn(
              'rounded-xl border px-3 py-2 text-sm font-medium transition-colors',
              kind === k
                ? 'border-firol-400 bg-firol-50 text-firol-700'
                : 'border-ink-200 bg-white text-ink-600 hover:bg-ink-50',
            )}
          >
            {AUDIT_KIND_LABELS[k]}
          </button>
        ))}
      </div>

      {templates === null ? (
        <Card className="flex justify-center px-4 py-8 text-ink-400">
          <Spinner />
        </Card>
      ) : (
        <>
          <Card className="flex flex-col gap-3 p-4">
            <div className="flex flex-wrap items-end gap-2">
              <label className="min-w-[12rem] flex-1 text-xs font-medium text-ink-600">
                Kontrolný list
                <Select
                  value={selectedId !== null ? String(selectedId) : ''}
                  onChange={(v) => setSelectedId(v ? Number(v) : null)}
                  options={templates.map((t) => ({
                    value: String(t.id),
                    label: t.name,
                    description: `${t.item_count} položiek${t.is_custom ? '' : ' · dodaný'}`,
                  }))}
                />
              </label>
              <Button
                size="sm"
                variant="secondary"
                leftIcon={<Copy className="size-3.5" />}
                loading={busy}
                onClick={() => {
                  if (!selected) return;
                  void run(
                    () =>
                      AuditTemplates.create(
                        { kind, name: `${selected.name} — kópia`, copy_from: selected.id },
                        csrfToken,
                      ),
                    (res) => {
                      setTemplates((prev) => (prev ? [...prev, res.template] : [res.template]));
                      setSelectedId(res.template.id);
                    },
                    'Kópia vytvorená',
                  );
                }}
              >
                Duplikovať
              </Button>
            </div>

            {selected && (
              <div className="flex flex-wrap gap-2">
                <Button
                  size="sm"
                  variant="ghost"
                  leftIcon={<Pencil className="size-3.5" />}
                  onClick={async () => {
                    const name = window.prompt('Názov kontrolného listu', selected.name);
                    if (!name || name === selected.name) return;
                    await run(
                      () => AuditTemplates.rename(selected.id, name, csrfToken),
                      (res) => replace(res.template),
                      'Premenované',
                    );
                  }}
                >
                  Premenovať
                </Button>
                {selected.is_custom ? (
                  <Button
                    size="sm"
                    variant="ghost"
                    leftIcon={<Trash2 className="size-3.5" />}
                    onClick={async () => {
                      const ok = await confirm({
                        title: 'Zmazať kontrolný list?',
                        description: 'Audity, ktoré z neho vznikli, zostanú nedotknuté.',
                        confirmLabel: 'Zmazať',
                        tone: 'danger',
                      });
                      if (!ok) return;
                      await run(
                        () => AuditTemplates.remove(selected.id, csrfToken),
                        () => {
                          setTemplates((prev) => prev?.filter((t) => t.id !== selected.id) ?? null);
                          setSelectedId(null);
                        },
                        'Kontrolný list zmazaný',
                      );
                    }}
                  >
                    Zmazať
                  </Button>
                ) : (
                  <Button
                    size="sm"
                    variant="ghost"
                    leftIcon={<RotateCcw className="size-3.5" />}
                    onClick={async () => {
                      const ok = await confirm({
                        title: 'Obnoviť pôvodný stav?',
                        description: 'Všetky tvoje úpravy tohto dodaného kontrolného listu sa zahodia. Už vystavené protokoly to neovplyvní.',
                        confirmLabel: 'Obnoviť',
                      });
                      if (!ok) return;
                      await run(
                        () => AuditTemplates.restore(selected.id, csrfToken),
                        (res) => replace(res.template),
                        'Kontrolný list obnovený',
                      );
                    }}
                  >
                    Obnoviť pôvodný
                  </Button>
                )}
              </div>
            )}
          </Card>

          {selected && (
            <TemplateEditor
              template={selected}
              busy={busy}
              onChanged={replace}
              run={run}
            />
          )}
        </>
      )}
    </div>
  );
}

type RunFn = <T>(fn: () => Promise<T>, onOk?: (result: T) => void, message?: string) => Promise<void>;

function TemplateEditor({
  template, busy, onChanged, run,
}: {
  template: AuditTemplate;
  busy: boolean;
  onChanged: (template: AuditTemplate) => void;
  run: RunFn;
}) {
  const { csrfToken } = useAuth();
  const confirm = useConfirm();
  const [open, setOpen] = useState<Record<number, boolean>>({});
  const [addingSection, setAddingSection] = useState(false);

  const apply = (res: { template: AuditTemplate }) => onChanged(res.template);

  function moveSection(index: number, delta: number) {
    const ids = template.sections.map((s) => s.id);
    const target = index + delta;
    if (target < 0 || target >= ids.length) return;
    [ids[index], ids[target]] = [ids[target], ids[index]];
    void run(() => AuditTemplates.reorder(template.id, { section_ids: ids }, csrfToken), apply);
  }

  return (
    <div className="flex flex-col gap-3">
      {template.sections.map((section, index) => (
        <Card key={section.id} className="overflow-hidden">
          <div className="flex items-start gap-2 px-4 py-3">
            <button
              type="button"
              onClick={() => setOpen((prev) => ({ ...prev, [section.id]: !prev[section.id] }))}
              className="flex min-w-0 flex-1 items-start gap-2 text-left"
            >
              <span className="mt-0.5 text-ink-400">
                {open[section.id] ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
              </span>
              <span className="min-w-0">
                <span className="block text-sm font-semibold text-ink-900">
                  {section.code} — {section.name}
                </span>
                <span className="block text-xs text-ink-500">{section.items.length} položiek</span>
              </span>
            </button>
            <div className="flex shrink-0 items-center gap-0.5">
              <IconButton
                title="Posunúť vyššie"
                disabled={busy || index === 0}
                onClick={() => moveSection(index, -1)}
              >
                <ArrowUp className="size-3.5" />
              </IconButton>
              <IconButton
                title="Posunúť nižšie"
                disabled={busy || index === template.sections.length - 1}
                onClick={() => moveSection(index, 1)}
              >
                <ArrowDown className="size-3.5" />
              </IconButton>
              <IconButton
                title="Premenovať sekciu"
                disabled={busy}
                onClick={async () => {
                  const name = window.prompt('Názov sekcie', section.name);
                  if (!name || name === section.name) return;
                  await run(
                    () => AuditTemplates.updateSection(template.id, section.id, { name }, csrfToken),
                    apply,
                  );
                }}
              >
                <Pencil className="size-3.5" />
              </IconButton>
              <IconButton
                title="Zmazať sekciu"
                disabled={busy}
                onClick={async () => {
                  const ok = await confirm({
                    title: `Zmazať sekciu ${section.code}?`,
                    description: `Zmaže sa aj ${section.items.length} položiek v nej.`,
                    confirmLabel: 'Zmazať',
                    tone: 'danger',
                  });
                  if (!ok) return;
                  await run(
                    () => AuditTemplates.removeSection(template.id, section.id, csrfToken),
                    apply,
                  );
                }}
              >
                <Trash2 className="size-3.5 text-status-bad" />
              </IconButton>
            </div>
          </div>

          {open[section.id] && (
            <SectionItems
              templateId={template.id}
              section={section}
              busy={busy}
              run={run}
              apply={apply}
            />
          )}
        </Card>
      ))}

      {addingSection ? (
        <NewSectionForm
          templateId={template.id}
          run={run}
          apply={apply}
          onClose={() => setAddingSection(false)}
        />
      ) : (
        <Button
          variant="secondary"
          leftIcon={<Plus className="size-4" />}
          onClick={() => setAddingSection(true)}
        >
          Pridať sekciu
        </Button>
      )}
    </div>
  );
}

function SectionItems({
  templateId, section, busy, run, apply,
}: {
  templateId: number;
  section: AuditTemplateSection;
  busy: boolean;
  run: RunFn;
  apply: (res: { template: AuditTemplate }) => void;
}) {
  const { csrfToken } = useAuth();
  const confirm = useConfirm();
  const [adding, setAdding] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);

  function moveItem(index: number, delta: number) {
    const ids = section.items.map((i) => i.id);
    const target = index + delta;
    if (target < 0 || target >= ids.length) return;
    [ids[index], ids[target]] = [ids[target], ids[index]];
    void run(
      () => AuditTemplates.reorder(templateId, { section_id: section.id, item_ids: ids }, csrfToken),
      apply,
    );
  }

  return (
    <div className="border-t border-ink-100">
      <ol className="divide-y divide-ink-100">
        {section.items.map((item, index) =>
          editingId === item.id ? (
            <li key={item.id} className="bg-firol-50/40 px-4 py-3">
              <ItemForm
                initial={item}
                onCancel={() => setEditingId(null)}
                onSubmit={async (values) => {
                  await run(
                    () => AuditTemplates.updateItem(templateId, item.id, values, csrfToken),
                    apply,
                  );
                  setEditingId(null);
                }}
              />
            </li>
          ) : (
            <li key={item.id} className="flex items-start gap-2 px-4 py-2.5">
              <span className="mt-0.5 w-9 shrink-0 text-xs font-semibold tabular-nums text-ink-400">
                {section.code}.{index + 1}
              </span>
              <span className="min-w-0 flex-1">
                <span className="block text-sm text-ink-800">{item.text}</span>
                <span className="mt-0.5 block text-xs text-ink-400">
                  {item.legal_basis || 'bez právneho základu'}
                  {' · '}
                  {AUDIT_ITEM_SCOPE_LABELS[item.scope]}
                </span>
              </span>
              <span className="flex shrink-0 items-center gap-0.5">
                <IconButton title="Vyššie" disabled={busy || index === 0} onClick={() => moveItem(index, -1)}>
                  <ArrowUp className="size-3.5" />
                </IconButton>
                <IconButton
                  title="Nižšie"
                  disabled={busy || index === section.items.length - 1}
                  onClick={() => moveItem(index, 1)}
                >
                  <ArrowDown className="size-3.5" />
                </IconButton>
                <IconButton title="Upraviť" disabled={busy} onClick={() => setEditingId(item.id)}>
                  <Pencil className="size-3.5" />
                </IconButton>
                <IconButton
                  title="Zmazať"
                  disabled={busy}
                  onClick={async () => {
                    const ok = await confirm({
                      title: 'Zmazať položku?',
                      description: item.text,
                      confirmLabel: 'Zmazať',
                      tone: 'danger',
                    });
                    if (!ok) return;
                    await run(
                      () => AuditTemplates.removeItem(templateId, item.id, csrfToken),
                      apply,
                    );
                  }}
                >
                  <Trash2 className="size-3.5 text-status-bad" />
                </IconButton>
              </span>
            </li>
          ),
        )}
      </ol>

      {adding ? (
        <div className="border-t border-ink-100 bg-firol-50/40 px-4 py-3">
          <ItemForm
            onCancel={() => setAdding(false)}
            onSubmit={async (values) => {
              await run(
                () => AuditTemplates.addItem(templateId, section.id, values, csrfToken),
                apply,
              );
              setAdding(false);
            }}
          />
        </div>
      ) : (
        <button
          type="button"
          onClick={() => setAdding(true)}
          className="flex w-full items-center justify-center gap-1.5 border-t border-ink-100 px-4 py-2.5 text-sm font-medium text-firol-600 transition-colors hover:bg-firol-50"
        >
          <Plus className="size-4" />
          Pridať položku
        </button>
      )}
    </div>
  );
}

function ItemForm({
  initial,
  onSubmit,
  onCancel,
}: {
  initial?: { text: string; legal_basis: string | null; scope: AuditItemScope };
  onSubmit: (values: { text: string; legal_basis: string | null; scope: AuditItemScope }) => Promise<void>;
  onCancel: () => void;
}) {
  const [text, setText] = useState(initial?.text ?? '');
  const [legalBasis, setLegalBasis] = useState(initial?.legal_basis ?? '');
  const [scope, setScope] = useState<AuditItemScope>(initial?.scope ?? 'VR');
  const [saving, setSaving] = useState(false);

  return (
    <form
      className="flex flex-col gap-2"
      onSubmit={async (e) => {
        e.preventDefault();
        if (text.trim() === '') return;
        setSaving(true);
        await onSubmit({
          text: text.trim(),
          legal_basis: legalBasis.trim() || null,
          scope,
        });
        setSaving(false);
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
          placeholder="Napr. § 6 ods. 1 z. 124/2006"
        />
      </label>
      <label className="flex flex-col gap-1 text-xs font-medium text-ink-600">
        Kde sa zobrazí
        <Select
          value={scope}
          onChange={(v) => setScope(v as AuditItemScope)}
          options={(Object.keys(AUDIT_ITEM_SCOPE_LABELS) as AuditItemScope[]).map((s) => ({
            value: s,
            label: AUDIT_ITEM_SCOPE_LABELS[s],
          }))}
        />
      </label>
      <div className="flex gap-2">
        <Button size="sm" type="submit" loading={saving}>
          Uložiť
        </Button>
        <Button size="sm" type="button" variant="ghost" onClick={onCancel}>
          Zrušiť
        </Button>
      </div>
    </form>
  );
}

function NewSectionForm({
  templateId, run, apply, onClose,
}: {
  templateId: number;
  run: RunFn;
  apply: (res: { template: AuditTemplate }) => void;
  onClose: () => void;
}) {
  const { csrfToken } = useAuth();
  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [saving, setSaving] = useState(false);

  return (
    <Card className="p-4">
      <form
        className="flex flex-col gap-2"
        onSubmit={async (e) => {
          e.preventDefault();
          if (code.trim() === '' || name.trim() === '') return;
          setSaving(true);
          await run(
            () =>
              AuditTemplates.addSection(
                templateId,
                { code: code.trim(), name: name.trim() },
                csrfToken,
              ),
            apply,
            'Sekcia pridaná',
          );
          setSaving(false);
          onClose();
        }}
      >
        <label className="flex flex-col gap-1 text-xs font-medium text-ink-600">
          Kód sekcie
          <Input value={code} onChange={(e) => setCode(e.target.value)} placeholder="M" autoFocus />
        </label>
        <label className="flex flex-col gap-1 text-xs font-medium text-ink-600">
          Názov sekcie
          <Input value={name} onChange={(e) => setName(e.target.value)} placeholder="Vlastná oblasť" />
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
    </Card>
  );
}

function IconButton({
  title, disabled, onClick, children,
}: {
  title: string;
  disabled?: boolean;
  onClick: () => void;
  children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      title={title}
      aria-label={title}
      disabled={disabled}
      onClick={onClick}
      className="grid size-8 place-items-center rounded-lg text-ink-500 transition-colors hover:bg-ink-100 disabled:opacity-30"
    >
      {children}
    </button>
  );
}
