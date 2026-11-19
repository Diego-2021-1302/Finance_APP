const { createClient } = require('redis');

module.exports = async function handler(req, res) {
  res.setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');
  res.setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, proxy-revalidate');

  if (req.method === 'OPTIONS') return res.status(204).end();
  if (req.method !== 'GET') return res.status(405).json({ error: 'Method not allowed' });

  const redisUrl = process.env.REDIS_URL || process.env.STORAGE_URL || process.env.KV_URL;
  if (!redisUrl) {
    return res.status(503).json({ error: 'REDIS_URL is not configured on Vercel' });
  }

  const client = createClient({
    url: redisUrl,
    socket: { connectTimeout: 5000 },
  });
  client.on('error', (error) => console.error('Redis connection error:', error));

  try {
    await client.connect();
    const keys = ['finance_app_tunnel_url', 'laravel_database_finance_app_tunnel_url'];
    let rawUrl = null;
    for (const key of keys) {
      rawUrl = await client.get(key);
      if (rawUrl) break;
    }

    if (!rawUrl) return res.status(404).json({ error: 'No active tunnel URL found in Redis' });

    const match = String(rawUrl).match(/https:\/\/[a-z0-9-]+\.trycloudflare\.com/i);
    const cleanUrl = (match ? match[0] : String(rawUrl).trim()).replace(/\/+$/, '');
    if (!cleanUrl.startsWith('https://')) {
      return res.status(502).json({ error: 'Redis contains an invalid tunnel URL' });
    }

    const updatedAt = await client.get('finance_app_tunnel_updated_at');

    return res.status(200).json({
      tunnel_url: cleanUrl,
      api_base_url: `${cleanUrl}/api/v1`,
      updated_at: updatedAt,
    });
  } catch (error) {
    console.error('Failed to read the active tunnel URL from Redis:', error);
    return res.status(502).json({ error: 'Unable to connect to Redis or read the active tunnel URL' });
  } finally {
    if (client.isOpen) await client.disconnect().catch(() => undefined);
  }
}
