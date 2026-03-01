import type { PaymentState } from '../types';

interface StatusMessageProps {
  state: PaymentState;
  error: string | null;
  strings: Record<string, string>;
}

const stateClassMap: Partial<Record<PaymentState, string>> = {
  creating: 'wcpos-vipps-status-message',
  polling: 'wcpos-vipps-status-message',
  authorized: 'wcpos-vipps-status-success',
  failed: 'wcpos-vipps-status-error',
  cancelled: 'wcpos-vipps-status-cancelled',
  expired: 'wcpos-vipps-status-error',
};

export function StatusMessage({ state, error, strings }: StatusMessageProps) {
  if (state === 'idle') return null;

  const className = stateClassMap[state] ?? '';

  const getMessage = (): string => {
    if (error) return error;
    switch (state) {
      case 'creating': return strings.creatingPayment ?? 'Creating payment...';
      case 'polling': return strings.waitingForPayment ?? 'Waiting for payment...';
      case 'authorized': return strings.paymentSuccess ?? 'Payment successful!';
      case 'cancelled': return strings.paymentCancelled ?? 'Payment cancelled.';
      case 'expired': return strings.paymentExpired ?? 'Payment expired. Please try again.';
      default: return '';
    }
  };

  const message = getMessage();
  if (!message) return null;

  return (
    <div className={`wcpos-vipps-status ${className}`} role="status">
      {message}
    </div>
  );
}
