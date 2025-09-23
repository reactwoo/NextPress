import type { FastifyInstance, FastifyReply, FastifyRequest } from 'fastify';
import { randomUUID } from 'uuid';
import { getProvider } from '../providers/index.js';

type ExportStatus = 'running' | 'success' | 'failed';

interface ExportRecord {
  id: string;
  status: ExportStatus;
  createdAt: number;
  payload?: any;
  deployUrl?: string;
  logsUrl?: string;
  error?: string;
}

const exportsStore: Map<string, ExportRecord> = new Map();

export default async function exportsRoutes(app: FastifyInstance) {
  app.post('/exports', async (request: FastifyRequest, reply: FastifyReply) => {
    const body = request.body as any;
    if (!body || typeof body !== 'object') {
      reply.code(400).send({ error: 'invalid_payload' });
      return;
    }

    const id = 'exp_' + randomUUID();
    const record: ExportRecord = { id, status: 'running', createdAt: Date.now(), payload: body };
    exportsStore.set(id, record);

    // Simulate async build with provider stub
    const providerName = body?.build?.provider || 'vercel';
    const provider = getProvider(providerName);
    setTimeout(async () => {
      try {
        const res = await provider.createDeployment(body);
        record.status = 'success';
        record.deployUrl = res.deployUrl;
        record.logsUrl = res.logsUrl;
      } catch (err: any) {
        record.status = 'failed';
        record.error = err?.message || 'build_failed';
      }
    }, 500);

    reply.code(202).send({ id });
  });

  app.get('/exports/:id', async (request: FastifyRequest, reply: FastifyReply) => {
    const params = request.params as any;
    const id = params.id as string;
    const rec = exportsStore.get(id);
    if (!rec) {
      reply.code(404).send({ error: 'not_found' });
      return;
    }
    reply.send({
      export_id: rec.id,
      status: rec.status,
      logs_url: rec.logsUrl,
      deploy_url: rec.deployUrl
    });
  });
}

