import React from 'react';

type ElementorNode = any;

function safeArray<T = any>(value: any): T[] {
  return Array.isArray(value) ? (value as T[]) : [];
}

function getSetting<T = any>(node: ElementorNode, key: string, fallback?: T): T | undefined {
  if (!node || typeof node !== 'object') return fallback;
  const settings = (node as any).settings || {};
  return (settings[key] as T) ?? fallback;
}

function renderWidget(node: ElementorNode, key?: React.Key): React.ReactNode {
  const widgetType = (node?.widgetType || '').toLowerCase();
  switch (widgetType) {
    case 'heading': {
      const title = getSetting<string>(node, 'title', '') || '';
      const tag = (getSetting<string>(node, 'title_tag', 'h2') || 'h2').toLowerCase();
      const Tag: any = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'].includes(tag) ? tag : 'h2';
      return (
        <Tag key={key} className="text-2xl font-semibold mb-3">
          {title}
        </Tag>
      );
    }
    case 'text-editor': {
      const html = getSetting<string>(node, 'editor', '') || '';
      return (
        <div key={key} className="mb-4" dangerouslySetInnerHTML={{ __html: html }} />
      );
    }
    case 'image': {
      const image = getSetting<any>(node, 'image', {});
      const url = image?.url || '';
      const alt = image?.alt || '';
      if (!url) return null;
      return (
        <img key={key} src={url} alt={alt} className="max-w-full h-auto mb-4" />
      );
    }
    case 'button': {
      const text = getSetting<string>(node, 'text', 'Click');
      const link = getSetting<any>(node, 'link', {});
      const href = typeof link === 'string' ? link : (link?.url || '#');
      const isExternal = !!(link?.is_external);
      const rel = isExternal ? 'noopener noreferrer' : undefined;
      const target = isExternal ? '_blank' : undefined;
      return (
        <a
          key={key}
          href={href}
          target={target}
          rel={rel}
          className="inline-block bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded mb-4"
        >
          {text}
        </a>
      );
    }
    case 'divider':
    case 'spacer': {
      return <div key={key} className="my-4" />;
    }
    default: {
      const label = widgetType || 'unknown';
      return (
        <div key={key} className="border border-dashed border-gray-300 text-gray-500 text-sm p-2 my-2">
          Unsupported widget: {label}
        </div>
      );
    }
  }
}

function renderNode(node: ElementorNode, key?: React.Key): React.ReactNode {
  if (!node || typeof node !== 'object') return null;
  const elType = (node.elType || '').toLowerCase();

  if (elType === 'section') {
    const children = safeArray(node.elements).map((child: any, idx: number) => renderNode(child, idx));
    return (
      <section key={key} className="container mx-auto px-4 py-6">
        {children}
      </section>
    );
  }

  if (elType === 'column') {
    const children = safeArray(node.elements).map((child: any, idx: number) => renderNode(child, idx));
    return (
      <div key={key} className="flex flex-col gap-4">
        {children}
      </div>
    );
  }

  if (elType === 'widget') {
    return renderWidget(node, key);
  }

  // Fallback for unexpected nodes
  const children = safeArray(node.elements).map((child: any, idx: number) => renderNode(child, idx));
  return (
    <div key={key}>
      {children}
    </div>
  );
}

export function renderElementorTree(nodes: ElementorNode[]): React.ReactNode {
  return <>{safeArray(nodes).map((n, i) => renderNode(n, i))}</>;
}

export default renderElementorTree;

