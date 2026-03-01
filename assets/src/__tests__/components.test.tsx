import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import { PhoneInput } from '../components/PhoneInput';
import { QrDisplay } from '../components/QrDisplay';
import { ActionButtons } from '../components/ActionButtons';
import { StatusMessage } from '../components/StatusMessage';
import { LogPanel } from '../components/LogPanel';

const strings: Record<string, string> = {
  phoneLabel: 'Phone number (optional)',
  phonePlaceholder: 'e.g. 4712345678',
  qrAlt: 'Vipps QR Code',
  generateQr: 'Generate QR Code',
  sendToPhone: 'Send to Phone',
  cancelPayment: 'Cancel Payment',
  waitingForPayment: 'Waiting for payment...',
  paymentSuccess: 'Payment successful!',
  paymentCancelled: 'Payment cancelled.',
  showLog: 'Show Log',
  hideLog: 'Hide Log',
};

describe('PhoneInput', () => {
  it('renders input and label', () => {
    render(<PhoneInput value="" onChange={() => {}} disabled={false} strings={strings} />);
    expect(screen.getByLabelText('Phone number (optional)')).toBeInTheDocument();
  });

  it('calls onChange when typing', () => {
    const onChange = vi.fn();
    render(<PhoneInput value="" onChange={onChange} disabled={false} strings={strings} />);
    fireEvent.change(screen.getByRole('textbox'), { target: { value: '4712345678' } });
    expect(onChange).toHaveBeenCalledWith('4712345678');
  });

  it('disables input when disabled prop is true', () => {
    render(<PhoneInput value="" onChange={() => {}} disabled={true} strings={strings} />);
    expect(screen.getByRole('textbox')).toBeDisabled();
  });
});

describe('QrDisplay', () => {
  it('renders nothing when qrUrl is null', () => {
    const { container } = render(<QrDisplay qrUrl={null} strings={strings} />);
    expect(container.firstChild).toBeNull();
  });

  it('renders QR image when qrUrl is provided', () => {
    render(<QrDisplay qrUrl="https://qr.vipps.no/test.png" strings={strings} />);
    const img = screen.getByAltText('Vipps QR Code');
    expect(img).toBeInTheDocument();
    expect(img).toHaveAttribute('src', 'https://qr.vipps.no/test.png');
  });
});

describe('ActionButtons', () => {
  it('renders both action buttons', () => {
    render(
      <ActionButtons state="idle" phone="" onGenerateQr={() => {}} onSendPush={() => {}} onCancel={() => {}} strings={strings} />
    );
    expect(screen.getByText('Generate QR Code')).toBeInTheDocument();
    expect(screen.getByText('Send to Phone')).toBeInTheDocument();
  });

  it('disables Send to Phone when phone is empty', () => {
    render(
      <ActionButtons state="idle" phone="" onGenerateQr={() => {}} onSendPush={() => {}} onCancel={() => {}} strings={strings} />
    );
    expect(screen.getByText('Send to Phone')).toBeDisabled();
  });

  it('enables Send to Phone when phone has value', () => {
    render(
      <ActionButtons state="idle" phone="4712345678" onGenerateQr={() => {}} onSendPush={() => {}} onCancel={() => {}} strings={strings} />
    );
    expect(screen.getByText('Send to Phone')).toBeEnabled();
  });

  it('disables buttons during polling', () => {
    render(
      <ActionButtons state="polling" phone="4712345678" onGenerateQr={() => {}} onSendPush={() => {}} onCancel={() => {}} strings={strings} />
    );
    expect(screen.getByText('Generate QR Code')).toBeDisabled();
    expect(screen.getByText('Send to Phone')).toBeDisabled();
  });

  it('shows cancel button during polling', () => {
    render(
      <ActionButtons state="polling" phone="" onGenerateQr={() => {}} onSendPush={() => {}} onCancel={() => {}} strings={strings} />
    );
    expect(screen.getByText('Cancel Payment')).toBeInTheDocument();
  });

  it('hides cancel button when idle', () => {
    render(
      <ActionButtons state="idle" phone="" onGenerateQr={() => {}} onSendPush={() => {}} onCancel={() => {}} strings={strings} />
    );
    expect(screen.queryByText('Cancel Payment')).not.toBeInTheDocument();
  });

  it('calls onGenerateQr when clicked', () => {
    const onGenerateQr = vi.fn();
    render(
      <ActionButtons state="idle" phone="" onGenerateQr={onGenerateQr} onSendPush={() => {}} onCancel={() => {}} strings={strings} />
    );
    fireEvent.click(screen.getByText('Generate QR Code'));
    expect(onGenerateQr).toHaveBeenCalledOnce();
  });
});

describe('StatusMessage', () => {
  it('renders nothing when state is idle', () => {
    const { container } = render(<StatusMessage state="idle" error={null} strings={strings} />);
    expect(container.firstChild).toBeNull();
  });

  it('shows success message when authorized', () => {
    render(<StatusMessage state="authorized" error={null} strings={strings} />);
    expect(screen.getByText('Payment successful!')).toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveClass('wcpos-vipps-status-success');
  });

  it('shows waiting message when polling', () => {
    render(<StatusMessage state="polling" error={null} strings={strings} />);
    expect(screen.getByText('Waiting for payment...')).toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveClass('wcpos-vipps-status-message');
  });

  it('shows cancelled message', () => {
    render(<StatusMessage state="cancelled" error={null} strings={strings} />);
    expect(screen.getByText('Payment cancelled.')).toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveClass('wcpos-vipps-status-cancelled');
  });

  it('shows error message when error is provided', () => {
    render(<StatusMessage state="failed" error="Something went wrong" strings={strings} />);
    expect(screen.getByText('Something went wrong')).toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveClass('wcpos-vipps-status-error');
  });
});

describe('LogPanel', () => {
  it('renders Show Log button', () => {
    render(<LogPanel entries={[]} strings={strings} />);
    expect(screen.getByText('Show Log')).toBeInTheDocument();
  });

  it('starts with log container collapsed', () => {
    render(<LogPanel entries={[]} strings={strings} />);
    const container = document.querySelector('.wcpos-vipps-log-container');
    expect(container).not.toHaveClass('open');
  });

  it('opens log container and changes text on click', () => {
    render(<LogPanel entries={['line 1']} strings={strings} />);

    fireEvent.click(screen.getByText('Show Log'));

    expect(screen.getByText('Hide Log')).toBeInTheDocument();
    expect(screen.queryByText('Show Log')).not.toBeInTheDocument();
    expect(document.querySelector('.wcpos-vipps-log-container')).toHaveClass('open');
    expect(document.querySelector('.wcpos-vipps-log-toggle')).toHaveClass('open');
  });

  it('closes log container on second click', () => {
    render(<LogPanel entries={['line 1']} strings={strings} />);

    fireEvent.click(screen.getByText('Show Log'));
    fireEvent.click(screen.getByText('Hide Log'));

    expect(screen.getByText('Show Log')).toBeInTheDocument();
    expect(document.querySelector('.wcpos-vipps-log-container')).not.toHaveClass('open');
  });

  it('displays log entries in textarea', () => {
    render(<LogPanel entries={['entry one', 'entry two']} strings={strings} />);
    const textarea = document.querySelector('textarea');
    expect(textarea).toHaveValue('entry one\nentry two');
  });

  it('textarea is readonly', () => {
    render(<LogPanel entries={['test']} strings={strings} />);
    const textarea = document.querySelector('textarea');
    expect(textarea).toHaveAttribute('readonly');
  });
});
