/**
 * Client type lives on the company (decision D1). A prevádzka under a
 * bytové družstvo is a bytový dom. Use these nouns only where the entity
 * is named — the rest of the app keeps saying prevádzka.
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

export type FacilityNouns = {
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

export function facilityNouns(value: string | null | undefined): FacilityNouns {
  return FACILITY[clientTypeOf(value)];
}

/** One-line hint under the type selector — the prototype's wording. */
export function clientTypeHint(value: string | null | undefined): string {
  return isBytoveDruzstvo(value)
    ? 'Pri bytovom dome sa do záznamu z prehliadky tlačí popis objektu a prehľad zariadení po vchodoch. Jedno družstvo môže mať viac domov.'
    : 'Pri firme sa popis objektu ani prehľad po častiach netlačí.';
}

export function descriptionHint(value: string | null | undefined): string {
  return isBytoveDruzstvo(value)
    ? 'Nepovinné. Pri bytovom dome býva vždy — tlačí sa do úvodu záznamu z prehliadky.'
    : 'Nepovinné. Pri firmách väčšinou netreba, ale keď ho vyplníte, vytlačí sa do úvodu záznamu z prehliadky.';
}
