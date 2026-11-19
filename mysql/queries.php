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