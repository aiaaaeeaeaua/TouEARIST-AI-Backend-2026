
<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: https://touearistcavite.infinityfreeapp.com");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// =========================================
// HANDLE REQUEST
// =========================================

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

$input = json_decode(file_get_contents("php://input"), true);

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

// Primary and fallback models
$models = [
    "gemini-3.8-flash",
    "gemini-3.5-flash-lite"
];

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
// REQUEST PAYLOAD
// =========================================

$payload = [
    "systemInstruction" => [
        "parts" => [
            ["text" => $systemPrompt]
        ]
    ],

    "contents" => [
        [
            "role" => "user",
            "parts" => [
                ["text" => $message]
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
// GEMINI RETRY + AUTOMATIC FALLBACK
// =========================================

$maxAttemptsPerModel = 2;

$temporaryErrors = [
    429,
    500,
    502,
    503,
    504
];

$totalAttempts = 0;
$lastStatus = 0;
$lastError = "";
$lastProviderStatus = "UNKNOWN";

$successfulData = null;
$successfulModel = null;

foreach ($models as $model) {

    $geminiUrl =
        "https://generativelanguage.googleapis.com/v1beta/models/" .
        $model .
        ":generateContent";

    for ($attempt = 1; $attempt <= $maxAttemptsPerModel; $attempt++) {

        $totalAttempts++;

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
            CURLOPT_TIMEOUT => 40
        ]);

        $result = curl_exec($ch);
        $curlError = curl_error($ch);

        $httpCode = curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

        curl_close($ch);

        $lastStatus = $httpCode;
        $lastError = $curlError;

        if ($result === false) {

            error_log(
                "Gemini connection error on " .
                $model . ": " . $curlError
            );

            break;
        }

        $data = json_decode($result, true);

        if (!is_array($data)) {

            error_log(
                "Invalid Gemini response from " . $model
            );

            break;
        }

        // SUCCESS
        if ($httpCode >= 200 && $httpCode < 300) {

            $successfulData = $data;
            $successfulModel = $model;

            break 2;
        }

        $lastProviderStatus =
            $data["error"]["status"] ?? "UNKNOWN";

        $providerMessage =
            $data["error"]["message"] ??
            "Unknown Gemini API error.";

        error_log(
            "Gemini error on " . $model .
            ": HTTP " . $httpCode .
            " - " . $providerMessage
        );

        // Retry temporary errors.
        if (in_array($httpCode, $temporaryErrors, true)) {

            if ($attempt < $maxAttemptsPerModel) {
                sleep(1);
                continue;
            }

            // Move to the next model.
            break;
        }

        // Model unavailable or not found:
        // try the next configured model.
        if ($httpCode === 404) {
            break;
        }

        // Do not retry invalid requests or API credentials.
        sendResponse(
            false,
            "Gemini rejected the request. Please check the API configuration.",
            [
                "provider" => "Gemini",
                "model" => $model,
                "provider_status" => $httpCode,
                "provider_error_status" => $lastProviderStatus,
                "provider_message" => $providerMessage,
                "attempts" => $totalAttempts
            ],
            502
        );
    }

    if ($successfulData !== null) {
        break;
    }
}

// =========================================
// ALL MODELS FAILED
// =========================================

if ($successfulData === null) {

    sendResponse(
        false,
        "The Gemini AI service is temporarily unavailable. Please try again later.",
        [
            "provider" => "Gemini",
            "models_tried" => $models,
            "provider_status" => $lastStatus,
            "provider_error_status" => $lastProviderStatus,
            "connection_error" => $lastError,
            "attempts" => $totalAttempts
        ],
        502
    );
}

// =========================================
// EXTRACT AI RESPONSE
// =========================================

$reply = "";

$candidates = $successfulData["candidates"] ?? [];

if (!empty($candidates)) {

    $parts = $candidates[0]["content"]["parts"] ?? [];

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
            "model" => $successfulModel,
            "finish_reason" =>
                $candidates[0]["finishReason"] ?? "UNKNOWN",
            "attempts" => $totalAttempts
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
        "model" => $successfulModel,
        "provider" => "Gemini",
        "ai_powered" => true,
        "attempts" => $totalAttempts
    ]
);

