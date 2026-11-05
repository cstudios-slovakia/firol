import { useState } from 'react';
import { Dialog } from '@/components/ui/Dialog';
import { CompanyForm } from '@/components/CompanyForm';
import type { Company } from '@/api/companies';
import { clientNouns, type ClientType } from '@/lib/clientKind';

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
 * separate page when they realise the client isn't on file yet.
 */
export function NewCompanyDialog({ open, onClose, onCreated }: Props) {
  const [clientType, setClientType] = useState<ClientType>('firma');
  return (
    <Dialog
      open={open}
      onClose={onClose}
      title={clientNouns(clientType).createTitle}
      description="Klient, ku ktorému budeš zaznamenávať revízie a kontroly."
      maxWidthClassName="max-w-lg"
    >
      <CompanyForm
        mode="create"
        onClientTypeChange={setClientType}
        onSaved={(company) => {
          onCreated(company);
          onClose();
        }}
        onCancel={onClose}
      />
    </Dialog>
  );
}
