# SetUp CMS 構築・移行・運用マニュアル

SetUp CMSは、Laravelで動作する小規模なヘッドレスCMSです。記事と固定ページはMarkdownで保存し、AstroなどのフロントエンドへJSON APIで提供します。管理画面のエディタ、カテゴリー管理、コメント管理、画像管理、リビジョン、予約公開、LaTeX記法、WordPress移行機能を備えています。

この文書では、ローカル環境での確認からXServerへの設置、WordPress記事と画像の移行、Astroとの連携、公開後の保守までを説明します。

## 目次

- [システム構成](#システム構成)
- [必要環境](#必要環境)
- [ローカル環境の構築](#ローカル環境の構築)
- [XServerへ設置する](#xserverへ設置する)
- [WordPressから移行する](#wordpressから移行する)
- [Astroと連携する](#astroと連携する)
- [日常運用](#日常運用)
- [更新・バックアップ・復元](#更新バックアップ復元)
- [セキュリティ確認](#セキュリティ確認)
- [トラブルシューティング](#トラブルシューティング)
- [コマンド一覧](#コマンド一覧)

## システム構成

```text
編集者
  └─ HTTPS ─ SetUp CMS管理画面（Laravel / XServer）
                 ├─ MySQL: 記事・分類・ユーザー・リビジョン
                 ├─ storage: アップロード画像
                 └─ JSON API ─ Astro ─ 公開サイト
```

以下ではCMSを`https://cms.example.com`へ設置するものとして説明します。

| 用途 | URL |
| --- | --- |
| 管理画面 | `https://cms.example.com/admin` |
| ログイン | `https://cms.example.com/login` |
| 記事一覧API | `https://cms.example.com/api/v1/posts` |
| 記事詳細API | `https://cms.example.com/api/v1/posts/{slug}` |
| 固定ページ一覧API | `https://cms.example.com/api/v1/pages` |
| 固定ページ詳細API | `https://cms.example.com/api/v1/pages/{slug}` |
| 記事コメントAPI | `https://cms.example.com/api/v1/posts/{slug}/comments` |
| 固定ページコメントAPI | `https://cms.example.com/api/v1/pages/{slug}/comments` |
| 分類API | `https://cms.example.com/api/v1/taxonomies` |
| ヘルスチェック | `https://cms.example.com/up` |

本文はMarkdownのままAPIから返されます。HTML化、デザイン、LaTeXの描画はAstro側で行います。

## 必要環境

- PHP 8.3以上（XServerではPHP 8.4を推奨）
- MySQLまたはMariaDB（ローカル開発だけSQLiteも利用可能）
- Git（XServerへのclone・更新に使用）
- Composer 2
- Node.js 20以上（フロントアセットのビルド時のみ）
- PHP拡張: Ctype、cURL、DOM、Fileinfo、Filter、GD、Hash、Mbstring、OpenSSL、PCRE、PDO、Session、Tokenizer、XML、XMLReader

PHP要件は[Laravel公式デプロイガイド](https://laravel.com/framework/docs/deployment)も参照してください。

## ローカル環境の構築

### 1. PHPを確認する

```powershell
php -v
php -m
composer --version
node --version
npm --version
```

PHP 8.3未満の場合はPHPを更新し、新しいPHPフォルダをWindowsのシステム環境変数`Path`で古いPHPより上へ移動します。PowerShellを開き直して確認します。

```powershell
where.exe php
php -v
```

### 2. 依存関係と環境設定

```powershell
cd C:\Users\aknr1\MyApps\MyCMS
composer install
npm ci
Copy-Item .env.example .env
php artisan key:generate
```

ローカルの`.env`例：

```dotenv
APP_NAME="SetUp CMS"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000
APP_TIMEZONE=Asia/Tokyo

DB_CONNECTION=sqlite
DB_DATABASE=C:/Users/aknr1/MyApps/MyCMS/database/database.sqlite

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
SESSION_SECURE_COOKIE=false
```

`DB_DATABASE`は絶対パスを推奨します。

```powershell
New-Item database\database.sqlite -ItemType File -Force
php artisan migrate
php artisan cms:create-admin admin@example.com
npm run build
php artisan test
php -d upload_max_filesize=512M -d post_max_size=565M -d max_execution_time=600 -d max_input_time=600 artisan serve
```

管理者パスワードは8文字以上です。コマンド履歴へ残さないため、通常は`--password`を付けず対話入力します。上記のPHPオプションは、最大50MBのWordPress WXR/XMLと最大512MBの画像ZIPを管理画面から取り込むためのローカル開発用設定です。起動後、`http://127.0.0.1:8000/login`を開きます。

## XServerへ設置する

### 配置方針

Laravel本体と`.env`は公開しません。XServerの`public_html`には、Laravelの`public`ディレクトリの内容だけを置きます。XServerでは`public_html`が公開領域です。[XServer公式のフォルダ説明](https://www.xserver.ne.jp/support/faq/service_ftp_public_html_folder.php)でも、非公開ファイルは`public_html`より上へ置くことが案内されています。

```text
/home/サーバーID/
├─ setup-cms/                                  # Gitで管理するLaravel本体・非公開
│  ├─ app/  bootstrap/  config/  storage/  vendor/
│  ├─ .env
│  └─ artisan
└─ example.com/public_html/cms.example.com/    # Web公開領域
   ├─ index.php
   ├─ .htaccess
   ├─ .user.ini
   ├─ build/
   └─ storage -> /home/サーバーID/setup-cms/storage/app/public
```

次の値は自分の契約内容へ置き換えてください。

| 表記 | 内容 |
| --- | --- |
| `サーバーID` | XServerのサーバーID |
| `example.com` | 所有ドメイン |
| `cms.example.com` | CMS用サブドメイン |
| `MYSQLホスト名` | 「MySQL情報」に表示される値 |
| `PHP_BIN` | 「コマンドパス一覧」に表示されるPHP 8.4 |
| `COMPOSER_BIN` | `command -v composer`で確認したComposer本体のパス |

### 1. サブドメイン、SSL、PHPを設定する

サーバーパネルで次を行います。

1. 「サブドメイン設定」から`cms.example.com`を追加
2. 無料独自SSLを有効化
3. 「PHP Ver.切替」でPHP 8.4を選択

サブドメインの公開先は通常`/example.com/public_html/cms.example.com`です。実際の配置先は[XServer公式のサブドメイン設定](https://www.xserver.ne.jp/manual/man_domain_subdomain_setting.php)、PHP操作は[XServer公式のPHPバージョン切替](https://www.xserver.ne.jp/manual/man_program_php_ver.php)で確認してください。

### 2. SSHを有効にする

サーバーパネルの「SSH設定」で公開鍵を登録します。XServerのSSHは公開鍵認証、ポート`10022`です。詳細は[XServer公式のSSH設定](https://www.xserver.ne.jp/manual/man_server_ssh.php)を参照してください。

```powershell
ssh -p 10022 -i C:\path\to\private_key サーバーID@サーバーID.xsrv.jp
```

SSH接続後、「サーバー情報 → コマンドパス一覧」にあるPHP 8.4を確認します。

```bash
/usr/bin/php8.4 -v
/usr/bin/php8.4 -m
PHP_BIN=/usr/bin/php8.4
COMPOSER_BIN=$(command -v composer)
"$PHP_BIN" "$COMPOSER_BIN" --version
```

サーバーパネルでPHP 8.4へ切り替えても、SSHで`php -v`を実行した際の既定バージョンは別管理のため古いことがあります。ComposerとArtisanは、以降必ず`$PHP_BIN`を付けて実行します。実際のPHPパスが異なる場合は「サーバー情報 → コマンドパス一覧」の値へ置き換えてください。

### 3. MySQLを作る

「MySQL設定」で次を行います。

1. `UTF-8（utf8mb4）`のデータベースを追加
2. CMS専用MySQLユーザーを追加
3. データベースへユーザーのアクセス権を付与
4. データベース名、ユーザー名、パスワード、ホスト名を記録

名前には通常`サーバーID_`が付きます。`DB_HOST`へ`localhost`を決め打ちせず、「MySQL情報」のホスト名を設定してください。詳しくは[XServer公式のMySQL設定](https://www.xserver.ne.jp/manual/man_db_setting.php)を参照してください。

### 4. GitHubと公開用ファイルを準備する

XServerではGitHubの`main`ブランチからコードを取得します。変更をXServer上で直接編集せず、ローカルで修正・テスト・pushしたものを`git pull`で反映する運用にします。

デプロイ前にローカルでビルドとテストを済ませます。

```powershell
cd C:\Users\aknr1\MyApps\MyCMS
npm ci
npm run build
php artisan test
```

`public/build`が生成されていることを確認します。

`public/build`、`.env`、`vendor`、`node_modules`、SQLite実データはGitの管理対象外です。ソースコードはGit、フロントアセットはビルド後にSCP/SFTP、秘密情報はXServer上の`.env`で管理します。

### 5. GitHubからcloneする

公開リポジトリの場合は、XServerへSSH接続して次を実行します。

```bash
cd /home/サーバーID
git --version
git clone --branch main --single-branch \
  https://github.com/S-Akinori/setup-cms.git setup-cms
cd /home/サーバーID/setup-cms
git config pull.ff only
git remote -v
git status
```

`git --version`が実行できない場合はXServerのサーバー情報を確認し、利用中サーバーでGitコマンドが使えるかXServerサポートへ確認してください。

#### 非公開リポジトリに変更する場合

GitHub用の読み取り専用Deploy Keyを使います。XServerへログインするためのSSH鍵とは別の鍵です。Deploy Keyは1つのリポジトリだけへアクセスさせられ、既定では読み取り専用です。詳細は[GitHub公式のDeploy Key管理](https://docs.github.com/en/authentication/connecting-to-github-with-ssh/managing-deploy-keys)を参照してください。

```bash
mkdir -p ~/.ssh
chmod 700 ~/.ssh
ssh-keygen -t ed25519 -f ~/.ssh/setup_cms_github -C "setup-cms-xserver"
cat ~/.ssh/setup_cms_github.pub
```

表示された公開鍵をGitHubリポジトリの「Settings → Deploy keys」へ登録します。書き込み権限は付けません。`~/.ssh/config`へ次を追加します。

```sshconfig
Host github-setup-cms
    HostName github.com
    User git
    IdentityFile ~/.ssh/setup_cms_github
    IdentitiesOnly yes
```

```bash
chmod 600 ~/.ssh/config ~/.ssh/setup_cms_github
ssh -T git@github-setup-cms
git clone --branch main --single-branch \
  git@github-setup-cms:S-Akinori/setup-cms.git setup-cms
```

秘密鍵、Personal Access Token、`.env`をリポジトリへコミットしないでください。

### 6. Composerとビルド済みアセットを配置する

cloneしたディレクトリで本番用PHP依存関係をインストールします。

```bash
cd /home/サーバーID/setup-cms
PHP_BIN=/usr/bin/php8.4
COMPOSER_BIN=$(command -v composer)
"$PHP_BIN" "$COMPOSER_BIN" install --no-dev --optimize-autoloader
```

Composerを使えない場合は、ローカルの別コピーで同じコマンドを実行し、生成された`vendor`もアップロードします。

Git管理外の`public/build`は、ローカルのPowerShellからXServerへ転送します。

```powershell
scp -P 10022 -i C:\path\to\xserver_private_key -r `
  C:\Users\aknr1\MyApps\MyCMS\public\build `
  サーバーID@サーバーID.xsrv.jp:/home/サーバーID/setup-cms/public/
```

Laravelの`public`の中身だけを公開先へコピーします。

```bash
cp -a /home/サーバーID/setup-cms/public/. \
  /home/サーバーID/example.com/public_html/cms.example.com/
```

XServerの初期`index.html`や`default_page.html`がある場合は、必要性を確認して公開先から取り除きます。`index.html`が残ると`index.php`より優先表示されます。

### 7. 公開側のindex.phpを変更する

公開先の`index.php`を次の内容にし、`サーバーID`を実値へ置き換えます。

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$applicationPath = '/home/サーバーID/setup-cms';

if (file_exists($maintenance = $applicationPath.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $applicationPath.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $applicationPath.'/bootstrap/app.php';
$app->usePublicPath(__DIR__);
$app->handleRequest(Request::capture());
```

`.htaccess`と`.user.ini`も公開先にあることを確認します。Laravel本体側の`public/index.php`は変更不要です。

### 8. 本番用.envを作る

`/home/サーバーID/setup-cms/.env`を作成します。

```dotenv
APP_NAME="SetUp CMS"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://cms.example.com
APP_TIMEZONE=Asia/Tokyo
APP_LOCALE=ja
APP_FALLBACK_LOCALE=ja

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=MYSQLホスト名
DB_PORT=3306
DB_DATABASE=サーバーID_setupcms
DB_USERNAME=サーバーID_setupcmsuser
DB_PASSWORD=十分に長いランダムなパスワード

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_PATH=/
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true

CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local

FRONTEND_BUILD_WEBHOOK_URL=
FRONTEND_BUILD_WEBHOOK_TOKEN=
```

`.env`は`public_html`へ置かず、権限を制限します。

```bash
chmod 600 /home/サーバーID/setup-cms/.env
```

### 9. Laravelを初期化する

```bash
cd /home/サーバーID/setup-cms
PHP_BIN=/usr/bin/php8.4

$PHP_BIN artisan key:generate
$PHP_BIN artisan migrate --force
$PHP_BIN artisan cms:create-admin admin@example.com
$PHP_BIN artisan optimize
chmod -R 755 storage bootstrap/cache
```

Laravelは`storage`と`bootstrap/cache`へ書き込む必要があります。`777`は使用しません。

### 10. 画像公開用リンクを作る

公開ディレクトリがLaravel本体と分かれているため、`php artisan storage:link`ではなく公開先へ直接リンクします。

```bash
ln -s /home/サーバーID/setup-cms/storage/app/public \
  /home/サーバーID/example.com/public_html/cms.example.com/storage
```

すでに`storage`がある場合は、削除前に中身とリンク先を確認してください。

### 11. 画像上限とCronを設定する

`public/.user.ini`には次が設定済みです。

```ini
upload_max_filesize = 512M
post_max_size = 565M
max_execution_time = 600
max_input_time = 600
```

反映されない場合は、サーバーパネルの「php.ini設定」でも同じ値以上にします。

予約公開のため「Cron設定」に毎分実行を追加します。

```cron
* * * * * cd /home/サーバーID/setup-cms && /usr/bin/php8.4 artisan schedule:run >> /dev/null 2>&1
```

PHPパスは「コマンドパス一覧」で確認してください。詳しくは[XServer公式のCron設定](https://www.xserver.ne.jp/manual/man_program_cron.php)を参照してください。Laravel側では予約公開を5分間隔で判定します。

### 12. 公開確認

```bash
curl -I https://cms.example.com/up
curl https://cms.example.com/api/v1/posts
```

次もブラウザで確認します。

1. `/up`がHTTP 200
2. `/login`でログインできる
3. 記事を保存できる
4. 画像をアップロードして再表示できる
5. 公開記事がAPIへ現れる
6. `APP_DEBUG=false`

## WordPressから移行する

管理画面の「WordPress移行」からWXR/XMLと画像ZIPをアップロードできます。最初に「事前確認」を実行し、対象件数と警告を確認してから「本番インポート」を実行してください。

画像も移行する場合は、`wp-content/uploads`フォルダまたはその中身をZIPにまとめ、画面の「画像ZIP」へ指定します。JPEG、PNG、WebPが対象で、WXRを先に解析して本文画像とアイキャッチに必要なファイルだけをZIPから展開します。画像は画像ライブラリへコピーされ、本文URLもSetUp CMSのURLへ置き換わります。ZIPは最大512MB、各画像は最大10MB、展開後は最大2GB・20,000ファイルです。事前確認ではZIPを展開せず、本番インポート時だけ画像を処理します。

512MBを超える場合やサーバーのアップロード上限を変更できない場合は、後述のCLI手順を使用してください。

### 移行内容

| WordPress | SetUp CMS |
| --- | --- |
| タイトル | タイトル |
| 本文HTML | Markdown本文 |
| 公開・下書き・予約・ゴミ箱 | 公開・下書き・予約・アーカイブ |
| 投稿日時 | 公開日時または予約日時 |
| 抜粋 | 概要 |
| カテゴリー・タグ | カテゴリー・タグ |
| 固定ページ | 固定ページ |
| 本文画像・アイキャッチ | CMSメディアへコピー |
| 主なYoast SEO項目 | SEOタイトル、説明、canonical、noindex |

通常コメントは、返信関係と承認状態（承認済み・承認待ち・スパム）を含めて移行します。トラックバック、ピンバック、カスタム投稿タイプ、WordPressユーザー、プラグイン設定は移行しません。投稿者は指定したSetUp CMS管理者になります。

### 1. バックアップとエクスポート

WordPressのデータベース、全ファイル、`wp-content/uploads`を保存します。SetUp CMS側に既存データがある場合は、そちらもバックアップします。

WordPress管理画面の「ツール → エクスポート → すべてのコンテンツ」でWXR/XMLをダウンロードします。詳しくは[WordPress公式のエクスポート手順](https://ja.wordpress.org/support/article/tools-export-screen/)を参照してください。

画像も移す場合は、FTPまたはSSHで`wp-content/uploads`をフォルダ構造ごと取得します。

### 2. CLIで移行する場合は非公開領域へ配置する

```text
/home/サーバーID/import/wordpress-export.xml
/home/サーバーID/import/uploads/2024/05/example.jpg
```

WXRと画像バックアップを`public_html`へ置かないでください。

### 3. dry-runで確認する

```bash
cd /home/サーバーID/setup-cms
PHP_BIN=/usr/bin/php8.4

$PHP_BIN artisan cms:import-wordpress \
  /home/サーバーID/import/wordpress-export.xml \
  --author-email=admin@example.com \
  --uploads=/home/サーバーID/import/uploads \
  --dry-run
```

`--dry-run`はデータベースと画像を変更しません。画像を旧URLのまま使う場合は`--uploads`を省略できますが、WordPress停止後に消えるため通常はコピーします。

### 4. 本移行する

```bash
$PHP_BIN artisan cms:import-wordpress \
  /home/サーバーID/import/wordpress-export.xml \
  --author-email=admin@example.com \
  --uploads=/home/サーバーID/import/uploads
```

同一slugの記事は再実行時にスキップされます。既存記事を置き換える場合だけ`--update-existing`を追加します。上書き前の内容はリビジョンへ保存されます。

### HTMLからMarkdownへの変換

WordPress本文は自動変換されます。

```html
<h2>見出し</h2>
<p>これは<strong>重要な文章</strong>です。</p>
<ul><li>項目1</li><li>項目2</li></ul>
```

```markdown
## 見出し

これは**重要な文章**です。

- 項目1
- 項目2
```

見出し、段落、改行、強調、リンク、リスト、引用、コード、画像、水平線に対応します。`script`、`iframe`、フォームなどは除去します。ショートコード、独自ブロック、複雑な表、SNS埋め込み、ギャラリー、フォーム、内部リンクは移行後に確認してください。

### 5. 移行結果と切り替え

1. 件数がWordPressの投稿数・固定ページ数と合うか確認
2. 公開・下書き・予約状態を確認
3. 文字化け、Markdown、数式を確認
4. 本文画像とアイキャッチを確認
5. カテゴリー・タグ・SEO項目を確認
6. APIとAstroの表示を確認
7. WordPressをメンテナンス状態にして最終同期
8. Astroを再ビルドして公開先を切り替え
9. 旧URLから新URLへの301リダイレクトを設定

自動移行は301リダイレクトを作りません。検索順位と外部リンクを維持するため、旧パーマリンクごとの転送を別途用意します。完了までWordPressを削除しないでください。

## Astroと連携する

Astro側の`.env`：

```dotenv
CMS_API_URL=https://cms.example.com
```

記事一覧と詳細：

```text
GET https://cms.example.com/api/v1/posts?limit=100
GET https://cms.example.com/api/v1/posts/example-slug
```

固定ページ一覧と詳細：

```text
GET https://cms.example.com/api/v1/pages?limit=100
GET https://cms.example.com/api/v1/pages/about
```

LaTeX記法を表示する場合：

```bash
npm install remark-math rehype-katex katex
```

```js
// astro.config.mjs
import { defineConfig } from 'astro/config';
import remarkMath from 'remark-math';
import rehypeKatex from 'rehype-katex';

export default defineConfig({
  markdown: {
    remarkPlugins: [remarkMath],
    rehypePlugins: [rehypeKatex],
  },
});
```

詳しい取得例は[docs/astro-integration.md](docs/astro-integration.md)を参照してください。100件を超える場合はAPIのページネーションカーソルをたどります。

ビルドWebhookを使う場合は、CMSの`.env`へリポジトリ専用の最小権限トークンを設定します。トークンをクライアントコードや公開リポジトリへ含めません。

## 日常運用

### 記事作成

1. `/admin/posts`を開く
2. タイトルと本文を入力
3. サイドバーでslug、概要、分類、公開状態を設定
4. 画像をドラッグ＆ドロップするか、アップロード済み画像を選択
5. 保存

本文はエディタ内でMarkdown表示とプレビューが切り替わります。H2、H3、箇条書き、番号付きリスト、画像はツールバーから挿入できます。

| 状態 | 動作 |
| --- | --- |
| 下書き | APIに表示されない |
| 予約 | Cronが予約時刻後に公開 |
| 公開 | 公開日時を過ぎていればAPIに表示 |
| アーカイブ | APIに表示されない |

ログ確認：

```bash
tail -n 200 /home/サーバーID/setup-cms/storage/logs/laravel.log
```

## 更新・バックアップ・復元

### バックアップ対象

1. MySQLデータベース
2. `storage/app/public`
3. `.env`
4. Gitなどで管理したコード

バックアップを`public_html`へ置かないでください。

```bash
mysqldump -h MYSQLホスト名 -u DBユーザー名 -p DB名 \
  > /home/サーバーID/backup/setup-cms-$(date +%Y%m%d).sql

tar -czf /home/サーバーID/backup/setup-cms-media-$(date +%Y%m%d).tar.gz \
  -C /home/サーバーID/setup-cms/storage/app public
```

### Gitを使った更新手順

更新前に必ずバックアップし、ローカルでテスト済みの変更を`main`へpushします。XServerのチェックアウトに未コミット変更がないことを確認してから更新します。

```bash
cd /home/サーバーID/setup-cms
PHP_BIN=/usr/bin/php8.4
COMPOSER_BIN=$(command -v composer)

git status --short
git fetch origin
git log --oneline HEAD..origin/main

$PHP_BIN artisan down
git pull --ff-only origin main
"$PHP_BIN" "$COMPOSER_BIN" install --no-dev --optimize-autoloader
$PHP_BIN artisan migrate --force
$PHP_BIN artisan optimize:clear
$PHP_BIN artisan optimize
$PHP_BIN artisan up
```

`git status --short`に意図しない変更が表示された場合はpullせず、変更内容を確認してください。`.env`と`storage`はGit管理外なので`git pull`では上書きされません。

JavaScriptやCSSを変更した場合は、ローカルで`npm run build`を実行して`public/build`をSCP/SFTPで再転送します。その後、公開側のアセットだけを更新します。

```bash
PUBLIC_DIR=/home/サーバーID/example.com/public_html/cms.example.com
mkdir -p "$PUBLIC_DIR/build"
cp -a public/build/. "$PUBLIC_DIR/build/"
cp public/.htaccess public/.user.ini public/favicon.ico public/robots.txt "$PUBLIC_DIR/"
```

公開側の`index.php`は絶対パスを設定した専用ファイルなので、通常の更新ではGit版を上書きしません。`index.php`自体に変更が必要な場合だけ、差分を確認して手作業で反映します。

更新後にコミットと動作を確認します。

```bash
git log -1 --oneline
curl -I https://cms.example.com/up
curl https://cms.example.com/api/v1/posts
```

### コードを一時的にロールバックする

直前のコミットへコードだけ戻して確認する場合は、戻したいコミットIDを指定します。DBマイグレーションは自動的に戻らないため、DB変更を含む場合はバックアップからの復元計画も必要です。

```bash
cd /home/サーバーID/setup-cms
PHP_BIN=/usr/bin/php8.4
COMPOSER_BIN=$(command -v composer)

git log --oneline -10
$PHP_BIN artisan down
git switch --detach 戻したいコミットID
"$PHP_BIN" "$COMPOSER_BIN" install --no-dev --optimize-autoloader
$PHP_BIN artisan optimize:clear
$PHP_BIN artisan optimize
$PHP_BIN artisan up
```

最新版へ戻す場合：

```bash
git switch main
git pull --ff-only origin main
```

DB復元例：

```bash
mysql -h MYSQLホスト名 -u DBユーザー名 -p DB名 \
  < /home/サーバーID/backup/setup-cms-YYYYMMDD.sql
```

## セキュリティ確認

- `APP_ENV=production`、`APP_DEBUG=false`
- `APP_URL`がHTTPS
- `SESSION_SECURE_COOKIE=true`
- `.env`は`public_html`外、権限`600`
- Laravel本体、`vendor`、ログは`public_html`外
- 管理者とDBに長く固有のパスワードを使用
- SSH秘密鍵をサーバーやリポジトリへ置かない
- MySQLユーザーはCMS専用
- PHP、Laravel、依存関係を定期更新
- 移行後のWXRと旧uploadsコピーを削除
- バックアップからの復元を定期的に試す
- 管理画面と`/up`を監視

## トラブルシューティング

### `Root composer.json requires php ^8.3`と表示される

SSHの既定PHPが8.1などの古いバージョンです。`--ignore-platform-req=php`は使わず、PHP 8.4のCLIでComposerを起動します。

```bash
cd /home/サーバーID/setup-cms
PHP_BIN=/usr/bin/php8.4
COMPOSER_BIN=$(command -v composer)

php -v
"$PHP_BIN" -v
"$PHP_BIN" "$COMPOSER_BIN" install --no-dev --optimize-autoloader
```

2つ目がPHP 8.4.xになれば正しい状態です。`/usr/bin/php8.4`が存在しない場合は、サーバーパネルの「サーバー情報 → コマンドパス一覧」でPHP 8.4（CLI）のパスを確認してください。

### 500エラーまたは真っ白

```bash
tail -n 200 storage/logs/laravel.log
$PHP_BIN artisan about
$PHP_BIN artisan optimize:clear
```

`.env`、`APP_KEY`、DB接続、PHPバージョン、`storage`と`bootstrap/cache`の権限を確認します。本番で`APP_DEBUG=true`にはしません。

### XServerの初期ページが表示される

公開先に`index.html`または`default_page.html`が残っていないか、サブドメインの正しいドキュメントルートへ配置したか確認します。

### Vite manifest not found

ローカルで`npm run build`を実行し、`public/build`を公開先へアップロードします。

### DBへ接続できない

- `DB_HOST`が「MySQL情報」と一致しているか
- DB名とユーザー名に`サーバーID_`が付いているか
- ユーザーへアクセス権を付けたか
- `.env`変更後に`artisan optimize:clear`を実行したか

### 画像をアップロードできない、表示できない

- JPEG、PNG、WebPで10MB以下か
- `storage/app/public`へ書き込めるか
- `.user.ini`の上限が反映されているか
- 公開側`storage`リンクが正しいか
- PHPのGD、Fileinfoが有効か
- `APP_URL`が正しいHTTPS URLか

### 予約記事が公開されない

```bash
$PHP_BIN artisan schedule:list
$PHP_BIN artisan cms:publish-scheduled
```

手動では公開される場合、Cron、PHPパス、時刻、`APP_TIMEZONE=Asia/Tokyo`を確認します。

### WordPress移行でスキップされる

同一slugは安全のためスキップされます。置き換える意図がある場合だけ`--update-existing`を使います。変換エラーはコマンド末尾の警告とLaravelログを確認します。

### 移行画像が旧URLのまま

`--uploads`には`wp-content/uploads`そのものを指定します。WXRのURLが`/wp-content/uploads/2024/05/example.jpg`なら、指定先に`2024/05/example.jpg`が必要です。

## コマンド一覧

```bash
$PHP_BIN artisan about
$PHP_BIN artisan cms:create-admin admin@example.com
$PHP_BIN artisan cms:import-wordpress /path/export.xml --author-email=admin@example.com --dry-run
$PHP_BIN artisan cms:import-wordpress /path/export.xml --author-email=admin@example.com --uploads=/path/uploads
$PHP_BIN artisan cms:publish-scheduled
$PHP_BIN artisan migrate:status
$PHP_BIN artisan migrate --force
$PHP_BIN artisan optimize:clear
$PHP_BIN artisan optimize
$PHP_BIN artisan test
```

## 参考資料

- [XServer: PHPのバージョン切り替え](https://www.xserver.ne.jp/manual/man_program_php_ver.php)
- [XServer: SSH設定](https://www.xserver.ne.jp/manual/man_server_ssh.php)
- [XServer: MySQL設定](https://www.xserver.ne.jp/manual/man_db_setting.php)
- [XServer: サブドメイン設定](https://www.xserver.ne.jp/manual/man_domain_subdomain_setting.php)
- [XServer: Cron設定](https://www.xserver.ne.jp/manual/man_program_cron.php)
- [GitHub: Deploy Keyの管理](https://docs.github.com/en/authentication/connecting-to-github-with-ssh/managing-deploy-keys)
- [WordPress: エクスポート](https://ja.wordpress.org/support/article/tools-export-screen/)
- [Laravel 13: Deployment](https://laravel.com/framework/docs/deployment)
- [Astro連携例](docs/astro-integration.md)
