import type { PokynSection } from '@/api/inspections';

/**
 * Default text of the "Pokyn na zabezpečenie ochrany pred požiarmi pri
 * žatevných prácach" (change request 2.3).
 *
 * Verbatim from the client's template,
 * docs/handoff/zmeny-2026-07/POapp_sablona_pokyn_zatva.docx. The technician
 * edits it before generating and the final wording is stored with the
 * document itself — so revising this default never changes a protocol that
 * has already been issued.
 *
 * Lines starting with an em dash render as bullet lists in the PDF; every
 * other line becomes a paragraph.
 */
export const POKYN_ZATVA_SECTIONS: PokynSection[] = [
  {
    title: '1. Všeobecné povinnosti',
    text:
      'Zamestnanci sú povinní zabezpečiť podľa predpisov o ochrane pred požiarmi opatrenia na ' +
      'všetkých miestach so zvýšeným nebezpečenstvom vzniku požiaru, pri činnostiach so zvýšeným ' +
      'nebezpečenstvom vzniku požiaru a v čase zvýšeného nebezpečenstva vzniku požiaru.',
  },
  {
    title: '2. Opatrenia pri zbere, spracovaní a skladovaní úrody',
    text: [
      'Zainteresovaní zamestnanci sú povinní zabezpečiť najmä:',
      '— prednostný zber dozretých obilovín najmä v okolí železničných tratí, pozemných komunikácií a skládok odpadov,',
      '— odsun pokoseného obilia alebo slamy pri železničných tratiach do vzdialenosti najmenej 30 m od osi krajnej koľaje; medzi koľajami a uloženým obilím alebo slamou (okrem plodín s podsevom) vytvoriť ochranný pás široký najmenej 10 m vo vzdialenosti 20 m od osi krajnej koľaje — pás skypriť a zbaviť ľahko zápalných látok,',
      '— pri kosbe kombajnom (kombajnmi) na ploche väčšej ako 10 ha mobilnú akcieschopnú cisternu s vodou (môže byť aj fekálna) a traktor s pluhom, pripravené na okamžité použitie v prípade požiaru,',
      '— vybavenie techniky na zber a stohovanie lapačmi iskier (okrem strojov, ktoré ich majú zabudované) a ručnými hasiacimi prístrojmi (kombajn: 1 ks vodný, 1 ks práškový); pri stohovaní zásobu vody najmenej 500 litrov (napr. 3 ks 200-litrové sudy),',
      '— zberovú a pozberovú techniku v technickom stave určenom výrobcom; zo zariadení odstraňovať usadený organický prach, ktorý môže byť zdrojom šírenia požiaru.',
    ].join('\n'),
  },
  {
    title: '3. Dosúšanie uvädnutého krmu a skladovanie sena',
    text: [
      'Zainteresovaní zamestnanci sú povinní:',
      '— zabezpečiť, aby výfukové potrubia dopravných prostriedkov boli vybavené lapačmi iskier (okrem vozidiel turbo a vozidiel, ktoré ich majú zabudované od výroby),',
      '— zabezpečiť určený počet ručných hasiacich prístrojov a zdrojov požiarnej vody,',
      '— merať teplotu uskladneného krmu najmenej na 6 miestach v jednej dosušovacej sekcii.',
      'Uvädnutý krm a iné steblové alebo stonkové rastliny sa musia dosúšať tak, aby nenastalo ich samovznietenie. Ak sa teplota krmu pri dosúšaní zvýši nad 30 °C, alebo ak sa zvýši o 7 °C nad najvyššiu dennú teplotu, musia sa zapnúť ventilátory.',
      'Lehoty merania teploty: a) počas naskladňovania a po naskladnení 1× denne po dobu jedného mesiaca, b) po mesiaci 1× týždenne ďalšie dva mesiace.',
      'Pri zohriatí krmu (seno a pod.) nad 65 °C vypnúť ventilátory a prehriaty krm vyskladniť. Pri teplote nad 90 °C okamžite vyskladniť, resp. rozobrať stoh za asistencie jednotky ochrany pred požiarmi.',
      'Teplota sa meria sondami: v skladoch sena s objemom 7 000 m³ a viac s minimálnou dĺžkou 4 m, v ostatných halových skladoch, stohoch a povalových priestoroch s dĺžkou najmenej 3 m. Meria sa najmä na miestach, kde pri rannom zapnutí ventilátorov vychádza z uskladneného krmu para alebo kde cítiť zápach prehriateho krmu.',
      'Namerané teploty, naskladňovanie a vyskladňovanie sa zaznamenávajú v skladovom denníku, ktorý obsahuje: dátum uskladnenia, druh a vlhkosť uvädnutého krmu, miesto uloženia a deň a miesto preskladnenia, preukázateľné záznamy o teplote a miestach merania, čas činnosti ventilátorov, meno a podpis osoby, ktorá meranie vykonala. Skladový denník sa uchováva jeden rok po skončení merania teploty.',
    ].join('\n'),
  },
  {
    title: '4. Zaobchádzanie s otvoreným ohňom',
    text: [
      'Spaľovať horľavé látky na voľnom priestranstve možno len výnimočne — na túto činnosť je potrebné vopred písomne požiadať o súhlas príslušné Okresné riaditeľstvo Hasičského a záchranného zboru, ktoré môže určiť podmienky alebo činnosť zakázať.',
      'V skladoch a do vzdialenosti 12 m od nich je zakázané akýmkoľvek spôsobom zaobchádzať s otvoreným ohňom, fajčiť alebo vykonávať činnosť, ktorou sa môže spôsobiť požiar.',
    ].join('\n'),
  },
  {
    title: '5. Technický stav strojov',
    text:
      'Pred zberom skontrolovať technický stav strojov (rezačky, kombajny a pod.), s osobitným ' +
      'zreteľom na: stav vysokotlakových hadíc hydrauliky, akumulátory, neporušenosť a izoláciu ' +
      'elektrických káblov, premazanie ložísk, stav klinových remeňov, stav trecích plôch ' +
      '(žacia lišta, mláťací bubon, vytriasadlá), tesnosť a stav palivovej nádrže a tesnosť ' +
      'výfukového potrubia.',
  },
  {
    title: '6. Hasiace prístroje',
    text: 'Skladové priestory a stroje vybaviť ručnými hasiacimi prístrojmi.',
  },
  {
    title: '7. Elektroinštalácia',
    text: 'Skontrolovať elektroinštaláciu v skladových priestoroch; zistené nedostatky odstrániť.',
  },
  {
    title: '8. Odstupové vzdialenosti pri stohovaní',
    text: [
      '— 30 m od vedenia vysokého napätia,',
      '— 50 m od zástavby (rodinné domy, maštale a pod.) a od voľných skladov sena a slamy,',
      '— 60 m od štátnych ciest,',
      '— 100 m od poľnohospodárskych závodov a lesov.',
    ].join('\n'),
  },
  {
    title: '9. Pozberové linky',
    text:
      'Osobitnú pozornosť venovať pozberovým linkám — najmä technickému stavu elektroinštalácie, ' +
      'odstraňovaniu organického prachu a vybaveniu hasiacimi prístrojmi.',
  },
];

/** Fresh, independently mutable copy for a new document. */
export function defaultPokynSections(): PokynSection[] {
  return POKYN_ZATVA_SECTIONS.map((s) => ({ ...s }));
}
