# 🚀 Git ブランチ戦略

## **📌 概要**

このドキュメントでは、`dev`, `qa`, `std`, `prod` の各環境に対応した GitHub のブランチ運用戦略を定義します。

## **📌 ブランチ構成**

| **ブランチ名**     | **目的**     | **環境** | **デプロイ対象**       |
| ------------- | ---------- | ------ | ---------------- |
| `main`        | 本番環境       | `prod` | 本番環境（Production） |
| `release/std` | 準本番環境      | `std`  | Staging（本番直前テスト） |
| `release/qa`  | QA 環境      | `qa`   | 品質保証（QA テスト）     |
| `develop`     | 開発環境       | `dev`  | 開発環境             |
| `feature/*`   | 新機能開発      | `dev`  | 開発環境             |
| `hotfix/*`    | 緊急修正（本番対応） | `prod` | 本番環境             |

## **🔹 1. ブランチフローの概要**
### **🛠️ 基本の流れ**
1.	**新機能開発** は `feature/*`xxxx`* ブランチで行い、`develop` にマージ
2.	`develop` で開発が進んだら `release/qa` へマージし、QA環境にデプロイ
3.	**QA テスト完了後**、`release/qa` を `release/std` にマージして **準本番環境** にデプロイ
4.	**本番リリースの準備が完了** したら、`release/std` を `main` にマージし、本番環境にデプロイ
5.	**緊急修正**（hotfix）が発生した場合は、`hotfix/*` を作成し、`main` に直接マージ

## **📌 2. ブランチ運用ルール**

### **🟢 ① `feature/*` ブランチ （新機能開発）**

- `develop` からブランチを切って開発
- コードレビュー後 `develop` にマージ

```bash
git checkout develop
git checkout -b feature/new-login
# 開発作業
git commit -m "Implement new login"
git push origin feature/new-login
```

### **🟡 ② `develop` ブランチ （開発環境）**

- `feature/*` ブランチをマージして **開発環境 (**`dev`**) にデプロイ**
- 定期的に `release/qa` にマージ

```bash
git checkout develop
git merge feature/new-login
git push origin develop
```

### **🔵 ③ `release/qa` ブランチ （QA 環境）**

- `develop` から `release/qa` にマージして **QA 環境 (**`qa`**) にデプロイ**
- QA チームがテストし、バグがあれば `develop` に修正を戻す

```bash
git checkout release/qa
git merge develop
git push origin release/qa
```

### **🟣 ④ `release/std` ブランチ （準本番環境）**

- QA 完了後 `release/std` にマージし **Staging (**`std`**) にデプロイ**
- 本番環境で問題がないか **最終チェック**
- main へのリリース準備

```bash
git checkout release/std
git merge release/qa
git push origin release/std
```

### **🔴 ⑤ `main` ブランチ （本番環境）**

- `release/std` を `main` にマージし、**本番 (`prod`) にデプロイ**
- 本番リリース後、リリースタグ (`v1.0.0` など)を付与

```bash
git checkout main
git merge release/std
git tag -a v1.0.0 -m "Release version 1.0.0"
git push origin main --tags
```

## **📌 3. 緊急対応（Hotfix）**

- `main` から `hotfix/xxx` を作成
- 修正後 `main` にマージし、本番環境 (`prod`) にデプロイ
- 修正を、`develop` にも適用

```bash
git checkout main
git checkout -b hotfix/fix-login-bug
# 修正作業
git commit -m "Fix login bug"
git push origin hotfix/fix-login-bug

# 本番デプロイ
git checkout main
git merge hotfix/fix-login-bug
git push origin main

# 開発環境にも適用
git checkout develop
git merge hotfix/fix-login-bug
git push origin develop
```

## **📌 4. GitHub Actions を活用して CI/CD を自動化**

GitHub Actions を使って、**各ブランチへの push をトリガーにデプロイ** することが可能。

```yaml
name: Deploy to Environments

on:
  push:
    branches:
      - main
      - release/std
      - release/qa
      - develop

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - name: Checkout code
        uses: actions/checkout@v3

    　- name: Set up Google Cloud SDK
        uses: google-github-actions/setup-gcloud@v1

      - name: Deploy based on branch
        run: |
          if [[ "${{ github.ref }}" == "refs/heads/main" ]]; then
            echo "Deploying to Production"
          elif [[ "${{ github.ref }}" == "refs/heads/release/std" ]]; then
            echo "Deploying to Staging"
          elif [[ "${{ github.ref }}" == "refs/heads/release/qa" ]]; then
            echo "Deploying to QA"
          elif [[ "${{ github.ref }}" == "refs/heads/develop" ]]; then
            echo "Deploying to Development"
          fi
```

## **📌 まとめ**

| **環境**        | **ブランチ**      | **目的**       | **デプロイ対象** |
| ------------- | ------------- | ------------ | ---------- |
| **開発 (dev)**  | `develop`     | 開発・統合        | `dev` 環境   |
| **QA**        | `release/qa`  | 品質保証テスト      | `qa` 環境    |
| **準本番 (std)** | `release/std` | 本番前の最終確認     | `std` 環境   |
| **本番 (prod)** | `main`        | ユーザー向け本番リリース | `prod` 環境  |

🚀 **この運用ルールを守りながら開発を進めてください！**

