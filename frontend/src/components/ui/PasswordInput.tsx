import { forwardRef, useState } from 'react';
import { Eye, EyeOff, Lock } from 'lucide-react';
import { Input } from './Input';

type PasswordInputProps = Omit<React.InputHTMLAttributes<HTMLInputElement>, 'type'> & {
  invalid?: boolean;
  /**
   * Hides the lock icon on the left when set to false. Default true.
   */
  withIcon?: boolean;
};

/**
 * Password input with an eye toggle for show/hide. Keep the autoComplete
 * value on every usage — Chrome only offers "Suggest strong password"
 * on inputs with autoComplete="new-password" and a sibling username
 * field (autoComplete="username" or "email", visible or hidden).
 *
 * tabIndex=-1 on the toggle keeps the natural focus order (label →
 * input → next field) instead of stopping on the eye in between.
 */
export const PasswordInput = forwardRef<HTMLInputElement, PasswordInputProps>(function PasswordInput(
  { withIcon = true, ...rest },
  ref,
) {
  const [visible, setVisible] = useState(false);
  return (
    <Input
      {...rest}
      ref={ref}
      type={visible ? 'text' : 'password'}
      leftIcon={withIcon ? <Lock className="size-4" /> : undefined}
      rightSlot={
        <button
          type="button"
          onClick={() => setVisible((v) => !v)}
          aria-label={visible ? 'Skryť heslo' : 'Zobraziť heslo'}
          title={visible ? 'Skryť heslo' : 'Zobraziť heslo'}
          tabIndex={-1}
          className="grid size-9 place-items-center rounded-xl text-ink-400 transition-colors hover:bg-ink-100 hover:text-ink-700"
        >
          {visible ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
        </button>
      }
    />
  );
});
