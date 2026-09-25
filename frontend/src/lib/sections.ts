import type { InspectionType } from '@/api/inspections';

/**
 * The three sections the app is split into — block 1 / chapter 2.
 *
 * The single section "Kontroly" becomes Revízie / OPP / BOZP, matching the
 * three paid modules one to one. The section Školenia disappears: a training is
 * an úkon like any other, with its own protocol and its own next term, so
 * školenie PO belongs beside the rest of the PO work rather than in a menu of
 * its own.
 *
 * Firms, the calendar, the profile and the branding stay common to all three.
 * A visit (chapter 9) belongs to no section at all — it is an activity, and it
 * offers types from every section at once.
 */
export type Section = 'revizie' | 'opp' | 'bozp';

export const SECTIONS: Section[] = ['revizie', 'opp', 'bozp'];

export const SECTION_LABELS: Record<Section, string> = {
  revizie: 'Revízie',
  opp: 'OPP',
  bozp: 'BOZP',
};

export const SECTION_PATHS: Record<Section, string> = {
  revizie: '/revizie',
  opp: '/opp',
  bozp: '/bozp',
};

/**
 * Colour of each odbor. Never the only signal — a protocol also names its
 * section in a box in the header, because that is the one thing that survives
 * a black-and-white printer.
 */
export const SECTION_COLORS: Record<Section, string> = {
  revizie: '#C75B45',
  opp: '#E8433A',
  bozp: '#3D7FC1',
};

/**
 * Colour of the trainings inside a section. A section's kontroly wear its own
 * colour; the trainings next to them wear this one, so the switcher, the
 * „Nové školenie" button and the training list read as the other half of the
 * section at a glance. Deliberately none of the three odbor colours — a
 * training is not a section of its own.
 */
export const TRAINING_COLOR = '#7A5AC8';

/** Inspection types in each section. */
export const SECTION_INSPECTION_TYPES: Record<Section, InspectionType[]> = {
  revizie: ['php', 'oprava_ts_php', 'vyradenie', 'hydranty', 'ts_hadic'],
  opp: ['poziarna_kniha', 'pu_akcieschopnost', 'pu_udrzba', 'nudzove_osvetlenie'],
  // The BOZP úkony of block 2.
  bozp: [
    // Block 2 — single-record úkony (api/bozpRecords.ts).
    'kniha_bozp', 'pracovisko', 'osamele_pracovisko', 'fajcenie',
    // Block 2 — chapter 5.3 order.
    'oopp', 'pracovne_prostriedky', 'rebriky', 'regale', 'oznacenie',
    // Oboznámenie BOZP is an úkon of this list, not a training-tree record
    // (it has a period, joins a visit and shares its person list with the
    // tests), so the BOZP section has no Školenia tab.
    'dychova_skuska', 'omamne_latky', 'skolenie_bozp',
  ],
};

/**
 * True when the section has anything in it. A section with no types would be a
 * dead menu item, so the nav leaves it out — which is also how the module
 * gating in block 5 will read once the BOZP types exist.
 */
export function sectionHasContent(section: Section): boolean {
  return SECTION_INSPECTION_TYPES[section].length > 0 || sectionHasTrainings(section);
}

/**
 * Trainings live in their own table but belong to a section like anything
 * else. The six PO trainings and the Pokyn — žatevné práce are OPP. BOZP
 * oboznámenie (block 2) is the inspection type `skolenie_bozp` and is listed
 * with the other BOZP úkony, so BOZP has no training tab.
 */
export const TRAINING_SECTION: Section = 'opp';

export function sectionHasTrainings(section: Section): boolean {
  return section === TRAINING_SECTION;
}

/**
 * Query that opens a section on its Školenia tab. The tab lives in the URL
 * rather than in component state so every „Späť" out of a training lands back
 * on the training list, not on the section's kontroly.
 */
export const SECTION_TAB_PARAM = 'tab';
export const TRAININGS_TAB = 'skolenia';

/** The training list — the Školenia tab of OPP. There is no /trainings page. */
export const TRAININGS_PATH =
  `${SECTION_PATHS[TRAINING_SECTION]}?${SECTION_TAB_PARAM}=${TRAININGS_TAB}`;

/**
 * The type picker narrowed to one section. Without a section the picker offers
 * every type, which is right from Dnes or a firm, but never from a section.
 */
export function newInspectionPath(section: Section | null): string {
  return section ? `/inspections/new?section=${section}` : '/inspections/new';
}

export function sectionForInspectionType(type: InspectionType): Section | null {
  for (const section of SECTIONS) {
    if (SECTION_INSPECTION_TYPES[section].includes(type)) return section;
  }
  return null;
}

/** Path of the list the given inspection belongs in. */
export function sectionPathForType(type: InspectionType): string {
  const section = sectionForInspectionType(type);
  return section ? SECTION_PATHS[section] : SECTION_PATHS.revizie;
}

export function isSection(value: string | undefined): value is Section {
  return typeof value === 'string' && (SECTIONS as string[]).includes(value);
}
