/**
 * YAZAN AI Core — Fastify server.
 *
 * Two routes:
 *   GET  /health       — liveness (unauthenticated).
 *   POST /v1/complete  — HMAC-authenticated; runs the provider chain and returns a normalized result.
 *
 * Stateless: it holds no keys and no store data. Keys arrive per-request from WordPress; the shared
 * secret only authenticates the caller.
 */
import { readFileSync } from 'node:fs';
import path from 'node:path';
import Fastify from 'fastify';
import { verify } from './auth';
import { runChain } from './router';
import { CompleteBody } from './types';

/** Minimal .env loader (no dependency): fills process.env from ./.env when present. */
function loadEnv(): void {
  try {
    const file = readFileSync(path.resolve(process.cwd(), '.env'), 'utf8');
    for (const line of file.split(/\r?\n/)) {
      const m = /^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/i.exec(line);
      if (m && process.env[m[1]] === undefined) {
        process.env[m[1]] = m[2].replace(/^["']|["']$/g, '');
      }
    }
  } catch {
    /* no .env — rely on real environment variables */
  }
}

loadEnv();

const SECRET = process.env.YAZAN_CORE_SECRET || '';
const PORT = Number.parseInt(process.env.PORT || '8787', 10);
const HOST = process.env.HOST || '127.0.0.1';
const WINDOW = Number.parseInt(process.env.SIGNATURE_WINDOW || '300', 10);
const VERSION = '1.0.0';

if (!SECRET || SECRET === 'change-me-to-a-long-random-string') {
  // Refuse to run open — an unauthenticated core would proxy anyone's provider calls.
  console.error('[yazan-ai-core] FATAL: YAZAN_CORE_SECRET is not set. Copy .env.example to .env and set a strong secret.');
  process.exit(1);
}
if (SECRET.length < 24 && HOST !== '127.0.0.1' && HOST !== 'localhost') {
  // Weak secret is tolerable on loopback; on an exposed host it's a real risk.
  console.warn('[yazan-ai-core] WARNING: YAZAN_CORE_SECRET is short (<24 chars) while bound to a non-loopback host. Use a 32+ char random secret in production.');
}

const app = Fastify({ logger: { level: process.env.LOG_LEVEL || 'info' }, bodyLimit: 15 * 1024 * 1024 });

// Capture the raw body (needed to verify the HMAC) while still parsing JSON.
app.addContentTypeParser('application/json', { parseAs: 'string' }, (req, body, done) => {
  (req as unknown as { rawBody: string }).rawBody = body as string;
  try {
    done(null, (body as string).length ? JSON.parse(body as string) : {});
  } catch (err) {
    done(err as Error, undefined);
  }
});

// Authenticate the completion route (health stays open).
app.addHook('preHandler', async (req, reply) => {
  if (req.method !== 'POST' || !req.url.startsWith('/v1/')) return;
  const sig = String(req.headers['x-yazan-signature'] || '');
  const ts = String(req.headers['x-yazan-timestamp'] || '');
  const raw = (req as unknown as { rawBody?: string }).rawBody || '';
  if (!verify(raw, sig, ts, SECRET, WINDOW)) {
    // MUST return the reply to halt the request lifecycle — falling off the end of an async hook
    // after send() lets the route handler run anyway on some Fastify versions, making auth cosmetic.
    return reply.code(401).send({ ok: false, error_code: 'unauthorized' });
  }
});

app.get('/health', async () => ({ ok: true, service: 'yazan-ai-core', version: VERSION }));

app.post('/v1/complete', async (req, reply) => {
  const body = req.body as CompleteBody;
  if (!body || !Array.isArray(body.chain) || !body.request || !Array.isArray(body.request.messages)) {
    reply.code(400).send({ ok: false, error_code: 'bad_request' });
    return;
  }
  const capability = body.capability === 'vision' ? 'vision' : 'text';
  const result = await runChain(body.request, body.chain, capability);
  return result;
});

app
  .listen({ port: PORT, host: HOST })
  .then(() => app.log.info(`yazan-ai-core listening on http://${HOST}:${PORT}`))
  .catch((err) => {
    app.log.error(err);
    process.exit(1);
  });
