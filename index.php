<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: https://touearistcavite.infinityfreeapp.com");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "reply" => "POST requests only."
    ]);

    exit;
}

// =========================================
// JSON RESPONSE
// =========================================

function sendResponse($success, $reply, $extra = [], $status = 200)
{
    http_response_code($status);

    echo json_encode(
        array_merge([
            "success" => $success,
            "reply" => $reply
        ], $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

// =========================================
// RECEIVE REQUEST
// =========================================

$input = json_decode(
    file_get_contents("php://input"),
    true
);

if (!is_array($input)) {
    sendResponse(false, "Invalid JSON request.", [], 400);
}

$message = trim($input["message"] ?? "");
$context = trim($input["context"] ?? "");

if ($message === "") {
    sendResponse(false, "Please enter a message.", [], 400);
}

if (strlen($message) > 2000) {
    sendResponse(false, "Your message is too long.", [], 400);
}

// =========================================
// GEMINI CONFIGURATION
// =========================================

$apiKey = getenv("GEMINI_API_KEY");

if (!$apiKey) {
    sendResponse(
        false,
        "AI service is not configured. Please check the server configuration.",
        [],
        500
    );
}

$model = "gemini-3.1-flash-lite";

if ($context === "") {
    $context = "TouEARIST is a campus information and virtual-tour project designed to help users explore EARIST Cavite Campus. It is separate from the official EARIST website.";
}

// =========================================
// SYSTEM PROMPT
// =========================================

$systemPrompt = "
You are the TouEARIST AI Assistant.

Answer questions using ONLY the verified information provided below.

Do not invent campus announcements, schedules, policies,
scholarships, building details, or other official information.

If the information is insufficient, clearly tell the user
that the answer is not available in the current system
and recommend checking authorized EARIST channels.

Keep answers clear, friendly, and concise.

VERIFIED INFORMATION:
" . $context;

// =========================================
// GEMINI REQUEST PAYLOAD
// =========================================

$payload = [
    "systemInstruction" => [
        "parts" => [
            [
                "text" => $systemPrompt
            ]
        ]
    ],

    "contents" => [
        [
            "role" => "user",
            "parts" => [
                [
                    "text" => $message
                ]
            ]
        ]
    ],

    "generationConfig" => [
        "temperature" => 0.2,
        "maxOutputTokens" => 500
    ]
];

$jsonPayload = json_encode(
    $payload,
    JSON_UNESCAPED_UNICODE
);

if ($jsonPayload === false) {
    sendResponse(
        false,
        "Unable to prepare the AI request.",
        [],
        500
    );
}

// =========================================
// GEMINI API CONNECTION WITH RETRIES
// =========================================

$geminiUrl =
    "https://generativelanguage.googleapis.com/v1beta/models/" .
    $model .
    ":generateContent";

$maxAttempts = 3;
$result = false;
$curlError = "";
$httpCode = 0;
$attemptsUsed = 0;

for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {

    $attemptsUsed = $attempt;

    $ch = curl_init($geminiUrl);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,

        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Accept: application/json",
            "x-goog-api-key: " . $apiKey
        ],

        CURLOPT_POSTFIELDS => $jsonPayload,

        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 45
    ]);

    $result = curl_exec($ch);

    $curlError = curl_error($ch);

    $httpCode = curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    curl_close($ch);

    // Stop if the request succeeded.
    if (
        $result !== false &&
        $httpCode >= 200 &&
        $httpCode < 300
    ) {
        break;
    }

    // Retry only temporary provider errors.
    $temporaryErrors = [
        429,
        500,
        502,
        503,
        504
    ];

    if (
        $attempt >= $maxAttempts ||
        !in_array($httpCode, $temporaryErrors, true)
    ) {
        break;
    }

    // Wait before the next attempt.
    sleep($attempt);
}

// =========================================
// CONNECTION ERROR
// =========================================

if ($result === false) {

    error_log("Gemini cURL error: " . $curlError);

    sendResponse(
        false,
        "Unable to connect to the Gemini AI service. Please try again.",
        [
            "provider" => "Gemini",
            "model" => $model,
            "attempts" => $attemptsUsed
        ],
        502
    );
}

// =========================================
// DECODE GEMINI RESPONSE
// =========================================

$data = json_decode($result, true);

if (!is_array($data)) {

    sendResponse(
        false,
        "Invalid response received from Gemini.",
        [
            "provider" => "Gemini",
            "model" => $model,
            "http_status" => $httpCode,
            "attempts" => $attemptsUsed
        ],
        502
    );
}

// =========================================
// GEMINI API ERROR
// =========================================

if ($httpCode < 200 || $httpCode >= 300) {

    $providerMessage =
        $data["error"]["message"] ??
        "Unknown Gemini API error.";

    $providerStatus =
        $data["error"]["status"] ??
        "UNKNOWN";

    error_log(
        "Gemini API error: HTTP " .
        $httpCode .
        " - " .
        $providerMessage
    );

    sendResponse(
        false,
        "The Gemini AI service is temporarily unavailable. Please try again later.",
        [
            "provider" => "Gemini",
            "model" => $model,
            "provider_status" => $httpCode,
            "provider_error_status" => $providerStatus,
            "provider_message" => $providerMessage,
            "attempts" => $attemptsUsed
        ],
        502
    );
}

// =========================================
// EXTRACT AI RESPONSE
// =========================================

$reply = "";

$candidates = $data["candidates"] ?? [];

if (!empty($candidates)) {

    $parts =
        $candidates[0]["content"]["parts"] ?? [];

    foreach ($parts as $part) {

        if (isset($part["text"])) {
            $reply .= $part["text"];
        }
    }
}

$reply = trim($reply);

// =========================================
// EMPTY RESPONSE
// =========================================

if ($reply === "") {

    sendResponse(
        false,
        "Gemini did not generate a response.",
        [
            "provider" => "Gemini",
            "model" => $model,
            "finish_reason" =>
                $candidates[0]["finishReason"] ?? "UNKNOWN",
            "attempts" => $attemptsUsed
        ],
        502
    );
}

// =========================================
// SUCCESS
// =========================================

sendResponse(
    true,
    $reply,
    [
        "model" => $model,
        "provider" => "Gemini",
        "ai_powered" => true,
        "attempts" => $attemptsUsed
    ]
);

?>
