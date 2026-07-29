# YAZAN AI Core — standalone execution service

The intelligence layer of the YAZAN AI platform, extracted from WordPress. It is **stateless**: given a
normalized request and an ordered chain of `{provider, model, apiKey}`, it runs the provider HTTP calls
with fallback and returns a normalized result. WordPress (`yazan-core`) keeps everything else — prompts,
budget, cache, logging, and secret storage — and delegates only the provider execution here.

This is the Phase 3 extraction target of `Yazan_AI_Gateway`: the WordPress gateway calls this service
when configured, and **automatically falls back to its in-PHP providers if the service is unreachable**,
so enabling it is zero-risk.

## Run locally

```bash
cd ai-core-service
npm install
cp .env.example .env          # then set YAZAN_CORE_SECRET to a long random string
npm run build && npm start    # or: npm run dev  (watch mode)
```

Generate a secret:

```bash
node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"
```

Put the **same** secret and the service URL (e.g. `http://127.0.0.1:8787`) into WordPress:
`/dashboard → AI Settings → AI Core (remote service)` → enable, save the secret, **Test Core**.

## API

- `GET /health` → `{ ok, service, version }` (unauthenticated).
- `POST /v1/complete` (authenticated) → runs the chain.

### Authentication

Every `POST /v1/*` request is signed. WordPress sends:

- `X-Yazan-Timestamp`: unix seconds.
- `X-Yazan-Signature`: `HMAC_SHA256( "<timestamp>.<rawBody>", secret )` in hex.

The service recomputes the HMAC and compares in constant time, rejecting anything outside a
`SIGNATURE_WINDOW`-second replay window with `401`.

### `POST /v1/complete` body

```jsonc
{
  "capability": "text" | "vision",
  "request": {
    "system": "…",
    "messages": [{ "role": "user", "content": "…", "images": ["data:image/…;base64,…"] }],
    "json": true,
    "temperature": 0.6,
    "max_tokens": 2048
  },
  "chain": [{ "provider": "openrouter", "model": "openai/gpt-4o-mini", "api_key": "sk-or-…" }]
}
```

Response:

```jsonc
{
  "ok": true,
  "text": "…",
  "tokens_in": 407,
  "tokens_out": 125,
  "provider": "openrouter",
  "model": "openai/gpt-4o-mini",
  "attempts": [{ "provider": "openrouter", "model": "…", "status": "ok" }]
}
```

## Providers

Ports the five WordPress adapters 1:1 — OpenRouter, OpenAI, Groq (OpenAI-schema), Claude (Anthropic
Messages), Gemini (generateContent). Vision inputs are normalized per provider (`images.ts`).

## Deploy

Any Node ≥18 host (Fastify, zero native deps). Set `HOST=0.0.0.0`, put it behind TLS, keep
`YAZAN_CORE_SECRET` in the environment, and point the WordPress AI Core URL at it. To evolve toward
multi-tenant SaaS, move key ownership into the service and add per-tenant auth — the request contract
already carries everything else.
