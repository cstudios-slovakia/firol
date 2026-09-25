import { periodicityOf, type InspectionListItem } from "@/api/inspections";
import { daysUntilNext } from "@/lib/periodicity";

export { daysUntilNext };

/**
 * Validity status of a finalized inspection, derived from its execution date
 * and periodicity (and supersession). This is the single source of truth used
 * by both the list view (grouping + badge) and the detail view (badge).
 */
export type InspectionStatusKind =
    | "draft" // still a working copy, no validity yet
    | "valid" // platná
    | "soon" // blíži sa termín (within SOON_THRESHOLD_DAYS)
    | "overdue" // po termíne
    | "superseded" // nahradená — a newer inspection exists for this facility + type
    | "entry"; // požiarna kniha plain entry — outside the statutory cycle

/** Days before the due date at which a control starts flagging "blíži sa termín". */
export const SOON_THRESHOLD_DAYS = 30;

type StatusInput = Pick<
    InspectionListItem,
    | "status"
    | "executed_on"
    | "periodicity_value"
    | "periodicity_unit"
    | "is_superseded"
    | "is_preventive_inspection"
>;

/**
 * Resolve the validity status. Supersession wins over the date math: once a
 * newer inspection exists for the same facility + type, the older one is
 * "Nahradená" and must not show up as overdue. Drafts have no validity status.
 */
export function getInspectionStatus(it: StatusInput): {
    kind: InspectionStatusKind;
    days: number | null;
} {
    if (it.status === "draft") return { kind: "draft", days: null };
    // A plain fire-book entry is outside the statutory cycle: it never counts
    // down to a due date and never flags overdue (change request 1.7).
    if (it.is_preventive_inspection === false) return { kind: "entry", days: null };
    if (it.is_superseded) return { kind: "superseded", days: null };

    const days = daysUntilNext(it.executed_on, periodicityOf(it));
    if (days === null) return { kind: "valid", days: null };
    if (days < 0) return { kind: "overdue", days };
    if (days <= SOON_THRESHOLD_DAYS) return { kind: "soon", days };
    return { kind: "valid", days };
}
