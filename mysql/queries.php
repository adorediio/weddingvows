<?php
// To find reasons for divorces
require_once __DIR__ . '/db.php';

// Latest year that has divorce rates
function latestYear() : int {
    $rows = getPdo() -> query("SELECT MAX(year_id) AS y FROM Fact_Divorce_Reason") -> fetchAll();
    return (Int) $rows[0]['y'];
}

// Reasons for the divorce and the quantity
function reasonStats(int $year): array {
    $sql = "SELECT r.reason_name AS reason, f.divorce_count AS count
    FROM fact_divorce_reason f
    JOIN dim_reason r on r.reason_id = f.reason_id
    JOIN dim_marriage_duration d on d.duration_id = f.duration_id
    WHERE d.marriage_duration = 'Total' AND f.year_id = ?
    ORDER BY f.divorce_count DESC";

    $st = getPdo()->prepare($sql);
    $st-> execute([$year]);
    $rows = $st->fetchAll();

    // Rank and percent of all divorces in that year
        $total = array_sum(array_column($rows, 'count'));
        foreach ($rows as $i => $row) {
            $rows[$i]['count']   = (int) $row['count'];
            $rows[$i]['rank']    = $i + 1;
            $rows[$i]['percent'] = $total ? round($row['count'] * 100 / $total, 1) : 0;
        }
        return $rows;
}

// To show divorces by gender and age group for each year
function ageStats(int $year): array{
    $sql = "SELECT g.gender_name AS gender, a.age_group AS age_group, fda.divorce_count AS count
            FROM Fact_Divorce_Age fda
            JOIN Dim_Gender g ON g.gender_id = fda.gender_id
            JOIN Dim_Age_Group a ON a.age_group_id = fda.age_group_id
            WHERE fda.year_id = ?
            ORDER BY g.gender_name, a.age_group_id";

    $st = getPdo()->prepare($sql);
    $st-> execute([$year]);
    $rows = $st->fetchAll();

    // percent of all divorces in that year
    $total = array_sum(array_column($rows, 'count'));
    foreach ($rows as $i => $row) {
        $rows[$i]['count']   = (int) $row['count'];
        $rows[$i]['percent'] = $total ? round($row['count'] * 100 / $total, 1) : 0;
    }
    return $rows;
}

// One reason across all years (marriage duration "Total"), oldest year first
function reasonTrend(string $reason): array {
    $sql = "SELECT f.year_id AS yr, f.divorce_count AS count
            FROM Fact_Divorce_Reason f
            JOIN Dim_Reason r ON r.reason_id = f.reason_id
            JOIN Dim_Marriage_Duration d ON d.duration_id = f.duration_id
            WHERE d.marriage_duration = 'Total' AND r.reason_name = ?
            ORDER BY f.year_id";
 
    $st = getPdo()->prepare($sql);
    $st->execute([$reason]);
    $rows = $st->fetchAll();
 
    foreach ($rows as $i => $row) {
        $rows[$i] = ['year' => (int) $row['yr'], 'count' => (int) $row['count']];
    }
    return $rows;
}
 
// One reason in one year, split by how long the marriage lasted (biggest group first)
function reasonByDuration(string $reason, int $year): array {
    $sql = "SELECT d.marriage_duration AS duration, f.divorce_count AS count
            FROM Fact_Divorce_Reason f
            JOIN Dim_Reason r ON r.reason_id = f.reason_id
            JOIN Dim_Marriage_Duration d ON d.duration_id = f.duration_id
            WHERE d.marriage_duration <> 'Total' AND r.reason_name = ? AND f.year_id = ?
            ORDER BY f.divorce_count DESC";
 
    $st = getPdo()->prepare($sql);
    $st->execute([$reason, $year]);
    $rows = $st->fetchAll();
 
    $total = array_sum(array_column($rows, 'count'));
    foreach ($rows as $i => $row) {
        $rows[$i]['count'] = (int) $row['count'];
        $rows[$i]['share'] = $total ? round($row['count'] * 100 / $total, 1) : 0;
    }
    return $rows;
}

// Every age group name in the database (the order is fixed in api/profile.php)
function ageGroupNames(): array {
    return getPdo()->query("SELECT age_group FROM Dim_Age_Group")->fetchAll(PDO::FETCH_COLUMN);
}
 
// One age group in one year, split by gender (two joins, two filters)
function ageGroupByGender(string $ageGroup, int $year): array {
    $sql = "SELECT g.gender_name AS gender, fda.divorce_count AS count
            FROM Fact_Divorce_Age fda
            JOIN Dim_Gender g ON g.gender_id = fda.gender_id
            JOIN Dim_Age_Group a ON a.age_group_id = fda.age_group_id
            WHERE a.age_group = ? AND fda.year_id = ?
            ORDER BY g.gender_name";
 
    $st = getPdo()->prepare($sql);
    $st->execute([$ageGroup, $year]);
    $rows = $st->fetchAll();
 
    foreach ($rows as $i => $row) {
        $rows[$i]['count'] = (int) $row['count'];
    }
    return $rows;
}
 
// Divorces per marriage length in one year, all reasons added together.
// "Total" is a pre-added row in the table, so it is filtered out here.
function durationStats(int $year): array {
    $sql = "SELECT d.marriage_duration AS duration, SUM(f.divorce_count) AS count
            FROM Fact_Divorce_Reason f
            JOIN Dim_Marriage_Duration d ON d.duration_id = f.duration_id
            WHERE d.marriage_duration <> 'Total' AND f.year_id = ?
            GROUP BY d.marriage_duration";
 
    $st = getPdo()->prepare($sql);
    $st->execute([$year]);
    $rows = $st->fetchAll();
 
    foreach ($rows as $i => $row) {
        $rows[$i]['count'] = (int) $row['count'];
    }
    return $rows;
}

