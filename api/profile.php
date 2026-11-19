<?php
// "Personal start": compares the player's age group with the national divorce data.
// The age and duration tables are grouped (25-29, 5-9 Years ...), so the "median" here is
// the median GROUP: the group that contains the middle divorce.
require_once __DIR__ . '/../mysql/queries.php';

// Age groups from youngest to oldest. "Unknown Age" is left out on purpose.
const AGE_GROUP_ORDER = ['Under 25', '25-29', '30-34', '35-39', '40-44', '45-49', '50-54', '55-59', '60 & Over'];
const DURATION_ORDER  = ['Under 5 Years', '5-9 Years', '10-14 Years', '15-19 Years', '20-24 Years', '25-29 Years', '30 Years & Over'];

// The age groups the player can pick: those in the database, in the right order.
function knownAgeGroups(): array {
    return array_values(array_intersect(AGE_GROUP_ORDER, ageGroupNames()));
}

// Index of the group that holds the middle value of a list of counts.
function medianIndex(array $counts): int {
    $half = array_sum($counts) / 2;
    $run  = 0;
    foreach ($counts as $i => $n) {
        $run += $n;
        if ($run >= $half) {
            return $i;
        }
    }
    return max(0, count($counts) - 1);
}

function buildProfileComparison(string $ageGroup, ?int $year = null): array {
    $year   = $year ?? latestYear();
    $groups = knownAgeGroups();
    $mine   = array_search($ageGroup, $groups, true);
    if ($mine === false) {
        throw new InvalidArgumentException('Unknown age group');
    }

    // --- age: people who divorced, per age group (both genders added) ---
    $perGroup = array_fill_keys($groups, 0);
    foreach (ageStats($year) as $row) {
        if (isset($perGroup[$row['age_group']])) {
            $perGroup[$row['age_group']] += $row['count'];
        }
    }
    $counts   = array_values($perGroup);
    $total    = array_sum($counts);
    $myCount  = $perGroup[$ageGroup];
    $rank     = 1 + count(array_filter($counts, function ($n) use ($myCount) { return $n > $myCount; }));
    $median   = medianIndex($counts);

    $byGender = ageGroupByGender($ageGroup, $year);
    $groupSum = array_sum(array_column($byGender, 'count'));
    foreach ($byGender as $i => $row) {
        $byGender[$i]['percent'] = $groupSum ? round($row['count'] * 100 / $groupSum, 1) : 0;
    }

    $bars = [];
    foreach ($groups as $i => $g) {
        $bars[] = [
            'age_group' => $g,
            'count'     => $counts[$i],
            'percent'   => $total ? round($counts[$i] * 100 / $total, 1) : 0,
        ];
    }

    // --- duration: divorces per marriage length, all reasons added together ---
    $durations = [];
    foreach (durationStats($year) as $row) {
        $durations[$row['duration']] = $row['count'];
    }
    $labels   = array_values(array_intersect(DURATION_ORDER, array_keys($durations)));
    $dCounts  = array_map(function ($l) use ($durations) { return $durations[$l]; }, $labels);
    $dTotal   = array_sum($dCounts);

    return [
        'year'      => $year,
        'age_group' => $ageGroup,
        'age' => [
            'count'            => $myCount,
            'percent'          => $total ? round($myCount * 100 / $total, 1) : 0,
            'rank'             => $rank,
            'of_groups'        => count($groups),
            'by_gender'        => $byGender,                 // gender, count, percent of the group
            'median_group'     => $groups[$median],
            'steps_from_median' => $mine - $median,          // negative = younger than the median
            'groups'           => $bars,
        ],
        'duration' => [
            'median_label'   => $labels ? $labels[medianIndex($dCounts)] : null,
            'under5_percent' => ($dTotal && $labels && $labels[0] === 'Under 5 Years') ? round($dCounts[0] * 100 / $dTotal, 1) : null,
            'total'          => $dTotal,
        ],
    ];
}