import { Component, type ErrorInfo, type ReactNode } from 'react';
import { Card } from '@/components/ui/Card';

/**
 * Catches uncaught render-time errors so the user gets a friendly screen
 * instead of a blank page. The fallback offers a hard reload — the most
 * reliable recovery path because we can't safely re-render a subtree
 * whose state is already in a bad shape.
 *
 * Network errors are not caught here (they happen inside async handlers
 * and flow through the per-page error states); only synchronous render
 * crashes hit this boundary.
 */
type State = { error: Error | null };

export class ErrorBoundary extends Component<{ children: ReactNode }, State> {
  state: State = { error: null };

  static getDerivedStateFromError(error: Error): State {
    return { error };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    // Surface in the dev console; in prod we could pipe this to a logger.
    // Keep the call site cheap so we don't double-fault during recovery.
    console.error('[ErrorBoundary]', error, info.componentStack);
  }

  render(): ReactNode {
    if (this.state.error) {
      return (
        <div className="min-h-screen bg-app">
          <div className="mx-auto flex max-w-md flex-col items-center gap-4 px-4 py-16 text-center">
            <span className="grid size-14 place-items-center rounded-2xl bg-[var(--color-status-bad-bg)] text-[var(--color-status-bad)] text-2xl font-semibold">
              !
            </span>
            <h1 className="text-lg font-semibold text-ink-900">
              Niečo sa pokazilo.
            </h1>
            <p className="text-sm text-ink-500">
              Stránku sa nepodarilo zobraziť. Skús ju načítať znova — tvoje uložené dáta sú v bezpečí.
            </p>
            <Card className="w-full px-3 py-2 text-left">
              <code className="block whitespace-pre-wrap break-words text-xs text-ink-600">
                {this.state.error.message}
              </code>
            </Card>
            <button
              type="button"
              onClick={() => window.location.reload()}
              className="inline-flex h-11 items-center rounded-2xl bg-firol-500 px-4 text-sm font-medium text-white shadow-[var(--shadow-glow)] hover:bg-firol-600"
            >
              Načítať znova
            </button>
          </div>
        </div>
      );
    }
    return this.props.children;
  }
}
