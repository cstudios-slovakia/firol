import { useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  AlertTriangle, Building2, CheckCircle2, ChevronLeft, ClipboardList,
  Download, FileSpreadsheet, GraduationCap, UploadCloud,
} from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { ImportApi, type ImportKind, type ImportResult } from '@/api/import';
import { ApiError } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { cn } from '@/lib/cn';

type SectionDef = {
  kind: ImportKind;
  title: string;
  description: string;
  hint: string;
  Icon: typeof Building2;
  color: string;
  bg: string;
  createdLabels: Record<string, string>;
};

const SECTIONS: SectionDef[] = [
  {
    kind: 'companies',
    title: 'Firmy a prevádzky',
    description:
      'Importuj firmy aj ich prevádzky naraz. Prevádzky sa naviažu na firmu cez IČO.',
    hint: 'Sheet „Firmy” — Názov je povinný. Sheet „Prevadzky” — IČO firmy musí existovať (buď v sheete vyššie, alebo už v aplikácii).',
    Icon: Building2,
    color: 'text-blue-600',
    bg: 'bg-blue-50',
    createdLabels: { companies: 'firiem', facilities: 'prevádzok' },
  },
  {
    kind: 'inspections',
    title: 'Kontroly',
    description:
      'Hlavičky kontrol aj ich položky. Každý typ má vlastný sheet s položkami (Polozky_php, Polozky_hydranty, …).',
    hint: 'Sheet „Kontroly” — # riadok je tvoje vlastné poradie (1, 2, 3…). Polozky_* odkazujú na toto # cez stĺpec „# kontrola”. E-mail technika, ktorý ešte nemá konto, sa predvytvorí a pridá do tvojho tímu; kontroly sa mu priradia, keď sa zaregistruje.',
    Icon: ClipboardList,
    color: 'text-firol-600',
    bg: 'bg-firol-50',
    createdLabels: { inspections: 'kontrol', items: 'položiek', technicians: 'nových technikov' },
  },
  {
    kind: 'trainings',
    title: 'Školenia',
    description:
      'Školenia a ich účastníkov. Podpisy účastníkov sa zachytia neskôr v aplikácii — Excel ich neimportuje.',
    hint: 'Sheet „Skolenia” — # riadok je tvoje vlastné poradie. Sheet „Ucastnici” odkazuje na toto # cez „# riadok školenia”. E-mail lektora, ktorý ešte nemá konto, sa predvytvorí a pridá do tvojho tímu; školenia sa mu priradia, keď sa zaregistruje.',
    Icon: GraduationCap,
    color: 'text-violet-600',
    bg: 'bg-violet-50',
    createdLabels: { trainings: 'školení', trainees: 'účastníkov', trainers: 'nových lektorov' },
  },
];

export function ImportPage() {
  return (
    <div className="flex flex-col gap-5">
      <header>
        <Link
          to="/settings"
          className="inline-flex items-center gap-1.5 rounded-xl py-1 text-sm font-medium text-ink-500 transition-colors hover:text-ink-800 sm:hidden"
        >
          <ChevronLeft className="size-4" />
          Nastavenia
        </Link>
        <h1 className="text-xl font-semibold tracking-tight text-ink-900">
          Import z Excelu
        </h1>
        <p className="mt-0.5 text-sm text-ink-500">
          Stiahni si vzorovú šablónu, vyplň ju a nahraj späť. Údaje sa
          importujú do tvojho účtu.
        </p>
      </header>

      <Card className="px-4 py-3 text-xs text-ink-600">
        <p className="flex items-start gap-2">
          <AlertTriangle className="mt-0.5 size-4 shrink-0 text-amber-500" />
          <span>
            Import beží <strong>atomicky</strong> — ak ktorýkoľvek riadok
            obsahuje chybu, neuloží sa <em>nič</em>. Oprav riadky podľa
            chybového hlásenia a nahraj súbor znova.
          </span>
        </p>
      </Card>

      {SECTIONS.map((section) => (
        <ImportSection key={section.kind} section={section} />
      ))}
    </div>
  );
}