// Mid-game HUD: Deep dive on a single divorce reason as the player encounters it
function midgameReasonSpotlight(string $reason, ?int $year = null): array {
    ensureViews();
    $year = $year ?? latestYear();
    $pdo = getPdo();

    // 1. National total, share %, rank, and YoY shift using Window Functions
    $summarySql = "
        WITH yearly AS (
            SELECT
                year,
                reason,
                count,
                ROUND(count * 100.0 / NULLIF(SUM(count) OVER (PARTITION BY year), 0), 1) AS share_pct,
                RANK() OVER (PARTITION BY year ORDER BY count DESC) AS yearly_rank
            FROM vw_divorce_reason_yearly
            WHERE marriage_duration = 'Total'
        ),
        with_yoy AS (
            SELECT
                year,
                reason,
                count,
                share_pct,
                yearly_rank,
                LAG(count) OVER (PARTITION BY reason ORDER BY year) AS prev_count,
                ROUND(share_pct - LAG(share_pct) OVER (PARTITION BY reason ORDER BY year), 1) AS yoy_pts
            FROM yearly
        )
        SELECT * FROM with_yoy
        WHERE reason = ? AND year = ?
    ";
    $st = $pdo->prepare($summarySql);
    $st->execute([$reason, $year]);
    $summary = $st->fetch() ?: null;

    // 2. Which marriage duration is hit hardest by this exact reason?
    $durSql = "
        SELECT
            marriage_duration,
            count,
            ROUND(count * 100.0 / NULLIF(SUM(count) OVER (), 0), 1) AS share_of_reason_pct
        FROM vw_divorce_reason_yearly
        WHERE year = ? AND reason = ? AND marriage_duration <> 'Total'
        ORDER BY count DESC
    ";
    $stDur = $pdo->prepare($durSql);
    $stDur->execute([$year, $reason]);
    $durations = $stDur->fetchAll();

    foreach ($durations as &$d) {
        $d['count'] = (int) $d['count'];
        $d['share_of_reason_pct'] = (float) $d['share_of_reason_pct'];
    }
    unset($d);

    return [
        'year'              => $year,
        'reason'            => $reason,
        'summary'           => $summary ? [
            'count'      => (int) $summary['count'],
            'share_pct'  => (float) $summary['share_pct'],
            'rank'       => (int) $summary['yearly_rank'],
            'prev_count' => $summary['prev_count'] !== null ? (int) $summary['prev_count'] : null,
            'yoy_pts'    => $summary['yoy_pts'] !== null ? (float) $summary['yoy_pts'] : null,
        ] : null,
        'peak_duration'     => $durations[0] ?? null,
        'by_duration'       => array_slice($durations, 0, 4),
    ];
}

// Room Hub Ticker & Halfway Checkpoint: Live national snapshot across both fact tables
function hubSqlFacts(?int $year = null): array {
    ensureViews();
    $year = $year ?? latestYear();
    $pdo = getPdo();

    // Peak age group in latest year (Fact_Divorce_Age)
    $ageSql = "
        SELECT
            age_group,
            SUM(count) AS total_count,
            ROUND(SUM(count) * 100.0 / NULLIF(SUM(SUM(count)) OVER (), 0), 1) AS pct
        FROM vw_divorce_age_yearly
        WHERE year = ? AND age_group <> 'Unknown Age'
        GROUP BY age_group
        ORDER BY total_count DESC
        LIMIT 1
    ";
    $stAge = $pdo->prepare($ageSql);
    $stAge->execute([$year]);
    $peakAge = $stAge->fetch();

    // Gender split in latest year (Fact_Divorce_Age)
    $genderSql = "
        SELECT gender, SUM(count) AS total_count
        FROM vw_divorce_age_yearly
        WHERE year = ?
        GROUP BY gender
    ";
    $stGen = $pdo->prepare($genderSql);
    $stGen->execute([$year]);
    $genders = $stGen->fetchAll();

    // Top 2 divorce reasons in latest year (Fact_Divorce_Reason)
    $topReasons = array_slice(reasonStats($year), 0, 2);

    // Biggest 1-year jump in reasons (e.g. Mutual Agreement introduced in 2024/2025)
    $jumpSql = "
        WITH diff AS (
            SELECT
                year,
                reason,
                count,
                count - LAG(count) OVER (PARTITION BY reason ORDER BY year) AS delta
            FROM vw_divorce_reason_yearly
            WHERE marriage_duration = 'Total'
        )
        SELECT reason, count, delta
        FROM diff
        WHERE year = ? AND delta IS NOT NULL
        ORDER BY delta DESC
        LIMIT 1
    ";
    $stJump = $pdo->prepare($jumpSql);
    $stJump->execute([$year]);
    $biggestRise = $stJump->fetch();

    return [
        'year'         => $year,
        'peak_age'     => $peakAge ? [
            'age_group' => $peakAge['age_group'],
            'count'     => (int) $peakAge['total_count'],
            'percent'   => (float) $peakAge['pct'],
        ] : null,
        'genders'      => $genders,
        'top_reasons'  => $topReasons,
        'biggest_rise' => $biggestRise ? [
            'reason' => $biggestRise['reason'],
            'count'  => (int) $biggestRise['count'],
            'delta'  => (int) $biggestRise['delta'],
        ] : null,
    ];
}