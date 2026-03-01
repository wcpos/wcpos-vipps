import { useState, useRef, useEffect } from 'react';

interface LogPanelProps {
  entries: string[];
  strings: Record<string, string>;
}

export function LogPanel({ entries, strings }: LogPanelProps) {
  const [open, setOpen] = useState(false);
  const textareaRef = useRef<HTMLTextAreaElement>(null);

  useEffect(() => {
    if (textareaRef.current) {
      textareaRef.current.scrollTop = textareaRef.current.scrollHeight;
    }
  }, [entries]);

  return (
    <div className="wcpos-vipps-log-section">
      <button
        type="button"
        className={`wcpos-vipps-log-toggle${open ? ' open' : ''}`}
        onClick={() => setOpen((v) => !v)}
      >
        <span className="chevron">&#9654;</span>
        <span className="label">
          {open
            ? (strings.hideLog ?? 'Hide Log')
            : (strings.showLog ?? 'Show Log')}
        </span>
      </button>
      <div className={`wcpos-vipps-log-container${open ? ' open' : ''}`}>
        <textarea
          ref={textareaRef}
          readOnly
          value={entries.join('\n')}
        />
      </div>
    </div>
  );
}
