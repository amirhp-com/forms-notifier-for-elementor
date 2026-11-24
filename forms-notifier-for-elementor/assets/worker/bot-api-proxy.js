/**
 * Bot API forwarder for a Worker. This file is sample code: the plugin shows it, it never runs it.
 *
 * Forwards https://<worker>/bot<token>/<method>            to https://api.telegram.org/bot<token>/<method>
 * and      https://<worker>/file/bot<token>/<file path>    to https://api.telegram.org/file/bot<token>/<file path>
 *
 * Optional variables of the Worker:
 *   ALLOWED_BOT_ID  numeric part of the token before the colon (several: comma separated); other bots are refused
 *   UPSTREAM        other Bot API server, for example https://tapi.bale.ai
 */
function answer(status, description) {
  return new Response(JSON.stringify({ ok: false, error_code: status, description: description }), {
    status: status,
    headers: { 'content-type': 'application/json' },
  });
}

export default {
  async fetch(request, env) {
    const upstream = (env.UPSTREAM || 'https://api.telegram.org').replace(/\/+$/, '');
    const url = new URL(request.url);
    const match = url.pathname.match(/^\/(file\/)?bot(\d+):[A-Za-z0-9_-]+\/[A-Za-z0-9_\/.-]+$/);
    if (!match) {
      return answer(404, 'Not found');
    }
    if (request.method !== 'GET' && request.method !== 'POST') {
      return answer(405, 'Method not allowed');
    }
    const allowed = String(env.ALLOWED_BOT_ID || '').split(',').map((id) => id.trim()).filter(Boolean);
    if (allowed.length && allowed.indexOf(match[2]) === -1) {
      return answer(403, 'Bot not allowed');
    }
    const headers = new Headers(request.headers);
    headers.delete('host');
    headers.delete('cf-connecting-ip');
    headers.delete('x-forwarded-for');
    headers.delete('x-real-ip');
    const init = { method: request.method, headers: headers, redirect: 'manual' };
    if (request.method === 'POST') {
      init.body = request.body;
    }
    return fetch(upstream + url.pathname + url.search, init);
  },
};
