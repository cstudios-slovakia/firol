import type { CSSProperties } from "react";
import type { Section } from "@/lib/sections";

export type SectionIconProps = {
    className?: string;
    style?: CSSProperties;
};

/**
 * One drawn icon per odbor, in the Lucide idiom (24 grid, 2px round stroke,
 * `currentColor`) so they sit beside the stock icons without looking foreign.
 * The main shape also carries a faint fill of the same colour — that is what
 * lets the odbor's colour read at 16px, where a bare outline goes thin.
 */
function Glyph({
    className,
    style,
    children,
}: SectionIconProps & { children: React.ReactNode }) {
    return (
        <svg
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={2}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            focusable="false"
            className={className}
            style={style}
        >
            {children}
        </svg>
    );
}

const TINT = { fill: "currentColor", fillOpacity: 0.16 } as const;

/** Revízie — a fire extinguisher ticked off: the inspected equipment. */
export function RevizieIcon(props: SectionIconProps) {
    return (
        <Glyph {...props}>
            <rect x="6" y="9" width="10" height="13" rx="3" {...TINT} />
            <path d="M11 9V4.5M8 4.5h5" />
            <path d="M11 7h5a3 3 0 0 1 3 3v3" />
            <path d="m8.6 15.9 1.9 1.9 3.4-3.6" />
        </Glyph>
    );
}

/** OPP — a flame under a shield: protection against fire. */
export function OppIcon(props: SectionIconProps) {
    return (
        <Glyph {...props}>
            <path
                d="M12 2.5 19.5 5.5v5.8c0 4.5-3.1 8.2-7.5 10.2-4.4-2-7.5-5.7-7.5-10.2V5.5Z"
                {...TINT}
            />
            <path d="M12 8.2c.4 1.5 2.6 2.6 2.6 4.6a2.6 2.6 0 0 1-5.2 0c0-.9.4-1.6.9-2.1.1.7.5 1.1 1 1.1-.2-1.3-.1-2.5.7-3.6Z" />
        </Glyph>
    );
}

/** BOZP — a hard hat: safety and health at work. */
export function BozpIcon(props: SectionIconProps) {
    return (
        <Glyph {...props}>
            <path d="M4.5 16v-1.5a7.5 7.5 0 0 1 15 0V16Z" {...TINT} />
            <path d="M2.5 16h19v2.5a1 1 0 0 1-1 1h-17a1 1 0 0 1-1-1Z" />
            <path d="M10 7.3V16M14 7.3V16" />
        </Glyph>
    );
}

export const SECTION_ICONS: Record<
    Section,
    (props: SectionIconProps) => React.JSX.Element
> = {
    revizie: RevizieIcon,
    opp: OppIcon,
    bozp: BozpIcon,
};
