
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

function sendResponse($success, $reply, $extra = [])
{
    echo json_encode(array_merge([
        "success" => $success,
        "reply" => $reply
    ], $extra));
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);

$message = trim($input["message"] ?? "");
$context = trim($input["context"] ?? "");

if ($message === "") {
    http_response_code(400);
    sendResponse(false, "Please enter a message.");
}

if (strlen($message) > 2000) {
    http_response_code(400);
    sendResponse(false, "Your message is too long.");
}

$apiKey = getenv("GEMINI_API_KEY");

if (!$apiKey) {
    http_response_code(500);
    sendResponse(false, "AI service is not configured.");
}

if ($context === "") {
    $context = "TouEARIST is a campus information and virtual-tour project designed to help users explore EARIST Cavite Campus. It is separate from the official EARIST website.";
}

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

$ch = curl_init(
    "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-lite:generateContent"
);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/json",
        "x-goog-api-key: " . $apiKey
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 60
]);

$result = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

if ($result === false) {
    http_response_code(502);
    sendResponse(false, "Unable to connect to the AI service.", [
        "error" => $curlError
    ]);
}

$data = json_decode($result, true);

if ($httpCode !== 200) {
    http_response_code(502);
    sendResponse(false, "The AI service returned an error.", [
        "provider_status" => $httpCode
    ]);
}

$reply = $data["candidates"][0]["content"]["parts"][0]["text"] ?? "";

if ($reply === "") {
    http_response_code(502);
    sendResponse(false, "The AI service returned an empty response.");
}

sendResponse(true, $reply, [
    "model" => "gemini-2.5-flash-lite",
    "provider" => "Gemini",
    "ai_powered" => true
]);
