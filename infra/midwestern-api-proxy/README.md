# midwestern-api-proxy

A Cloudflare Worker that fronts the Midwestern API and reaches it over a
**Workers VPC Service** binding, so the API box can drop all public inbound
and be reachable only through Cloudflare's private link.

```
Browser ──> Worker (api-midwesternlearning.midwesternog.com)
                 └─ VPC_SERVICE binding ──> private API origin
```

The Worker runs on the API's **own hostname**, so the SPA needs no change and
the API's existing CORS headers pass straight through.

## Setup (summary — see the full runbook in chat)

1. `npm install`
2. `npx wrangler login` (the account that owns the zone + the VPC service)
3. Confirm with infra: the hostname/port the VPC service `01a1cedc-…` is bound
   to, and set `ORIGIN_HOST` in `wrangler.jsonc` to match.
4. **Smoke test** — comment out the `routes` block, `npx wrangler deploy`, then
   `curl https://midwestern-api-proxy.<subdomain>.workers.dev/v1/health`.
5. **Cutover** — un-comment `routes`, `npx wrangler deploy` (binds the real
   hostname, replaces its DNS record).
6. Verify on the live hostname + in the SPA, then close the firewall's public
   inbound on the API box.

## Rollback

Point `api-midwesternlearning.midwesternog.com` back at the origin (remove the
Worker custom domain / restore the old DNS record) and reopen the firewall.

## Files

- `wrangler.jsonc` — Worker config: VPC binding, route, `ORIGIN_HOST`.
- `src/index.ts` — the reverse proxy.
