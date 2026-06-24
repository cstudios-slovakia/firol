import type { InspectionListItem } from "@/api/inspections";

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
    | "superseded"; // nahradená — a newer inspection exists for this facility + type

/** Days before the due date at which a control starts flagging "blíži sa termín". */
export const SOON_THRESHOLD_DAYS = 30;

type StatusInput = Pick<
    InspectionListItem,
    "status" | "executed_on" | "periodicity_months" | "is_superseded"
>;

/**
 * Days from today until the next due date (executed_on + periodicity_months).
 * Negative when already past due. Null when there is no execution date yet.
 */
export function daysUntilNext(
    executedOn: string | null,
    periodicityMonths: number,
): number | null {
    if (!executedOn) return null;
    const base = new Date(executedOn);
    if (isNaN(base.getTime())) return null;
    const next = new Date(base);
    next.setMonth(next.getMonth() + periodicityMonths);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    next.setHours(0, 0, 0, 0);
    return Math.round(
        (next.getTime() - today.getTime()) / (1000 * 60 * 60 * 24),
    );
}

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
    if (it.is_superseded) return { kind: "superseded", days: null };

    const days = daysUntilNext(it.executed_on, it.periodicity_months);
    if (days === null) return { kind: "valid", days: null };
    if (days < 0) return { kind: "overdue", days };
    if (days <= SOON_THRESHOLD_DAYS) return { kind: "soon", days };
    return { kind: "valid", days };
}
