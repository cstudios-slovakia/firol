import { api, buildUrl, type OptimisticSpec } from '@/lib/api';

export type TrainingType =
  | 'vstupne'
  | 'opakovane'
  | 'opp_mimo'
  | 'zdrzujuca_sa'
  | 'hliadka_oph'
  | 'hliadka_opah'
  | 'pokyn_zatva';

export const TRAINING_TYPES: TrainingType[] = [
  'vstupne', 'opakovane', 'opp_mimo', 'zdrzujuca_sa', 'hliadka_oph', 'hliadka_opah',
  'pokyn_zatva',
];

export const TRAINING_TYPE_LABELS: Record<TrainingType, string> = {
  vstupne: 'Vstupné školenie vedúcich a ostatných zamestnancov',
  opakovane: 'Opakované školenie vedúcich a ostatných zamestnancov',
  opp_mimo: 'Školenie osôb zabezpečujúcich OPP v mimopracovnom čase',
  zdrzujuca_sa: 'Školenie osôb zdržujúcich sa na pracovisku',
  hliadka_oph: 'Odborná príprava protipožiarnej hliadky pracoviska',
  hliadka_opah: 'Odborná príprava protipožiarnej asistenčnej hliadky',
  pokyn_zatva:
    'Pokyn na zabezpečenie ochrany pred požiarmi pri žatevných prácach, '
    + 'pri zbere a skladovaní objemových krmovín',
};

export const TRAINING_TYPE_SHORT: Record<TrainingType, string> = {
  vstupne: 'Vstupné',
  opakovane: 'Opakované',
  opp_mimo: 'OPP mimopracovne',
  zdrzujuca_sa: 'Zdržujúce sa osoby',
  hliadka_oph: 'Príprava OPH',
  hliadka_opah: 'Príprava OPAH',
  pokyn_zatva: 'Pokyn — žatva',
};

/**
 * The one type that is a document for the client's employees rather than a
 * session they attend: no trainee list, an editable instruction text instead,
 * and its own ZAT-RRRR-NNN number series (change request 2.3).
 */
export const POKYN_ZATVA: TrainingType = 'pokyn_zatva';

export function isPokyn(type: TrainingType): boolean {
  return type === POKYN_ZATVA;
}

/** One editable block of the Pokyn's instruction text. */
export type PokynSection = { title: string; text: string };

/**
 * Payload of a Pokyn — the harvest year it covers, an optional per-document
 * override of the company's schvaľujúca osoba, and the instruction text. The
 * text lives with the document, so an issued Pokyn keeps saying what it said
 * even after the default template is revised.
 */
export type PokynZatvaFields = {
  year: number;
  approver: string | null;
  sections: PokynSection[];
};

export type TrainingStatus = 'draft' | 'finalized';

export type TrainingListItem = {
  id: number;
  type: TrainingType;
  date: string | null;
  duration_min: number | null;
  topics: string | null;
  status: TrainingStatus;
  created_at: string;
  company_id: number;
  company_name: string;
  facility_id: number | null;
  facility_name: string | null;
  trainer_id: number | null;
  trainer_name: string | null;
  trainees_count: number;
  // Pokyn only — the harvest year, so a list row can name itself without
  // dragging the whole instruction text along.
  pokyn_year: number | null;
};

export type Training = TrainingListItem & {
  updated_at: string;
  company_ico: string | null;
  company_approver: string | null;
  trainer_certification_number: string | null;
  /** Pokyn only; null for the six attendance-based training types. */
  fields: PokynZatvaFields | null;
};

export type Trainee = {
  id: number;
  fullname: string;
  position: string | null;
  has_signature: boolean;
  signed_at: string | null;
  created_at: string;
  updated_at: string;
};

export type TrainingDocument = {
  id: number;
  type: string;
  number: string;
  generated_at: string;
  signed: boolean;
  download_url: string;
};

