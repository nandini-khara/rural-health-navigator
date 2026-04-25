<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../db.php';
require_once '../ai.php';

$input = json_decode(file_get_contents('php://input'), true);
$symptoms = trim($input['symptoms'] ?? '');
$history  = $input['history']   ?? [];
$sessionId = $input['session_id'] ?? null;

if (!$symptoms && empty($history)) {
    echo json_encode(['error' => 'No symptoms provided']); exit;
}

// Create session on first message
if (!$sessionId) {
    $sessionId = createSession('quick');
}

// Add new user message to history
$history[] = ['role' => 'user', 'content' => $symptoms ?: end($history)['content']];

$result = getQuickAssessment($symptoms, $history);

// If AI gave a full assessment, save it to DB
if ($result && !$result['needs_more_info']) {
    saveAssessment(
        $sessionId,
        $symptoms,
        $result['urgency'],
        $result['recommended_action'],
        $result['possible_causes'],
        $result['recommended_action']
    );
}

// Add AI response to history for next turn
$history[] = ['role' => 'assistant', 'content' => json_encode($result)];

echo json_encode([
    'session_id' => $sessionId,
    'result'     => $result,
    'history'    => $history
]);
?>