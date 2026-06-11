# LINE WORKS 連携 セットアップ手順

Olive Note を LINE WORKS と連携すると、次のことができます。

- **通知（Olive Note → LINE WORKS）**：コメント/課題でのメンション、課題の期限・開始日リマインド、週次の活動サマリを各メンバーの LINE WORKS に配信。
- **起票（LINE WORKS → Olive Note）**：Bot にメモを送ると、ヒアリング形式で課題を作成。

連携は任意です。設定しなければ Olive Note は通常どおり動作します。

---

## 全体の流れ

1. LINE WORKS Developer Console で **アプリ(App)** を作る（4つの値を取得）
2. **Bot** を作る（2つの値を取得）＋ Callback URL を登録
3. 管理者画面で Bot を公開・メンバーに追加
4. Olive Note の管理画面「LINE WORKS 接続設定」に値を入力
5. 各メンバーに **LINE WORKS ID** を登録
6. （任意）定期通知のために Cron を登録

> 入口：https://developers.worksmobile.com/ → 管理者でログイン → Console。**API 2.0** を使います。

---

## STEP 1. アプリ(App)を作成

Console → **API 2.0 → アプリ → アプリの追加**。作成後、アプリ詳細で以下を取得します。

| 取得する値 | Olive Note の設定項目 |
|---|---|
| Client ID | Client ID |
| Client Secret | Client Secret |
| Service Account（`xxxx.serviceaccount@ドメイン`） | Service Account |
| Private Key（発行/ダウンロード。`-----BEGIN PRIVATE KEY-----` 〜 `-----END-----`） | Private Key |

**OAuth Scopes** に次の3つを必ず追加してください（不足すると API がエラーになります）。

- `bot`
- `bot.message`
- `user.read`（受信時の本人特定・ID 検証に使用）

> ⚠️ Private Key は発行時にしか表示されないことがあります。その場で保存してください。

## STEP 2. Bot を作成

Console → **Bot → Bot の登録**。

| 設定 | 推奨値 |
|---|---|
| Bot名 | `Olive Note`（メンバーのトークに表示される名前） |
| Callback（メッセージ受信） | **使用する** |
| Callback URL | Olive Note を開いている URL の **`api.php?lw=callback`**（例: `https://example.com/olivenote/api.php?lw=callback`） |
| Callback Events | **「メッセージ」にチェック**（他は不要） |
| 複数人トークへの招待 | 許可しない（1:1運用でOK） |

作成後、Bot 詳細で以下を取得します。

| 取得する値 | Olive Note の設定項目 |
|---|---|
| Bot No.（数字） | Bot No. |
| Bot Secret | Bot Secret |

最後に、この **Bot を STEP1 のアプリに連携（紐付け）** します。

## STEP 3. Bot を公開してメンバーに追加

LINE WORKS の **管理者画面**（Developer Console ではなく通常の管理画面）→ **サービス → Bot** で、作成した Bot を **公開／利用中** にし、利用範囲を **全メンバー**、メンバーへの追加を **自動追加** にします。これで各メンバーが Bot との 1:1 トークでメモを送れるようになります。

## STEP 4. Olive Note に値を入力

管理者で Olive Note にログイン → **管理画面「LINE WORKS 接続設定」**（`app/admin/lineworks_settings.php`）を開き、STEP1・2 で取得した値を入力して保存します。

- **Cron Token** は自分で決める任意のランダム文字列です（定期通知 URL を保護する合言葉）。32文字以上の英数字を推奨。
- Private Key は PEM をそのまま（改行ごと）貼り付けて構いません。

## STEP 5. 各メンバーの LINE WORKS ID を登録

Olive Note → **設定 → メンバー管理** で、各メンバーの **LINE WORKS ID**（ログインID＝メール形式のID。管理者画面のメンバー一覧で確認できます）を入力します。

> **重要**：LINE WORKS ID が未登録のメンバーには通知が届かず、本人の「LINE WORKS 通知設定」画面も操作できません。連携を使う人は全員登録してください。

各メンバーは **設定 → LINE WORKS 通知** で、自分が受け取る通知の種類（メンション／期限 5・3・1・当日・超過／開始 3・1・当日／週次サマリと配信曜日）を個別に ON/OFF できます。

## STEP 6.（任意）定期通知の Cron

期限・開始日リマインドと週次サマリは、サーバーの Cron から1日1回エンドポイントを叩いて配信します。

```
curl -s "（Olive Note のURL）/api.php?lw=cron&token=（STEP4のCron Token）" > /dev/null 2>&1
```

推奨は **毎朝 8:00**。`{"success":true,...}` が返れば成功です（該当タスクが無ければ `sent:0` で正常）。URL は必ずダブルクォートで囲んでください（`&` でコマンドが切れるのを防ぐため）。

---

## うまくいかないとき

- **通知が届かない**：そのメンバーの LINE WORKS ID が正しいか、本人の通知設定が ON か、Bot がそのメンバーに追加されているかを確認。
- **Bot にメモを送ると「アカウントと紐付いていない」と返る**：そのメンバーの LINE WORKS ID が登録されているか確認。`user.read` スコープが付いているかも確認。
- **Cron が `forbidden`**：設定画面の Cron Token と Cron コマンドの `token=` が一致しているか確認。
- サーバーの PHP エラーログに `[lineworks]` で始まる行が出ます。原因切り分けの手がかりになります。