function ImportSection({ section }: { section: SectionDef }) {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const fileRef = useRef<HTMLInputElement>(null);
  const [uploading, setUploading] = useState(false);
  const [downloading, setDownloading] = useState(false);
  const [result, setResult] = useState<ImportResult | null>(null);
  const [networkError, setNetworkError] = useState<string | null>(null);

  async function onDownload() {
    setDownloading(true);
    setNetworkError(null);
    try {
      await ImportApi.downloadTemplate(section.kind);
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Stiahnutie šablóny zlyhalo.';
      setNetworkError(msg);
      toast.error(msg);
    } finally {
      setDownloading(false);
    }
  }

  async function onPick(file: File | undefined) {
    if (!file) return;
    setUploading(true);
    setResult(null);
    setNetworkError(null);
    try {
      const res = await ImportApi.upload(section.kind, file, csrfToken);
      setResult(res);
      if (res.errors.length > 0) {
        toast.error(`Import zlyhal — ${res.errors.length} chýb v súbore.`);
      } else if (res.created) {
        const summary = Object.entries(res.created)
          .map(([k, v]) => `${v} ${section.createdLabels[k] ?? k}`)
          .join(', ');
        toast.success(`Naimportované: ${summary}`);
      }
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Nahrávanie zlyhalo.';
      setNetworkError(msg);
      toast.error(msg);
    } finally {
      setUploading(false);
      if (fileRef.current) fileRef.current.value = '';
    }
  }

  return (
    <Card className="overflow-hidden">
      <div className="flex items-start gap-3 border-b border-ink-100 px-5 py-4">
        <div className={cn('grid size-11 shrink-0 place-items-center rounded-2xl', section.bg)}>
          <section.Icon className={cn('size-5', section.color)} />
        </div>
        <div className="min-w-0 flex-1">
          <h2 className="text-base font-semibold text-ink-900">{section.title}</h2>
          <p className="mt-0.5 text-xs text-ink-500">{section.description}</p>
        </div>
      </div>

      <div className="flex flex-col gap-3 px-5 py-4">
        <p className="rounded-xl border border-ink-100 bg-ink-50/40 px-3 py-2 text-xs text-ink-600">
          <FileSpreadsheet className="-mt-0.5 mr-1 inline size-3.5 text-ink-400" />
          {section.hint}
        </p>

        <div className="flex flex-col gap-2 sm:flex-row">
          <Button
            type="button"
            variant="secondary"
            leftIcon={<Download className="size-4" />}
            onClick={onDownload}
            loading={downloading}
            className="sm:flex-1"
          >
            Stiahnuť vzorový .xlsx
          </Button>
          <input
            ref={fileRef}
            type="file"
            accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
            className="hidden"
            onChange={(e) => onPick(e.target.files?.[0])}
          />
          <Button
            type="button"
            leftIcon={<UploadCloud className="size-4" />}
            onClick={() => fileRef.current?.click()}
            loading={uploading}
            className="sm:flex-1"
          >
            Nahrať vyplnený súbor
          </Button>
        </div>

        {networkError && (
          <div className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">
            {networkError}
          </div>
        )}

        {result && result.errors.length === 0 && result.created && (
          <div className="rounded-xl border border-emerald-200 bg-emerald-50/60 px-3 py-2.5 text-sm text-emerald-800">
            <p className="flex items-center gap-2 font-semibold">
              <CheckCircle2 className="size-4" />
              Import dokončený
            </p>
            <ul className="mt-1.5 flex flex-wrap gap-2">
              {Object.entries(result.created).map(([k, v]) => (
                <li key={k}>
                  <Badge tone="ok">
                    {v} {section.createdLabels[k] ?? k}
                  </Badge>
                </li>
              ))}
            </ul>
          </div>
        )}

        {result && result.errors.length > 0 && (
          <div className="rounded-xl border border-rose-200 bg-rose-50/40 px-3 py-2.5 text-sm">
            <p className="flex items-center gap-2 font-semibold text-rose-800">
              <AlertTriangle className="size-4" />
              Súbor obsahuje {result.errors.length}{' '}
              {result.errors.length === 1 ? 'chybu' : 'chýb'} — neuložilo sa nič.
            </p>
            <ul className="mt-2 max-h-64 space-y-1 overflow-y-auto pr-1">
              {result.errors.map((e, i) => (
                <li key={i} className="text-xs text-rose-800">
                  <span className="font-mono font-semibold">
                    {e.sheet} · riadok {e.row}
                  </span>{' '}
                  — {e.message}
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>
    </Card>
  );
}
