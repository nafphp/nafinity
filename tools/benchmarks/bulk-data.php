<?php

// Disposable fixture preparation for the nafinity-acceptance Compose project only.
if (getenv('APP_ENV') !== 'test' || getenv('DB_DATABASE') !== 'nafinity_probe') {
    throw new RuntimeException('Wrong measurement database');
}
$pdo      = new PDO('mysql:host=db;dbname=nafinity_probe', getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$board    = $pdo->query('SELECT id FROM boards WHERE project_id=1')->fetchColumn();
$first    = (int) $pdo->query('SELECT COALESCE(MAX(number),0)+1 FROM tickets WHERE project_id=1')->fetchColumn();
$position = (int) $pdo->query('SELECT COALESCE(MAX(position),0)+65536 FROM tickets WHERE project_id=1 AND column_id=1 AND swimlane_id=1')->fetchColumn();
$insert   = $pdo->prepare('INSERT INTO tickets(project_id,board_id,column_id,swimlane_id,number,title,description,priority,color,status,created_by,created_at,updated_at,position,version) VALUES(1,?,1,1,?,?,?,"normal","#6366f1","open",1,NOW(),NOW(),?,1)');
$ids      = [];
$pdo->beginTransaction();

try {
    for ($i = 0;$i < 5000;$i++) {
        $insert->execute([$board, $first + $i, 'Measurement sunflower ' . ($first + $i), 'Isolated bulk measurement', $position + $i * 65536]);
        $ids[] = (int) $pdo->lastInsertId();
    }$pdo->prepare("UPDATE boards SET next_number=?,revision=revision+1 WHERE id=?")->execute([$first + 5000, $board]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}
echo json_encode(['inserted' => count($ids), 'ids' => $ids]),PHP_EOL;
