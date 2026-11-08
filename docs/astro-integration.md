# Astro連携

CMSはQiita互換の変換前Markdownを返します。Astro側で `remark-gfm`、`remark-math`、`rehype-katex` などを使ってHTMLと数式を生成してください。

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
    bodyMarkdown: z.string().nullable(),
    markdownFlavor: z.literal('qiita'),
    passwordProtected: z.boolean(),
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
    markdownFlavor: z.literal('qiita'),
    publishedAt: z.string(),
    updatedAt: z.string(),
  }),
});

export const collections = { posts, pages };
```

## 限定公開記事

一覧APIでは限定公開記事の`passwordProtected`が`true`、`bodyMarkdown`が`null`になります。限定本文を静的HTMLへ含めると保護できないため、対象ページはAstro SSRまたはサーバー側APIルートで動的に取得してください。読者が入力したパスワードをブラウザへ保存せず、CMSへHTTPSで送信します。

```ts
const response = await fetch(`${import.meta.env.CMS_API_URL}/api/v1/posts/${slug}`, {
  headers: { 'X-Content-Password': password },
  cache: 'no-store',
});

if (response.status === 401) {
  return { passwordRequired: true };
}
if (response.status === 429) {
  throw new Error('試行回数が多すぎます。時間を置いて再試行してください。');
}
if (!response.ok) throw new Error(`CMS API: ${response.status}`);

const { data: post } = await response.json();
```

コメントの取得・投稿にも同じ`X-Content-Password`ヘッダーが必要です。パスワードはURL、ログ、静的生成物、クライアント用環境変数へ含めないでください。

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

```bash
npm install remark-gfm remark-math rehype-katex katex
```

```js
import { defineConfig } from 'astro/config';
import remarkGfm from 'remark-gfm';
import remarkMath from 'remark-math';
import rehypeKatex from 'rehype-katex';

export default defineConfig({
  markdown: { remarkPlugins: [remarkGfm, remarkMath], rehypePlugins: [rehypeKatex] },
});
```

Qiita Note（`:::note`）、Mermaid、脚注、CMSボタンは、公開サイト側でも対応するremarkプラグインまたは独自変換を追加してください。安全性を保つため、CMSが禁止している生HTMLを有効化する`rehype-raw`は使用しないでください。

## GitHub Actionsによる自動更新

Astroのデプロイワークフローで、CMSから送られるイベントを受信します。

```yaml
on:
  repository_dispatch:
    types: [cms-published]
```

CMSの本番`.env`へ、対象リポジトリだけに限定したFine-grained personal access tokenを設定します。

```dotenv
GITHUB_DEPLOY_TOKEN=github_pat_xxxxxxxxx
GITHUB_DEPLOY_OWNER=S-Akinori
GITHUB_DEPLOY_REPOSITORY=physics-setup-blog
GITHUB_DEPLOY_EVENT_TYPE=cms-published
```

記事やカテゴリーの保存だけではビルドしません。管理画面ヘッダーの「ブログを更新」を押すと、CMSは`POST /repos/{owner}/{repository}/dispatches`を呼び出します。既存のGitHub ActionsがAstroを再ビルドしてXServerへ配置します。複数の記事を続けて編集する場合は、最後に1回だけ更新ボタンを押してください。

トークンには対象リポジトリの`Contents: Read and write`だけを付与し、ブラウザ、Astroの公開環境変数、Git管理ファイルへ露出させないでください。`repository_dispatch`を受信するワークフローは、リポジトリのデフォルトブランチに存在する必要があります。

## 下書きプレビュー

CMSの「設定」で、Astro側に用意したプレビューページのURLを登録します。編集画面の「プレビュー」を押すと、Astroへ`cms_preview_url`、`slug`、`type`が渡されます。

Astroのプレビューページでは、クライアント側で`cms_preview_url`を取得してCMSへリクエストします。

```html
<script>
  const status = document.querySelector('[data-preview-status]');
  const params = new URLSearchParams(window.location.search);
  const contentUrl = params.get('cms_preview_url');

  try {
    if (!contentUrl) throw new Error('プレビューURLが指定されていません。');
    const response = await fetch(contentUrl, { cache: 'no-store' });
    if (!response.ok) throw new Error('プレビューURLが無効、または期限切れです。');
    const payload = await response.json();
    // payload.data.bodyMarkdownを通常の記事と同じ安全な処理で描画する
    status.textContent = payload.data.title;
  } catch (error) {
    status.textContent = error instanceof Error ? error.message : 'プレビューを取得できませんでした。';
  }
</script>
```

署名付きURLの有効期限は15分です。APIは下書きや限定公開本文を返すため、URLをログへ残したり第三者へ共有したりせず、HTMLの生成時にも生HTMLを許可しないでください。未保存の編集内容は含まれません。
