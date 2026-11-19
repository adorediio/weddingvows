<?php
// Run from the project root: php test_profile.php
// Needs MySQL running with divorce_db imported (no MongoDB needed).
require __DIR__ . '/api/profile.php';

echo "Age groups: " . implode(' | ', knownAgeGroups()) . "\n\n";
echo json_encode(buildProfileComparison('30-34'), JSON_PRETTY_PRINT) . "\n";

// An unknown group must be refused
try {
    buildProfileComparison('99-100');
} catch (InvalidArgumentException $e) {
    echo "\nRefused as expected: " . $e->getMessage() . "\n";
}