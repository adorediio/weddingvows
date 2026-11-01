<?php
require __DIR__ . '/mongodb/mongo.php';

$pid = createPlayer('testuser_' . rand(100, 999), 'test@example.com');
$sid = startSession($pid);
saveChoice($sid, 'c01', 'scene_01', 'scene_02');
saveChoice($sid, 'c05', 'scene_02', 'scene_03');
print_r(getSession($sid));
completeSession($sid);
echo "Done\n";