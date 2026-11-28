<?php
// Run from the project root: php test_scoring.php
// Reads rooms from MongoDB (run php mongodb/seed_rooms.php first), or from /rooms when ROOMS_SOURCE=json.
require __DIR__ . '/api/choices.php';

$choices = [
    ['choiceID' => 'r2_06'],   // Reply "Maybe"            Adultery & Infidelity, 2
    ['choiceID' => 'r2_25'],   // Read it in the bathroom  Adultery & Infidelity, 2
    ['choiceID' => 'r4_01'],   // Match his volume         Unreasonable Behaviour, 1
    ['choiceID' => 'r4_20'],   // Call for help            Domestic Violence & Abuses, 3
    ['choiceID' => 'r1_01'],   // neutral choice (no reason), must be skipped
    ['choiceID' => 'zz'],      // unknown choice, must be skipped
];

print_r(tallyChoices($choices));
// Expected, highest first:
//   Adultery & Infidelity      => 4
//   Domestic Violence & Abuses => 3
//   Unreasonable Behaviour     => 1

echo "\nroom_done checks (expect true, then false):\n";
var_dump(getChoiceRule('r1_11')['room_done']);   // "Sit down tonight and make a plan" -> ending
var_dump(getChoiceRule('r1_01')['room_done']);   // "Soak it in" -> r1_s2a

echo "\nTotal choices loaded: " . count(loadChoiceRules()) . " (expect 272: the number of choices in the room files)\n";