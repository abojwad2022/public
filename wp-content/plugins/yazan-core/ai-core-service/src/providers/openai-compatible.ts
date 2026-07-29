/**
 * YAZAN AI Core — OpenAI-compatible providers (OpenRouter, OpenAI, Groq).
 *
 * Ports Yazan_AI_Provider_OpenAI (+ its subclasses). They share the `/chat/completions` schema;
 * images become OpenAI content parts. Vision support is per-model (Groq overrides it).
 */
import { CompletionRequest, Provider, ProviderError, ProviderResult } from '../types';

const TIMEOUT_MS = 45_000;

export class OpenAICompatibleProvider implements Provider {
  constructor(
    public id: string,
    private baseUrl: string,
    private extraHeaders: Record<string, string> = {},
    private visionCheck: (model: string) => boolean = () => true,
  ) {}

  supportsVision(model: string): boolean {
    return this.visionCheck(model);
  }

  async complete(request: CompletionRequest, model: string, apiKey: string): Promise<ProviderResult> {
    const messages: unknown[] = [];

    if (request.system) messages.push({ role: 'system', content: request.system });

    for (const msg of request.messages || []) {
      const role = msg.role === 'assistant' ? 'assistant' : 'user';
      const content = msg.content || '';
      const images = msg.images || [];

      if (images.length === 0) {
        messages.push({ role, content });
        continue;
      }
      const parts: unknown[] = [{ type: 'text', text: content }];
      for (const image of images) parts.push({ type: 'image_url', image_url: { url: image } });
      messages.push({ role, content: parts });
    }

    const body: Record<string, unknown> = {
      model,
      messages,
      temperature: request.temperature ?? 0.6,
      max_tokens: request.max_tokens ?? 1200,
    };
    if (request.json) body.response_format = { type: 'json_object' };

    const decoded = await postJson(`${this.baseUrl.replace(/\/$/, '')}/chat/completions`, body, {
      Authorization: `Bearer ${apiKey}`,
      ...this.extraHeaders,
    });

    const choice = decoded?.choices?.[0];
    const text = choice?.message?.content ?? '';
    // Empty content (no choice, or a content-filtered / truncated null) is a failure — throw retryable
    // so the router fails over rather than returning a blank success. Mirrors the PHP base adapter.
    if (String(text).trim() === '') {
      throw new ProviderError('empty_response', `empty content (${choice?.finish_reason ?? 'empty'})`, 0, true);
    }
    return {
      text: String(text),
      tokens_in: Number(decoded?.usage?.prompt_tokens ?? 0),
      tokens_out: Number(decoded?.usage?.completion_tokens ?? 0),
    };
  }
}

const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));

/**
 * Shared JSON POST with per-attempt timeout + HTTP→ProviderError mapping, and ONE retry on a
 * transient failure (transport / 5xx) with a short backoff — parity with PHP Yazan_AI_Http.
 */
export async function postJson(
  url: string,
  body: unknown,
  headers: Record<string, string>,
): Promise<any> {
  let last: ProviderError | null = null;
  for (let attempt = 1; attempt <= 2; attempt++) {
    if (attempt > 1) await sleep(400); // backoff before the single retry

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), TIMEOUT_MS);
    try {
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', ...headers },
        body: JSON.stringify(body),
        signal: controller.signal,
      });
      const raw = await res.text();
      if (res.ok) {
        try {
          return JSON.parse(raw);
        } catch {
          throw new ProviderError('bad_response', 'Provider returned a non-JSON body.', res.status, true);
        }
      }
      last = ProviderError.fromHttp(res.status, raw);
      if (res.status < 500) throw last; // 4xx won't change on retry to the same endpoint
    } catch (err) {
      if (err instanceof ProviderError) {
        if (err.status && err.status < 500) throw err; // non-retryable
        last = err;
      } else {
        last = new ProviderError('transport', (err as Error).message || 'transport error', 0, true);
      }
    } finally {
      clearTimeout(timer);
    }
  }
  throw last ?? new ProviderError('transport', 'Provider request failed.', 0, true);
}

export function makeOpenRouter(): Provider {
  return new OpenAICompatibleProvider('openrouter', 'https://openrouter.ai/api/v1', {
    'HTTP-Referer': 'https://yazan.local/',
    'X-Title': 'YAZAN',
  });
}

export function makeOpenAI(): Provider {
  return new OpenAICompatibleProvider('openai', 'https://api.openai.com/v1');
}

export function makeGroq(): Provider {
  return new OpenAICompatibleProvider(
    'groq',
    'https://api.groq.com/openai/v1',
    {},
    // Groq multimodal models: Llama 4 (Scout/Maverick) + the older *-vision-preview ids.
    (model) => /vision|llama-4|scout|maverick/i.test(model),
  );
}
