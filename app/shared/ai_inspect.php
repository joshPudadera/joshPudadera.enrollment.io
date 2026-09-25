<?php
// ============================================================
//  AI_INSPECT.PHP  (shared/)
//  Calls OpenAI GPT-4 Vision to analyze an uploaded document.
//  Returns a structured JSON result with:
//    - authenticity verdict (real/fake/uncertain)
//    - extracted fields (name, birthday, gender, etc.)
//    - confidence score
//    - reasoning notes
//
//  USAGE:
//    require_once __DIR__ . '/ai_inspect.php';
//    $result = ai_inspect_document('/absolute/path/to/file.jpg', 'BirthCertificate');
//
//  SETUP:
//    Set your OpenAI API key in one of these ways (in order of priority):
//      1. PHP environment:  putenv('OPENAI_API_KEY=sk-...');
//      2. .env file at app root:  OPENAI_API_KEY=sk-...
//      3. Hardcode below (not recommended for production)
// ============================================================

function ai_inspect_document(string $abs_path, string $doc_type): array {
    // ── 1. Load API key ──────────────────────────────────────
    $api_key = getenv('OPENAI_API_KEY');

    // Try loading from .env file at app root if env var not set
    if (!$api_key) {
        $env_file = __DIR__ . '/../.env';
        if (file_exists($env_file)) {
            foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (str_starts_with(trim($line), 'OPENAI_API_KEY=')) {
                    $api_key = trim(substr($line, strpos($line, '=') + 1));
                    break;
                }
            }
        }
    }

    if (!$api_key) {
        return [
            'success' => false,
            'error'   => 'OpenAI API key not configured. Set OPENAI_API_KEY in your .env file or environment.',
        ];
    }

    // ── 2. Validate file ─────────────────────────────────────
    if (!file_exists($abs_path)) {
        return ['success' => false, 'error' => 'File not found: ' . $abs_path];
    }

    $ext  = strtolower(pathinfo($abs_path, PATHINFO_EXTENSION));
    $mime_map = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];
    $mime = $mime_map[$ext] ?? 'application/octet-stream';

    // GPT-4 Vision requires image formats — for PDFs convert first page or return error
    if ($ext === 'pdf') {
        return [
            'success' => false,
            'error'   => 'PDF inspection is not yet supported. Please upload a JPG or PNG image of the document.',
        ];
    }

    // ── 3. Base64-encode the image ───────────────────────────
    $image_data   = base64_encode(file_get_contents($abs_path));
    $image_url    = "data:{$mime};base64,{$image_data}";

    // ── 4. Build the prompt ──────────────────────────────────
    $doc_label = match ($doc_type) {
        'BirthCertificate' => 'Philippine PSA (formerly NSO) Birth Certificate',
        'ReportCard'       => 'Philippine high school Form 138 (Report Card)',
        'GoodMoral'        => 'Certificate of Good Moral Character issued by a Philippine school',
        'Form137'          => 'Philippine high school Form 137',
        'IDPhoto'          => 'ID photo',
        'Diploma'          => 'school diploma',
        default            => 'official document',
    };

    // ── Per-document-type extraction fields and authenticity checks ──
    $type_instructions = match ($doc_type) {

        'BirthCertificate' => <<<'INST'
This is a PSA Birth Certificate. Check for:
1. PSA security paper (light blue/green security paper with PSA watermark)
2. Official PSA seal/stamp and barcode
3. Registry number in the upper right corner
4. Consistent fonts — PSA certificates use a standard typeface; mixed fonts are a red flag
5. Presence of the civil registrar's signature and dry seal
6. "Republic of the Philippines" and PSA header text
7. No signs of digital alteration (blurred edges, pixel anomalies, misaligned text)

Extract these fields:
- full_name (Last Name, First Name, Middle Name combined)
- last_name, first_name, middle_name (from "Name of Child" section)
- date_of_birth (from "Date of Birth")
- place_of_birth (city/municipality and province)
- sex (Male or Female)
- nationality (usually "Filipino")
- registration_number (Registry No. upper right)
- date_issued (if printed on the cert)
- issuing_authority ("Philippine Statistics Authority" or local civil registrar)
- name_of_mother (mother's full maiden name)
- name_of_father (father's full name)
INST,

        'ReportCard' => <<<'INST'
This is a Philippine high school Form 138 (Report Card). Check for:
1. DepEd letterhead or school header with school name, address, and school ID
2. Student's name and LRN (Learner Reference Number) at the top
3. Grade levels and subject grades in the standard DepEd format
4. School year and grading period labels
5. Teacher/adviser and principal signatures with printed names and positions
6. School dry seal or stamp
7. No signs of grade tampering (erased numbers, inconsistent ink, misaligned cells)

Extract these fields:
- full_name (student's full name as printed)
- last_name, first_name, middle_name
- date_of_birth (if present)
- lrn (Learner Reference Number)
- school_name (name of the school)
- school_year (e.g. "2024-2025")
- grade_level (e.g. "Grade 12")
- strand_or_track (e.g. "STEM", "ABM", "TVL")
- general_average (final general average if visible)
- class_adviser (adviser's name)
- principal (school principal's name)
- date_issued (date the card was issued, if printed)
- issuing_authority (school name + principal)
INST,

        'GoodMoral' => <<<'INST'
This is a Certificate of Good Moral Character issued by a Philippine school or barangay. Check for:
1. School/institution letterhead with name and address
2. Recipient's full name clearly stated in the body text
3. Purpose statement (e.g. "for enrollment purposes", "for college admission")
4. Signatory's name, position/designation, and signature
5. Official dry seal or stamp of the school/institution
6. Date of issuance
7. No signs of digital editing (font inconsistencies, pixelation around text)

Extract these fields:
- full_name (name of the person the certificate is issued for)
- last_name, first_name, middle_name (if separable)
- issuing_school (name of school/institution issuing the cert)
- issuing_authority (name + position of the signatory, e.g. "Juan dela Cruz, Principal")
- date_issued (YYYY-MM-DD or as written)
- purpose (stated reason for issuance)
- year_graduated (if mentioned, e.g. "SY 2024-2025")
INST,

        default => <<<'INST'
Analyze the document for general authenticity indicators:
1. Official letterhead, seals, or stamps
2. Consistent fonts and formatting
3. Presence of authorized signatures
4. No visible signs of digital alteration

Extract: full_name, last_name, first_name, middle_name, date_of_birth,
registration_number, date_issued, issuing_authority.
INST,
    };

    $prompt = <<<PROMPT
You are a document verification AI for a Philippine college enrollment system.

Analyze this uploaded image of a {$doc_label}.

{$type_instructions}

Return ONLY a valid JSON object (no markdown, no explanation) with these exact keys:

{
  "is_authentic": true | false | "uncertain",
  "confidence": <number 0-100>,
  "document_type_detected": "<what type of document this appears to be>",
  "authenticity_notes": "<brief explanation of why you consider it authentic or fake>",
  "red_flags": ["<list any suspicious indicators, or empty array if none>"],
  "extracted_data": {
    "full_name": "<extracted full name or null>",
    "last_name": "<last name or null>",
    "first_name": "<first name or null>",
    "middle_name": "<middle name or null>",
    "date_of_birth": "<YYYY-MM-DD format or null>",
    "place_of_birth": "<city/municipality, province or null>",
    "sex": "<Male | Female | null>",
    "nationality": "<nationality or null>",
    "registration_number": "<document registration/reference number or null>",
    "date_issued": "<YYYY-MM-DD or null>",
    "issuing_authority": "<issuing office or signatory or null>",
    "name_of_mother": "<mother's name — Birth Certificate only — or null>",
    "name_of_father": "<father's name — Birth Certificate only — or null>",
    "lrn": "<Learner Reference Number — Report Card only — or null>",
    "school_name": "<school name — Report Card / Good Moral only — or null>",
    "school_year": "<school year — Report Card only — or null>",
    "grade_level": "<grade level — Report Card only — or null>",
    "strand_or_track": "<strand/track — Report Card only — or null>",
    "general_average": "<final average — Report Card only — or null>",
    "class_adviser": "<adviser name — Report Card only — or null>",
    "principal": "<principal name — Report Card / Good Moral only — or null>",
    "purpose": "<stated purpose — Good Moral only — or null>",
    "year_graduated": "<graduation year — Good Moral only — or null>",
    "other_details": "<any other notable information or null>"
  }
}

If the image is unclear, blurry, or does not appear to be the expected document type,
set is_authentic to "uncertain" and explain in authenticity_notes.
PROMPT;

    // ── 5. Call OpenAI API ───────────────────────────────────
    $payload = json_encode([
        'model'      => 'gpt-4o',
        'max_tokens' => 1000,
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
        CURLOPT_TIMEOUT        => 60,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return ['success' => false, 'error' => 'Network error: ' . $curl_error];
    }

    $response_data = json_decode($response, true);

    if ($http_code !== 200) {
        $api_error = $response_data['error']['message'] ?? 'Unknown API error';
        return ['success' => false, 'error' => "OpenAI API error ($http_code): $api_error"];
    }

    // ── 6. Parse GPT response ────────────────────────────────
    $content = $response_data['choices'][0]['message']['content'] ?? '';

    // Strip markdown code fences if present
    $content = preg_replace('/^```(?:json)?\s*/m', '', $content);
    $content = preg_replace('/\s*```$/m', '', $content);
    $content = trim($content);

    $parsed = json_decode($content, true);

    if (!$parsed) {
        return [
            'success'     => false,
            'error'       => 'Could not parse AI response.',
            'raw_response'=> $content,
        ];
    }

    return [
        'success'      => true,
        'is_authentic' => $parsed['is_authentic']           ?? 'uncertain',
        'confidence'   => (int)($parsed['confidence']       ?? 0),
        'doc_detected' => $parsed['document_type_detected'] ?? '',
        'notes'        => $parsed['authenticity_notes']     ?? '',
        'red_flags'    => $parsed['red_flags']              ?? [],
        'extracted'    => $parsed['extracted_data']         ?? [],
        'inspected_at' => date('Y-m-d H:i:s'),
        'model'        => 'gpt-4o',
        'doc_type'     => $doc_type,
    ];
}
