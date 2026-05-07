import { useEffect, useState } from 'react';
import { Flame, Server, Database } from 'lucide-react';
import { cn } from '@/lib/cn';

type Health = { status: string; php: string; time: string };

export default function App() {
  const [health, setHealth] = useState<Health | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    fetch(`${import.meta.env.VITE_API_BASE_URL ?? ''}/api/health`)
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then(setHealth)
      .catch((e) => setError(e.message));
  }, []);

  return (
    <div className="min-h-screen bg-gradient-to-br from-zinc-50 to-zinc-100 flex items-center justify-center p-6">
      <div className="w-full max-w-md rounded-3xl bg-white shadow-xl border border-zinc-100 p-8 space-y-6">
        <div className="flex items-center gap-3">
          <div className="rounded-2xl bg-[#E8433A] p-2.5 text-white">
            <Flame className="size-6" />
          </div>
          <div>
            <h1 className="text-2xl font-semibold text-zinc-900">Firol</h1>
            <p className="text-sm text-zinc-500">Vývojové prostredie pripravené.</p>
          </div>
        </div>

        <div className="space-y-2">
          <Row icon={<Server className="size-4" />} label="Frontend (Vite)">
            <Badge tone="green">OK</Badge>
          </Row>
          <Row icon={<Database className="size-4" />} label="Backend /api/health">
            {error ? (
              <Badge tone="red">{error}</Badge>
            ) : health ? (
              <Badge tone="green">{health.status} · PHP {health.php}</Badge>
            ) : (
              <Badge tone="gray">…</Badge>
            )}
          </Row>
        </div>

        <p className="text-xs text-zinc-400">
          Skutočná aplikácia ešte nie je rozbehnutá — toto je iba scaffold.
        </p>
      </div>
    </div>
  );
}

function Row({
  icon,
  label,
  children,
}: {
  icon: React.ReactNode;
  label: string;
  children: React.ReactNode;
}) {
  return (
    <div className="flex items-center justify-between gap-3 rounded-2xl border border-zinc-100 bg-zinc-50 px-4 py-3">
      <div className="flex items-center gap-2 text-sm text-zinc-700">
        {icon}
        <span>{label}</span>
      </div>
      {children}
    </div>
  );
}

function Badge({
  tone,
  children,
}: {
  tone: 'green' | 'red' | 'gray';
  children: React.ReactNode;
}) {
  return (
    <span
      className={cn(
        'rounded-full px-2.5 py-0.5 text-xs font-medium',
        tone === 'green' && 'bg-green-100 text-green-700',
        tone === 'red' && 'bg-red-100 text-red-700',
        tone === 'gray' && 'bg-zinc-200 text-zinc-600'
      )}
    >
      {children}
    </span>
  );
}
