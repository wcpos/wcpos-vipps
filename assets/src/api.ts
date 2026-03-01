import type { AjaxResponse, CreatePaymentResponse, CheckStatusResponse, PaymentFlow } from './types';

async function post<T>(url: string, body: Record<string, string>): Promise<AjaxResponse<T>> {
  const formData = new URLSearchParams(body);
  const response = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: formData,
  });
  if (!response.ok) {
    return { success: false, data: { message: `HTTP error ${response.status}` } as T & { message: string } };
  }
  return response.json();
}

export function createPayment(
  ajaxUrl: string,
  orderId: number,
  token: string,
  flow: PaymentFlow,
  phone?: string,
): Promise<AjaxResponse<CreatePaymentResponse>> {
  return post(ajaxUrl, {
    action: 'wcpos_vipps_create_payment',
    order_id: String(orderId),
    token,
    flow,
    phone: phone ?? '',
  });
}

export function checkStatus(
  ajaxUrl: string,
  orderId: number,
  token: string,
): Promise<AjaxResponse<CheckStatusResponse>> {
  return post(ajaxUrl, {
    action: 'wcpos_vipps_check_status',
    order_id: String(orderId),
    token,
  });
}

export function cancelPayment(
  ajaxUrl: string,
  orderId: number,
  token: string,
): Promise<AjaxResponse<{ message: string }>> {
  return post(ajaxUrl, {
    action: 'wcpos_vipps_cancel_payment',
    order_id: String(orderId),
    token,
  });
}
