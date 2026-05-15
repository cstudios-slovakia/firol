import { useState, type FormEvent } from 'react';
import { Mail, Send } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { Documents } from '@/api/documents';
import { ApiError } from '@/lib/api';
import { useToast } from '@/lib/toast';
import { Input } from '@/components/ui/Input';
import { Button } from '@/components/ui/Button';

/**
 * Compact inline form for emailing a generated PDF protocol to an
 * arbitrary recipient. Rendered as the footer of each document row on
 * the inspection / training detail pages.
 */
export function EmailDocumentForm({
  documentId,
  documentNumber,
}: {
  documentId: number;
  documentNumber: string;
}) {
  const { csrfToken } = useAuth();
  const toast = useToast();
  const [email, setEmail] = useState('');
  const [note, setNote] = useState('');
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    if (sending) return;
    const trimmed = email.trim();
    if (!trimmed) {
      setError('Zadaj e-mailovú adresu.');
      return;
    }
    setError(null);
    setSending(true);
    try {
      await Documents.email(
        documentId,
        { email: trimmed, note: note.trim() || null },
        csrfToken,
      );
      toast.success(`Protokol ${documentNumber} odoslaný na ${trimmed}.`);
      setEmail('');
      setNote('');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Odoslanie e-mailu zlyhalo.');
    } finally {
      setSending(false);
    }
  }

  return (
    <form
      onSubmit={handleSubmit}
      className="flex flex-col gap-2 border-t border-ink-100 bg-ink-50/40 px-4 py-3"
    >
      <div className="flex items-center gap-2 text-xs font-medium text-ink-600">
        <Mail className="size-3.5" />
        Odoslať protokol e-mailom
      </div>
      <div className="flex flex-col gap-2 sm:flex-row">
        <Input
          type="email"
          inputMode="email"
          autoComplete="email"
          placeholder="napr. zakaznik@firma.sk"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          disabled={sending}
          invalid={!!error}
          className="sm:flex-1"
        />
        <Button
          type="submit"
          variant="primary"
          loading={sending}
          disabled={!email.trim()}
          leftIcon={<Send className="size-4" />}
        >
          Odoslať
        </Button>
      </div>
      <Input
        type="text"
        placeholder="Voliteľná poznámka pre príjemcu"
        value={note}
        onChange={(e) => setNote(e.target.value)}
        disabled={sending}
      />
      {error && <p className="text-xs text-status-bad">{error}</p>}
    </form>
  );
}
