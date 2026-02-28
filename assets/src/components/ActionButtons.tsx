import type { PaymentState } from '../types';

interface ActionButtonsProps {
  state: PaymentState;
  phone: string;
  onGenerateQr: () => void;
  onSendPush: () => void;
  onCancel: () => void;
  strings: Record<string, string>;
}

export function ActionButtons({ state, phone, onGenerateQr, onSendPush, onCancel, strings }: ActionButtonsProps) {
  const loading = state === 'creating' || state === 'polling';

  return (
    <>
      <div className="wcpos-vipps-actions">
        <button
          type="button"
          className="button wcpos-vipps-btn-primary"
          onClick={onGenerateQr}
          disabled={loading}
        >
          {strings.generateQr ?? 'Generate QR Code'}
        </button>
        <button
          type="button"
          className="button wcpos-vipps-btn-secondary"
          onClick={onSendPush}
          disabled={loading || !phone.trim()}
        >
          {strings.sendToPhone ?? 'Send to Phone'}
        </button>
      </div>
      {loading && (
        <button
          type="button"
          className="button wcpos-vipps-btn-danger"
          onClick={onCancel}
        >
          {strings.cancelPayment ?? 'Cancel Payment'}
        </button>
      )}
    </>
  );
}
