<?php
require_once 'config.php';

function getDB() {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

// Creates a new session row, returns the session ID
function createSession($mode) {
    $db = getDB();
    $id = uniqid('rhn_', true);
    $db->prepare("INSERT INTO sessions (session_id, mode) VALUES (?, ?)")
       ->execute([$id, $mode]);
    return $id;
}

// Saves the AI result into health_assessments table
function saveAssessment($sessionId, $symptoms, $urgency, $aiResponse, $causes, $action) {
    $db = getDB();
    $db->prepare("INSERT INTO health_assessments 
        (session_id, symptom_text, urgency_level, ai_response, possible_causes, recommended_action)
        VALUES (?, ?, ?, ?, ?, ?)")
       ->execute([$sessionId, $symptoms, $urgency, $aiResponse, $causes, $action]);
    return $db->lastInsertId();
}
?>