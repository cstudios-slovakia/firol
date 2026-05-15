import { CheckCircle2, FileText, Flame, Shield } from 'lucide-react';
import { AuroraBackground } from '@/components/AuroraBackground';

const HERO_FEATURES = [
  { icon: Shield, text: 'Evidencia hasiacich prístrojov a hydrantov' },
  { icon: FileText, text: 'PDF protokoly jedným kliknutím' },
  { icon: CheckCircle2, text: 'Prehľadná správa klientov a zákaziek' },
];

export function AuthLayout({
  title,
  subtitle,
  children,
  footer,
  wide = false,
}: {
  title: string;
  subtitle?: string;
  children: React.ReactNode;
  footer?: React.ReactNode;
  wide?: boolean;
}) {
  return (
    <main className="relative bg-app">
      <AuroraBackground />

      <div className="relative lg:grid lg:grid-cols-2">

        {/* ── Left hero panel (desktop only) ── */}
        <div className="hidden lg:sticky lg:top-0 lg:flex lg:h-screen lg:flex-col lg:items-start lg:justify-center lg:overflow-hidden lg:px-14 lg:py-12 bg-gradient-to-br from-firol-700 via-firol-600 to-orange-500">
          {/* decorative blobs */}
          <div className="pointer-events-none absolute -right-24 -top-24 size-80 rounded-full bg-white/10 blur-3xl" />
          <div className="pointer-events-none absolute -left-10 bottom-0 size-64 rounded-full bg-black/15 blur-3xl" />
          <div className="pointer-events-none absolute right-10 bottom-24 size-40 rounded-full bg-white/5 blur-2xl" />

          <div className="relative z-10 flex flex-col gap-10">
            {/* brand */}
            <div className="flex items-center gap-3">
              <div className="grid size-11 place-items-center rounded-2xl bg-white/20 shadow-lg backdrop-blur-sm">
                <Flame className="size-5 text-white" />
              </div>
              <span className="text-xl font-bold tracking-tight text-white">Firol</span>
            </div>

            {/* headline */}
            <div>
              <h2 className="text-[3.25rem] font-bold leading-tight text-white">
                Vitajte<br />vo Firole
              </h2>
              <p className="mt-3 max-w-xs text-base leading-relaxed text-white/70">
                Moderný softvér pre požiarnych technikov. Správa inšpekcií, protokoly a klienti na jednom mieste.
              </p>
            </div>

            {/* feature list */}
            <ul className="flex flex-col gap-3.5">
              {HERO_FEATURES.map(({ icon: Icon, text }) => (
                <li key={text} className="flex items-center gap-3 text-sm text-white/85">
                  <div className="grid size-7 shrink-0 place-items-center rounded-xl bg-white/15">
                    <Icon className="size-3.5 text-white" />
                  </div>
                  {text}
                </li>
              ))}
            </ul>
          </div>
        </div>

        {/* ── Right form panel ── */}
        <div className="flex min-h-screen flex-col px-5 py-8 sm:px-10 sm:py-14">
          <div className={`mx-auto flex w-full flex-1 flex-col ${wide ? 'max-w-[548px]' : 'max-w-md'}`}>
            <div className="flex flex-1 flex-col justify-center">
              <header className="mb-6">
                <h1 className="text-2xl font-semibold tracking-tight text-ink-900">{title}</h1>
                {subtitle && <p className="mt-1 text-sm text-ink-500">{subtitle}</p>}
              </header>

              {children}
            </div>

            {footer && (
              <footer className="pt-8 text-center text-base text-ink-600">{footer}</footer>
            )}
          </div>
        </div>

      </div>
    </main>
  );
}
