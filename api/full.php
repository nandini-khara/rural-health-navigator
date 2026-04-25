<?php
// api/full.php — Detailed Discussion endpoint
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle browser preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once '../db.php';
require_once '../ai.php';

// Read JSON body sent from the frontend
$input     = json_decode(file_get_contents('php://input'), true);
$symptoms  = trim($input['symptoms']   ?? '');
$history   = $input['history']         ?? [];
$sessionId = $input['session_id']      ?? null;
$age       = trim($input['age']        ?? '');
$gender    = trim($input['gender']     ?? '');
$location  = trim($input['location']   ?? '');
$name      = trim($input['name']       ?? '');

// Must have symptoms OR an ongoing conversation
if (!$symptoms && empty($history)) {
    echo json_encode(['error' => 'No symptoms provided']);
    exit;
}

// Create a new session row the first time
if (!$sessionId) {
    $sessionId = createSession('full');

    // Save patient details if provided
    if ($age || $gender || $location) {
        try {
            $db = getDB();
            // Split location into district/state if possible (simple split on comma)
            $parts    = explode(',', $location);
            $district = trim($parts[0] ?? '');
            $state    = trim($parts[1] ?? '');

            $db->prepare(
                "INSERT INTO patient_details (session_id, age_group, gender, district, state)
                 VALUES (?, ?, ?, ?, ?)"
            )->execute([$sessionId, $age, $gender, $district, $state]);
        } catch (Exception $e) {
            // Not critical — continue even if this fails
        }
    }
}

// Build conversation history for multi-turn chat
if (empty($history)) {
    $history[] = ['role' => 'user', 'content' => $symptoms];
} else {
    // Follow-up message — append latest user reply
    if ($symptoms) {
        $history[] = ['role' => 'user', 'content' => $symptoms];
    }
}

// Call Claude with full patient context
$result = getFullAssessment($symptoms, $age, $gender, $location, $history);

// If we got a full result, save it to the database
if ($result && isset($result['needs_more_info']) && !$result['needs_more_info']) {
    $assessmentId = saveAssessment(
        $sessionId,
        $symptoms,
        $result['urgency']            ?? 'medium',
        $result['recommended_action'] ?? '',
        $result['possible_causes']    ?? '',
        $result['recommended_action'] ?? ''
    );
}

// Append AI reply to history so the next request stays in context
$history[] = ['role' => 'assistant', 'content' => json_encode($result)];

// Send back everything the frontend needs
echo json_encode([
    'session_id' => $sessionId,
    'result'     => $result,
    'history'    => $history
]);
?>