import type { BuildProvider, CreateDeploymentResult } from './types.js';

export class NetlifyProvider implements BuildProvider {
  async createDeployment(payload: any): Promise<CreateDeploymentResult> {
    const slug = (payload?.pages?.[0]?.slug || 'site') as string;
    return {
      deployUrl: `https://example-${slug}.netlify.app/`,
      logsUrl: `https://app.netlify.com/sites/example/deploys`,
      providerDeploymentId: 'netlify_dummy_1'
    };
  }
}

export default function createNetlify(): BuildProvider {
  return new NetlifyProvider();
}

