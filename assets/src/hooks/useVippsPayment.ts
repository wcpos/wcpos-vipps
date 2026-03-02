import { useState, useRef, useCallback, useEffect } from 'react';
import { createPayment, checkStatus, cancelPayment as cancelPaymentApi } from '../api';
import type { PaymentState, AjaxResponse } from '../types';

const POLL_INTERVAL = 2000;
const MAX_POLLS = 150;

interface UseVippsPaymentOptions {
  ajaxUrl: string;
  orderId: number;
  token: string;
  debug: boolean;
  phoneFlowMode: 'push' | 'redirect';
}

interface UseVippsPaymentResult {
  state: PaymentState;
  qrUrl: string | null;
  error: string | null;
  logEntries: string[];
  phoneFlowMode: 'push' | 'redirect';
  createQr: () => void;
  sendPush: (phone: string) => void;
  cancel: () => void;
}

function timestamp(): string {
  return new Date().toLocaleTimeString('en-GB', { hour12: false });
}

export function useVippsPayment({ ajaxUrl, orderId, token, debug, phoneFlowMode }: UseVippsPaymentOptions): UseVippsPaymentResult {
  const [state, setState] = useState<PaymentState>('idle');
  const [qrUrl, setQrUrl] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [logEntries, setLogEntries] = useState<string[]>([]);

  const pollingRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const pollCountRef = useRef(0);
  const pollInFlightRef = useRef(false);
  const cancelledRef = useRef(false);
  const [currentFlowMode, setCurrentFlowMode] = useState<'push' | 'redirect'>(phoneFlowMode);
  const redirectTabRef = useRef<Window | null>(null);

  const appendLog = useCallback((message: string) => {
    if (!debug) return;
    setLogEntries((prev) => [...prev, `${timestamp()} ${message}`]);
  }, [debug]);

  const collectServerLogs = useCallback((response: AjaxResponse<unknown>) => {
    if (!debug) return;
    const entries = response.data?.log_entries;
    if (entries?.length) {
      setLogEntries((prev) => [...prev, ...entries]);
    }
  }, [debug]);

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
        appendLog('[CLIENT] Polling timed out after 5 minutes');
        return;
      }

      try {
        const response = await checkStatus(ajaxUrl, orderId, token);
        collectServerLogs(response);

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
          appendLog('[CLIENT] Payment authorized — submitting order');
        } else if (vippsState === 'ABORTED' || vippsState === 'TERMINATED') {
          stopPolling();
          setState('cancelled');
          setQrUrl(null);
          appendLog(`[CLIENT] Payment ${vippsState.toLowerCase()} by customer`);
        } else if (vippsState === 'EXPIRED') {
          stopPolling();
          setState('expired');
          setQrUrl(null);
          setError('Payment expired. Please try again.');
          appendLog('[CLIENT] Payment expired');
        }
        // CREATED = still waiting, continue polling.
      } catch {
        appendLog('[CLIENT] Network error during status check');
      } finally {
        pollInFlightRef.current = false;
      }
    }, POLL_INTERVAL);
  }, [ajaxUrl, orderId, token, stopPolling, appendLog, collectServerLogs]);

  const handleCreate = useCallback(async (flow: 'qr' | 'push', phone?: string) => {
    setState('creating');
    setError(null);
    setQrUrl(null);
    cancelledRef.current = false;

    // Pre-open a blank tab if we know we need redirect mode.
    let tab: Window | null = null;
    if (flow === 'push' && currentFlowMode === 'redirect') {
      tab = window.open('about:blank', '_blank');
      redirectTabRef.current = tab;
      if (!tab) {
        setState('failed');
        setError('Popup blocked. Please allow popups and try again.');
        appendLog('[CLIENT] Popup blocked — cannot open redirect tab');
        return;
      }
    }

    appendLog(`[CLIENT] ${flow === 'qr' ? 'Generating QR code' : `Sending push to ${phone}`}...`);

    try {
      const response = await createPayment(ajaxUrl, orderId, token, flow, phone);
      collectServerLogs(response);

      if (cancelledRef.current) {
        tab?.close();
        return;
      }

      if (response.success) {
        // Handle modeChanged: backend detected PUSH_MESSAGE is unsupported.
        if (response.data.modeChanged) {
          setCurrentFlowMode('redirect');
          tab?.close();
          setState('failed');
          setError('Direct push not available for your account. Click Send to Phone again.');
          appendLog('[CLIENT] PUSH_MESSAGE not supported — switching to redirect mode');
          return;
        }

        // Handle redirect flow: set the pre-opened tab URL.
        if (response.data.flow === 'redirect') {
          if (!response.data.redirectUrl) {
            tab?.close();
            setState('failed');
            setError('Could not open Vipps page. Please try again.');
            appendLog('[CLIENT] Redirect flow returned without redirect URL');
            return;
          }
          if (!tab) {
            setState('failed');
            setError('Popup blocked. Please allow popups and try again.');
            appendLog('[CLIENT] Popup blocked — cannot open redirect tab');
            return;
          }
          tab.location.href = response.data.redirectUrl;
          appendLog('[CLIENT] Opened Vipps landing page in new tab');
        }

        if (flow === 'qr' && response.data.qrUrl) {
          setQrUrl(response.data.qrUrl);
          appendLog('[CLIENT] QR code displayed');
        }

        setState('polling');
        appendLog('[CLIENT] Polling started (every 2s, max 5 min)');
        startPolling();
      } else {
        tab?.close();
        setState('failed');
        setError(response.data.message ?? 'Payment failed. Please try again.');
        appendLog(`[CLIENT] Payment creation failed: ${response.data.message ?? 'unknown error'}`);
      }
    } catch {
      tab?.close();
      if (cancelledRef.current) return;
      setState('failed');
      setError('Network error. Please check your connection.');
      appendLog('[CLIENT] Network error during payment creation');
    }
  }, [ajaxUrl, orderId, token, currentFlowMode, startPolling, appendLog, collectServerLogs]);

  const createQr = useCallback(() => handleCreate('qr'), [handleCreate]);

  const sendPush = useCallback((phone: string) => {
    if (!phone.trim()) {
      setError('Please enter a phone number.');
      appendLog('[CLIENT] Push failed — no phone number');
      return;
    }
    handleCreate('push', phone);
  }, [handleCreate, appendLog]);

  const cancel = useCallback(async () => {
    cancelledRef.current = true;
    stopPolling();
    setState('cancelled');
    setQrUrl(null);
    appendLog('[CLIENT] Cancelling payment...');

    try {
      await cancelPaymentApi(ajaxUrl, orderId, token);
    } catch {
      // Best-effort cancellation.
    }
  }, [ajaxUrl, orderId, token, stopPolling, appendLog]);

  return { state, qrUrl, error, logEntries, phoneFlowMode: currentFlowMode, createQr, sendPush, cancel };
}
