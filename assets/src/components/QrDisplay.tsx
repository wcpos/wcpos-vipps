interface QrDisplayProps {
  qrUrl: string | null;
  strings: Record<string, string>;
}

export function QrDisplay({ qrUrl, strings }: QrDisplayProps) {
  if (!qrUrl) return null;

  return (
    <div className="wcpos-vipps-qr-display">
      <img src={qrUrl} alt={strings.qrAlt ?? 'Vipps QR Code'} />
    </div>
  );
}
