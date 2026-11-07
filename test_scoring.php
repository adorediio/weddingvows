<?php
// To be remove once the final code is implemented
require __DIR__ . '/api/choices.php';

$choices = [
    ['choiceID' => 'c01'],
    ['choiceID' => 'c05'],
    ['choiceID' => 'c01'],
    ['choiceID' => 'zz'],     // unknown choice, must be skipped
];
 
print_r(tallyChoices($choices));
// Expected with the sample table:
//   Financial Problems    => 4   (c01 twice, 2 points each)
//   Adultery & Infidelity => 3   (c05 once)