<?php
// ============================================================
//  AI_INSPECT.PHP  (shared/)
//
//  Single exported function:
//    ai_inspect_document(abs_path, doc_type, redacted_path)
//
//  Sends the REDACTED copy to GPT-4o to verify structural
//  authenticity. NEVER called automatically on upload —
//  the admin triggers it manually via the "Check Structure"
//  button in the Redaction Review tab.
//
//  Set OPENAI_API_KEY in  app/.env  to enable.
// ============================================================

function ai_inspect_document(string $abs_path, string $doc_type, string $redacted_path = ''): array {

    // ── 1. Load API key ──────────────────────────────────────
    $api_key = getenv('OPENAI_API_KEY') ?: '';
    if (!$api_key) {
        $env = __DIR__ . '/../.env';
        if (file_exists($env)) {
            foreach (file($env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (str_starts_with(trim($line), 'OPENAI_API_KEY=')) {
                    $api_key = trim(substr($line, strpos($line, '=') + 1));
                    break;
                }
            }
        }
    }
    if (!$api_key) {
        return ['success' => false,
                'error'   => 'OpenAI API key not configured. Add OPENAI_API_KEY=sk-... to app/.env'];
    }

    // ── 2. Resolve which file to send ─────────────────────────
    // Always use the redacted copy. If it doesn't exist yet, refuse.
    if ($redacted_path && file_exists($redacted_path)) {
        $inspect_path = $redacted_path;
    } elseif ($redacted_path) {
        return ['success' => false,
                'error'   => 'Redacted copy not found. Run redaction first.'];
    } else {
        return ['success' => false,
                'error'   => 'No redacted path provided. Redact the document first.'];
    }

    $ext = strtolower(pathinfo($inspect_path, PATHINFO_EXTENSION));
    if ($ext === 'pdf') {
        return ['success' => false, 'error' => 'PDF inspection not supported. Upload JPG or PNG.'];
    }
    $mime = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png'][$ext] ?? 'image/jpeg';

    // ── 3. Encode image ───────────────────────────────────────
    $b64       = base64_encode(file_get_contents($inspect_path));
    $image_url = "data:{$mime};base64,{$b64}";

    // ── 4. Build per-type structural check prompt ─────────────
    $doc_label = match ($doc_type) {
        'BirthCertificate' => 'Philippine PSA Certificate of Live Birth (Municipal Form No. 102)',
        'ReportCard'       => 'Philippine high school Form 138 (Report Card)',
        'GoodMoral'        => 'Certificate of Good Moral Character from a Philippine school',
        default            => 'Philippine official document',
    };

    $checks = match ($doc_type) {
        'BirthCertificate' => <<<'TXT'
Check the following structural elements (PII values are blacked out — focus only on form structure):
1. "Republic of the Philippines" header text present
2. "OFFICE OF THE CIVIL REGISTRAR GENERAL" text present
3. "CERTIFICATE OF LIVE BIRTH" title visible
4. "Municipal Form No. 102" label present
5. Numbered field boxes (1–22) visible and correctly laid out
6. PSA security paper background / watermark texture visible
7. Barcode strip at bottom-right present
8. Registry No. box in upper-right visible
9. REMARKS/ANNOTATIONS column on the right side present
10. Consistent printed font and form grid lines throughout
11. No signs of digital alteration (blurred borders, pixel artifacts, misaligned boxes)

Also assess image quality and physical document characteristics:
12. IMAGE BLUR: Is the image sharp and in focus, slightly blurred but still readable, or too blurred to verify?
13. ALIGNMENT/CENTERING: Is the document square and centered in the frame, or is it skewed, rotated, or cropped unevenly?
14. LEGITIMACY: Based on the presence of all official PSA form elements (watermark, barcode, official seals, proper form layout), does this appear to be a genuine PSA-issued document?
TXT,

        'ReportCard' => <<<'TXT'
Check the following structural elements:
1. School name/letterhead header visible at top
2. "REPORT CARD" or "FORM 138" label present
3. DepEd logo or school seal visible
4. Subject/grade table with proper columns (Q1, Q2, Q3, Q4, Final)
5. School year label present
6. Signature lines for adviser and principal present
7. Dry seal impression or stamp visible
8. No signs of grade tampering (inconsistent ink, erased cells)
TXT,

        'GoodMoral' => <<<'TXT'
Check the following structural elements:
1. Institution letterhead or name at top
2. Body paragraph starting with "This is to certify" or similar
3. Stated purpose (enrollment, college application, etc.) present
4. Signature line for authorized signatory present
5. Official dry seal or stamp impression visible
6. Date of issuance present
7. No signs of digital editing (font inconsistencies, pixelation)
TXT,

        default => 'Check for official letterhead, seals, consistent fonts, and absence of digital alteration.',
    };

    $prompt = <<<PROMPT
You are a document verification AI for a Philippine college enrollment system.

The image is a REDACTED copy of a {$doc_label}. All personal information (names, dates of birth, addresses, etc.) has been covered with black boxes to protect privacy. Do NOT try to read or recover any redacted content.

Your sole task is to verify the STRUCTURAL AUTHENTICITY of the document.

{$checks}

Return ONLY a valid JSON object (no markdown, no explanation):
{
  "is_authentic": true | false | "uncertain",
  "confidence": <0-100>,
  "document_type_detected": "<type of document you see>",
  "authenticity_notes": "<brief explanation>",
  "red_flags": ["<suspicious issue>"] | [],
  "image_blur": "sharp" | "slightly_blurred" | "too_blurred",
  "alignment_ok": true | false,
  "alignment_notes": "<brief note on centering/skew, or empty string if fine>",
  "is_legitimate": true | false | "uncertain",
  "legitimacy_notes": "<brief explanation of whether this appears to be a genuine PSA document>"
}
PROMPT;

    // ── 5. Call OpenAI ────────────────────────────────────────
    $payload = json_encode([
        'model'      => 'gpt-4o',
        'max_tokens' => 500,
        'messages'   => [[
            'role'    => 'user',
            'content' => [
                ['type' => 'text',      'text'      => $prompt],
                ['type' => 'image_url', 'image_url' => ['url' => $image_url, 'detail' => 'high']],
            ],
        ]],
    ]);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 60,
    ]);

    $response   = curl_exec($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) return ['success' => false, 'error' => 'Network error: ' . $curl_error];

    $resp = json_decode($response, true);
    if ($http_code !== 200) {
        return ['success' => false,
                'error'   => "OpenAI API error ($http_code): " . ($resp['error']['message'] ?? 'unknown')];
    }

    $content = $resp['choices'][0]['message']['content'] ?? '';
    $content = preg_replace('/^```(?:json)?\s*/m', '', $content);
    $content = preg_replace('/\s*```$/m', '', $content);
    $parsed  = json_decode(trim($content), true);

    if (!$parsed) {
        return ['success' => false, 'error' => 'Could not parse AI response: ' . substr($content, 0, 200)];
    }

    return [
        'success'          => true,
        'is_authentic'     => $parsed['is_authentic']           ?? 'uncertain',
        'confidence'       => (int)($parsed['confidence']       ?? 0),
        'doc_detected'     => $parsed['document_type_detected'] ?? '',
        'notes'            => $parsed['authenticity_notes']     ?? '',
        'red_flags'        => $parsed['red_flags']              ?? [],
        'image_blur'       => $parsed['image_blur']             ?? 'unknown',
        'alignment_ok'     => $parsed['alignment_ok']           ?? null,
        'alignment_notes'  => $parsed['alignment_notes']        ?? '',
        'is_legitimate'    => $parsed['is_legitimate']          ?? 'uncertain',
        'legitimacy_notes' => $parsed['legitimacy_notes']       ?? '',
        'extracted'        => [],   // no PII extraction — structural check only
        'inspected_at'     => date('Y-m-d H:i:s'),
        'model'            => 'gpt-4o',
        'doc_type'         => $doc_type,
        'used_redacted'    => true,
    ];
}
