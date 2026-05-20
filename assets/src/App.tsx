import { useState } from 'react';
import { useVippsPayment } from './hooks/useVippsPayment';
import { PhoneInput } from './components/PhoneInput';
import { QrDisplay } from './components/QrDisplay';
import { ActionButtons } from './components/ActionButtons';
import { StatusMessage } from './components/StatusMessage';
import { LogPanel } from './components/LogPanel';
import type { VippsConfig } from './types';

interface AppProps {
  config: VippsConfig;
}

export function App({ config }: AppProps) {
  const [phone, setPhone] = useState('');

  const { state, qrUrl, error, logEntries, createQr, sendPush, cancel } = useVippsPayment({
    ajaxUrl: config.ajaxUrl,
    orderId: config.orderId,
    token: config.token,
    debug: config.debug,
    phoneFlowMode: config.phoneFlowMode,
  });

  return (
    <>
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
      {config.debug && <LogPanel entries={logEntries} strings={config.strings} />}
    </>
  );
}
