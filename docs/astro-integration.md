# Astro連携

CMSは変換前のMarkdownを返します。Astro側で `remark-math` と `rehype-katex` を使ってHTMLと数式を生成してください。

```ts
// src/content.config.ts
import { defineCollection, z } from 'astro:content';

const posts = defineCollection({
  loader: async () => {
    const response = await fetch(`${import.meta.env.CMS_API_URL}/api/v1/posts?limit=100`);
    if (!response.ok) throw new Error(`CMS API: ${response.status}`);
    const payload = await response.json();
    return payload.data.map((post: any) => ({ id: post.id, ...post }));
  },
  schema: z.object({
    slug: z.string(),
    title: z.string(),
    description: z.string().nullable(),
    bodyMarkdown: z.string(),
    publishedAt: z.string(),
    updatedAt: z.string(),
    tags: z.array(z.object({ name: z.string(), slug: z.string() })),
  }),
});

const pages = defineCollection({
  loader: async () => {
    const response = await fetch(`${import.meta.env.CMS_API_URL}/api/v1/pages?limit=100`);
    if (!response.ok) throw new Error(`CMS API: ${response.status}`);
    const payload = await response.json();
    return payload.data.map((page: any) => ({ id: page.id, ...page }));
  },
  schema: z.object({
    slug: z.string(),
    title: z.string(),
    description: z.string().nullable(),
    bodyMarkdown: z.string(),
    publishedAt: z.string(),
    updatedAt: z.string(),
  }),
});

export const collections = { posts, pages };
```

## コメントAPI

公開済みコメントの取得：

```ts
const response = await fetch(`${import.meta.env.CMS_API_URL}/api/v1/posts/${slug}/comments`);
const { data: comments } = await response.json();
```

コメント投稿は同じURLへJSONをPOSTします。投稿直後は必ず承認待ちになり、管理画面で承認したものだけ取得APIに現れます。

```ts
await fetch(`${import.meta.env.CMS_API_URL}/api/v1/posts/${slug}/comments`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    authorName: '表示名',
    authorEmail: 'reader@example.com',
    authorUrl: '',
    body: 'コメント本文',
    parentId: null,
    company: '', // スパム対策用のハニーポット。画面では非表示にします
  }),
});
```

固定ページでは `/api/v1/pages/{slug}/comments` を使用します。メールアドレスは管理画面だけで扱われ、公開APIからは返されません。フロント側ではコメント本文をHTMLとして挿入せず、テキストとしてエスケープ表示してください。

`astro.config.mjs` のMarkdown設定例：

```js
import { defineConfig } from 'astro/config';
import remarkMath from 'remark-math';
import rehypeKatex from 'rehype-katex';

export default defineConfig({
  markdown: { remarkPlugins: [remarkMath], rehypePlugins: [rehypeKatex] },
});
```

公開Webhookにはリポジトリ専用の最小権限トークンを使い、ブラウザへ露出させないでください。
