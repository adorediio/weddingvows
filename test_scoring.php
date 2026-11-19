<?php
// Run from the project root: php test_scoring.php
// Needs the room files in /rooms (room1.json ... room4.json).
require __DIR__ . '/api/choices.php';

$choices = [
    ['choiceID' => 'r2_02'],   // Adultery & Infidelity, 2
    ['choiceID' => 'r2_06'],   // Adultery & Infidelity, 2
    ['choiceID' => 'r4_01'],   // Unreasonable Behaviour, 1
    ['choiceID' => 'r4_10'],   // Domestic Violence & Abuses, 3
    ['choiceID' => 'r1_01'],   // neutral choice (no reason), must be skipped
    ['choiceID' => 'zz'],      // unknown choice, must be skipped
];

print_r(tallyChoices($choices));
// Expected, highest first:
//   Adultery & Infidelity      => 4
//   Domestic Violence & Abuses => 3
//   Unreasonable Behaviour     => 1

echo "\nroom_done checks (expect true, then false):\n";
var_dump(getChoiceRule('r1_03')['room_done']);   // next scene is an ending
var_dump(getChoiceRule('r1_01')['room_done']);   // next scene is r1_s2a