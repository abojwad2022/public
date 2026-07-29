/**
 * YAZAN AI Core — Google Gemini provider. Ports Yazan_AI_Provider_Gemini.
 * generateContent: `contents` (role+parts), optional systemInstruction, generationConfig. Images =
 * inline_data (base64). The API key rides in the query string.
 */
import { toBase64 } from '../images';
import { CompletionRequest, Provider, ProviderError, ProviderResult } from '../types';
import { postJson } from './openai-compatible';

export class GeminiProvider implements Provider {
  id = 'gemini';

  supportsVision(): boolean {
    return true;
  }

  async complete(request: CompletionRequest, model: string, apiKey: string): Promise<ProviderResult> {
    const contents: unknown[] = [];

    for (const msg of request.messages || []) {
      const role = msg.role === 'assistant' ? 'model' : 'user';
      const parts: unknown[] = [];
      if (msg.content) parts.push({ text: msg.content });
      for (const image of msg.images || []) {
        const inline = await toBase64(image);
        if (inline) parts.push({ inline_data: { mime_type: inline.mime, data: inline.data } });
      }
      if (parts.length === 0) parts.push({ text: '' });
      contents.push({ role, parts });
    }

    const generationConfig: Record<string, unknown> = {
      temperature: request.temperature ?? 0.6,
      maxOutputTokens: request.max_tokens ?? 1200,
    };
    if (request.json) generationConfig.responseMimeType = 'application/json';

    const body: Record<string, unknown> = { contents, generationConfig };
    if (request.system) body.systemInstruction = { parts: [{ text: request.system }] };

    const url =
      `https://generativelanguage.googleapis.com/v1beta/models/${encodeURIComponent(model)}:generateContent` +
      `?key=${encodeURIComponent(apiKey)}`;

    const decoded = await postJson(url, body, {});

    let text = '';
    const parts = decoded?.candidates?.[0]?.content?.parts;
    if (Array.isArray(parts)) for (const p of parts) if (p?.text) text += p.text;

    if (text === '' && decoded?.candidates?.[0] === undefined) {
      const reason = decoded?.promptFeedback?.blockReason || 'no_candidates';
      throw new ProviderError('bad_response', `Gemini returned no content: ${reason}`, 0, false);
    }
    return {
      text,
      tokens_in: Number(decoded?.usageMetadata?.promptTokenCount ?? 0),
      tokens_out: Number(decoded?.usageMetadata?.candidatesTokenCount ?? 0),
    };
  }
}
