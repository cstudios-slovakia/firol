import { ChevronsRight } from 'lucide-react';
import { Button } from '@/components/ui/Button';

/**
 * „Uložiť a ďalší v poradí" — the fourth button of an item form (fix list of
 * 16. 11. 2026, point 2): saves the item being edited and opens the next item
 * already recorded in the same úkon, so a technician walking through carried
 * items never has to go back to the summary between them. Shown only while
 * editing an item that has a next one; the page decides that and navigates.
 */
export function NextRecordedButton({
  loading,
  disabled = false,
  onClick,
}: {
  loading: boolean;
  /** While a photo is still being resized. */
  disabled?: boolean;
  onClick: (e: React.SyntheticEvent) => void;
}) {
  return (
    <Button
      type="button"
      variant="secondary"
      onClick={onClick}
      loading={loading}
      disabled={disabled}
      rightIcon={<ChevronsRight className="size-4" />}
      title="Uloží položku a otvorí ďalšiu už zapísanú položku tejto kontroly."
      data-next-recorded
    >
      Uložiť a ďalší v poradí
    </Button>
  );
}
