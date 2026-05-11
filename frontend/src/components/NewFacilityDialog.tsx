import { Dialog } from '@/components/ui/Dialog';
import { FacilityForm } from '@/components/FacilityForm';
import type { Facility } from '@/api/facilities';

type Props = {
  open: boolean;
  onClose: () => void;
  /** Company the new facility will be attached to. */
  companyId: number;
  /** Company name shown in the dialog subtitle. */
  companyName: string;
  /**
   * Called once the facility has been created. The caller is expected
   * to refresh its facility list and (optionally) auto-select the new
   * one.
   */
  onCreated: (facility: Facility) => void;
};

/**
 * Quick-create dialog used inside facility pickers (inspection step 1,
 * new training, …). Mirrors NewCompanyDialog so the user has the same
 * inline-create affordance one level deeper, without bouncing out to
 * the standalone facility editor.
 */
export function NewFacilityDialog({ open, onClose, companyId, companyName, onCreated }: Props) {
  return (
    <Dialog
      open={open}
      onClose={onClose}
      title="Nová prevádzka"
      description={`Pridať prevádzku pre firmu ${companyName}`}
      maxWidthClassName="max-w-lg"
    >
      <FacilityForm
        mode="create"
        companyId={companyId}
        onSaved={(facility) => {
          onCreated(facility);
          onClose();
        }}
        onCancel={onClose}
      />
    </Dialog>
  );
}
