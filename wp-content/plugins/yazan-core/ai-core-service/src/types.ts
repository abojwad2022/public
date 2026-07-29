/**
 * YAZAN AI Core — the request/result contract shared with WordPress.
 *
 * These shapes mirror the PHP gateway's normalized request and the provider adapters' normalized
 * result, so the service is a drop-in for the in-WordPress execution path.
 */

export interface ChatMessage {
  role: 'user' | 'assistant';
  content: string;
  images?: string[]; // data: URIs or http(s) URLs
}

export interface CompletionRequest {
  system?: string;
  messages: ChatMessage[];
  json?: boolean;
  temperature?: number;
  max_tokens?: number;
}

/** One provider attempt the chain may try, WITH the key (WordPress owns keys and passes them). */
export interface ChainEntry {
  provider: string;
  model: string;
  api_key: string;
}

/** POST /v1/complete body. */
export interface CompleteBody {
  request: CompletionRequest;
  capability?: 'text' | 'vision';
  chain: ChainEntry[];
}

/** What a single provider returns on success. */
export interface ProviderResult {
  text: string;
  tokens_in: number;
  tokens_out: number;
}

/** The normalized response WordPress consumes. */
export interface CompleteResponse {
  ok: boolean;
  text: string;
  tokens_in: number;
  tokens_out: number;
  provider: string;
  model: string;
  error_code?: string;
  // Developer-facing detail of the final failure — WordPress surfaces it only on the admin-only
  // "Test connection" diagnostic path, never to storefront customers.
  error_message?: string;
  attempts: { provider: string; model: string; status: 'ok' | 'error'; error_code?: string }[];
}

/**
 * Provider failure carrying a stable code + retryability (mirrors PHP Yazan_AI_Exception).
 */
export class ProviderError extends Error {
  constructor(
    public code: string,
    message: string,
    public status = 0,
    public retryable = true,
  ) {
    super(message);
    this.name = 'ProviderError';
  }

  /** Map an HTTP status to a stable code + retryability (mirrors Yazan_AI_Exception::from_http). */
  static fromHttp(status: number, body: string): ProviderError {
    const snippet = String(body || '').slice(0, 300);
    if (status === 401 || status === 403) return new ProviderError('auth', `auth failed (${status}): ${snippet}`, status, true);
    if (status === 429) return new ProviderError('rate_limited', `rate limited (${status}): ${snippet}`, status, true);
    if (status >= 500) return new ProviderError('server', `server error (${status}): ${snippet}`, status, true);
    if (status >= 400) return new ProviderError('bad_request', `rejected (${status}): ${snippet}`, status, true);
    return new ProviderError('unknown', `unexpected (${status}): ${snippet}`, status, true);
  }
}

/** Contract every provider adapter implements. */
export interface Provider {
  id: string;
  supportsVision(model: string): boolean;
  complete(request: CompletionRequest, model: string, apiKey: string): Promise<ProviderResult>;
}
