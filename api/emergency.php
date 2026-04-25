<?php
// api/emergency.php — Emergency Services endpoint
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once '../db.php';
require_once '../ai.php';

$input     = json_decode(file_get_contents('php://input'), true);
$location  = trim($input['location']  ?? '');
$lat       = $input['lat']            ?? null;   // GPS latitude  (optional)
$lng       = $input['lng']            ?? null;   // GPS longitude (optional)
$sessionId = $input['session_id']     ?? null;

if (!$location && !$lat) {
    echo json_encode(['error' => 'Please provide a location or GPS coordinates.']);
    exit;
}

// Create emergency session
if (!$sessionId) {
    $sessionId = createSession('emergency');
}

// ── STEP 1: Parse district and state from location text ──────
// e.g. "Bhatpara, North 24 Parganas, West Bengal"
// We split by comma and use first two parts
$parts    = array_map('trim', explode(',', $location));
$district = $parts[0] ?? $location;
$state    = $parts[1] ?? '';

// ── STEP 2: Look up hospitals in our database (100% free) ────
$hospitals = getHospitalsFromDB($district, $state);

// ── STEP 3: If DB has no results, use defaults for West Bengal ─
// (hardcoded fallback so the app always gives SOME answer)
if (empty($hospitals)) {
    $hospitals = [
        [
            'name'              => 'Nearest Government Hospital',
            'hospital_type'     => 'government',
            'address'           => 'Please search locally for your nearest hospital',
            'phone_primary'     => 'N/A',
            'phone_emergency'   => '102',
            'ambulance_number'  => '108'
        ]
    ];
}

// ── STEP 4: Save the emergency request to DB ─────────────────
try {
    $db = getDB();
    $hospitalsShown = json_encode(array_column($hospitals, 'name'));
    $db->prepare(
        "INSERT INTO emergency_requests
         (session_id, district, state, location_source, ambulance_numbers, hospitals_shown)
         VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([
        $sessionId,
        $district,
        $state,
        $lat ? 'gps' : 'manual',
        '102, 108, 112',
        $hospitalsShown
    ]);
} catch (Exception $e) {
    // Not critical — send the response anyway
}

// ── STEP 5: Format hospital list for the frontend ────────────
$formattedHospitals = array_map(function($h) {
    return [
        'name'      => $h['name'],
        'type'      => ucfirst($h['hospital_type']),
        'address'   => $h['address'] ?? 'Address not available',
        'phone'     => $h['phone_emergency'] ?: $h['phone_primary'] ?: 'N/A',
        'ambulance' => $h['ambulance_number'] ?? '108'
    ];
}, $hospitals);

// Always include national helplines
$response = [
    'session_id'  => $sessionId,
    'location'    => $location,
    'helplines'   => [
        ['number' => '112', 'label' => 'National Emergency (Police/Fire/Ambulance)'],
        ['number' => '108', 'label' => 'Ambulance (Free, 24×7)'],
        ['number' => '102', 'label' => 'Government Ambulance Service'],
        ['number' => '1800-180-1104', 'label' => 'Poison Control Helpline']
    ],
    'hospitals'   => $formattedHospitals,
    'note'        => 'For life-threatening emergencies, call 112 immediately. Do not wait.'
];

echo json_encode($response);
?>