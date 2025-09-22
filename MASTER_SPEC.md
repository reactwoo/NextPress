## Elementor → Next.js Exporter
**Master Specification & Development Blueprint**

---

## 1. Product Concept

We are building a system that converts **Elementor-built WordPress sites** into **Next.js frontends** for speed, SEO, and scalability.

It consists of three parts:

1) **WordPress Plugin (Exporter)**
   - Runs on any hosting (shared, cPanel, managed WP)
   - Extracts Elementor data + site styles
   - Provides:
     - Local ZIP export (Next.js project)
     - Cloud Export (send payload to our API → auto-build)

2) **Cloud Service (API + Workers)**
   - Receives payloads from plugin
   - Maps Elementor → React components
   - Generates a Next.js repo
   - Runs builds (Vercel/Netlify/Cloudflare)
   - Logs + artifacts stored
   - Enforces license + quotas

3) **Client Dashboard (Frontend)**
   - Multi-tenant web UI for managing sites
   - Features: builds, quotas, deployments, widget coverage, billing
   - Agency mode: multiple sites, white-label, SLAs

---

## 2. System Architecture

```yaml
wordpress_plugin:
  extract:
    - elementor_json (_elementor_data)
    - elementor_css (_elementor_css)
    - global_styles (elementor_global_settings)
    - media_manifest (uploads dir)
  connect:
    - license_server: license.reactwoo.com
    - cloud_api: api.reactwoo.com
  modes:
    - local_zip_export
    - cloud_export
  ui:
    - export_wizard
    - build_status_view

cloud_api:
  stack: Node.js + Fastify + Prisma + Redis
  endpoints:
    - POST /v1/exports
    - GET /v1/exports/:id
    - POST /v1/hooks/content-updated
    - POST /v1/licenses/verify
    - POST /v1/providers/vercel/connect
    - POST /v1/providers/netlify/connect
    - GET /v1/widgets/supported
  services:
    - mapping_engine
    - repo_generator (Next.js project generator)
    - build_runner (Vercel/Netlify/Cloudflare)
    - quota_manager
    - fallback_renderer

nextjs_project:
  framework: next.js 14+ (App Router, ISR/SSG)
  styling: tailwindcss
  cms: wp-graphql (preferred) or REST
  features:
    - static_export
    - incremental_regeneration
    - query_loop
    - woocommerce_basic (phase 2)
    - fallback_components

dashboard:
  framework: next.js + tailwind
  features:
    - login + site management
    - builds + logs
    - deployments + domains
    - quotas + billing
    - widget matrix
    - support tickets
```

---

## 3. Tech Stack

### WordPress Plugin
- PHP 8.2+, WP REST API
- Elementor JSON parser
- Local export → ZIP (Next.js boilerplate)
- Cloud export → payload to API
- License verification via JWT

### Cloud Service
- Node.js 20+, Fastify or Express
- DB: MySQL (reuse cPanel) or Postgres (Neon/PlanetScale)
- ORM: Prisma
- Queue: Redis (Upstash free → ElastiCache later)
- Storage: S3-compatible (Wasabi/Backblaze B2)
- Builds: Vercel/Netlify API
- Auth: JWT + HMAC

### Frontends
- Next.js 14+ (App Router)
- TailwindCSS
- next/image (media proxy)
- SEO: next-sitemap, next-seo
- Data: WPGraphQL → SSG/ISR

### Dashboard
- Next.js SaaS frontend
- Multi-tenant auth (NextAuth or custom JWT)
- Stripe billing (subscription + overage)
- Agency features: multi-site, white-label

---

## 4. Hosting & Cost Strategy

### Phase 1 (Low-cost MVP)
- WP stays where it is (shared/cPanel)
- License API on same cPanel Node (Passenger)
- Builds on Vercel Free/Hobby
- Frontends hosted on Vercel Free
- Queue: Upstash Redis free
- CDN: Cloudflare Free

### Phase 2 (Balanced)
- API → Fly.io/Render small instance
- DB → PlanetScale/Neon free tier
- Storage → Backblaze B2
- Frontends → Vercel Pro (passed to clients)

### Phase 3 (Scalable)
- API → AWS Lightsail/ECS
- DB → Aurora/PlanetScale Pro
- Queue → Redis/SQS
- Storage → S3 + CloudFront
- CDN → Cloudflare + custom rules

---

## 5. Deployment Strategy (Vercel & Netlify)

### 5.1 Vercel

**Option 1 — User-owned**
- Users create Vercel account and connect via OAuth
- Builds pushed into their projects; they own billing and domains
- ✅ No infra cost for us; ❌ Some friction for non-technical users

**Option 2 — Managed Org (our account)**
- All projects under our Vercel org; users point domains
- ✅ Seamless UX, recurring revenue; ❌ We absorb infra cost

**Option 3 — Hybrid/Affiliate**
- Guide users to sign up via affiliate link; integrate builds
- ✅ Earn affiliate + SaaS revenue; ❌ Some friction remains

**Recommendation**
- Phase 1: Option 1
- Phase 2: Option 2 as Pro/Agency tier
- Phase 3: Add Option 3 affiliate

### 5.2 Netlify

**Option A — User-owned**
- Users connect their Netlify account via OAuth
- We create sites via API, link to generated repos (Git or direct deploy)
- ✅ Low cost for us; ❌ User setup friction (environment vars, domains)

**Option B — Managed Team (our team)**
- All sites under our Netlify team; custom domains delegated by users
- ✅ Smooth UX, central control; ❌ We pay Netlify usage; need quotas

