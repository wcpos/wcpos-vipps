interface PhoneInputProps {
  value: string;
  onChange: (value: string) => void;
  disabled: boolean;
  strings: Record<string, string>;
}

export function PhoneInput({ value, onChange, disabled, strings }: PhoneInputProps) {
  return (
    <div className="wcpos-vipps-phone-section">
      <label htmlFor="wcpos-vipps-phone">
        {strings.phoneLabel ?? 'Phone number (optional)'}
      </label>
      <input
        type="tel"
        id="wcpos-vipps-phone"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        disabled={disabled}
        placeholder={strings.phonePlaceholder ?? 'e.g. 4712345678'}
      />
    </div>
  );
}
