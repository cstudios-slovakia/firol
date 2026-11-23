import { Building, Building2, type LucideIcon } from 'lucide-react';

/**
 * Client type lives on the company (decision D1). A prevádzka under a
 * bytové družstvo is a bytový dom.
 *
 * Wording rule: wherever a screen is about ONE known client / facility, take
 * the noun from clientNouns() / facilityNouns() so a bytové družstvo never
 * reads "firma" and a bytový dom never reads "prevádzka". Where a screen lists
 * or picks from clients of both kinds (and none is chosen yet) use the neutral
 * "klient" — see CLIENT_NEUTRAL.
 * The PHP twin is Firol\Support\ClientKind.
 */

export type ClientType = 'firma' | 'bytove_druzstvo';

export const CLIENT_TYPES: ClientType[] = ['firma', 'bytove_druzstvo'];

export function clientTypeOf(value: string | null | undefined): ClientType {
  return value === 'bytove_druzstvo' ? 'bytove_druzstvo' : 'firma';
}

export function isBytoveDruzstvo(value: string | null | undefined): boolean {
  return clientTypeOf(value) === 'bytove_druzstvo';
}

function skPlural(n: number, one: string, few: string, many: string): string {
  if (n === 1) return one;
  if (n >= 2 && n <= 4) return few;
  return many;
}

/** Slovak case forms of a noun phrase (all lowercase). */
export type Cases = {
  nom: string;
  gen: string;
  dat: string;
  acc: string;
  loc: string;
};

/** "Firma" → "Firma" — first letter up for sentence starts and labels. */
export function cap(text: string): string {
  return text.charAt(0).toUpperCase() + text.slice(1);
}

export type ClientNouns = {
  /** firma / bytové družstvo — lowercase case forms */
  cases: Cases;
  /** táto firma / toto bytové družstvo — demonstrative + noun */
  demo: Cases;
  /** celá firma / celé bytové družstvo — "the whole client" */
  whole: Cases;
  /** archivovaná / archivované — agrees with the noun's gender */
  archivedAdj: string;
  /** obnovená / obnovené */
  restoredAdj: string;
  /** Firma / Bytové družstvo */
  singular: string;
  /** Firmy / Bytové družstvá — list heading */
  plural: string;
  /** Nová firma / Nové bytové družstvo */
  createTitle: string;
  /** Upraviť firmu / Upraviť bytové družstvo */
  editTitle: string;
  /** Pridať firmu / Pridať bytové družstvo */
  addLabel: string;
  /** Vytvoriť firmu / Vytvoriť bytové družstvo */
  createLabel: string;
  /** Názov firmy / Názov bytového družstva */
  nameLabel: string;
  /** Doplň názov firmy. / Doplň názov bytového družstva. */
  nameRequired: string;
  /** Firma vytvorená / Bytové družstvo vytvorené */
  createdToast: string;
  /** Firma uložená / Bytové družstvo uložené */
  savedToast: string;
  /** ALFA Trade s. r. o. / Bytové družstvo Lipová */
  namePlaceholder: string;
  count: (n: number) => string;
};

const CLIENT: Record<ClientType, ClientNouns> = {
  firma: {
    cases: { nom: 'firma', gen: 'firmy', dat: 'firme', acc: 'firmu', loc: 'firme' },
    demo: { nom: 'táto firma', gen: 'tejto firmy', dat: 'tejto firme', acc: 'túto firmu', loc: 'tejto firme' },
    whole: { nom: 'celá firma', gen: 'celej firmy', dat: 'celej firme', acc: 'celú firmu', loc: 'celej firme' },
    archivedAdj: 'archivovaná',
    restoredAdj: 'obnovená',
    singular: 'Firma',
    plural: 'Firmy',
    createTitle: 'Nová firma',
    editTitle: 'Upraviť firmu',
    addLabel: 'Pridať firmu',
    createLabel: 'Vytvoriť firmu',
    nameLabel: 'Názov firmy',
    nameRequired: 'Doplň názov firmy.',
    createdToast: 'Firma vytvorená',
    savedToast: 'Firma uložená',
    namePlaceholder: 'ALFA Trade s. r. o.',
    count: (n) => `${n} ${skPlural(n, 'firma', 'firmy', 'firiem')}`,
  },
  bytove_druzstvo: {
    cases: {
      nom: 'bytové družstvo',
      gen: 'bytového družstva',
      dat: 'bytovému družstvu',
      acc: 'bytové družstvo',
      loc: 'bytovom družstve',
    },
    demo: {
      nom: 'toto bytové družstvo',
      gen: 'tohto bytového družstva',
      dat: 'tomuto bytovému družstvu',
      acc: 'toto bytové družstvo',
      loc: 'tomto bytovom družstve',
    },
    whole: {
      nom: 'celé bytové družstvo',
      gen: 'celého bytového družstva',
      dat: 'celému bytovému družstvu',
      acc: 'celé bytové družstvo',
      loc: 'celom bytovom družstve',
    },
    archivedAdj: 'archivované',
    restoredAdj: 'obnovené',
    singular: 'Bytové družstvo',
    plural: 'Bytové družstvá',
    createTitle: 'Nové bytové družstvo',
    editTitle: 'Upraviť bytové družstvo',
    addLabel: 'Pridať bytové družstvo',
    createLabel: 'Vytvoriť bytové družstvo',
    nameLabel: 'Názov bytového družstva',
    nameRequired: 'Doplň názov bytového družstva.',
    createdToast: 'Bytové družstvo vytvorené',
    savedToast: 'Bytové družstvo uložené',
    namePlaceholder: 'Bytové družstvo Lipová',
    count: (n) => `${n} ${skPlural(n, 'bytové družstvo', 'bytové družstvá', 'bytových družstiev')}`,
  },
};

