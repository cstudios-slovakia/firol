import { AlertTriangle, CheckCircle2, Clock, History } from "lucide-react";
import { Badge } from "@/components/ui/Badge";
import {
    getInspectionStatus,
    type InspectionStatusKind,
} from "@/lib/inspectionStatus";
import type { InspectionListItem } from "@/api/inspections";

type Tone = "neutral" | "ok" | "warn" | "bad";

const META: Record<
    Exclude<InspectionStatusKind, "draft">,
    { tone: Tone; label: string; Icon: typeof CheckCircle2 }
> = {
    valid: { tone: "ok", label: "Platná", Icon: CheckCircle2 },
    soon: { tone: "warn", label: "Blíži sa", Icon: Clock },
    overdue: { tone: "bad", label: "Po termíne", Icon: AlertTriangle },
    superseded: { tone: "neutral", label: "Nahradená", Icon: History },
};

/**
 * Validity badge for a finalized inspection (platná / blíži sa / po termíne /
 * nahradená). Renders nothing for drafts — their lifecycle is shown by the
 * "Koncept" badge instead. Used in both the list and detail views so the
 * status reads identically everywhere.
 */
export function InspectionStatusBadge({
    inspection,
    showDays = true,
    className,
}: {
    inspection: Pick<
        InspectionListItem,
        "status" | "executed_on" | "periodicity_months" | "is_superseded"
    >;
    showDays?: boolean;
    className?: string;
}) {
    const { kind, days } = getInspectionStatus(inspection);
    if (kind === "draft") return null;

    const { tone, label, Icon } = META[kind];
    const suffix =
        showDays && days !== null && (kind === "overdue" || kind === "soon")
            ? ` · ${Math.abs(days)} dní`
            : "";

    return (
        <Badge tone={tone} className={className}>
            <Icon className="size-3" />
            {label}
            {suffix}
        </Badge>
    );
}
