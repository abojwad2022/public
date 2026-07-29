/**
 * YAZAN AI Core — Claude (Anthropic) provider. Ports Yazan_AI_Provider_Claude.
 * Messages API: top-level `system`, content blocks, usage.input_tokens/output_tokens. Images = base64.
 */
import { toBase64 } from '../images';
import { CompletionRequest, Provider, ProviderError, ProviderResult } from '../types';
import { postJson } from './openai-compatible';

const API_VERSION = '2023-06-01';

export class ClaudeProvider implements Provider {
  id = 'claude';

  supportsVision(): boolean {
    return true;
  }

  async complete(request: CompletionRequest, model: string, apiKey: string): Promise<ProviderResult> {
    const messages: unknown[] = [];

    for (const msg of request.messages || []) {
      const role = msg.role === 'assistant' ? 'assistant' : 'user';
      const blocks: unknown[] = [];
      for (const image of msg.images || []) {
        const inline = await toBase64(image);
        if (inline) {
          blocks.push({ type: 'image', source: { type: 'base64', media_type: inline.mime, data: inline.data } });
        }
      }
      blocks.push({ type: 'text', text: msg.content || '' });
      messages.push({ role, content: blocks });
    }

    let system = request.system || '';
    if (request.json) system = `${system}\nRespond with a single valid JSON object and nothing else.`.trim();

    const body: Record<string, unknown> = {
      model,
      max_tokens: request.max_tokens ?? 1200,
      temperature: request.temperature ?? 0.6,
      messages,
    };
    if (system) body.system = system;

    const decoded = await postJson('https://api.anthropic.com/v1/messages', body, {
      'x-api-key': apiKey,
      'anthropic-version': API_VERSION,
    });

    let text = '';
    if (Array.isArray(decoded?.content)) {
      for (const block of decoded.content) if (block?.type === 'text' && block?.text) text += block.text;
    }
    if (text === '' && decoded?.content === undefined) {
      throw new ProviderError('bad_response', 'No content in Claude response.', 0, true);
    }
    return {
      text,
      tokens_in: Number(decoded?.usage?.input_tokens ?? 0),
      tokens_out: Number(decoded?.usage?.output_tokens ?? 0),
    };
  }
}
