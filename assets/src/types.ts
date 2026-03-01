export type PaymentState =
  | 'idle'
  | 'creating'
  | 'polling'
  | 'authorized'
  | 'failed'
  | 'cancelled'
  | 'expired';

export type PaymentFlow = 'qr' | 'push';

export interface VippsConfig {
  ajaxUrl: string;
  orderId: number;
  token: string;
  debug: boolean;
  strings: Record<string, string>;
}

export interface CreatePaymentResponse {
  reference: string;
  flow: PaymentFlow;
  qrUrl?: string;
}

export interface CheckStatusResponse {
  state: string;
}

export interface AjaxResponse<T> {
  success: boolean;
  data: T & { message?: string; log_entries?: string[] };
}