export type TrainingGeneratePdfResponse = {
  document: TrainingDocument & {
    parent_type: 'training';
    parent_id: number;
    file_path: string;
    signed_at: string | null;
  };
};

export type TrainingDetail = {
  training: Training;
  trainees: Trainee[];
};

export type TrainingPayload = {
  type: TrainingType;
  company_id: number;
  facility_id?: number | null;
  date: string;
  trainer_id?: number | null;
  topics?: string | null;
  duration_min?: number | null;
  /** Pokyn only — seeded from the template at creation, editable afterwards. */
  fields?: PokynZatvaFields | null;
};

export type TrainingUpdatePayload = {
  date?: string | null;
  trainer_id?: number | null;
  topics?: string | null;
  duration_min?: number | null;
  fields?: PokynZatvaFields | null;
};

export type TrainingListFilters = {
  company_id?: number;
  facility_id?: number;
  type?: TrainingType;
};

function buildQuery(filters: TrainingListFilters = {}): string {
  const parts: string[] = [];
  if (filters.company_id) parts.push(`company_id=${filters.company_id}`);
  if (filters.facility_id) parts.push(`facility_id=${filters.facility_id}`);
  if (filters.type) parts.push(`type=${encodeURIComponent(filters.type)}`);
  return parts.length > 0 ? `?${parts.join('&')}` : '';
}

export const Trainings = {
  list: (filters?: TrainingListFilters) =>
    api<{ items: TrainingListItem[] }>(`/api/trainings${buildQuery(filters)}`),
  show: (id: number) => api<TrainingDetail>(`/api/trainings/${id}`),
  create: (body: TrainingPayload, csrfToken: string | null, optimistic?: OptimisticSpec) =>
    api<{ training: Training }>('/api/trainings', { method: 'POST', body, csrfToken, optimistic }),
  update: (id: number, body: TrainingUpdatePayload, csrfToken: string | null) =>
    api<{ training: Training }>(`/api/trainings/${id}`, { method: 'PATCH', body, csrfToken }),
  archive: (id: number, csrfToken: string | null) =>
    api<void>(`/api/trainings/${id}`, { method: 'DELETE', csrfToken }),

  addTrainee: (
    trainingId: number,
    // SIGNATURE DISABLED — restore `signature: Blob` to the payload type when re-enabling on-screen capture
    payload: { fullname: string; position?: string | null },
    csrfToken: string | null,
  ) => {
    const fd = new FormData();
    fd.append('fullname', payload.fullname);
    if (payload.position) fd.append('position', payload.position);
    // SIGNATURE DISABLED — fd.append('signature', payload.signature, 'signature.png');
    return api<{ trainee: Trainee }>(`/api/trainings/${trainingId}/trainees`, {
      method: 'POST',
      body: fd,
      csrfToken,
    });
  },
  deleteTrainee: (trainingId: number, traineeId: number, csrfToken: string | null) =>
    api<void>(`/api/trainings/${trainingId}/trainees/${traineeId}`, {
      method: 'DELETE',
      csrfToken,
    }),

  generatePdf: (trainingId: number, csrfToken: string | null) =>
    api<TrainingGeneratePdfResponse>(`/api/trainings/${trainingId}/generate-pdf`, {
      method: 'POST',
      csrfToken,
      requireOnline: true,
    }),
  documents: (trainingId: number) =>
    api<{ items: TrainingDocument[] }>(`/api/trainings/${trainingId}/documents`),
};

export function trainingDocumentDownloadUrl(documentId: number): string {
  const base = import.meta.env.VITE_API_BASE_URL ?? '';
  return `${base}/api/documents/${documentId}/download`;
}

export function traineeSignatureUrl(traineeId: number, cacheBuster?: string | number): string {
  const qs = cacheBuster !== undefined ? `?t=${encodeURIComponent(String(cacheBuster))}` : '';
  return buildUrl(`/api/trainees/${traineeId}/signature${qs}`);
}
