# crew-onboarding ペルソナテスト確認 SQL

`scripts/run_persona_tests.php` 実行後に Raspi4 で実行して結果を検証する。

```
sudo mysql twin_crew_dev
```

または

```
sudo mysql twin
```

---

## 1. ペルソナごとの最終スコア・Grade・LINE状態

```sql
SELECT
    cs.session_token,
    SUBSTRING(cs.session_token, 1, 16) AS token_short,
    a.experience,
    a.candidate_score,
    a.priority_grade,
    a.days_per_week,
    a.alcohol,
    a.bring_trial,
    a.referrals,
    CASE WHEN a.line_applied_at IS NOT NULL THEN 'あり' ELSE 'なし' END AS line_tapped,
    a.completed_at,
    a.created_at
FROM crew_applicants a
JOIN chat_sessions cs ON cs.id = a.session_id
WHERE cs.session_token LIKE 'persona_test_%'
ORDER BY a.created_at DESC;
```

---

## 2. P03 / P08 が Grade A・score 80以上か確認

```sql
SELECT
    cs.session_token,
    a.candidate_score,
    a.priority_grade,
    a.experience,
    a.referrals,
    a.bring_trial,
    a.bring_now,
    a.days_per_week,
    a.score_detail
FROM crew_applicants a
JOIN chat_sessions cs ON cs.id = a.session_id
WHERE cs.session_token IN (
    SHA2('persona_test_p03_' || DATE_FORMAT(NOW(), '%Y%m%d'), 256),
    SHA2('persona_test_p08_' || DATE_FORMAT(NOW(), '%Y%m%d'), 256)
)
ORDER BY a.created_at DESC;
```

> Grade A かつ candidate_score >= 80 であることを確認する。

---

## 3. P09 が experience = 'some' になったか確認

```sql
SELECT
    cs.session_token,
    a.experience,
    a.candidate_score,
    a.priority_grade,
    a.score_detail
FROM crew_applicants a
JOIN chat_sessions cs ON cs.id = a.session_id
WHERE cs.session_token LIKE 'persona_test_p09_%'
ORDER BY a.created_at DESC
LIMIT 1;
```

> experience = 'some' になっていることを確認する。
> 'none' になっていた場合は recruit_engine の経験判定を要確認。

---

## 4. intent 一覧（ペルソナ別）

```sql
SELECT
    cs.session_token,
    m.message,
    m.intent,
    m.sender,
    m.created_at
FROM chat_messages m
JOIN chat_sessions cs ON cs.id = m.session_id
WHERE cs.session_token LIKE 'persona_test_%'
  AND m.sender = 'user'
ORDER BY cs.session_token, m.id;
```

---

## 5. other に落ちた発話一覧

```sql
SELECT
    cs.session_token,
    m.message,
    m.intent,
    m.created_at
FROM chat_messages m
JOIN chat_sessions cs ON cs.id = m.session_id
WHERE cs.session_token LIKE 'persona_test_%'
  AND m.sender = 'user'
  AND (m.intent = 'other' OR m.intent IS NULL OR m.intent = '')
ORDER BY cs.session_token, m.id;
```

> P07（はる）の「学校・テスト・大学」、P10（りん）の「子供・育児・急な欠席」が
> ここに出ていれば intent 追加の候補。

---

## 6. P07 「学校・テスト・大学」の intent 確認

```sql
SELECT
    m.message,
    m.intent
FROM chat_messages m
JOIN chat_sessions cs ON cs.id = m.session_id
WHERE cs.session_token LIKE 'persona_test_p07_%'
  AND m.sender = 'user'
ORDER BY m.id;
```

> 「テスト期間は休ませてほしい」「大学のテストが」が
> `other` になっているか確認する。
> `other` → intent `student_schedule` 追加を検討。

---

## 7. P10 「子供・育児・急な欠席」の intent 確認

```sql
SELECT
    m.message,
    m.intent
FROM chat_messages m
JOIN chat_sessions cs ON cs.id = m.session_id
WHERE cs.session_token LIKE 'persona_test_p10_%'
  AND m.sender = 'user'
ORDER BY m.id;
```

> 「子供がいるので急な欠席は」「育児中でも働けますか」が
> `other` になっているか確認する。
> `other` → intent `childcare_concern` 追加を検討。

---

## 8. score_detail（スコア内訳）の確認

```sql
SELECT
    cs.session_token,
    a.priority_grade,
    a.candidate_score,
    JSON_PRETTY(a.score_detail) AS score_detail
FROM crew_applicants a
JOIN chat_sessions cs ON cs.id = a.session_id
WHERE cs.session_token LIKE 'persona_test_%'
ORDER BY a.priority_grade, a.candidate_score DESC;
```

> `route` が `novice` / `experienced` になっているか確認する。
> P09（まい・ガールズバー経験）は `novice` ルートになるはず。

---

## 9. LINE CTA 表示イベント確認

```sql
SELECT
    cs.session_token,
    e.event_name,
    e.event_value,
    e.created_at
FROM event_logs e
JOIN chat_sessions cs ON cs.id = e.session_id
WHERE cs.session_token LIKE 'persona_test_%'
  AND e.event_name IN ('line_cta_shown', 'cta_click')
ORDER BY cs.session_token, e.created_at;
```

---

## 10. テストデータの全削除（再テスト前）

```sql
-- 確認（削除前に件数を確認）
SELECT COUNT(*) FROM chat_sessions WHERE session_token LIKE 'persona_test_%';

-- 削除
DELETE ca FROM crew_applicants ca
JOIN chat_sessions cs ON cs.id = ca.session_id
WHERE cs.session_token LIKE 'persona_test_%';

DELETE el FROM event_logs el
JOIN chat_sessions cs ON cs.id = el.session_id
WHERE cs.session_token LIKE 'persona_test_%';

DELETE cm FROM chat_messages cm
JOIN chat_sessions cs ON cs.id = cm.session_id
WHERE cs.session_token LIKE 'persona_test_%';

DELETE FROM chat_sessions WHERE session_token LIKE 'persona_test_%';
```

> または `php scripts/run_persona_tests.php --clean` で自動削除してから再実行。

---

## 想定結果一覧

| ペルソナ | 経験 | 想定Grade | 想定score目安 | LINE | 特記 |
|---|---|---|---|---|---|
| P01 あかり | none | B | 50〜69 | あり（テスト上は記録なし） | 未経験ルート |
| P02 みく   | none | D | 0〜39  | なし | 未経験・飲めない・呼客なし |
| P03 さつき | yes  | A | 80以上 | なし（テスト上は記録なし） | 経験者ルート |
| P04 ゆい   | yes  | B | 50〜79 | なし | 経験者・週3〜4 |
| P05 れな   | none | B | 50〜69 | なし | 未経験・飲める・呼客あり |
| P06 なな   | yes  | C | 40〜59 | なし | 経験者・指名少・出勤少 |
| P07 はる   | none | D | 0〜39  | なし | 未経験・飲めない・出勤週1〜2 |
| P08 こと   | yes  | A | 80以上 | なし | 経験者・指名多・呼客強め |
| P09 まい   | some | C | 40〜59 | なし | experience=some が重要 |
| P10 りん   | yes  | C | 40〜59 | なし（テスト上は記録なし） | 経験者・ブランク・指名少 |

> LINE 列「なし」はテストスクリプトが `cta_click` イベントを送信しないため。
> 実際の応募者はチャット画面でボタンをタップしたときに記録される。
