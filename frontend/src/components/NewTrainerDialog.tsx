import { useState } from 'react';
import { CheckCircle, FileSignature } from 'lucide-react';
import { Trainers, type Trainer } from '@/api/trainers';
import { ApiError } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { useAuth } from '@/auth/AuthContext';
import { Dialog } from '@/components/ui/Dialog';
import { Button } from '@/components/ui/Button';
import { TrainerForm } from '@/components/TrainerForm';
import { SignaturePickerModal } from '@/components/SignaturePickerModal';

type Props = {
  open: boolean;
  onClose: () => void;
  onCreated: (trainer: Trainer) => void;
};

type Step = 'form' | 'signature';

export function NewTrainerDialog({ open, onClose, onCreated }: Props) {
  const { csrfToken } = useAuth();
  const toast = useToast();

  const [step, setStep] = useState<Step>('form');
  const [createdTrainer, setCreatedTrainer] = useState<Trainer | null>(null);
  const [sigPickerOpen, setSigPickerOpen] = useState(false);
  const [savingSig, setSavingSig] = useState(false);

  function handleClose() {
    if (createdTrainer) {
      onCreated(createdTrainer);
    }
    onClose();
    setTimeout(() => {
      setStep('form');
      setCreatedTrainer(null);
    }, 300);
  }

  function handleTrainerCreated(trainer: Trainer) {
    setCreatedTrainer(trainer);
    setStep('signature');
  }

  async function handleSignatureSave(blob: Blob) {
    if (!createdTrainer) return;
    setSavingSig(true);
    try {
      const res = await Trainers.uploadSignature(createdTrainer.id, blob, csrfToken);
      setCreatedTrainer(res.trainer);
      toast.success('Podpis nahraný');
      setSigPickerOpen(false);
      onCreated(res.trainer);
      onClose();
      setTimeout(() => {
        setStep('form');
        setCreatedTrainer(null);
      }, 300);
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Nahranie podpisu sa nepodarilo.';
      toast.error(msg);
    } finally {
      setSavingSig(false);
    }
  }

  return (
    <>
      <Dialog
        open={open}
        onClose={handleClose}
        title={step === 'form' ? 'Nový školiteľ' : 'Podpis školiteľa'}
        description={
          step === 'form'
            ? 'Pridaj školiteľa, ktorého budeš môcť vybrať pri školeniach.'
            : `Podpis ${createdTrainer?.fullname ?? ''} sa zobrazí na PDF protokole.`
        }
        maxWidthClassName="max-w-lg"
      >
        {step === 'form' ? (
          <TrainerForm
            mode="create"
            onSaved={handleTrainerCreated}
            onCancel={handleClose}
          />
        ) : (
          <div className="flex flex-col gap-4">
            <div className="flex items-center gap-3 rounded-2xl bg-[var(--color-status-ok-bg)] px-3 py-2.5">
              <CheckCircle className="size-4 shrink-0 text-status-ok" />
              <p className="text-sm text-status-ok">
                Školiteľ <strong>{createdTrainer?.fullname}</strong> bol vytvorený.
              </p>
            </div>

            <p className="text-sm text-ink-600">
              Bez podpisu sa nedá vystaviť PDF protokol školenia. Nahrať ho môžeš teraz
              alebo neskôr v Nastaveniach.
            </p>

            <div className="flex flex-col gap-2 sm:flex-row sm:justify-end">
              <Button type="button" variant="ghost" onClick={handleClose}>
                Preskočiť
              </Button>
              <Button
                type="button"
                leftIcon={<FileSignature className="size-4" />}
                onClick={() => setSigPickerOpen(true)}
              >
                Nahrať podpis
              </Button>
            </div>
          </div>
        )}
      </Dialog>

      {sigPickerOpen && (
        <SignaturePickerModal
          onClose={() => setSigPickerOpen(false)}
          onSave={handleSignatureSave}
          saving={savingSig}
        />
      )}
    </>
  );
}
