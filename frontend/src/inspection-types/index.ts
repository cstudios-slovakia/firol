import type { InspectionType } from '@/api/inspections';
import type { InspectionTypeModule } from './common';
import { phpModule } from './php';
import { hydrantyModule } from './hydranty';
import { opravaTsPhpModule } from './oprava_ts_php';
import { poziarnaKnihaModule } from './poziarna_kniha';
import { puAkcieschopnostModule } from './pu_akcieschopnost';
import { puUdrzbaModule } from './pu_udrzba';
import { nudzoveOsvetlenieModule } from './nudzove_osvetlenie';
import { tsHadicModule } from './ts_hadic';
import { vyradenieModule } from './vyradenie';
import { knihaBozpModule } from './kniha_bozp';
import { pracoviskoModule } from './pracovisko';
import { osamelePracoviskoModule } from './osamele_pracovisko';
import { fajcenieModule } from './fajcenie';
import { ooppModule } from './oopp';
import { pracovneProstriedkyModule } from './pracovne_prostriedky';
import { rebrikyModule } from './rebriky';
import { regaleModule } from './regale';
import { oznacenieModule } from './oznacenie';
import { dychovaSkuskaModule, omamneLatkyModule, skolenieBozpModule } from './osoby';

/**
 * Per-inspection-type form/row registry. Pages dispatch via
 * `getTypeModule(type)`; types not yet implemented return null and the
 * page renders a "coming soon" notice.
 *
 * Add new types by importing the module and registering it here.
 */
const REGISTRY: Partial<Record<InspectionType, InspectionTypeModule>> = {
  php: phpModule,
  hydranty: hydrantyModule,
  oprava_ts_php: opravaTsPhpModule,
  poziarna_kniha: poziarnaKnihaModule,
  pu_akcieschopnost: puAkcieschopnostModule,
  pu_udrzba: puUdrzbaModule,
  nudzove_osvetlenie: nudzoveOsvetlenieModule,
  ts_hadic: tsHadicModule,
  vyradenie: vyradenieModule,
  // Block 2 — single-record BOZP úkony.
  kniha_bozp: knihaBozpModule,
  pracovisko: pracoviskoModule,
  osamele_pracovisko: osamelePracoviskoModule,
  fajcenie: fajcenieModule,
  // Block 2 — BOZP úkony whose rows are items.
  oopp: ooppModule,
  pracovne_prostriedky: pracovneProstriedkyModule,
  rebriky: rebrikyModule,
  regale: regaleModule,
  oznacenie: oznacenieModule,
  // Block 2 — the person-list úkony; the list itself is typed on
  // pages/PersonsFillPage (/inspections/:id/osoby).
  dychova_skuska: dychovaSkuskaModule,
  omamne_latky: omamneLatkyModule,
  skolenie_bozp: skolenieBozpModule,
};

export function getTypeModule(type: InspectionType): InspectionTypeModule | null {
  return REGISTRY[type] ?? null;
}

export type { InspectionTypeModule } from './common';
