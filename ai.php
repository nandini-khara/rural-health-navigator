<?php
// ai.php — All Claude AI functions
require_once 'config.php';

// ─────────────────────────────────────────────────────────────
// CORE: Send messages to Claude API and get back a text reply
// ─────────────────────────────────────────────────────────────
function callClaude($messages, $systemPrompt) {
    $data = [
        'model'      => 'claude-sonnet-4-20250514',
        'max_tokens' => 1000,
        'system'     => $systemPrompt,
        'messages'   => $messages
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01'
        ],
        CURLOPT_POSTFIELDS => json_encode($data)
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return json_encode([
            'needs_more_info' => false,
            'error'           => 'Connection error: ' . $curlError,
            'urgency'         => 'high',
            'possible_causes' => 'Unable to reach AI service.',
            'recommended_action' => 'Please try again or visit a doctor directly.',
            'safe_home_remedies' => '',
            'when_to_see_doctor' => 'Visit a doctor immediately if symptoms are severe.'
        ]);
    }

    $result = json_decode($response, true);
    $text   = $result['content'][0]['text'] ?? '';

    // Strip markdown code fences if Claude wraps the JSON
    $text = trim(preg_replace('/^```json\s*|^```\s*|```\s*$/m', '', $text));
    return $text;
}

// ─────────────────────────────────────────────────────────────
// QUICK CHECK — anonymous symptom assessment
// ─────────────────────────────────────────────────────────────
function getQuickAssessment($symptoms, $conversationHistory = []) {

    $system = <<<PROMPT
You are a rural health assistant helping people in India who may be far from a hospital.
Your job is to assess symptoms and give safe, general guidance.

RULES:
- If the symptom description is too vague to assess (fewer than 2 clear symptoms), ask 2-3 short clarifying questions in simple English.
- If you have enough information, give a full assessment.
- Never diagnose. Only give general guidance that is safe for a non-doctor to follow.
- Always recommend seeing a doctor for anything serious.

You MUST respond ONLY with a valid JSON object — no other text before or after it.

If you need more information:
{
  "needs_more_info": true,
  "questions": ["Question 1?", "Question 2?"],
  "urgency": null,
  "possible_causes": null,
  "recommended_action": null,
  "safe_home_remedies": null,
  "when_to_see_doctor": null
}

If you have enough information:
{
  "needs_more_info": false,
  "questions": [],
  "urgency": "low",
  "possible_causes": "Explain possible causes in 2-3 simple sentences.",
  "recommended_action": "Step by step what the person should do right now.",
  "safe_home_remedies": "Simple safe remedies using things available at home.",
  "when_to_see_doctor": "Describe exactly which warning signs mean they must see a doctor."
}

urgency must be exactly one of: "low", "medium", or "high"
- low = can manage at home, monitor
- medium = see a doctor within 1-2 days
- high = go to hospital today / call ambulance
PROMPT;

    // Build the message array
    $messages = [];
    if (!empty($conversationHistory)) {
        $messages = $conversationHistory;
    } else {
        $messages[] = ['role' => 'user', 'content' => $symptoms];
    }

    $text   = callClaude($messages, $system);
    $parsed = json_decode($text, true);

    // If JSON parsing fails, return a safe fallback
    if (!$parsed) {
        return [
            'needs_more_info'    => false,
            'questions'          => [],
            'urgency'            => 'medium',
            'possible_causes'    => 'Could not parse AI response. Please try describing your symptoms again.',
            'recommended_action' => 'Please consult a doctor for a proper diagnosis.',
            'safe_home_remedies' => 'Rest and stay hydrated until you can see a doctor.',
            'when_to_see_doctor' => 'See a doctor as soon as possible.'
        ];
    }

    return $parsed;
}

// ─────────────────────────────────────────────────────────────
// DETAILED DISCUSSION — personalised with age, gender, location
// ─────────────────────────────────────────────────────────────
function getFullAssessment($symptoms, $age = '', $gender = '', $location = '', $conversationHistory = []) {

    // Build patient context string
    $patientInfo = '';
    if ($age)      $patientInfo .= "Age: $age. ";
    if ($gender)   $patientInfo .= "Gender: $gender. ";
    if ($location) $patientInfo .= "Location: $location. ";

    $system = <<<PROMPT
You are a thorough rural health advisor helping people in India.
Patient details provided: {$patientInfo}

Your job is to give a deeper, more personalised health assessment based on the patient's age, gender, and location context.
Consider age-related risks (e.g. elderly patients, children under 5 need faster escalation).
Consider gender-specific conditions where relevant.

RULES:
- If symptoms are unclear, ask 2-3 targeted clarifying questions.
- If you have enough information, give a full personalised assessment.
- Never diagnose. Give general guidance that is safe for a non-doctor.
- Be more detailed and precise than a quick check since you have patient details.

You MUST respond ONLY with a valid JSON object — no other text before or after it.

If you need more information:
{
  "needs_more_info": true,
  "questions": ["Question 1?", "Question 2?"],
  "urgency": null,
  "possible_causes": null,
  "recommended_action": null,
  "safe_home_remedies": null,
  "when_to_see_doctor": null,
  "specialist_type": null
}

If you have enough information:
{
  "needs_more_info": false,
  "questions": [],
  "urgency": "low",
  "possible_causes": "Detailed explanation of likely causes considering the patient's age and gender.",
  "recommended_action": "Clear numbered steps the person should take.",
  "safe_home_remedies": "Specific home care advice appropriate for this patient.",
  "when_to_see_doctor": "Specific warning signs that mean they must get medical help immediately.",
  "specialist_type": "general physician"
}

urgency must be exactly: "low", "medium", or "high"
specialist_type examples: "general physician", "cardiologist", "dermatologist", "gynaecologist", "paediatrician", "orthopaedic", "ENT specialist", "neurologist"
PROMPT;

    $messages = [];
    if (!empty($conversationHistory)) {
        $messages = $conversationHistory;
    } else {
        $messages[] = ['role' => 'user', 'content' => $symptoms];
    }

    $text   = callClaude($messages, $system);
    $parsed = json_decode($text, true);

    if (!$parsed) {
        return [
            'needs_more_info'    => false,
            'questions'          => [],
            'urgency'            => 'medium',
            'possible_causes'    => 'Could not parse AI response. Please try again.',
            'recommended_action' => 'Please consult a doctor for a proper diagnosis.',
            'safe_home_remedies' => 'Rest and stay hydrated until you can see a doctor.',
            'when_to_see_doctor' => 'See a doctor as soon as possible.',
            'specialist_type'    => 'general physician'
        ];
    }

    return $parsed;
}

// ─────────────────────────────────────────────────────────────
// EMERGENCY — find hospitals from DB based on district/state
// (Google Places is optional and costs money — we use our DB first)
// ─────────────────────────────────────────────────────────────
function getHospitalsFromDB($district, $state) {
    require_once 'db.php';
    try {
        $db   = getDB();
        $stmt = $db->prepare(
            "SELECT name, hospital_type, address, phone_primary, phone_emergency, ambulance_number
             FROM hospitals_directory
             WHERE is_active = 1
               AND (district LIKE ? OR state LIKE ?)
             ORDER BY hospital_type ASC
             LIMIT 5"
        );
        $stmt->execute(["%$district%", "%$state%"]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}
?>