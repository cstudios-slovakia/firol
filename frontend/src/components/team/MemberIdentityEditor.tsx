import { useEffect, useState } from 'react';
import { Check } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { Team, type TeamMember } from '@/api/team';
import { ApiError } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { cn } from '@/lib/cn';
import { Dialog } from '@/components/ui/Dialog';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { Button } from '@/components/ui/Button';
import { TechnicianAvatar } from '@/components/team/TechnicianAvatar';

/**
 * A member's avatar in Nastavenia → Technici, and the dialog to change it
 * (chapter 11.5). Initials may be changed by the main user for anyone and by
 * a member for themselves; the colour only by the main user, from the fixed
 * palette, never one another member already has. Texts from
 * texty_ui.json → tim.
 */
export function MemberIdentityEditor({
  member,
  members,
  isMain,
  isSelf,
  onSaved,
}: {
  member: TeamMember;
  /** The whole team — colours taken by the others are not offered. */
  members: TeamMember[];
  isMain: boolean;
  isSelf: boolean;
  onSaved: () => void;
}) {
  const { csrfToken, refresh } = useAuth();
  const toast = useToast();
  const [open, setOpen] = useState(false);
  const [initials, setInitials] = useState(member.initials);
  const [color, setColor] = useState(member.avatar_color);
  const [palette, setPalette] = useState<string[]>([]);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const canEditInitials = isMain || isSelf;
  const canEditColor = isMain;

  useEffect(() => {
    if (!open) return;
    setInitials(member.initials);
    setColor(member.avatar_color);
    setError(null);
    if (canEditColor && palette.length === 0) {
      Team.avatarPalette()
        .then((r) => setPalette(r.palette))
        .catch(() => undefined);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const taken = new Set(
    members.filter((m) => m.id !== member.id).map((m) => m.avatar_color.toUpperCase()),
  );

  const avatar = (
    <TechnicianAvatar
      size="lg"
      technician={{ fullname: member.fullname, initials: member.initials, avatar_color: member.avatar_color }}
    />
  );

  if (!canEditInitials) {
    return avatar;
  }

  async function save(e: React.FormEvent) {
    e.preventDefault();
    const body: { initials?: string; avatar_color?: string } = {};
    if (initials.trim() !== member.initials) body.initials = initials.trim();
    if (canEditColor && color !== member.avatar_color) body.avatar_color = color;
    if (Object.keys(body).length === 0) {
      setOpen(false);
      return;
    }
    setSaving(true);
    setError(null);
    try {
      await Team.setIdentity(member.id, body, csrfToken);
      // /api/me carries the roster the calendar draws avatars from.
      await refresh().catch(() => undefined);
      toast.success('Uložené');
      setOpen(false);
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Uloženie zlyhalo.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        title="Iniciály a farba technika"
        className="shrink-0 rounded-full transition-transform duration-150 hover:scale-105 active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300 focus-visible:ring-offset-2"
      >
        <span className="pointer-events-none">{avatar}</span>
      </button>

      <Dialog
        open={open}
        onClose={() => setOpen(false)}
        title={member.fullname}
        dismissible={!saving}
      >
        <form onSubmit={save} className="flex flex-col gap-4" noValidate>
          <div className="flex justify-center">
            <TechnicianAvatar
              size="lg"
              technician={{
                fullname: member.fullname,
                initials: initials.trim() || member.initials,
                avatar_color: color,
              }}
            />
          </div>

          <Field label="Iniciály" hint="Iniciály sa odvodia z mena, dajú sa zmeniť.">
            {(p) => (
              <Input
                {...p}
                value={initials}
                maxLength={3}
                onChange={(e) => setInitials(e.target.value.replace(/\s/g, ''))}
              />
            )}
          </Field>

          {canEditColor && (
            <div className="flex flex-col gap-1.5">
              <span className="text-xs font-semibold uppercase tracking-wide text-ink-500">
                Farba technika
              </span>
              <div className="flex flex-wrap gap-2">
                {palette.map((c) => {
                  const selected = c.toUpperCase() === color.toUpperCase();
                  const disabled = taken.has(c.toUpperCase());
                  return (
                    <button
                      key={c}
                      type="button"
                      disabled={disabled}
                      aria-pressed={selected}
                      aria-label={c}
                      onClick={() => setColor(c)}
                      style={{ backgroundColor: c }}
                      className={cn(
                        'grid size-8 place-items-center rounded-full text-white transition-transform duration-150',
                        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-firol-300 focus-visible:ring-offset-2',
                        selected && 'ring-2 ring-ink-900 ring-offset-2',
                        disabled ? 'cursor-not-allowed opacity-20' : 'hover:scale-110 active:scale-95',
                      )}
                    >
                      {selected && <Check className="size-4" />}
                    </button>
                  );
                })}
              </div>
            </div>
          )}

          {error && <p className="text-xs text-status-bad">{error}</p>}

          <div className="flex justify-end gap-2">
            <Button type="button" variant="secondary" onClick={() => setOpen(false)}>
              Zrušiť
            </Button>
            <Button type="submit" loading={saving}>
              Uložiť
            </Button>
          </div>
        </form>
      </Dialog>
    </>
  );
}
