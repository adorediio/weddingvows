<?php
// To be remove once the final code is implemented
require __DIR__ . '/api/outcome.php';
 
$choices = [
    ['choiceID' => 'c01'],
    ['choiceID' => 'c05'],
    ['choiceID' => 'c01'],
];
 
echo json_encode(buildOutcome($choices, 2025), JSON_PRETTY_PRINT) . "\n";