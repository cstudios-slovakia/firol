import { Dialog } from '@/components/ui/Dialog';
import { CompanyForm } from '@/components/CompanyForm';
import type { Company } from '@/api/companies';

type Props = {
  open: boolean;
  onClose: () => void;
  /**
   * Called once the company has been created. The caller is expected to
   * refresh its company list and (optionally) auto-select the new one.
   */
  onCreated: (company: Company) => void;
};

/**
 * Quick-create dialog used inside company pickers (inspection step 1,
 * new training, …) so the technician doesn't need to bounce out to a
 * separate page when they realise the firm isn't on file yet.
 */
export function NewCompanyDialog({ open, onClose, onCreated }: Props) {
  return (
    <Dialog
      open={open}
      onClose={onClose}
      title="Nová firma"
      description="Klient, ku ktorému budeš zaznamenávať revízie a kontroly."
      maxWidthClassName="max-w-lg"
    >
      <CompanyForm
        mode="create"
        onSaved={(company) => {
          onCreated(company);
          onClose();
        }}
        onCancel={onClose}
      />
    </Dialog>
  );
}
