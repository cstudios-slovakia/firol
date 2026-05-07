import { Card } from '@/components/ui/Card';
import { Sparkles } from 'lucide-react';

export function PlaceholderPage({ title, subtitle }: { title: string; subtitle: string }) {
  return (
    <div className="flex flex-col gap-4">
      <header>
        <h1 className="text-xl font-semibold tracking-tight text-ink-900">{title}</h1>
      </header>
      <Card className="flex flex-col items-center gap-3 px-6 py-12 text-center">
        <div className="grid size-14 place-items-center rounded-2xl bg-firol-50 text-firol-500">
          <Sparkles className="size-6" />
        </div>
        <h2 className="text-base font-semibold text-ink-900">Príde čoskoro</h2>
        <p className="max-w-xs text-sm text-ink-500">{subtitle}</p>
      </Card>
    </div>
  );
}
