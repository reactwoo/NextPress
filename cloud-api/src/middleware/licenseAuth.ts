import type { FastifyReply, FastifyRequest } from 'fastify';
import { createRemoteJWKSet, jwtVerify } from 'jose';

let jwks: ReturnType<typeof createRemoteJWKSet> | null = null;

function getAuthorizationToken(request: FastifyRequest): string | null {
  const header = request.headers['authorization'] || request.headers['Authorization'];
  if (!header || Array.isArray(header)) return null;
  const parts = header.split(' ');
  if (parts.length === 2 && parts[0].toLowerCase() === 'bearer') {
    return parts[1];
  }
  return null;
}

export async function licenseAuth(request: FastifyRequest, reply: FastifyReply) {
  const token = getAuthorizationToken(request);
  if (!token) {
    reply.code(401).send({ error: 'missing_authorization' });
    return reply;
  }

  const jwksUrl = process.env.LICENSE_JWKS_URL || 'https://license.reactwoo.com/.well-known/jwks.json';
  const audience = process.env.LICENSE_AUDIENCE || 'reactwoo-cloud-api';
  const issuer = process.env.LICENSE_ISSUER || 'https://license.reactwoo.com/';

  try {
    if (!jwks) {
      jwks = createRemoteJWKSet(new URL(jwksUrl));
    }
    const { payload } = await jwtVerify(token, jwks, { audience, issuer });
    // Attach claims for downstream usage
    (request as any).license = payload;
  } catch (err) {
    request.log.warn({ err }, 'license verification failed');
    reply.code(401).send({ error: 'invalid_license' });
    return reply;
  }
}

