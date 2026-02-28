import { renderHook, act } from '@testing-library/react';
import { useVippsPayment } from '../hooks/useVippsPayment';
import * as api from '../api';
import { vi, describe, it, expect, beforeEach, afterEach } from 'vitest';

vi.mock('../api');

const mockConfig = {
  ajaxUrl: 'http://example.com/wp-admin/admin-ajax.php',
  orderId: 42,
  token: 'test-token',
};

describe('useVippsPayment', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.clearAllMocks();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('starts in idle state', () => {
    const { result } = renderHook(() => useVippsPayment(mockConfig));
    expect(result.current.state).toBe('idle');
    expect(result.current.qrUrl).toBeNull();
    expect(result.current.error).toBeNull();
  });

  it('transitions to creating then polling on QR success', async () => {
    vi.mocked(api.createPayment).mockResolvedValue({
      success: true,
      data: { reference: 'ref-1', flow: 'qr', qrUrl: 'https://qr.vipps.no/img.png' },
    });

    const { result } = renderHook(() => useVippsPayment(mockConfig));

    await act(async () => {
      result.current.createQr();
    });

    expect(result.current.state).toBe('polling');
    expect(result.current.qrUrl).toBe('https://qr.vipps.no/img.png');
  });

  it('transitions to failed on API error', async () => {
    vi.mocked(api.createPayment).mockResolvedValue({
      success: false,
      data: { reference: '', flow: 'qr', message: 'Something broke' },
    });

    const { result } = renderHook(() => useVippsPayment(mockConfig));

    await act(async () => {
      result.current.createQr();
    });

    expect(result.current.state).toBe('failed');
    expect(result.current.error).toBe('Something broke');
  });

  it('transitions to failed on network error', async () => {
    vi.mocked(api.createPayment).mockRejectedValue(new Error('Network failure'));

    const { result } = renderHook(() => useVippsPayment(mockConfig));

    await act(async () => {
      result.current.createQr();
    });

    expect(result.current.state).toBe('failed');
    expect(result.current.error).toContain('Network error');
  });

  it('sets error when sendPush called with empty phone', async () => {
    const { result } = renderHook(() => useVippsPayment(mockConfig));

    await act(async () => {
      result.current.sendPush('');
    });

    expect(result.current.state).toBe('idle');
    expect(result.current.error).toContain('phone number');
  });

  it('polls and transitions to authorized', async () => {
    vi.mocked(api.createPayment).mockResolvedValue({
      success: true,
      data: { reference: 'ref-2', flow: 'push' },
    });

    vi.mocked(api.checkStatus).mockResolvedValue({
      success: true,
      data: { state: 'AUTHORIZED' },
    });

    const { result } = renderHook(() => useVippsPayment(mockConfig));

    await act(async () => {
      result.current.sendPush('4712345678');
    });

    expect(result.current.state).toBe('polling');

    await act(async () => {
      await vi.advanceTimersToNextTimerAsync();
    });

    expect(result.current.state).toBe('authorized');
  });

  it('transitions to cancelled on ABORTED', async () => {
    vi.mocked(api.createPayment).mockResolvedValue({
      success: true,
      data: { reference: 'ref-3', flow: 'qr', qrUrl: 'https://qr.vipps.no/x.png' },
    });

    vi.mocked(api.checkStatus).mockResolvedValue({
      success: true,
      data: { state: 'ABORTED' },
    });

    const { result } = renderHook(() => useVippsPayment(mockConfig));

    await act(async () => {
      result.current.createQr();
    });

    await act(async () => {
      await vi.advanceTimersToNextTimerAsync();
    });

    expect(result.current.state).toBe('cancelled');
  });

  it('cancels payment and stops polling', async () => {
    vi.mocked(api.createPayment).mockResolvedValue({
      success: true,
      data: { reference: 'ref-4', flow: 'qr', qrUrl: 'https://qr.vipps.no/y.png' },
    });

    vi.mocked(api.cancelPayment).mockResolvedValue({
      success: true,
      data: { message: 'Cancelled' },
    });

    const { result } = renderHook(() => useVippsPayment(mockConfig));

    await act(async () => {
      result.current.createQr();
    });

    await act(async () => {
      result.current.cancel();
    });

    expect(result.current.state).toBe('cancelled');
    expect(api.cancelPayment).toHaveBeenCalledWith(mockConfig.ajaxUrl, mockConfig.orderId, mockConfig.token);
  });
});
