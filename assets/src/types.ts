export type PaymentState =
  | 'idle'
  | 'creating'
  | 'polling'
  | 'authorized'
  | 'failed'
  | 'cancelled'
  | 'expired';

export type PaymentFlow = 'qr' | 'push' | 'redirect';

export interface VippsConfig {
  ajaxUrl: string;
  orderId: number;
  token: string;
  debug: boolean;
  phoneFlowMode: 'push' | 'redirect';
  strings: Record<string, string>;
}

export interface CreatePaymentResponse {
  reference: string;
  flow: PaymentFlow;
  qrUrl?: string;
  redirectUrl?: string;
  modeChanged?: boolean;
}

export interface CheckStatusResponse {
  state: string;
}

export interface AjaxResponse<T> {
  success: boolean;
  data: T & { message?: string; log_entries?: string[] };
}
