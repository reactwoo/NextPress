## NextPress Cloud Hosting integrations

This document specifies the "Connect Hosting" flows, OAuth scopes, and deploy APIs for supported providers. The plugin will defer user authorization to `server.reactwoo.com`, which securely stores provider tokens and performs provisioning/deployments.

### Providers and scopes
- Vercel
  - Scopes: `read:projects`, `write:projects`, `read:deployments`, `write:deployments`, `read:env`, `write:env`
  - APIs: Projects, Deployments, Env Vars, Domains
- Cloudflare (Pages + Workers)
  - Scopes: `Pages:Edit`, `Workers Scripts:Edit`, optional `KV:Edit`, `R2:Read/Write`, `D1:Edit`
  - APIs: Pages Projects/Deployments, Workers (for Edge SSR), KV/R2/D1 for storage
- Netlify
  - Scopes: `sites`, `deploys`, `env`, `hooks`
  - APIs: Sites, Deploys, Build Hooks, Env Vars

### Connect flow (high-level)
1) In WP Admin → Static Builder, user picks a hosting provider and clicks "Connect".
2) Browser opens:
   `https://server.reactwoo.com/connect?provider={provider}&site={home_url}&installId={installId}&return={admin_url}`
3) User authorizes provider (OAuth or API token). Server stores token mapped to `{installId, site}`.
4) Server redirects back to `return` URL. Plugin can optionally poll status later.

### Provisioning (server side reference)
- Create project/app/site
- Set env vars from WordPress (e.g., PUBLIC_BASE_URL, BYPASS_PARAM)
- Configure build command and output
- Configure custom domains (optional)
- For Edge SSR: create Worker/Function as needed

### Deploy (server → provider)
- Vercel: create deployment via Deployments API (git or upload)
- Cloudflare Pages: trigger deployment via Pages API; for SSR, publish Worker
- Netlify: trigger via Build Hook or API, set env vars via API first

### Webhooks and status
- Subscribe to provider deploy webhooks; post status to plugin via existing webhook URL or poll.
- Recommended event payload (to plugin webhook):
  `{ "event":"deploy.status", "provider":"vercel|cloudflare|netlify", "status":"success|failed|in_progress", "commit":"...", "url":"https://..." }`

### Entitlements (from license server)
- `hostingProvider`: vercel|cloudflare|netlify
- `buildsPerMonth`, `deploysPerMonth`, `edgeInvocations`, `bandwidthGB`
- Enforce via usage metering endpoints; warn at 80%, cap at 100% (soft-fail with upgrade link)

