export interface CreateDeploymentResult {
  deployUrl: string;
  logsUrl?: string;
  providerDeploymentId?: string;
}

export interface BuildProvider {
  createDeployment(payload: any): Promise<CreateDeploymentResult>;
}

