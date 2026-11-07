# Certify LMS

マルチ資格対応の資格学習プラットフォームです。受講生は資格ごとの教材で学習し、演習問題・模擬試験で理解度を確かめながら、コーチの面談サポートを受けて資格取得を目指せます。

> プロジェクト構造・ドメインモデル・コードの読み進め方は [ONBOARDING.md](./ONBOARDING.md) を参照してください。

## 主な機能

| ロール            | 機能                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| ----------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 受講生（student） | 教材閲覧 / 演習問題・苦手分野ドリル（演習履歴・分野別統計） / 模擬試験（分野別ヒートマップ・合格可能性スコア） / 質問掲示板（Q&A） / 面談予約・リマインダー通知 / アプリ内通知（ポップオーバー・一覧・未読バッジ）・メール通知（面談・Q&A・チャット・お知らせ等） / 追加面談の購入（Stripe 決済） / チャット / AI 学習相談（Gemini。右下のウィジェットから全画面表示可能、サイドバーからフル画面ページも利用可） / 学習目標設定（個人目標・目標受験日） / 学習時間・進捗・ストリーク管理 / 修了証の受領（PDF 出力） |
| コーチ（coach）   | 担当資格の教材・演習問題・模試の管理 / 担当受講生の一覧・学習進捗（Section / Chapter / Part 単位）の確認・受講生メモ記録 / 面談対応・面談メモ・面談設定（対応可能時間枠）・Google カレンダー連携 / チャット対応 / 質問対応（質問掲示板） / アプリ内通知（ポップオーバー・一覧・未読バッジ）・メール通知（面談・Q&A・チャット等）                                                                                                                                                                                    |
| 管理者（admin）   | ユーザー招待・管理（受講期間延長・面談回数付与） / 受講登録管理 / 資格・資格分類マスタ管理 / 資格へのコーチ割当 / プラン・面談パックマスタ管理 / 模試マスタ管理・受験セッション閲覧 / お知らせ一斉配信（全受講生／資格指定／ユーザー指定） / チャット監査・質問掲示板モデレーション / 全体ダッシュボード・運用統計                                                                                                                                                                                                  |
| 共通（全ロール）  | 設定・プロフィール管理（パスワード変更等）                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |

## 動作環境

- Docker Desktop / Docker Compose
  - ※ホストマシンへの PHP / Node.js のインストールは不要です（すべて Docker 内で完結します）。
