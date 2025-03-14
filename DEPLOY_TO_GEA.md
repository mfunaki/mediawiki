# 🚀 MediaWiki を GitHub Actions で Google App Engine に自動デプロイ

## **📌 目標**
- **MediaWiki を GitHub レポジトリ (`https://github.com/mfunaki/mediawiki`) から自動デプロイ**
- **Wikipedia 日本語版のデータを Google Cloud SQL に格納**
- **Cloud Storage & CDN で静的ファイルを管理**
- **GitHub Actions で CI/CD を実現**

---

## **🚀 1. Google Cloud の準備**

### **① プロジェクトの設定**
Google Cloud プロジェクトを作成し、必要な API を有効化。

```bash
gcloud config set project YOUR_PROJECT_ID
gcloud services enable appengine.googleapis.com sqladmin.googleapis.com storage.googleapis.com artifactregistry.googleapis.com
```

### **② App Engine の作成**
```bash
gcloud app create --region=us-central
```

### **③ Cloud SQL（MySQL）の作成**
Wikipedia 日本語版のデータを格納する MySQL インスタンスを作成。

```bash
gcloud sql instances create wikipedia-db \
    --database-version=MYSQL_8_0 \
    --tier=db-n1-standard-2 \
    --region=us-central1
```

```bash
gcloud sql databases create wikidb --instance=wikipedia-db
gcloud sql users create wikiuser --instance=wikipedia-db --password=wikisecret
```

---

## **🖼️ 2. Cloud Storage & CDN の設定**
Wikipedia の画像やキャッシュを保存するため、Cloud Storage を作成。

```bash
gsutil mb -l us-central1 gs://wikipedia-static-files
gcloud compute backend-buckets create wikipedia-backend --gcs-bucket-name=wikipedia-static-files --enable-cdn
```

---

## **📥 3. Wikipedia 日本語版データをインポート**
Wikipedia 日本語版のデータダンプを取得し、Cloud SQL にインポート。

```bash
wget https://dumps.wikimedia.org/jawiki/latest/jawiki-latest-pages-articles.xml.bz2
bzip2 -d jawiki-latest-pages-articles.xml.bz2
php maintenance/importDump.php jawiki-latest-pages-articles.xml
```

---

## **📦 4. `Dockerfile` を作成**
Google App Engine にデプロイするため、Docker を使って MediaWiki をコンテナ化。

```dockerfile
FROM mediawiki:latest

RUN apt-get update && apt-get install -y \
    php-mbstring php-intl php-xml php-mysql \
    && rm -rf /var/lib/apt/lists/*

# LocalSettings.php のコピー
COPY LocalSettings.php /var/www/html/LocalSettings.php

# 初回セットアップスクリプト
COPY mediawiki-setup.sh /mediawiki-setup.sh
RUN chmod +x /mediawiki-setup.sh
```

---

## **📜 5. `app.yaml`（App Engine 設定）**
GAE の環境変数やデプロイ設定を記述。

```yaml
runtime: custom
service: mediawiki
instance_class: F2

handlers:
  - url: /.*
    script: auto

env_variables:
  DB_HOST: "/cloudsql/YOUR_PROJECT_ID:us-central1:wikipedia-db"
  DB_NAME: "wikidb"
  DB_USER: "wikiuser"
  DB_PASS: "wikisecret"
```

---

## **🔄 6. GitHub Actions の設定**
GitHub Actions を使って、GitHub にプッシュすると **GAE に自動デプロイ** されるように設定。

```yaml
name: Deploy to Google App Engine

on:
  push:
    branches:
      - main

jobs:
  deploy:
    runs-on: ubuntu-latest

    steps:
      - name: Checkout repository
        uses: actions/checkout@v3

      - name: Authenticate with Google Cloud
        uses: google-github-actions/auth@v1
        with:
          credentials_json: ${{ secrets.GCP_SA_KEY }}

      - name: Set up Google Cloud SDK
        uses: google-github-actions/setup-gcloud@v1

      - name: Configure Docker
        run: gcloud auth configure-docker

      - name: Build Docker image
        run: |
          docker build -t gcr.io/YOUR_PROJECT_ID/mediawiki .
          docker tag gcr.io/YOUR_PROJECT_ID/mediawiki gcr.io/YOUR_PROJECT_ID/mediawiki:${{ github.sha }}
          docker push gcr.io/YOUR_PROJECT_ID/mediawiki:${{ github.sha }}

      - name: Deploy to Google App Engine
        run: gcloud app deploy app.yaml --image-url=gcr.io/YOUR_PROJECT_ID/mediawiki:${{ github.sha }} --quiet

      - name: Run Cloud SQL migrations
        run: |
          gcloud sql instances patch wikipedia-db \
            --database-version=MYSQL_8_0 --quiet
          gcloud sql databases list --instance=wikipedia-db

      - name: Notify on Success
        if: success()
        run: echo "Deployment to App Engine successful!"
```

---

## **🔐 7. GitHub Secrets の設定**
GitHub の **Settings → Secrets and variables → Actions** に以下を追加：
| Secret Name | 値 |
|-------------|----------------------|
| `GCP_SA_KEY` | `key.json` の内容 |
| `PROJECT_ID` | `YOUR_PROJECT_ID` |

---

## **🚀 8. デプロイの実行**
GitHub にプッシュすると、GitHub Actions が自動でデプロイを実行。

```bash
git add .
git commit -m "Deploy MediaWiki to Google App Engine"
git push origin main
```

デプロイ完了後、Google Cloud で URL を確認：
```bash
gcloud app browse
```

---

## **📌 まとめ**
- **GitHub に MediaWiki (`https://github.com/mfunaki/mediawiki`) を Fork**
- **Docker でコンテナ化**
- **Cloud SQL（MySQL）に Wikipedia データをセットアップ**
- **Cloud Storage & Cloud CDN で最適化**
- **GitHub Actions で自動デプロイ**
- **本番デプロイ後、`gcloud app browse` で動作確認**

🚀 **この手順で Wikipedia 日本語版を Google App Engine にデプロイできます！**
