import { createClient } from 'redis';

export default async function handler(req, res) {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');
  res.setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, proxy-revalidate');

  if (req.method === 'OPTIONS') {
    return res.status(200).end();
  }

  const redisUrl = process.env.REDIS_URL || process.env.STORAGE_URL || process.env.KV_URL;
  if (!redisUrl) {
    return res.status(500).json({ error: 'REDIS_URL variable not set on Vercel' });
  }

  try {
    const client = createClient({ url: redisUrl });
    await client.connect();

    const keys = [
      'finance_app_tunnel_url',
      'laravel_database_finance_app_tunnel_url',
      'laravel_database_laravel_cache_finance_app_tunnel_url',
    ];

    let foundUrl = null;

    for (const key of keys) {
      const val = await client.get(key);
      if (val) {
        const match = String(val).match(/https:\/\/[a-z0-9\-]+\.trycloudflare\.com/i);
        if (match) {
          foundUrl = match[0];
          break;
        }
      }
    }

    await client.disconnect();

    if (!rawUrl) {
      return res.status(404).json({ error: 'No active tunnel URL found in Redis for key finance_app_tunnel_url' });
    }

    const match = String(rawUrl).match(/https:\/\/[a-z0-9\-]+\.trycloudflare\.com/i);
    const cleanUrl = match ? match[0] : String(rawUrl).replace(/^s:\d+:"/, '').replace(/";$/, '').trim();

    return res.status(200).json({
      tunnel_url: cleanUrl,
      api_base_url: `${cleanUrl}/api/v1`,
      updated_at: new Date().toISOString(),
    });
  } catch (err) {
    return res.status(500).json({ error: err.message });
  }
}
