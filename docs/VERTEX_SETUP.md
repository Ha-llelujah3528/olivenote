# Vertex AI セットアップガイド（任意）

Olive Note は Gemini（Vertex AI）で以下の機能を提供します：

- **タスクアドバイザー**: TaskModal 右側に出るチャット形式の相談
- **AI コンシェルジュ**: 画面右下のフロート（ツール使い方ガイド）
- **AI ドキュメント出力**: タスク情報を Google Docs に自動転記
- **リリースノート自動生成**: 雑なメモを清書して Docs に追記

これらは **オプション機能** です。Vertex AI を使わない場合、インストーラで全項目を空欄にすれば AI ボタンは押せても応答だけがエラーになります。

> 所要時間: 約10分（既に Google Cloud に慣れている前提）

---

## ステップ 1. Vertex AI を有効化

[OAUTH_SETUP.md](./OAUTH_SETUP.md) で作成したプロジェクト、**または別の Google Cloud プロジェクト** どちらでも可です。

> 💡 推奨: **Drive 用プロジェクトと分ける** と請求が見やすく、SAの権限管理もシンプルになります。本パッケージのSTG環境では別プロジェクト構成にしています。

1. [Google Cloud Console](https://console.cloud.google.com/)
2. プロジェクトを選択
3. 左メニュー「**APIとサービス**」→「**ライブラリ**」
4. `Vertex AI API` を検索 → 「**有効にする**」

---

## ステップ 2. Vertex AI 用サービスアカウントを作成

[DRIVE_SETUP.md ステップ2](./DRIVE_SETUP.md#ステップ-2-サービスアカウントを作成) と同じ手順で、別のサービスアカウントを作成：

- **サービスアカウント名**: 例 `olivenote-vertex`
- **ロール**: `Vertex AI ユーザー` を付与（重要）

JSONキーを発行 → `client_email` と `private_key` を控える。

---

## ステップ 3. リージョン（Location）の決定

通常は `us-central1` でOK（東京リージョン `asia-northeast1` でも Gemini は使えますが、モデルによっては未対応）。

---

## ステップ 4. インストーラに入力

| 項目 | 値 |
|---|---|
| Vertex Project ID    | 上記プロジェクトの **プロジェクトID**（プロジェクト名ではない） |
| Vertex Location      | `us-central1` |
| Vertex SA メールアドレス | `olivenote-vertex@xxx.iam.gserviceaccount.com` |
| Vertex SA 秘密鍵 (PEM) | 発行した JSON 内の `private_key` |

---

## ステップ 5. 動作確認

ログイン後：

1. 課題を開く → 右上「**AIに相談**」ボタン → チャットが応答するか
2. 画面右下のフローティング「💬」→ ツールの使い方を質問 → 応答するか
3. （管理者）設定 → リリースノート → 雑なメモを入力 → 「AIで清書」が動くか

---

## 🚨 トラブルシュート

### `Permission denied: Vertex AI API has not been used`

→ ステップ1の API 有効化を再確認。プロジェクトを間違えていないか。

### `404 Model not found`

→ モデル名のリージョン対応を確認。デフォルトは us-central1 想定。

### 課金が気になる

→ Gemini Flash の入力1Mトークンあたり数十円〜。日常運用では月1000円以内に収まることが多いですが、念のため Google Cloud 側で **予算アラート** を設定しておくと安心。
