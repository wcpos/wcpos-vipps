import { renderHook, act } from '@testing-library/react';
import { useVippsPayment } from '../hooks/useVippsPayment';
import * as api from '../api';
import { vi, describe, it, expect, beforeEach, afterEach } from 'vitest';

vi.mock('../api');

const mockConfig = {
  ajaxUrl: 'http://example.com/wp-admin/admin-ajax.php',
  orderId: 42,
  token: 'test-token',
  debug: true,
  phoneFlowMode: 'push' as const,
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

  it('transitions to failed when status check returns success false', async () => {
    vi.mocked(api.createPayment).mockResolvedValue({
      success: true,
      data: { reference: 'ref-5', flow: 'qr', qrUrl: 'https://qr.vipps.no/z.png' },
    });

    vi.mocked(api.checkStatus).mockResolvedValue({
      success: false,
      data: { state: '', message: 'Status check failed' },
    });

    const { result } = renderHook(() => useVippsPayment(mockConfig));

    await act(async () => {
      result.current.createQr();
    });

    await act(async () => {
      await vi.advanceTimersToNextTimerAsync();
    });

    expect(result.current.state).toBe('failed');
    expect(result.current.error).toBe('Status check failed');
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

  it('starts with empty logEntries', () => {
    const { result } = renderHook(() => useVippsPayment(mockConfig));
    expect(result.current.logEntries).toEqual([]);
  });

  it('collects client-side log entries during QR flow', async () => {
    vi.mocked(api.createPayment).mockResolvedValue({
      success: true,
      data: { reference: 'ref-log', flow: 'qr', qrUrl: 'https://qr.vipps.no/log.png' },
    });

    const { result } = renderHook(() => useVippsPayment(mockConfig));

    await act(async () => {
      result.current.createQr();
    });

    expect(result.current.logEntries.some((e) => e.includes('[CLIENT] Generating QR code'))).toBe(true);
    expect(result.current.logEntries.some((e) => e.includes('[CLIENT] QR code displayed'))).toBe(true);
  });

  it('collects server log_entries from API responses', async () => {
    vi.mocked(api.createPayment).mockResolvedValue({
      success: true,
      data: {
        reference: 'ref-srv',
        flow: 'qr',
        qrUrl: 'https://qr.vipps.no/srv.png',
        log_entries: ['[SERVER] Payment created', '[SERVER] ePayment initiated'],
      },
    });

    const { result } = renderHook(() => useVippsPayment(mockConfig));

    await act(async () => {
      result.current.createQr();
    });

    expect(result.current.logEntries).toContain('[SERVER] Payment created');
    expect(result.current.logEntries).toContain('[SERVER] ePayment initiated');
  });

  it('updates phoneFlowMode when modeChanged is true', async () => {
    vi.mocked(api.createPayment).mockResolvedValue({
      success: true,
      data: {
        reference: 'ref-mode',
        flow: 'redirect',
        redirectUrl: 'https://vipps.no/landing',
        modeChanged: true,
      },
    });

    const { result } = renderHook(() =>
      useVippsPayment({ ...mockConfig, phoneFlowMode: 'push' })
    );

    await act(async () => {
      result.current.sendPush('4712345678');
    });

    expect(result.current.state).toBe('failed');
    expect(result.current.error).toContain('Click');
    expect(result.current.phoneFlowMode).toBe('redirect');
  });

  it('opens tab and sets URL when in redirect mode', async () => {
    const mockTab = { location: { href: '' }, close: vi.fn() };
    vi.spyOn(window, 'open').mockReturnValue(mockTab as unknown as Window);

    vi.mocked(api.createPayment).mockResolvedValue({
      success: true,
      data: {
        reference: 'ref-redir',
        flow: 'redirect',
        redirectUrl: 'https://vipps.no/landing-page',
      },
    });

    const { result } = renderHook(() =>
      useVippsPayment({ ...mockConfig, phoneFlowMode: 'redirect' })
    );

    await act(async () => {
      result.current.sendPush('4712345678');
    });

    expect(window.open).toHaveBeenCalledWith('about:blank', '_blank');
    expect(mockTab.location.href).toBe('https://vipps.no/landing-page');
    expect(result.current.state).toBe('polling');
  });

  it('closes pre-opened tab on redirect flow failure', async () => {
    const mockTab = { location: { href: '' }, close: vi.fn() };
    vi.spyOn(window, 'open').mockReturnValue(mockTab as unknown as Window);

    vi.mocked(api.createPayment).mockResolvedValue({
      success: false,
      data: { reference: '', flow: 'redirect', message: 'Payment failed' },
    });

    const { result } = renderHook(() =>
      useVippsPayment({ ...mockConfig, phoneFlowMode: 'redirect' })
    );

    await act(async () => {
      result.current.sendPush('4712345678');
    });

    expect(mockTab.close).toHaveBeenCalled();
    expect(result.current.state).toBe('failed');
  });

  it('does not collect logs when debug is false', async () => {
    vi.mocked(api.createPayment).mockResolvedValue({
      success: true,
      data: {
        reference: 'ref-no-dbg',
        flow: 'qr',
        qrUrl: 'https://qr.vipps.no/nd.png',
        log_entries: ['[SERVER] should not appear'],
      },
    });

    const { result } = renderHook(() =>
      useVippsPayment({ ...mockConfig, debug: false })
    );

    await act(async () => {
      result.current.createQr();
    });

    expect(result.current.logEntries).toEqual([]);
  });
});
