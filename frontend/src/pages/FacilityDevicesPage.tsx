import { useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { ArrowLeft } from "lucide-react";
import { Facilities, type FacilityDeviceGroup } from "@/api/facilities";
import { INSPECTION_TYPE_LABELS } from "@/api/inspections";
import { ApiError } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Spinner } from "@/components/ui/Spinner";

/**
 * Read-only device list of one prevádzka (spec 3.3). The rows are the latest
 * finalized control of each type; nothing here is edited.
 */
export function FacilityDevicesPage() {
  const { id: idStr } = useParams<{ id: string }>();
  const id = Number(idStr);
  const [name, setName] = useState<string | null>(null);
  const [groups, setGroups] = useState<FacilityDeviceGroup[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    Promise.all([Facilities.show(id), Facilities.devices(id)])
      .then(([facility, devices]) => {
        if (cancelled) return;
        setName(facility.facility.name);
        setGroups(devices.groups);
      })
      .catch((err: unknown) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : "Zariadenia sa nepodarilo načítať.");
      });
    return () => {
      cancelled = true;
    };
  }, [id]);

  const total = (groups ?? []).reduce((sum, g) => sum + g.items.length, 0);

  return (
    <div className="flex flex-col gap-4">
      <Link
        to={`/facilities/${id}`}
        className="inline-flex items-center gap-1 self-start text-sm text-ink-500 transition-colors hover:text-ink-700"
      >
        <ArrowLeft className="size-4" />
        {name ?? "Späť"}
      </Link>

      <header>
        <h1 className="text-xl font-semibold tracking-tight text-ink-900">Zariadenia</h1>
        <p className="mt-0.5 text-sm text-ink-500">
          {groups === null ? "Načítavam…" : `${total} zariadení · z posledných kontrol tejto prevádzky`}
        </p>
      </header>

      {error && (
        <p className="rounded-xl bg-[var(--color-status-bad-bg)] px-3 py-2 text-sm text-[var(--color-status-bad)]">{error}</p>
      )}

      {groups === null && !error && (
        <div className="flex justify-center py-10 text-ink-400">
          <Spinner />
        </div>
      )}

      {groups !== null && groups.length === 0 && (
        <p className="rounded-2xl bg-ink-50 px-3 py-3 text-sm leading-relaxed text-ink-600">
          Zatiaľ tu nie je nič. Zariadenia pribudnú, keď na tejto prevádzke urobíte prvú kontrolu daného typu — alebo ich nahráte importom z Excelu.
        </p>
      )}

      {groups?.map((group) => (
        <section key={group.type} className="flex flex-col gap-2">
          <h2 className="text-xs font-semibold uppercase tracking-wider text-ink-400">
            {INSPECTION_TYPE_LABELS[group.type]} · {group.items.length}
          </h2>
          <ul className="flex flex-col gap-2">
            {group.items.map((item) => (
              <li key={item.source_item_id}>
                <Card className="px-4 py-3">
                  <p className="text-sm font-semibold text-ink-900">
                    {item.label}
                    {item.description && (
                      <span className="font-normal text-ink-600"> · {item.description}</span>
                    )}
                  </p>
                  {(item.location || (item.serial && item.serial !== item.label)) && (
                    <p className="mt-0.5 text-xs text-ink-500">
                      {[item.location, item.serial && item.serial !== item.label ? `v. č. ${item.serial}` : null]
                        .filter(Boolean)
                        .join(" · ")}
                    </p>
                  )}
                </Card>
              </li>
            ))}
          </ul>
        </section>
      ))}

      <p className="rounded-2xl bg-ink-50 px-3 py-2.5 text-xs leading-relaxed text-ink-600">
        Zoznam sa needituje. Opraviť označenie alebo umiestnenie prístroja sa dá pri kontrole, kde je zapísaný — tam, kde to aj tak musíte prejsť.
      </p>
    </div>
  );
}
