# 04-php-buildpacks-demo

このハンズオンでは、Google Cloud Buildpacks を利用して、`Dockerfile` を使わずに PHP + Nginx のコンテナイメージをビルドし、Cloud Run にデプロイする方法を学びます。

## このハンズオンで学べること

- `Dockerfile` の管理から解放される方法
- `project.toml` を使った Buildpacks の基本的な設定
- `pack` CLI を使ったローカルでのコンテナイメージのビルド
- `docker compose` を使ったローカル環境での動作確認
- `gcloud` コマンド一発でソースコードから Cloud Run へデプロイする方法

## ファイル構成

- `index.php`: アプリケーション本体。簡単なメッセージを表示します。
- `composer.json`: PHP のバージョンを指定し、Buildpacks にこのプロジェクトが PHP であることを伝えます。
- `project.toml`: `Dockerfile` の代わりとなる Buildpacks 用の設定ファイルです。ビルド時の環境変数（例: Composer のバージョン）などを設定します。
- `compose.yaml`: `docker compose` でローカル実行するための定義ファイルです。
- `README.md`: このファイルです。

## 必要なツール

- [Google Cloud SDK (`gcloud`)](https://cloud.google.com/sdk/docs/install?hl=ja)
- [pack CLI](https://buildpacks.io/docs/install/)
- [Docker](https://docs.docker.com/engine/install/)
- `docker compose`
  - Docker Desktop には通常同梱されています。

## 1. ローカル環境での実行手順

### ステップ 1: コンテナイメージをビルドする

まず、`pack` コマンドを使ってソースコードから直接コンテナイメージをビルドします。

```bash
pack build php-buildpacks-app --builder gcr.io/buildpacks/builder
```

- `php-buildpacks-app`: 作成するイメージ名です。
- `--builder gcr.io/buildpacks/builder`: Google Cloud が提供するビルダーを指定します。

### ステップ 2: `docker compose` で起動する

ビルドしたイメージを `docker compose` を使って起動します。

```bash
docker compose up
```

### ステップ 3: ブラウザで確認する

ブラウザで `http://localhost:8080?name=World` にアクセスし、メッセージが表示されれば成功です。

確認が終わったら、`Ctrl+C` でコンテナを停止してください。

## 2. Cloud Run へのデプロイ手順

ローカルでの動作が確認できたら、いよいよ Cloud Run にデプロイします。
`gcloud` コマンドを使えば、ソースコードから直接デプロイが可能です。

```bash
# 環境変数を設定
export PROJECT_ID="$(gcloud config get-value project)"
export REGION="asia-northeast1" # リージョンは適宜変更してください
export SERVICE_NAME="php-buildpacks-demo"

# ソースコードから直接デプロイ
gcloud run deploy "${SERVICE_NAME}" \
  --source . \
  --region "${REGION}" \
  --project "${PROJECT_ID}" \
  --allow-unauthenticated
```

このコマンド一発で、Cloud Build が裏側で `pack` と同じように Buildpacks を使ってコンテナイメージをビルドし、そのイメージを Cloud Run にデプロイしてくれます。

デプロイが完了すると URL が表示されるので、アクセスしてメッセージが表示されることを確認してください。

## 3. カスタマイズ編

Buildpacks は設定なしで動作しますが、環境変数を通じて挙動をカスタマイズすることも可能です。
設定は `project.toml` に記述します。

### Nginx 設定のカスタマイズ

Buildpacks が自動生成する Nginx の設定ではなく、自前の設定ファイルを使いたい場合は、`GOOGLE_CUSTOM_NGINX_CONFIG` 環境変数を指定します。

1.  プロジェクトルートに `my-nginx.conf` のようなカスタム設定ファイルを作成します。
2.  `project.toml` に以下を追記します。

```toml
# project.toml

# ... (既存の設定) ...

# Nginx のカスタム設定ファイルを指定します。
[[build.env]]
name = "GOOGLE_CUSTOM_NGINX_CONFIG"
value = "my-nginx.conf"
```

これで、ビルド時に `my-nginx.conf` が Nginx の設定として使用されます。

### Composer バージョンの指定

特定のバージョンの Composer を使用したい場合は、`GOOGLE_COMPOSER_VERSION` 環境変数を指定します。

`project.toml` に以下を追記します。

```toml
# project.toml

# ... (既存の設定) ...

# Composer のバージョンを明示的に指定します。
[[build.env]]
name = "GOOGLE_COMPOSER_VERSION"
value = "2.8.12"
```

指定しない場合は、Buildpacks に組み込まれたデフォルトのバージョンが使用されます。