import { createContext, useCallback, useContext, useRef, useState } from 'react';
import { Dialog } from '@/components/ui/Dialog';
import { Button } from '@/components/ui/Button';

type ConfirmOptions = {
  title: string;
  description?: string;
  /** Confirm button label. Defaults to "Odstrániť". */
  confirmLabel?: string;
  /** Cancel button label. Defaults to "Zrušiť". */
  cancelLabel?: string;
  /** Confirm button styling. "danger" (default) for destructive actions. */
  tone?: 'danger' | 'primary';
};

type ConfirmFn = (options: ConfirmOptions) => Promise<boolean>;

const ConfirmContext = createContext<ConfirmFn | null>(null);

/**
 * App-wide confirmation dialog. Replaces the native `window.confirm()` with
 * the same `<Dialog>` used elsewhere so every yes/no prompt looks consistent.
 *
 * Promise-based so call sites stay terse:
 *   if (!(await confirm({ title: 'Odstrániť?' }))) return;
 */
export function ConfirmProvider({ children }: { children: React.ReactNode }) {
  const [open, setOpen] = useState(false);
  // Kept set during the exit animation so the text doesn't blank out mid-fade.
  const [options, setOptions] = useState<ConfirmOptions | null>(null);
  const resolverRef = useRef<((value: boolean) => void) | null>(null);

  const confirm = useCallback<ConfirmFn>((opts) => {
    setOptions(opts);
    setOpen(true);
    return new Promise<boolean>((resolve) => {
      resolverRef.current = resolve;
    });
  }, []);

  const settle = useCallback((value: boolean) => {
    resolverRef.current?.(value);
    resolverRef.current = null;
    setOpen(false);
  }, []);

  return (
    <ConfirmContext.Provider value={confirm}>
      {children}
      <Dialog
        open={open}
        onClose={() => settle(false)}
        title={options?.title ?? ''}
        description={options?.description}
      >
        <div className="flex justify-end gap-2">
          <Button variant="ghost" size="sm" onClick={() => settle(false)}>
            {options?.cancelLabel ?? 'Zrušiť'}
          </Button>
          <Button
            variant={options?.tone === 'primary' ? 'primary' : 'danger'}
            size="sm"
            onClick={() => settle(true)}
          >
            {options?.confirmLabel ?? 'Odstrániť'}
          </Button>
        </div>
      </Dialog>
    </ConfirmContext.Provider>
  );
}

export function useConfirm(): ConfirmFn {
  const ctx = useContext(ConfirmContext);
  if (!ctx) {
    throw new Error('useConfirm must be used inside <ConfirmProvider>');
  }
  return ctx;
}
