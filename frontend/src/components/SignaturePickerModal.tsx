import { useRef, useState } from 'react';
import { PenLine, Upload, X } from 'lucide-react';
import { cn } from '@/lib/cn';
import { Button } from '@/components/ui/Button';
import { SignaturePad, type SignaturePadHandle } from '@/components/SignaturePad';

type Mode = 'draw' | 'upload';

type Props = {
  onClose: () => void;
  onSave: (blob: Blob) => void;
  saving: boolean;
};

export function SignaturePickerModal({ onClose, onSave, saving }: Props) {
  const [mode, setMode] = useState<Mode>('draw');
  const padRef = useRef<SignaturePadHandle>(null);
  const [padEmpty, setPadEmpty] = useState(true);
  const fileRef = useRef<HTMLInputElement>(null);
  const [pickedFile, setPickedFile] = useState<File | null>(null);

  const canSave = mode === 'draw' ? !padEmpty : pickedFile !== null;

  async function handleSave() {
    if (mode === 'draw') {
      const blob = await padRef.current?.toBlob();
      if (!blob) return;
      onSave(blob);
    } else {
      if (!pickedFile) return;
      onSave(pickedFile);
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4">
      <div className="absolute inset-0 bg-black/40 backdrop-blur-sm" onClick={onClose} />
      <div className="relative z-10 flex w-full max-w-md flex-col gap-4 rounded-t-3xl bg-white p-5 shadow-2xl sm:rounded-3xl">
        <div className="flex items-center justify-between">
          <h3 className="text-base font-semibold text-ink-900">Podpis</h3>
          <button
            type="button"
            onClick={onClose}
            className="grid size-8 place-items-center rounded-full text-ink-400 transition-colors hover:bg-ink-100 hover:text-ink-700"
          >
            <X className="size-4" />
          </button>
        </div>

        <div className="flex rounded-xl border border-ink-100 bg-ink-50/60 p-1">
          <button
            type="button"
            onClick={() => setMode('draw')}
            className={cn(
              'flex flex-1 items-center justify-center gap-2 rounded-lg py-2 text-sm font-medium transition-all',
              mode === 'draw'
                ? 'bg-white text-ink-900 shadow-sm'
                : 'text-ink-500 hover:text-ink-700',
            )}
          >
            <PenLine className="size-4" />
            Nakresliť
          </button>
          <button
            type="button"
            onClick={() => setMode('upload')}
            className={cn(
              'flex flex-1 items-center justify-center gap-2 rounded-lg py-2 text-sm font-medium transition-all',
              mode === 'upload'
                ? 'bg-white text-ink-900 shadow-sm'
                : 'text-ink-500 hover:text-ink-700',
            )}
          >
            <Upload className="size-4" />
            Nahrať PNG
          </button>
        </div>

        {mode === 'draw' ? (
          <SignaturePad
            ref={padRef}
            heightPx={180}
            onEmptyChange={setPadEmpty}
          />
        ) : (
          <div>
            <input
              ref={fileRef}
              type="file"
              accept="image/png"
              className="hidden"
              onChange={(e) => setPickedFile(e.target.files?.[0] ?? null)}
            />
            <button
              type="button"
              onClick={() => fileRef.current?.click()}
              className="flex w-full flex-col items-center gap-2 rounded-2xl border border-dashed border-ink-200 py-8 text-sm text-ink-500 transition-colors hover:border-firol-300 hover:bg-firol-50/50"
            >
              <Upload className="size-5" />
              <span>{pickedFile ? pickedFile.name : 'Vybrať PNG súbor'}</span>
            </button>
            <p className="mt-1.5 text-xs text-ink-400">
              PNG s priehľadným pozadím, max 512 KB. Optimálne ~600×200 px.
            </p>
          </div>
        )}

        <div className="flex gap-2.5">
          <Button type="button" variant="secondary" onClick={onClose} className="flex-1">
            Zrušiť
          </Button>
          <Button
            type="button"
            disabled={!canSave}
            loading={saving}
            onClick={handleSave}
            className="flex-1"
          >
            Uložiť podpis
          </Button>
        </div>
      </div>
    </div>
  );
}
