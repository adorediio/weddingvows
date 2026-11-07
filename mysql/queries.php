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