- 開発環境は Laravel Sail で構築します（PHP コンテナ・MySQL・Mailpit・phpMyAdmin を起動）
- Stripe の決済・Webhook の動作確認を行う場合のみ、[Stripe CLI](https://docs.stripe.com/stripe-cli) をホストにインストールし、`stripe login` を済ませてください

## 外部サービスのキー

以下の外部サービスは、キーを各自で取得して `.env` に設定する必要があります。キーが未設定でも基本機能は動作しますが、対応する機能は使えません。環境構築の前に取得しておくと手戻りを防げます。

| サービス                   | 変数                                                                            | 必須か | 未設定時の影響                         | 取得先                                                    |
| -------------------------- | ------------------------------------------------------------------------------- | ------ | -------------------------------------- | --------------------------------------------------------- |
| Google AI Studio（Gemini） | `GEMINI_API_KEY`                                                                | 任意   | AI 学習相談が使えません                | [Google AI Studio](https://aistudio.google.com/)          |
| Stripe                     | `STRIPE_SECRET` / `STRIPE_WEBHOOK_SECRET`                                       | 任意   | 追加面談の購入ができません             | Stripe ダッシュボード（テストモード）/ Stripe CLI         |
| Google Cloud（OAuth）      | `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET`                                     | 任意   | Google カレンダー連携が失敗します      | [Google Cloud Console](https://console.cloud.google.com/) |
| Pusher                     | `PUSHER_APP_ID` / `PUSHER_APP_KEY` / `PUSHER_APP_SECRET` / `PUSHER_APP_CLUSTER` | 任意   | チャットのリアルタイム反映がされません | [Pusher](https://pusher.com/)                             |

各変数の詳細と動作確認の手順は、後述の「環境変数」を参照してください。

## 環境構築手順

### 1. リポジトリの clone

```bash
git clone https://github.com/alienworldadventurer-debug/certify-lms.git
cd certify-lms
```

### 2. 環境変数ファイルの作成

```bash
cp .env.example .env
```

`.env.example` は Sail 向けに設定済みのため、コピーするだけで基本機能のローカル開発を始められます。外部サービスのキーは未設定のままでも起動でき、対応する機能だけが使えなくなります（[外部サービスのキー](#外部サービスのキー)参照）。

### 3. 依存パッケージのインストール（初回のみ）

`vendor/` がまだ無いため、初回のみ Docker 経由で Composer を実行します。Composer は依存関係の解決だけに使うため、PHP 8.5 の Sail 環境とは別のイメージ（`php84-composer`）を使用します（`--ignore-platform-reqs` で PHP バージョンの違いは無視されます）。

```bash
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs
```

### 4. Sail エイリアスの設定（推奨）

```bash
alias sail='./vendor/bin/sail'
```

以降のコマンドはこのエイリアス前提で記載します（未設定の場合は `./vendor/bin/sail` に読み替えてください）。

### 5. コンテナの起動

```bash
sail up -d
```

### 6. アプリケーションの初期化

```bash
sail artisan key:generate
sail artisan storage:link
sail artisan migrate:fresh --seed
```

`storage:link` は教材画像・プロフィール画像の配信に必要です。`migrate:fresh --seed` でテーブル作成とデモデータ投入が行われます（いつでも再実行してデータを初期状態に戻せます）。

### 7. フロントエンドのビルド

```bash
sail npm install
sail npm run build
```

Blade / CSS / JS を編集しながら開発する場合は、`build` の代わりに `sail npm run dev` を起動したままにしてください（Vite のホットリロードが効きます）。

### 8. 動作確認

http://localhost:8000 にアクセスし、下記の[ログインアカウント](#ログインアカウント)でログインできればセットアップ完了です。

#### 通知ポップオーバーの確認

ヘッダー右上のベル（🔔）から通知ポップオーバーを開けます。

- 受講生（student）またはコーチ（coach）でログインして確認してください（管理者には表示されません）
- ポップオーバーは JavaScript で動作するため、手順 7 のフロントエンドのビルド（または `sail npm run dev`）が完了している必要があります。ビルド成果物（`public/build/`）はリポジトリに含めていないため、clone 直後は必ず実行してください
- 通知 API は同一オリジン（http://localhost:8000）からセッション Cookie で認証されるため、追加の設定は不要です。別のホスト名やポートでアクセスする場合のみ、`.env` の `SANCTUM_STATEFUL_DOMAINS` にそのホストを追加してください

## 開発環境 URL

| 用途                  | URL                   |
| --------------------- | --------------------- |
| アプリケーション      | http://localhost:8000 |
| phpMyAdmin（DB 確認） | http://localhost:8080 |
| Mailpit（メール確認） | http://localhost:8025 |

アプリケーションが送信するメール（招待メールなど）はすべて Mailpit に届きます。実際のメールは送信されません。

## ログインアカウント

`migrate:fresh --seed` 後、以下の固定アカウントが使えます（パスワードはすべて `password`）。

| ロール | メールアドレス           | 備考                                             |
| ------ | ------------------------ | ------------------------------------------------ |
| 管理者 | admin@certify-lms.test   | 全機能にアクセス可能                             |
| コーチ | coach@certify-lms.test   | IT 系資格の担当                                  |
| コーチ | coach2@certify-lms.test  | ビジネス系資格の担当                             |
| 受講生 | student@certify-lms.test | 受講中の資格・学習履歴・面談などのデモデータ付き |

このほか、ライフサイクル（招待中 / 受講中 / 卒業 / 退会）を網羅したデモユーザーが投入されます。

> 本サービスは**招待制**です。公開の会員登録画面はありません。新規ユーザーを作るには、管理者でログイン → ユーザー管理から招待 → Mailpit で招待メールの URL を開く → オンボーディング登録、という流れになります。

## キューワーカー（通知・メール送信）

通知とメールは DB キュー（`QUEUE_CONNECTION=database`）経由で非同期に送信します。ワーカーが起動していないと送信されないため、開発時は別ターミナルで起動してください。

```bash
sail artisan migrate                 # jobs テーブルの作成（初回・マイグレーション更新時）
sail artisan queue:work --tries=4    # ワーカー起動（失敗時は最大3回リトライ）
sail artisan queue:failed            # 失敗したジョブの一覧
sail artisan queue:retry all         # 失敗したジョブをすべて再投入
sail artisan queue:retry <uuid>      # 個別に再投入（uuid は queue:failed で確認）
```

- リトライ間隔は通知・招待メールごとに 1 分・5 分・15 分です（各クラスの `$backoff`）。
- 送信に失敗して上限回数を超えたジョブは `failed_jobs` テーブルに記録されます。原因を解消してから `queue:retry` で再投入してください。
- 面談リマインダーの DB 通知は重複判定に使うため同期保存され、メールのみキュー経由で送信されます。

## スケジューラー（面談リマインダー）

面談の前日リマインダー（毎日 18:00）と 1 時間前リマインダー（5 分間隔）は、Laravel のスケジューラー（`app/Console/Kernel.php`）から実行されます。キューワーカーとは別に、スケジューラーも起動しておく必要があります。

```bash
sail artisan schedule:work    # ローカル開発用（起動したままにする）
```

動作確認は定期実行を待たず、コマンドを手動で実行できます。

```bash
sail artisan notifications:send-meeting-reminders --window=eve                # 翌日開始の予約済み面談が対象
sail artisan notifications:send-meeting-reminders --window=one_hour_before    # 開始まで 60 分以内の予約済み面談が対象
```

- 同じ面談・同じ window の通知は重複判定により二重配信されません。
- 確認用には、既存の面談の予約時刻を翌日または 1 時間以内に変更してください。
- メールは非同期送信のため、キューワーカー（上記）も起動していないと届きません。

## テスト

```bash
sail artisan test                           # 全テスト実行
sail artisan test --filter=Xxx             # クラス名・メソッド名で絞り込み
sail artisan test --group=external          # 外部 API 連携テストのみ実行（Google/Gemini/Stripe）
sail artisan test --exclude-group=external  # 外部 API 連携テストを除外して実行
```

- テスト実行時は `Http::preventStrayRequests()` により、モックしていない外部への実通信が防止されています（[tests/TestCase.php](/home/ta2gu/laravel-checktest/certify-lms/tests/TestCase.php)）。
- 外部 API 連携テストには `#[Group('external')]` が付与されています。

## コード整形

Laravel Pint を使用しています。コミット前に実行してください。

```bash
sail bin pint --dirty    # 変更ファイルのみ整形
sail bin pint --test     # 整形漏れの確認（CI 相当のチェック）
```

## 使用技術

- PHP 8.5 / Laravel 10
- MySQL 8.4
- Laravel Fortify（認証）/ Laravel Sanctum（API 認証）
- Blade + Tailwind CSS + Vite（JavaScript は素の JS、フレームワーク不使用）
- PHPUnit / Laravel Pint
- league/commonmark（教材本文の Markdown レンダリング）
- Pusher（チャットのリアルタイム配信）
- Google Gemini API（AI 学習相談チャットボット）
- mpdf/mpdf（修了証 PDF 出力）
- Docker（Laravel Sail）

## 環境変数

`.env.example` をコピーするだけで、外部サービスのキーを必要としない機能はローカルで動作します（メールは Mailpit に配信されます）。キーが必要な機能は、以下の各項目でキーを設定してください。

- `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` / `GOOGLE_REDIRECT_URI` — コーチの Google カレンダー連携に使用します。Google Cloud Console で OAuth クライアント（ウェブアプリケーション）を作成し、承認済みのリダイレクト URI に `GOOGLE_REDIRECT_URI` の値を登録してください。未設定の場合、連携操作は失敗します（面談予約自体は影響を受けません）。なお、連携で保存するアクセストークン・リフレッシュトークンは現状暗号化していません。本番運用ではトークンの暗号化を推奨します
- `STRIPE_SECRET` / `STRIPE_WEBHOOK_SECRET` — 追加面談パックの Stripe Checkout 決済および Webhook 署名検証に使用します。Stripe ダッシュボードで取得したテスト用 API キー（`sk_test_...`）と Webhook 署名シークレット（`whsec_...`）を設定してください。未設定の場合、購入開始時に「決済画面の作成に失敗しました。」と表示され決済画面へ進めません
- `PUSHER_APP_ID` / `PUSHER_APP_KEY` / `PUSHER_APP_SECRET` / `PUSHER_APP_CLUSTER` — チャットのリアルタイム配信に使用します。Pusher ダッシュボードでアプリを作成し、上記の値を `.env` に設定したうえで、`BROADCAST_DRIVER=pusher` に変更してください。未設定（既定の `BROADCAST_DRIVER=log`）でもメッセージの送受信自体は動作し、相手画面へのリアルタイム反映のみ行われません
    - `VITE_PUSHER_*` は `PUSHER_*` の値からビルド時に埋め込まれます。Pusher の値を変更した場合は `sail artisan config:clear` に加えて `sail npm run build`（開発中は `sail npm run dev` の再起動）を実行してください。`config:clear` だけでは反映されません
- `DASHBOARD_ADMIN_CACHE_TTL` — 管理者ダッシュボードの集計（全体 KPI・認定ごとの修了率）をキャッシュする有効期間を秒数で指定します。既定値は `300`（5分）です。値を変更した後は `sail artisan config:clear` を実行してください。受講状態が変わると該当キャッシュは即時に破棄されるため、この値は再計算までの最大待ち時間の目安です
- `GEMINI_API_KEY` / `GEMINI_MODEL` — 受講生向け AI 学習相談（Gemini チャットボット）に使用します。[Google AI Studio](https://aistudio.google.com/) で API キーを取得して `GEMINI_API_KEY` に設定してください。`GEMINI_MODEL` の既定値は `gemini-3.5-flash-lite` です。未設定の場合も画面は表示されますが、メッセージ送信時に「送信に失敗しました。時間をおいて再度お試しください。」と表示され、応答は得られません（質問も保存されません）
- `AI_CHAT_ENABLED` — AI 学習相談機能全体の有効化スイッチです（`.env.example` では `true`、コード側の既定値は `false`）。`false` にすると、関連ルート（`/ai-chat` など）が 404 になり、フローティングウィジェットも表示されません。値を変更した後は `sail artisan config:clear` を実行してください
- `AI_CHAT_DAILY_LIMIT` — 受講生 1 人あたりの 1 日の送信上限回数です（既定値: `50`）。新しい相談の最初の質問も含みます
- `AI_CHAT_HISTORY_LIMIT` — AI への入力に含める直近のメッセージ件数です（既定値: `10`）
- `AI_CHAT_AUTO_TITLE_ENABLED` — 最初の AI 応答後に、AI に会話タイトルを付け直させるかどうかです（既定値: `true`）
- `AI_CHAT_SYSTEM_PROMPT` — AI に与えるシステムプロンプトです。未設定時は `config/ai-chat.php` の既定文を使用します

### AI 学習相談（Gemini チャットボット）の動作確認手順

1. `.env` の `GEMINI_API_KEY` に API キーを設定し、`sail artisan config:clear` を実行します
2. `student@certify-lms.test` でログインし、画面右下の AI 相談ボタン、またはサイドバーの「AI 相談」から質問を送信します
3. 応答が表示され、会話一覧に履歴が残ることを確認します

`GEMINI_API_KEY` を空のままにして質問を送信すると、「送信に失敗しました。時間をおいて再度お試しください。」と表示され、応答が得られないことを確認できます。

### Stripe Webhook（追加面談購入）の動作確認手順

Stripe CLI（「動作環境」参照）を利用してローカル環境で決済から残数反映までの動作確認を行えます。

1. Stripe CLI でローカルへ Webhook イベントを転送します（転送するイベントの指定が必須です）。

```bash
stripe listen --events checkout.session.completed,checkout.session.expired,checkout.session.async_payment_failed,payment_intent.payment_failed,charge.refunded --forward-to localhost:8000/webhooks/stripe
```

2. 表示された Webhook signing secret (`whsec_...`) を `.env` の `STRIPE_WEBHOOK_SECRET` に設定し、次のコマンドを実行します。

```bash
sail artisan config:clear
```

3. アプリで追加面談パックの購入画面から決済を開始し、テストカード `4242 4242 4242 4242`（有効期限・CVC は任意の将来日・3 桁の値）で決済を完了する。その他のテストカードは [Stripe のテスト用カード一覧](https://docs.stripe.com/testing) を参照してください。
4. `stripe listen` の画面に `checkout.session.completed` が届いたことを確認する
5. 画面上の残数が増えていることを確認する

新しい環境変数やセットアップ手順を追加した場合は、`.env.example` と本 README に追記し、チームの誰でも環境を再現できる状態を保ってください。
