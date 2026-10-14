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

export const collections = { posts };
```

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