export function clientNouns(value: string | null | undefined): ClientNouns {
  return CLIENT[clientTypeOf(value)];
}

/**
 * Neutral wording for screens that list or pick from clients of both kinds
 * before one is chosen ("Klienti", "Vyber klienta").
 */
export const CLIENT_NEUTRAL = {
  singular: 'Klient',
  plural: 'Klienti',
  cases: { nom: 'klient', gen: 'klienta', dat: 'klientovi', acc: 'klienta', loc: 'klientovi' } as Cases,
  whole: {
    nom: 'celý klient',
    gen: 'celého klienta',
    dat: 'celému klientovi',
    acc: 'celého klienta',
    loc: 'celom klientovi',
  } as Cases,
  /** "— vyber klienta —" */
  pickPlaceholder: '— vyber klienta —',
  pickFirstPlaceholder: '— najprv vyber klienta —',
} as const;

export type FacilityNouns = {
  /** prevádzka / bytový dom — lowercase case forms */
  cases: Cases;
  /** táto prevádzka / tento bytový dom — demonstrative + noun */
  demo: Cases;
  /** prevádzky / bytové domy — plural nominative & accusative, lowercase */
  pluralNom: string;
  /** prevádzok / bytových domov — plural genitive, lowercase */
  pluralGen: string;
  /** archivovaná / archivovaný — agrees with the noun's gender */
  archivedAdj: string;
  /** obnovená / obnovený */
  restoredAdj: string;
  /** Prevádzka / Bytový dom */
  singular: string;
  /** Nová prevádzka / Nový bytový dom */
  createTitle: string;
  /** Upraviť prevádzku / Upraviť bytový dom */
  editTitle: string;
  /** Pridať prevádzku / Pridať bytový dom */
  addLabel: string;
  /** Prevádzky / Bytové domy */
  tabLabel: string;
  /** Vytvoriť prevádzku / Vytvoriť bytový dom */
  createLabel: string;
  /** Názov prevádzky / Názov bytového domu */
  nameLabel: string;
  /** Doplň názov prevádzky. / Doplň názov bytového domu. */
  nameRequired: string;
  /** Prevádzka vytvorená / Bytový dom vytvorený */
  createdToast: string;
  /** Prevádzka uložená / Bytový dom uložený */
  savedToast: string;
  /** Sklad A / Novomestská 2 – 4 */
  namePlaceholder: string;
  count: (n: number) => string;
};

const FACILITY: Record<ClientType, FacilityNouns> = {
  firma: {
    cases: { nom: 'prevádzka', gen: 'prevádzky', dat: 'prevádzke', acc: 'prevádzku', loc: 'prevádzke' },
    demo: { nom: 'táto prevádzka', gen: 'tejto prevádzky', dat: 'tejto prevádzke', acc: 'túto prevádzku', loc: 'tejto prevádzke' },
    pluralNom: 'prevádzky',
    pluralGen: 'prevádzok',
    archivedAdj: 'archivovaná',
    restoredAdj: 'obnovená',
    singular: 'Prevádzka',
    createTitle: 'Nová prevádzka',
    editTitle: 'Upraviť prevádzku',
    addLabel: 'Pridať prevádzku',
    tabLabel: 'Prevádzky',
    createLabel: 'Vytvoriť prevádzku',
    nameLabel: 'Názov prevádzky',
    nameRequired: 'Doplň názov prevádzky.',
    createdToast: 'Prevádzka vytvorená',
    savedToast: 'Prevádzka uložená',
    namePlaceholder: 'Sklad A',
    count: (n) => `${n} ${skPlural(n, 'prevádzka', 'prevádzky', 'prevádzok')}`,
  },
  bytove_druzstvo: {
    cases: { nom: 'bytový dom', gen: 'bytového domu', dat: 'bytovému domu', acc: 'bytový dom', loc: 'bytovom dome' },
    demo: {
      nom: 'tento bytový dom',
      gen: 'tohto bytového domu',
      dat: 'tomuto bytovému domu',
      acc: 'tento bytový dom',
      loc: 'tomto bytovom dome',
    },
    pluralNom: 'bytové domy',
    pluralGen: 'bytových domov',
    archivedAdj: 'archivovaný',
    restoredAdj: 'obnovený',
    singular: 'Bytový dom',
    createTitle: 'Nový bytový dom',
    editTitle: 'Upraviť bytový dom',
    addLabel: 'Pridať bytový dom',
    tabLabel: 'Bytové domy',
    createLabel: 'Vytvoriť bytový dom',
    nameLabel: 'Názov bytového domu',
    nameRequired: 'Doplň názov bytového domu.',
    createdToast: 'Bytový dom vytvorený',
    savedToast: 'Bytový dom uložený',
    namePlaceholder: 'Novomestská 2 – 4',
    count: (n) => `${n} ${skPlural(n, 'bytový dom', 'bytové domy', 'bytových domov')}`,
  },
};

