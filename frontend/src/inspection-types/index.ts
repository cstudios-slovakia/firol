import type { InspectionType } from '@/api/inspections';
import type { InspectionTypeModule } from './common';
import { rphpModule } from './rphp';
import { hydrantyModule } from './hydranty';
import { opravaTsRphpModule } from './oprava_ts_rphp';
import { poziarnaKnihaModule } from './poziarna_kniha';
import { puAkcieschopnostModule } from './pu_akcieschopnost';
import { puUdrzbaModule } from './pu_udrzba';
import { nudzoveOsvetlenieModule } from './nudzove_osvetlenie';
import { tsHadicModule } from './ts_hadic';

/**
 * Per-inspection-type form/row registry. Pages dispatch via
 * `getTypeModule(type)`; types not yet implemented return null and the
 * page renders a "coming soon" notice.
 *
 * Add new types by importing the module and registering it here.
 */
const REGISTRY: Partial<Record<InspectionType, InspectionTypeModule>> = {
  rphp: rphpModule,
  hydranty: hydrantyModule,
  oprava_ts_rphp: opravaTsRphpModule,
  poziarna_kniha: poziarnaKnihaModule,
  pu_akcieschopnost: puAkcieschopnostModule,
  pu_udrzba: puUdrzbaModule,
  nudzove_osvetlenie: nudzoveOsvetlenieModule,
  ts_hadic: tsHadicModule,
};

export function getTypeModule(type: InspectionType): InspectionTypeModule | null {
  return REGISTRY[type] ?? null;
}

export type { InspectionTypeModule } from './common';
