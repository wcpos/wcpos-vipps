import { useState, useRef, useCallback, useEffect } from 'react';
import { createPayment, checkStatus, cancelPayment as cancelPaymentApi } from '../api';
import type { PaymentState } from '../types';

const POLL_INTERVAL = 2000;
const MAX_POLLS = 150;

interface UseVippsPaymentOptions {
  ajaxUrl: string;
  orderId: number;
  token: string;
}

interface UseVippsPaymentResult {
  state: PaymentState;
  qrUrl: string | null;
  error: string | null;
  createQr: () => void;
  sendPush: (phone: string) => void;
  cancel: () => void;
}

export function useVippsPayment({ ajaxUrl, orderId, token }: UseVippsPaymentOptions): UseVippsPaymentResult {
  const [state, setState] = useState<PaymentState>('idle');
  const [qrUrl, setQrUrl] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const pollingRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const pollCountRef = useRef(0);
  const pollInFlightRef = useRef(false);
  const cancelledRef = useRef(false);

  const stopPolling = useCallback(() => {
    if (pollingRef.current) {
      clearInterval(pollingRef.current);
      pollingRef.current = null;
    }
    pollCountRef.current = 0;
    pollInFlightRef.current = false;
  }, []);

  // Clean up on unmount.
  useEffect(() => {
    return () => stopPolling();
  }, [stopPolling]);

  const startPolling = useCallback(() => {
    stopPolling();
    pollCountRef.current = 0;

    pollingRef.current = setInterval(async () => {
      if (pollInFlightRef.current) return;
      pollInFlightRef.current = true;
      pollCountRef.current++;

      if (pollCountRef.current >= MAX_POLLS) {
        stopPolling();
        setState('expired');
        setQrUrl(null);
        setError('Payment expired. Please try again.');
        return;
      }

      try {
        const response = await checkStatus(ajaxUrl, orderId, token);

        if (!response.success) {
          stopPolling();
          setState('failed');
          setQrUrl(null);
          setError(response.data.message ?? 'Unable to verify payment status. Please try again.');
          return;
        }

        const vippsState = response.data.state;

        if (vippsState === 'AUTHORIZED') {
          stopPolling();
          setState('authorized');
        } else if (vippsState === 'ABORTED' || vippsState === 'TERMINATED') {
          stopPolling();
          setState('cancelled');
          setQrUrl(null);
        } else if (vippsState === 'EXPIRED') {
          stopPolling();
          setState('expired');
          setQrUrl(null);
          setError('Payment expired. Please try again.');
        }
        // CREATED = still waiting, continue polling.
      } catch {
        // Silently continue polling on network hiccups.
      } finally {
        pollInFlightRef.current = false;
      }
    }, POLL_INTERVAL);
  }, [ajaxUrl, orderId, token, stopPolling]);

  const handleCreate = useCallback(async (flow: 'qr' | 'push', phone?: string) => {
    setState('creating');
    setError(null);
    setQrUrl(null);
    cancelledRef.current = false;

    try {
      const response = await createPayment(ajaxUrl, orderId, token, flow, phone);

      // Guard: if cancel() was called while createPayment was in flight, discard the response.
      if (cancelledRef.current) return;

      if (response.success) {
        if (flow === 'qr' && response.data.qrUrl) {
          setQrUrl(response.data.qrUrl);
        }
        setState('polling');
        startPolling();
      } else {
        setState('failed');
        setError(response.data.message ?? 'Payment failed. Please try again.');
      }
    } catch {
      if (cancelledRef.current) return;
      setState('failed');
      setError('Network error. Please check your connection.');
    }
  }, [ajaxUrl, orderId, token, startPolling]);

  const createQr = useCallback(() => handleCreate('qr'), [handleCreate]);

  const sendPush = useCallback((phone: string) => {
    if (!phone.trim()) {
      setError('Please enter a phone number.');
      return;
    }
    handleCreate('push', phone);
  }, [handleCreate]);

  const cancel = useCallback(async () => {
    cancelledRef.current = true;
    stopPolling();
    setState('cancelled');
    setQrUrl(null);

    try {
      await cancelPaymentApi(ajaxUrl, orderId, token);
    } catch {
      // Best-effort cancellation.
    }
  }, [ajaxUrl, orderId, token, stopPolling]);

  return { state, qrUrl, error, createQr, sendPush, cancel };
}
