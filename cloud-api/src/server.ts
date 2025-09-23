import Fastify from 'fastify';
import cors from '@fastify/cors';
import helmet from '@fastify/helmet';
import { licenseAuth } from './middleware/licenseAuth.js';
import exportsRoutes from './routes/exports.js';

const app = Fastify({ logger: true });

await app.register(cors, { origin: true });
await app.register(helmet);

// Health should be public
app.get('/health', async () => ({ ok: true }));

// Auth middleware for API routes
app.addHook('onRequest', async (request, reply) => {
  if (request.raw.url?.startsWith('/health')) return;
  if (request.method === 'OPTIONS') return;
  await licenseAuth(request, reply);
});

await app.register(exportsRoutes, { prefix: '/v1' });

const port = Number(process.env.PORT || 3001);
const host = process.env.HOST || '0.0.0.0';

app
  .listen({ port, host })
  .then((addr) => app.log.info({ addr }, 'cloud api listening'))
  .catch((err) => {
    app.log.error(err, 'failed to start server');
    process.exit(1);
  });

