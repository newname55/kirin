# 採用結果分析 SQL

AI評価 → 店長判断 → 実結果 の検証クエリ集。

対象テーブル: `crew_applicants`
対象DB: `twin_crew_dev`（Raspi4開発）/ `twin`（Raspi5本番）

---

## 1. Grade別 採用率

AIが出した Grade ごとに、実際に採用された割合。

```sql
SELECT
    priority_grade                                         AS grade,
    COUNT(*)                                               AS 応募者数,
    SUM(outcome_status = 'hired')                         AS 採用数,
    SUM(outcome_status = 'declined')                      AS 辞退数,
    SUM(outcome_status = 'rejected')                      AS 不採用数,
    ROUND(SUM(outcome_status = 'hired') / COUNT(*) * 100) AS 採用率_pct
FROM crew_applicants
WHERE outcome_status IS NOT NULL
GROUP BY priority_grade
ORDER BY FIELD(priority_grade, 'A', 'B', 'C', 'D');
```

---

## 2. Grade別 定着率

採用後に継続しているか（退店していないか）。

```sql
SELECT
    priority_grade                                              AS grade,
    COUNT(*)                                                    AS 採用数,
    SUM(outcome_retention = 'active')                          AS 継続中,
    SUM(outcome_retention = 'left')                            AS 退店,
    ROUND(SUM(outcome_retention = 'active') / COUNT(*) * 100) AS 定着率_pct
FROM crew_applicants
WHERE outcome_status = 'hired'
  AND outcome_retention IS NOT NULL
  AND outcome_retention != 'unknown'
GROUP BY priority_grade
ORDER BY FIELD(priority_grade, 'A', 'B', 'C', 'D');
```

---

## 3. Grade別 売上ランク分布

AIスコアと実際の売上が一致しているか。

```sql
SELECT
    priority_grade   AS grade,
    outcome_sales_rank AS 売上ランク,
    COUNT(*)         AS 人数
FROM crew_applicants
WHERE outcome_status = 'hired'
  AND outcome_sales_rank IS NOT NULL
GROUP BY priority_grade, outcome_sales_rank
ORDER BY
    FIELD(priority_grade, 'A', 'B', 'C', 'D'),
    FIELD(outcome_sales_rank, 'S', 'A', 'B', 'C', 'D');
```

---

## 4. AI推奨率別 売上ランク一致率

採用推奨率（A=95% / B=80% / C=60% / D=30%）と実結果の相関。

```sql
SELECT
    priority_grade                                                    AS grade,
    CASE priority_grade WHEN 'A' THEN 95 WHEN 'B' THEN 80
                        WHEN 'C' THEN 60 WHEN 'D' THEN 30 END       AS AI推奨率_pct,
    COUNT(*)                                                          AS 採用数,
    SUM(outcome_sales_rank IN ('S', 'A'))                            AS 高売上_SA,
    ROUND(SUM(outcome_sales_rank IN ('S', 'A')) / COUNT(*) * 100)   AS 高売上率_pct
FROM crew_applicants
WHERE outcome_status = 'hired'
  AND outcome_sales_rank IS NOT NULL
GROUP BY priority_grade
ORDER BY FIELD(priority_grade, 'A', 'B', 'C', 'D');
```

---

## 5. D評価だが高売上（AI外れ — 隠れた才能）

AIが低評価でも実際には活躍した応募者。共通点の手がかりになる。

```sql
SELECT
    id,
    experience,
    candidate_score,
    priority_grade,
    outcome_sales_rank,
    outcome_retention,
    outcome_bring,
    outcome_checked_at,
    outcome_note
FROM crew_applicants
WHERE priority_grade = 'D'
  AND outcome_sales_rank IN ('S', 'A', 'B')
  AND outcome_status = 'hired'
ORDER BY outcome_sales_rank, outcome_checked_at DESC;
```

---

## 6. A評価だが早期退店（AI外れ — 過大評価）

AIが高評価でも実際には定着しなかった応募者。評価指標の見直しに使う。

```sql
SELECT
    id,
    experience,
    candidate_score,
    priority_grade,
    outcome_sales_rank,
    outcome_retention,
    outcome_bring,
    outcome_checked_at,
    outcome_note
FROM crew_applicants
WHERE priority_grade = 'A'
  AND outcome_retention = 'left'
  AND outcome_status = 'hired'
ORDER BY outcome_checked_at DESC;
```

---

## 7. 呼客実績とAIスコアの相関

呼客力スコアが実際の呼客実績と一致しているか。

```sql
SELECT
    outcome_bring                                                       AS 呼客実績,
    COUNT(*)                                                            AS 人数,
    ROUND(AVG(candidate_score))                                         AS 平均スコア,
    SUM(priority_grade = 'A')                                          AS A評価数,
    SUM(priority_grade = 'B')                                          AS B評価数,
    SUM(priority_grade = 'C')                                          AS C評価数,
    SUM(priority_grade = 'D')                                          AS D評価数
FROM crew_applicants
WHERE outcome_status = 'hired'
  AND outcome_bring IS NOT NULL
  AND outcome_bring != 'unknown'
GROUP BY outcome_bring
ORDER BY FIELD(outcome_bring, 'high', 'normal', 'low');
```

---

## 8. 全体サマリー（定期確認用）

月次・四半期ごとの全体チェック。

```sql
SELECT
    COUNT(*)                                                AS 総応募数,
    SUM(outcome_status IS NOT NULL)                        AS 結果記録済み,
    SUM(outcome_status = 'hired')                          AS 採用数,
    SUM(outcome_status = 'declined')                       AS 辞退数,
    SUM(outcome_status = 'rejected')                       AS 不採用数,
    ROUND(AVG(candidate_score), 1)                         AS 平均スコア,
    SUM(outcome_retention = 'active')                      AS 現在継続中,
    SUM(outcome_retention = 'left')                        AS 退店済み,
    SUM(outcome_sales_rank IN ('S', 'A'))                  AS 高売上_SA数,
    ROUND(SUM(outcome_sales_rank IN ('S', 'A'))
        / NULLIF(SUM(outcome_status = 'hired'), 0) * 100) AS 高売上率_pct
FROM crew_applicants;
```

---

## 備考

- `outcome_checked_at` が入っているレコードのみ分析対象にするとデータの質が上がる
- 最低20件以上のサンプルが揃ってから傾向を読む（それ以下は偶然に左右される）
- D評価で成功したケースの `outcome_note` に理由を書いておくと、後でルール改善の根拠になる