**Option C — Hybrid/Affiliate**
- Encourage users to create their own team via our link; we provision
- ✅ Affiliate + SaaS; ❌ Mixed ownership complexity

**Provider Abstractions**
- Standardize build interface: `createProject`, `createDeployment`, `getLogs`, `setEnv`
- Support both providers with feature flags (preview URLs, edge functions)

---

## 6. User Flows

### Local Export
1. Install plugin
2. Extract Elementor JSON + styles
3. Generate Next.js project ZIP
4. User deploys manually

### Cloud Export
1. Install plugin → license connect
2. Select pages → export
3. Payload → API → build on Vercel/Netlify
4. Status → plugin + dashboard
5. Sync via webhook on content change

### Dashboard
- Connect sites
- Manage builds + deployments
- View quotas + billing
- Check widget support
- Request custom mappings

---

## 7. Data Contracts

### Export Payload
```json
{
  "site": {
    "id": "wp_123",
    "url": "https://example.com",
    "graphql": "https://example.com/graphql"
  },
  "pages": [
    {
      "id": 123,
      "slug": "home",
      "elementor_data": { "...": "..." },
      "elementor_css": ".css-hash{...}",
      "meta": { "template": "default" }
    }
  ],
  "globals": {
    "colors": [{ "name": "Primary", "value": "#37b0d2" }],
    "fonts": { "body": "Inter", "heading": "Inter" }
  },
  "media_manifest": [
    { "url": "https://example.com/wp-content/uploads/hero.jpg", "hash": "sha256:..." }
  ],
  "build": { "provider": "vercel", "mode": "ssg", "revalidate": 60 }
}
```

### Build Status
```json
{
  "export_id": "exp_456",
  "status": "success|failed|running",
  "logs_url": "https://api.reactwoo.com/logs/exp_456",
  "deploy_url": "https://frontend.vercel.app"
}
```

---

## 8. Widget Coverage (MVP Matrix)

| Elementor Widget   | Next.js Mapping                |
|--------------------|--------------------------------|
| Section/Container  | <div class="container">        |
| Column             | <div class="flex-col">         |
| Heading            | <h2>                           |
| Text Editor        | <p>                            |
| Image              | <Image />                      |
| Button             | <Button />                     |
| Icon Box           | <Card />                       |
| Divider/Spacer     | <hr /> / <Spacer />            |
| Tabs/Accordion     | <Tabs /> / <Accordion />       |
| Query Loop         | <Posts /> via GraphQL          |

Unsupported → `<UnsupportedWidget note="..." />`

---

## 9. Risks & Mitigations

| Risk                                 | Mitigation                                             |
|--------------------------------------|--------------------------------------------------------|
| Unsupported widgets break builds      | Fallback placeholders + upsell custom mapping         |
| Cheaper alternatives (caching)        | Market as “Headless managed service” (SEO, security)  |
| Build failures                        | Log + retry + SLA for Pro tiers                       |
| Infra costs grow                      | Quotas, overage billing, agency pricing               |

---

## 10. Development Roadmap

### Phase 1 (MVP – Local Exporter)
- [ ] WP plugin: parse Elementor JSON
- [ ] Map core widgets (section, column, text, image, button)
- [ ] Tailwind config generator
- [ ] Generate Next.js repo ZIP

### Phase 2 (Cloud Export – Pro)
- [ ] API endpoints: /v1/exports, /v1/licenses/verify
- [ ] Build runner → Vercel/Netlify API
- [ ] Plugin: send payload + show status
- [ ] Dashboard: login, connect site, build list

### Phase 3 (Growth Features)
- [ ] Query Loop → WPGraphQL
- [ ] WooCommerce basics
- [ ] Forms integration
- [ ] Add-on packs: Woo, Marketing, Membership
- [ ] Agency: multi-site, white-label

### Phase 4 (Enterprise)
- [ ] Custom widget mapping workflow
- [ ] Managed hosting tier (Vercel org/Netlify team)
- [ ] Advanced telemetry/logging
- [ ] SLA + enterprise support

---

## 11. Suggested Repo Structure

```text
repo-root/
  plugin/                      # WordPress plugin (PHP)
    src/
    includes/
    assets/
    elementor/
    composer.json
    readme.txt

  cloud-api/                   # Node.js API + workers
    src/
      api/
      services/
      workers/
      prisma/
      providers/
        vercel/
        netlify/
    prisma/schema.prisma
    package.json
    tsconfig.json
    Dockerfile

  dashboard/                   # Multi-tenant SaaS frontend
    app/
    components/
    lib/
    package.json
    next.config.mjs
    tailwind.config.ts

  nextjs-template/             # Generated project boilerplate
    app/
      (pages)/
      components/
      layouts/
    lib/
    package.json
    next.config.mjs
    tailwind.config.ts

  tooling/
    scripts/
    ci/

  .github/
    workflows/

  README.md
  LICENSE
```

### Notes
- `cloud-api/providers/*` encapsulates build/deploy provider logic via a common interface
- `nextjs-template/` is the scaffold used by exporter to generate per-site repos
- Shared type definitions: publish a small `@reactwoo/contracts` package or `tooling/contracts/`

---

## 12. Comparison & Alignment

This consolidated document combines the concept overview, architecture, tech stack, deployment strategies (now including Netlify alongside Vercel), user flows, data contracts, widget coverage, risks, and roadmap, plus a concrete repo structure so engineering can scaffold immediately.

