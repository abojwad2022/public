/**
 * YAZAN AI Core — provider router.
 *
 * Walks the ordered chain WordPress sent (each entry already carries its api_key and resolved model),
 * calls each provider, and falls through to the next on a retryable failure — exactly like the PHP
 * gateway loop. Returns which provider/model served the request plus a per-attempt trace.
 */
import { ClaudeProvider } from './providers/claude';
import { GeminiProvider } from './providers/gemini';
import { makeGroq, makeOpenAI, makeOpenRouter } from './providers/openai-compatible';
import { ChainEntry, CompleteResponse, CompletionRequest, Provider, ProviderError } from './types';

function provider(id: string): Provider | null {
  switch (id) {
    case 'openrouter':
      return makeOpenRouter();
    case 'openai':
      return makeOpenAI();
    case 'groq':
      return makeGroq();
    case 'claude':
      return new ClaudeProvider();
    case 'gemini':
      return new GeminiProvider();
    default:
      return null;
  }
}

export async function runChain(
  request: CompletionRequest,
  chain: ChainEntry[],
  capability: 'text' | 'vision',
): Promise<CompleteResponse> {
  const attempts: CompleteResponse['attempts'] = [];
  let lastError: ProviderError | null = null;

  for (const entry of chain) {
    const adapter = provider(entry.provider);
    if (!adapter || !entry.api_key || !entry.model) {
      attempts.push({ provider: entry.provider, model: entry.model, status: 'error', error_code: 'unconfigured' });
      continue;
    }
    if (capability === 'vision' && !adapter.supportsVision(entry.model)) {
      attempts.push({ provider: entry.provider, model: entry.model, status: 'error', error_code: 'no_vision' });
      continue;
    }

    try {
      const result = await adapter.complete(request, entry.model, entry.api_key);
      attempts.push({ provider: entry.provider, model: entry.model, status: 'ok' });
      return {
        ok: true,
        text: result.text,
        tokens_in: result.tokens_in,
        tokens_out: result.tokens_out,
        provider: entry.provider,
        model: entry.model,
        attempts,
      };
    } catch (err) {
      const e = err instanceof ProviderError ? err : new ProviderError('unknown', (err as Error).message);
      lastError = e;
      attempts.push({ provider: entry.provider, model: entry.model, status: 'error', error_code: e.code });
      if (!e.retryable) break; // e.g. blocked content — stop the chain.
    }
  }

  return {
    ok: false,
    text: '',
    tokens_in: 0,
    tokens_out: 0,
    provider: '',
    model: '',
    error_code: lastError?.code ?? 'no_provider',
    error_message: lastError?.message ?? '',
    attempts,
  };
}
