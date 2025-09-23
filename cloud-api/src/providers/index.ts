import type { BuildProvider } from './types.js';
import createVercel from './vercel.js';
import createNetlify from './netlify.js';

export function getProvider(name: string | undefined): BuildProvider {
  const key = (name || '').toLowerCase();
  switch (key) {
    case 'vercel':
      return createVercel();
    case 'netlify':
      return createNetlify();
    default:
      return createVercel();
  }
}

