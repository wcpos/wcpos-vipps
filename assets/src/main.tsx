import { createRoot } from 'react-dom/client';
import { App } from './App';
import type { VippsConfig } from './types';

declare global {
  interface Window {
    wcposVippsData?: VippsConfig;
  }
}

const container = document.getElementById('wcpos-vipps-root');
const config = window.wcposVippsData;

if (container && config) {
  createRoot(container).render(<App config={config} />);
}
