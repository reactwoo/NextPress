import type { BuildProvider, CreateDeploymentResult } from './types.js';

export class VercelProvider implements BuildProvider {
  async createDeployment(payload: any): Promise<CreateDeploymentResult> {
    // TODO: integrate Vercel API in Phase 2 proper
    const slug = (payload?.pages?.[0]?.slug || 'site') as string;
    return {
      deployUrl: `https://example-${slug}.vercel.app/`,
      logsUrl: `https://vercel.com/your-org/your-project/deployments`,
      providerDeploymentId: 'vercel_dummy_1'
    };
  }
}

export default function createVercel(): BuildProvider {
  return new VercelProvider();
}

