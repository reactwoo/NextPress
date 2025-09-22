import data from '../exportData.json';

export default function Page({ params }: { params: { slug: string } }) {
  const pages = (data as any).pages || [];
  const page = pages.find((p: any) => p.slug === params.slug);
  if (!page) return <div style={{ padding: 24 }}>Not found</div>;
  return (
    <main style={{ padding: 24 }}>
      <h1>{page.title || page.slug}</h1>
      <div dangerouslySetInnerHTML={{ __html: page.content || '' }} />
    </main>
  );
}

export function generateStaticParams() {
  const pages = (data as any).pages || [];
  return pages.map((p: any) => ({ slug: p.slug }));
}
