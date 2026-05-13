import { Flame } from 'lucide-react';
import { Link } from 'react-router-dom';
import { AuroraBackground } from '@/components/AuroraBackground';

export function AuthLayout({
  title,
  subtitle,
  children,
  footer,
}: {
  title: string;
  subtitle?: string;
  children: React.ReactNode;
  footer?: React.ReactNode;
}) {
  return (
    <main className="bg-app relative min-h-screen">
      <AuroraBackground />
      <div className="mx-auto flex min-h-screen max-w-lg flex-col px-5 py-8 sm:py-14">
        <Link to="/" className="flex items-center gap-2.5 self-start">
          <span className="grid size-10 place-items-center rounded-2xl bg-firol-500 text-white shadow-[var(--shadow-glow)]">
            <Flame className="size-5" />
          </span>
          <span className="text-lg font-semibold tracking-tight text-ink-900">Firol</span>
        </Link>

        <div className="flex flex-1 flex-col justify-center pt-10">
          <header className="mb-6">
            <h1 className="text-2xl font-semibold tracking-tight text-ink-900">{title}</h1>
            {subtitle && <p className="mt-1 text-sm text-ink-500">{subtitle}</p>}
          </header>

          {children}
        </div>

        {footer && <footer className="pt-6 text-center text-sm text-ink-500">{footer}</footer>}
      </div>
    </main>
  );
}
