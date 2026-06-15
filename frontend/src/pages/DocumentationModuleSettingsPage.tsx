import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { ChevronLeft, FileText, Plus, Trash2 } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import {
  DocumentationSettings, type DocumentationSettingsData, type WaterUtility,
} from '@/api/documentations';
import { ApiError } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Button } from '@/components/ui/Button';
import { Spinner } from '@/components/ui/Spinner';

/**
 * Nastavenia → Dokumentácia PO (spec §9). Global, FIROL-managed defaults:
 * the signer-function list offered in the wizard and the per-region
 * water-utility emergency numbers. Admin-only.
 */
export function DocumentationModuleSettingsPage() {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [signers, setSigners] = useState<string[]>([]);
  const [water, setWater] = useState<WaterUtility[]>([]);

  useEffect(() => {
    let cancelled = false;
    DocumentationSettings.show()
      .then((s: DocumentationSettingsData) => {
        if (cancelled) return;
        setSigners(s.signer_functions);
        setWater(s.water_utilities);
      })
      .catch(() => undefined)
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, []);

  async function save() {
    setSaving(true);
    try {
      const cleanSigners = signers.map((s) => s.trim()).filter(Boolean);
      const cleanWater = water
        .map((w) => ({ region: w.region.trim(), phone: w.phone.trim() }))
        .filter((w) => w.region);
      const res = await DocumentationSettings.update(
        { signer_functions: cleanSigners, water_utilities: cleanWater },
        csrfToken,
      );
      setSigners(res.signer_functions);
      setWater(res.water_utilities);
      toast.success('Nastavenia uložené');
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : 'Uloženie zlyhalo.');
    } finally {
      setSaving(false);
    }
  }

  if (loading) {
    return <div className="flex justify-center py-10 text-ink-400"><Spinner /></div>;
  }

  return (
    <div className="flex flex-col gap-5">
      <Link to="/settings" className="inline-flex items-center gap-1 self-start text-sm text-ink-500 hover:text-ink-700 sm:hidden">
        <ChevronLeft className="size-4" /> Nastavenia
      </Link>

      <header className="flex items-center gap-3">
        <span className="grid size-11 place-items-center rounded-2xl bg-firol-50 text-firol-600">
          <FileText className="size-5" />
        </span>
        <div>
          <h1 className="text-lg font-semibold tracking-tight text-ink-900">Dokumentácia PO</h1>
          <p className="text-sm text-ink-500">Predvolené hodnoty pre modul dokumentácie.</p>
        </div>
      </header>

      <Card className="flex flex-col gap-3 p-5">
        <div>
          <h2 className="text-sm font-semibold text-ink-900">Funkcie podpisujúceho</h2>
          <p className="text-xs text-ink-500">Možnosti v rozbaľovacom zozname „Funkcia podpisujúceho“ pri konateľovi.</p>
        </div>
        {signers.map((s, i) => (
          <div key={i} className="flex items-center gap-2">
            <Input value={s} onChange={(e) => setSigners((prev) => prev.map((v, j) => (j === i ? e.target.value : v)))} />
            <button type="button" onClick={() => setSigners((prev) => prev.filter((_, j) => j !== i))} className="text-ink-400 hover:text-status-bad">
              <Trash2 className="size-4" />
            </button>
          </div>
        ))}
        <button type="button" onClick={() => setSigners((prev) => [...prev, ''])} className="flex items-center justify-center gap-2 rounded-xl border border-dashed border-ink-300 py-2.5 text-sm font-medium text-firol-700 hover:border-firol-400 hover:bg-firol-50">
          <Plus className="size-4" /> Pridať funkciu
        </button>
      </Card>

      <Card className="flex flex-col gap-3 p-5">
        <div>
          <h2 className="text-sm font-semibold text-ink-900">Pohotovosť vodární podľa regiónu</h2>
          <p className="text-xs text-ink-500">Predvyplnia sa pri tvorbe dokumentácie. Telefón nechaj prázdny, ak ho nepoznáš.</p>
        </div>
        {water.map((w, i) => (
          <div key={i} className="flex items-center gap-2">
            <Input className="flex-1" value={w.region} placeholder="Región / kraj"
              onChange={(e) => setWater((prev) => prev.map((v, j) => (j === i ? { ...v, region: e.target.value } : v)))} />
            <Input className="flex-1" value={w.phone} placeholder="Telefón"
              onChange={(e) => setWater((prev) => prev.map((v, j) => (j === i ? { ...v, phone: e.target.value } : v)))} />
            <button type="button" onClick={() => setWater((prev) => prev.filter((_, j) => j !== i))} className="text-ink-400 hover:text-status-bad">
              <Trash2 className="size-4" />
            </button>
          </div>
        ))}
        <button type="button" onClick={() => setWater((prev) => [...prev, { region: '', phone: '' }])} className="flex items-center justify-center gap-2 rounded-xl border border-dashed border-ink-300 py-2.5 text-sm font-medium text-firol-700 hover:border-firol-400 hover:bg-firol-50">
          <Plus className="size-4" /> Pridať región
        </button>
      </Card>

      <div className="flex justify-end">
        <Button onClick={save} loading={saving}>Uložiť nastavenia</Button>
      </div>
    </div>
  );
}
