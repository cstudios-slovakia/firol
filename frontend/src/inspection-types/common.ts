/**
 * Shared types and helpers for per-inspection-type Step 2 forms.
 *
 * Each inspection type lives in its own module under `inspection-types/`
 * and exports both a `<Step2Form>` (the per-prístroj entry form) and an
 * `<ItemRow>` (how a saved item renders in the Step 3 summary). The
 * parent pages use the registry in `index.ts` to pick the right pair.
 */
import type {
  InspectionItem,
  InspectionType,
} from '@/api/inspections';

/**
 * The action the user picked on the form CTA. Determines where to
 * navigate after the API call resolves.
 */
export type SubmitAction = 'save-and-next' | 'save-and-summary';

export type Step2FormProps = {
  inspectionId: number;
  /** When set, the form opens in edit mode and prefills from this item. */
  initialItem: InspectionItem | null;
  /** CSRF token from AuthContext, threaded through via the parent. */
  csrfToken: string | null;
  /** Called after a successful save with the action the user picked. */
  onSaved: (action: SubmitAction) => void;
};

export type ItemRowProps = {
  inspectionId: number;
  index: number;
  item: InspectionItem;
  /** Drafts allow edit/delete; finalized inspections lock both. */
  canEdit: boolean;
  /** True while the parent is awaiting the DELETE response for this row. */
  deleting: boolean;
  onDelete: () => void;
};

export type StatsBarProps = {
  items: InspectionItem[];
};

/**
 * Shape that every per-type module exports. Lets the parent dispatch
 * without knowing what fields each type carries.
 */
export type InspectionTypeModule = {
  type: InspectionType;
  /**
   * Per-prístroj entry form (Step 2). Renders inside Step2Page's wrapper
   * with a card + cta; the form is responsible for state, validation and
   * the API call to add or update an item.
   */
  Step2Form: React.ComponentType<Step2FormProps>;
  /**
   * One row of the items list shown on the Step 3 summary.
   */
  ItemRow: React.ComponentType<ItemRowProps>;
  /**
   * Per-type aggregate counts shown above the items list. Each type
   * exposes its own metric (RPHP shows A/TS/O/V, hydranty shows
   * vyhovuje/nevyhovuje).
   */
  StatsBar: React.ComponentType<StatsBarProps>;
};
