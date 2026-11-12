// Reverse proxy that puts a Cloudflare Worker in front of the private API and
// reaches it over a Workers VPC Service binding. The whole API — every method,
// path, header and body — is forwarded through; the response is streamed back
// verbatim, so auth, uploads, downloads and non-JSON all work.
//
// Deployed on the API's OWN hostname (see wrangler.jsonc "routes"), so the SPA
// needs no change and the API's existing CORS headers pass straight through.

export interface Env {
  /** The VPC Service binding, used exactly like global fetch(). */
  VPC_SERVICE: Fetcher;
  /** Hostname the VPC service is bound to (nginx vhost + TLS SNI). */
  ORIGIN_HOST: string;
  /** Only set when the Worker is NOT on the API's own hostname (adds CORS). */
  ALLOW_ORIGIN?: string;
}

export default {
  async fetch(request: Request, env: Env): Promise<Response> {
    // Answer CORS preflight at the edge only when we inject CORS ourselves.
    if (request.method === "OPTIONS" && env.ALLOW_ORIGIN) {
      return new Response(null, { status: 204, headers: cors(env, request) });
    }

    // Keep the full path + query; only the host comes from the VPC target.
    const incoming = new URL(request.url);
    const target = new URL(incoming.pathname + incoming.search, `https://${env.ORIGIN_HOST}`);

    // Clone method, headers (incl. Authorization) and body through unchanged.
    const proxied = new Request(target, request);
    proxied.headers.set("X-Forwarded-Proto", "https");
    const ip = request.headers.get("CF-Connecting-IP");
    if (ip) proxied.headers.set("X-Forwarded-For", ip);

    let res: Response;
    try {
      res = await env.VPC_SERVICE.fetch(proxied); // routes over the private VPC link
    } catch (err) {
      return new Response(`Upstream unreachable: ${(err as Error).message}`, {
        status: 502,
        headers: env.ALLOW_ORIGIN ? cors(env, request) : undefined,
      });
    }

    // Same-hostname deploy: the API's own CORS headers already pass through.
    if (!env.ALLOW_ORIGIN) return res;

    const headers = new Headers(res.headers);
    cors(env, request).forEach((v, k) => headers.set(k, v));
    return new Response(res.body, { status: res.status, statusText: res.statusText, headers });
  },
};

function cors(env: Env, request: Request): Headers {
  return new Headers({
    "Access-Control-Allow-Origin": env.ALLOW_ORIGIN!,
    "Vary": "Origin",
    "Access-Control-Allow-Credentials": "true",
    "Access-Control-Allow-Methods": "GET,POST,PUT,PATCH,DELETE,OPTIONS",
    "Access-Control-Allow-Headers":
      request.headers.get("Access-Control-Request-Headers") ?? "Authorization,Content-Type",
    "Access-Control-Max-Age": "86400",
  });
}
