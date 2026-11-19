import { beforeEach, describe, expect, it, vi } from 'vitest';

describe('live API client', () => {
  let storedValues: Map<string, string>;

  beforeEach(() => {
    vi.clearAllTimers();
    vi.useRealTimers();
    vi.resetModules();
    vi.unstubAllGlobals();
    storedValues = new Map();
    vi.stubGlobal('localStorage', {
      getItem: (key: string) => storedValues.get(key) ?? null,
      setItem: (key: string, value: string) => storedValues.set(key, value),
      removeItem: (key: string) => storedValues.delete(key),
    });
  });

  it('persists the login token and uses it for authenticated live API calls', async () => {
    const fetchStub = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(new Response(JSON.stringify({
        data: { user: { id: 7, name: 'Taylor Example', email: 'taylor@example.com' }, token: 'ephemeral-token' },
      }), { status: 200, headers: { 'Content-Type': 'application/json' } }))
      .mockResolvedValueOnce(new Response(JSON.stringify({
        data: [{
          id: 2,
          source: 'Salary',
          amount: '68000.00',
          expected_date: '2026-10-30',
          status: 'expected',
        }],
        meta: { total: 1 },
      }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
    vi.stubGlobal('fetch', fetchStub);

    const { liveClient } = await import('../api/client');
    const user = await liveClient.login({ email: 'taylor@example.com', password: 'a-private-password' });
    expect(storedValues.get('finance-app.access-token')).toBe('ephemeral-token');
    expect(storedValues.get('finance-app.device-id')).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
    );
    expect(JSON.parse(String(fetchStub.mock.calls[0][1]?.body)).device_id)
      .toBe(storedValues.get('finance-app.device-id'));
    const incomes = await liveClient.listIncomes();

    expect(user.id).toBe('7');
    expect(incomes[0]).toMatchObject({
      source: 'Salary',
      amount: 'RD$ 68,000.00',
      status: 'Esperado',
    });
    expect(incomes[0].expectedDate).toMatch(/30|oct/i);
    expect(fetchStub).toHaveBeenCalledTimes(2);
    const secondCall = fetchStub.mock.calls[1];
    expect(secondCall[0]).toBe('/api/v1/incomes');
    expect(new Headers(secondCall[1]?.headers).get('Authorization')).toBe('Bearer ephemeral-token');
  });

  it('updates a cached tunnel URL from Redis before sending a request on Vercel', async () => {
    vi.useFakeTimers();
    vi.stubGlobal('location', { hostname: 'finance-app.vercel.app' });
    storedValues.set('finance-app.active-tunnel-url', 'https://old-tunnel.trycloudflare.com');

    const fetchStub = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(new Response(JSON.stringify({
        tunnel_url: 'https://new-tunnel.trycloudflare.com',
      }), { status: 200, headers: { 'Content-Type': 'application/json' } }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ status: 'healthy' }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }))
      .mockResolvedValueOnce(new Response(JSON.stringify({
        data: { user: { id: 11, name: 'Casey Example', email: 'casey@example.com' }, token: 'new-token' },
      }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
    vi.stubGlobal('fetch', fetchStub);

    const { liveClient } = await import('../api/client');
    await liveClient.login({ email: 'casey@example.com', password: 'a-private-password' });

    expect(fetchStub.mock.calls.map(([url]) => url)).toEqual([
      expect.stringMatching(/^\/api\/tunnel\?/),
      'https://new-tunnel.trycloudflare.com/api/v1/health',
      'https://new-tunnel.trycloudflare.com/api/v1/auth/login',
    ]);
    expect(storedValues.get('finance-app.active-tunnel-url')).toBe('https://new-tunnel.trycloudflare.com');
  });

  it('rejects a successful HTML response instead of treating the SPA as API data', async () => {
    vi.stubGlobal('fetch', vi.fn<typeof fetch>()
      .mockResolvedValue(new Response('<!doctype html><title>Finance App</title>', {
        status: 200,
        headers: { 'Content-Type': 'text/html' },
      })));

    const { liveClient } = await import('../api/client');
    await expect(liveClient.getDashboardSummary()).rejects.toMatchObject({
      status: 502,
      message: 'Vercel devolvió la página web en lugar de la respuesta JSON de la API.',
    });
  });

  it('restores a saved session after a reload and clears it on explicit logout', async () => {
    const fetchStub = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(new Response(JSON.stringify({
        data: { id: 9, name: 'Sam Example', email: 'sam@example.com' },
      }), { status: 200, headers: { 'Content-Type': 'application/json' } }))
      .mockResolvedValueOnce(new Response(JSON.stringify({
        data: { signed_out: true },
      }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
    vi.stubGlobal('fetch', fetchStub);
    storedValues.set('finance-app.access-token', 'remembered-token');

    const { liveClient } = await import('../api/client');
    await expect(liveClient.getCurrentUser()).resolves.toMatchObject({
      id: '9',
      email: 'sam@example.com',
    });
    await liveClient.logout();

    expect(storedValues.has('finance-app.access-token')).toBe(false);
    expect(fetchStub).toHaveBeenCalledTimes(2);
  });

  it('shares concurrent session checks and retains the token after a rate limit response', async () => {
    const fetchStub = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(new Response(JSON.stringify({
        error: { code: 'TOO_MANY_REQUESTS', message: 'Please try again shortly.' },
      }), { status: 429, headers: { 'Content-Type': 'application/json' } }))
      .mockResolvedValueOnce(new Response(JSON.stringify({
        data: { id: 10, name: 'Alex Example', email: 'alex@example.com' },
      }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
    vi.stubGlobal('fetch', fetchStub);
    storedValues.set('finance-app.access-token', 'remembered-token');

    const { liveClient } = await import('../api/client');
    const firstCheck = liveClient.getCurrentUser();
    const duplicateCheck = liveClient.getCurrentUser();
    expect(firstCheck).toBe(duplicateCheck);
    await expect(firstCheck).rejects.toMatchObject({ status: 429 });
    expect(storedValues.get('finance-app.access-token')).toBe('remembered-token');

    await expect(liveClient.getCurrentUser()).resolves.toMatchObject({ id: '10' });
    expect(fetchStub).toHaveBeenCalledTimes(2);
  });

  it('loads debt history and sends Gemini analysis through an explicit consent request', async () => {
    const fetchStub = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(new Response(JSON.stringify({
        data: { user: { id: 8, name: 'Jordan Example', email: 'jordan@example.com' }, token: 'ephemeral-token' },
      }), { status: 200, headers: { 'Content-Type': 'application/json' } }))
      .mockResolvedValueOnce(new Response(JSON.stringify({
        data: [{ month: '2026-10', balance: '12500.75' }],
      }), { status: 200, headers: { 'Content-Type': 'application/json' } }))
      .mockResolvedValueOnce(new Response(JSON.stringify({
        data: {
          summary: 'Tus pagos se mantienen constantes.',
          good_habits: ['Registras tus movimientos.'],
          habits_to_improve: [],
          recommendations: [],
        },
      }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
    vi.stubGlobal('fetch', fetchStub);

    const { liveClient } = await import('../api/client');
    await liveClient.login({ email: 'jordan@example.com', password: 'a-private-password' });
    await expect(liveClient.getDebtBalanceHistory()).resolves.toEqual([
      { month: '2026-10', balance: 12500.75 },
    ]);
    await expect(liveClient.getFinanceInsights()).resolves.toMatchObject({
      summary: 'Tus pagos se mantienen constantes.',
    });

    const historyCall = fetchStub.mock.calls[1];
    expect(historyCall[0]).toBe('/api/v1/debts/history');
    const insightsCall = fetchStub.mock.calls[2];
    expect(insightsCall[0]).toBe('/api/v1/insights/recommendations');
    expect(insightsCall[1]?.method).toBe('POST');
    expect(JSON.parse(String(insightsCall[1]?.body))).toEqual({ consent: true });
  });

  it('sends chat messages to the assistant and handles responses', async () => {
    const fetchStub = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(new Response(JSON.stringify({
        data: { id: 1, role: 'assistant', content: '¡Entendido! Registrado el gasto de RD$ 350.00' },
      }), { status: 201, headers: { 'Content-Type': 'application/json' } }))
      .mockResolvedValueOnce(new Response(JSON.stringify({
        data: [{ id: 1, role: 'assistant', content: '¡Entendido! Registrado el gasto de RD$ 350.00' }],
      }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
    vi.stubGlobal('fetch', fetchStub);
    storedValues.set('finance-app.access-token', 'chat-token');

    const { liveClient } = await import('../api/client');
    const reply = await liveClient.sendChatMessage('Compré un helado de 350');
    expect(reply.content).toContain('RD$ 350.00');

    const history = await liveClient.listChatMessages();
    expect(history).toHaveLength(1);
    expect(history[0].role).toBe('assistant');
  });
});
