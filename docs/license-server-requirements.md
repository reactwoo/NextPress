## License server requirements for Cloud Hosting

Purpose: enable Cloud Hosting monetization tiers while keeping the plugin thin. Token storage and provisioning live on `server.reactwoo.com`.

### New/expanded entities
- License entitlements: `hostingProvider`, `addons[]`, `quotas{ buildsPerMonth, deploysPerMonth, edgeInvocations, bandwidthGB }`, `siteLimits{ prodSites, devStagingFree }`
- Activations: map `licenseId ↔ (installId, domain, environment)`
- Provider credentials: `{ installId, provider, accountRef, tokenRef }` (server-side only)

### API (server)
- POST `/api/v1/hosting/connect/start` → returns provider auth URL
- GET `/api/v1/hosting/connect/callback` → finalize, store token, redirect to `return`
- POST `/api/v1/hosting/provision` `{ installId, provider, env, settings }`
- POST `/api/v1/hosting/deploy` `{ installId, provider, projectRef, branch|artifact }`
- POST `/api/v1/usage/increment` on build/deploy/edge events
- Webhooks to plugin: `deploy.status`, `license.updated`

### JWT fields (plugin consumption)
```
{
  "hostingProvider": "cloudflare",
  "quotas": {"buildsPerMonth": 500, "deploysPerMonth": 100, "edgeInvocations": 5000, "bandwidthGB": 200},
  "features": ["core.pro", "module.events"],
  "addons": ["module.events"],
  "siteLimits": {"prodSites": 5, "devStagingFree": true}
}
```

### Security
- HMAC on plugin→server requests; JWT for entitlements with `kid` and rotation
- Idempotency keys for usage increments
- Rate limits per license and IP

### Billing
- Sync payment events from Stripe/Woo Subscriptions → `license.updated`
- Proration and immediate entitlements update on upgrades