/**
 * Client wording for a screen that has a client picker: the exact noun once a
 * client is chosen, the neutral "klient" before.
 */
export function clientPickerNouns(
  clientChosen: boolean,
  clientType: string | null | undefined,
): { cases: Cases; whole: Cases } {
  if (clientChosen) {
    const n = clientNouns(clientType);
    return { cases: n.cases, whole: n.whole };
  }
  return { cases: CLIENT_NEUTRAL.cases, whole: CLIENT_NEUTRAL.whole };
}

export function facilityNouns(value: string | null | undefined): FacilityNouns {
  return FACILITY[clientTypeOf(value)];
}

/** Case forms of the facility noun, joined for both kinds: "prevádzka / bytový dom". */
const FACILITY_EITHER_CASES: Cases = {
  nom: `${FACILITY.firma.cases.nom} / ${FACILITY.bytove_druzstvo.cases.nom}`,
  gen: `${FACILITY.firma.cases.gen} / ${FACILITY.bytove_druzstvo.cases.gen}`,
  dat: `${FACILITY.firma.cases.dat} / ${FACILITY.bytove_druzstvo.cases.dat}`,
  acc: `${FACILITY.firma.cases.acc} / ${FACILITY.bytove_druzstvo.cases.acc}`,
  loc: `${FACILITY.firma.cases.loc} / ${FACILITY.bytove_druzstvo.cases.loc}`,
};

/**
 * Facility wording for a picker that sits below a client picker. Until a
 * client is chosen nobody knows which kind of facility is meant, so it names
 * both ("Prevádzka / bytový dom"); once one is chosen it is the exact noun.
 */
export function facilityPickerNouns(
  clientChosen: boolean,
  clientType: string | null | undefined,
): { singular: string; cases: Cases } {
  if (clientChosen) {
    const n = facilityNouns(clientType);
    return { singular: n.singular, cases: n.cases };
  }
  return { singular: cap(FACILITY_EITHER_CASES.nom), cases: FACILITY_EITHER_CASES };
}

/** One-line hint under the type selector — the prototype's wording. */
export function clientTypeHint(value: string | null | undefined): string {
  return isBytoveDruzstvo(value)
    ? 'Pri bytovom dome sa do záznamu z prehliadky tlačí popis objektu a prehľad zariadení po vchodoch. Jedno družstvo môže mať viac domov.'
    : 'Pri firme sa popis objektu ani prehľad po častiach netlačí.';
}

export function descriptionHint(_value?: string | null): string {
  return 'Tlačí sa do úvodu záznamu z prehliadky.';
}

/**
 * Icons representing the entity:
 * - Firma: commercial office building (Building)
 * - Bytové družstvo: multi-wing apartment complex / panelák (Building2)
 */
export function clientTypeIcon(value: string | null | undefined): LucideIcon {
  return isBytoveDruzstvo(value) ? Building2 : Building;
}

export type ClientTypeStyles = {
  avatarBg: string;
  avatarStaticBg: string;
  heroGradient: string;
  badge: string;
  accentText: string;
  iconBg: string;
};

export function clientTypeStyles(value: string | null | undefined, archived = false): ClientTypeStyles {
  if (archived) {
    return {
      avatarBg: 'bg-ink-200 text-ink-500 hover:bg-ink-300',
      avatarStaticBg: 'bg-ink-200 text-ink-500',
      heroGradient: 'from-ink-100/70',
      badge: 'bg-ink-100 text-ink-600',
      accentText: 'text-ink-500',
      iconBg: 'bg-ink-100 text-ink-500',
    };
  }

  if (isBytoveDruzstvo(value)) {
    return {
      avatarBg: 'bg-blue-600 text-white shadow-[0_8px_24px_hsl(217_91%_60%/0.22)] hover:bg-blue-700',
      avatarStaticBg: 'bg-blue-600 text-white shadow-[0_8px_24px_hsl(217_91%_60%/0.22)]',
      heroGradient: 'from-blue-50/70',
      badge: 'bg-blue-50 text-blue-700 border border-blue-200/60',
      accentText: 'text-blue-600',
      iconBg: 'bg-blue-50 text-blue-600',
    };
  }

  return {
    avatarBg: 'bg-firol-500 text-white shadow-[var(--shadow-glow)] hover:bg-firol-600',
    avatarStaticBg: 'bg-firol-500 text-white shadow-[var(--shadow-glow)]',
    heroGradient: 'from-firol-50/60',
    badge: 'bg-firol-50 text-firol-700 border border-firol-200/60',
    accentText: 'text-firol-600',
    iconBg: 'bg-firol-50 text-firol-500',
  };
}
