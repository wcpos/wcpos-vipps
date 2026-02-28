import { useState, useEffect } from 'react';
import { useVippsPayment } from './hooks/useVippsPayment';
import { PhoneInput } from './components/PhoneInput';
import { QrDisplay } from './components/QrDisplay';
import { ActionButtons } from './components/ActionButtons';
import { StatusMessage } from './components/StatusMessage';
import type { VippsConfig } from './types';

interface AppProps {
  config: VippsConfig;
}

export function App({ config }: AppProps) {
  const [phone, setPhone] = useState('');

  const { state, qrUrl, error, createQr, sendPush, cancel } = useVippsPayment({
    ajaxUrl: config.ajaxUrl,
    orderId: config.orderId,
    token: config.token,
  });

  // Auto-submit the WC form when payment is authorized.
  useEffect(() => {
    if (state !== 'authorized') return;

    const form = document.querySelector<HTMLFormElement>('form#order_review, form.checkout');
    if (!form) return;

    const button = form.querySelector<HTMLButtonElement>('#place_order, button[type="submit"]');
    if (button) {
      button.click();
    } else {
      form.submit();
    }
  }, [state]);

  return (
    <div id="wcpos-vipps-payment-interface">
      <PhoneInput
        value={phone}
        onChange={setPhone}
        disabled={state === 'creating' || state === 'polling'}
        strings={config.strings}
      />
      <ActionButtons
        state={state}
        phone={phone}
        onGenerateQr={createQr}
        onSendPush={() => sendPush(phone)}
        onCancel={cancel}
        strings={config.strings}
      />
      <QrDisplay qrUrl={qrUrl} strings={config.strings} />
      <StatusMessage state={state} error={error} strings={config.strings} />
    </div>
  );
}
