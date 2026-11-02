import { Badge } from "@/components/ui/Badge";
import {
    PREHLIADKA_SCOPE_LABELS,
    type ConceptState,
    type PrehliadkaScopeLabel,
} from "@/api/inspections";

/**
 * Tags a preventívna prehliadka puts on the records it touches (plan 8b):
 * the scope of the prehliadka (PO / BOZP / PO + BOZP) and, on a control it
 * prepared, that it is a concept waiting for the technician's confirmation.
 */
export function InspectionTags({
    scope,
    concept,
    className,
}: {
    scope?: PrehliadkaScopeLabel | null;
    concept?: ConceptState | null;
    className?: string;
}) {
    if (!scope && !concept) return null;
    return (
        <>
            {scope && (
                <Badge tone="brand" className={className}>
                    {PREHLIADKA_SCOPE_LABELS[scope]}
                </Badge>
            )}
            {concept && (
                <Badge tone={concept === "pending" ? "warn" : "ok"} className={className}>
                    {concept === "pending" ? "Z prehliadky · čaká na potvrdenie" : "Z prehliadky · potvrdený"}
                </Badge>
            )}
        </>
    );
}
