import { createClient } from 'redis';

export default async function handler(req, res) {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');
  res.setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, proxy-revalidate');

  if (req.method === 'OPTIONS') {
    return res.status(200).end();
  }

  const redisUrl = process.env.REDIS_URL || process.env.STORAGE_URL || process.env.KV_URL;
  const restUrl = process.env.STORAGE_REST_API_URL || process.env.KV_REST_API_URL || process.env.UPSTASH_REDIS_REST_URL || process.env.REDIS_REST_URL;
  const restToken = process.env.STORAGE_REST_API_TOKEN || process.env.KV_REST_API_TOKEN || process.env.UPSTASH_REDIS_REST_TOKEN || process.env.REDIS_REST_TOKEN;

  let rawUrl = null;

  // 1. Try REST API if configured
  if (restUrl && restToken) {
    try {
      const keys = [
        'finance_app_tunnel_url',
        'laravel_database_finance_app_tunnel_url',
        'laravel_database_laravel_cache_finance_app_tunnel_url',
      ];
      for (const k of keys) {
        const response = await fetch(`${restUrl.replace(/\/+$/, '')}/get/${k}`, {
          headers: { Authorization: `Bearer ${restToken}` },
        }).catch(() => null);

        if (response && response.ok) {
          const data = await response.json().catch(() => null);
          if (data?.result) {
            rawUrl = data.result;
            break;
          }
        }
      }
    } catch {
      // Ignore
    }
  }

  // 2. Try Node Redis Client TCP if REST API didn't return
  if (!rawUrl && redisUrl) {
    try {
      const isTls = redisUrl.startsWith('rediss://');
      const client = createClient({
        url: redisUrl,
        socket: isTls ? { tls: true, rejectUnauthorized: false } : {},
      });

      await client.connect();
      const keys = [
        'finance_app_tunnel_url',
        'laravel_database_finance_app_tunnel_url',
        'laravel_database_laravel_cache_finance_app_tunnel_url',
      ];
      for (const key of keys) {
        const val = await client.get(key);
        if (val) {
          rawUrl = val;
          break;
        }
      }
      await client.disconnect().catch(() => null);
    } catch (err) {
      if (!rawUrl) {
        return res.status(500).json({ error: `Redis TCP connection failed: ${err.message}` });
      }
    }
  }

  if (!rawUrl) {
    return res.status(404).json({ error: 'No active tunnel URL found in Redis' });
  }

  const match = String(rawUrl).match(/https:\/\/[a-z0-9\-]+\.trycloudflare\.com/i);
  const cleanUrl = match ? match[0] : String(rawUrl).replace(/^s:\d+:"/, '').replace(/";$/, '').trim();

  return res.status(200).json({
    tunnel_url: cleanUrl,
    api_base_url: `${cleanUrl}/api/v1`,
    updated_at: new Date().toISOString(),
  });
}
