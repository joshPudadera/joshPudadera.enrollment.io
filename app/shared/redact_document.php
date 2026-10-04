<?php
// ============================================================
//  REDACT_DOCUMENT.PHP  (shared/)
//  PHP caller that invokes redact_document.py.
//
//  Uses proc_open() with an ARRAY descriptor so PHP passes
//  arguments directly to the OS — no shell involved, so paths
//  with spaces (e.g. "C:\Program Files\Python312\python.exe")
//  work correctly on Windows without any quoting tricks.
// ============================================================

function redact_document(string $abs_input, string $doc_type): array {

    if (!file_exists($abs_input)) {
        return ['success' => false, 'error' => 'Input file not found: ' . $abs_input];
    }

    $ext = strtolower(pathinfo($abs_input, PATHINFO_EXTENSION));
    if ($ext === 'pdf') {
        return ['success' => false,
                'error'   => 'PDF redaction is not supported. Upload a JPG or PNG scan.'];
    }

    // ── Build output path ─────────────────────────────────────
    $dir     = dirname($abs_input);
    $base    = pathinfo($abs_input, PATHINFO_FILENAME);
    $abs_out = $dir . DIRECTORY_SEPARATOR . $base . '_redacted.' . $ext;

    // ── Locate Python executable ──────────────────────────────
    $candidates = [
        'C:\\Program Files\\Python312\\python.exe',
        'C:\\Program Files\\Python311\\python.exe',
        'C:\\Program Files\\Python310\\python.exe',
        'C:\\Python312\\python.exe',
        'python3',
        'python',
    ];
    $python = null;
    foreach ($candidates as $c) {
        if ((str_contains($c, '\\') || str_contains($c, '/')) && file_exists($c)) {
            $python = $c; break;
        }
        // Plain name — quick version probe via proc_open (no shell)
        if (!str_contains($c, '\\') && !str_contains($c, '/')) {
            $ph = [['pipe','r'],['pipe','w'],['pipe','w']];
            $pr = proc_open([$c, '--version'], $ph, $pipes);
            if (is_resource($pr)) {
                $rc = proc_close($pr);
                if ($rc === 0) { $python = $c; break; }
            }
        }
    }

    if (!$python) {
        return ['success' => false,
                'error'   => 'Python 3 not found. Install it at C:\\Program Files\\Python312\\python.exe'];
    }

    $py_script = __DIR__ . DIRECTORY_SEPARATOR . 'redact_document.py';

    // ── Run via proc_open with argument ARRAY ─────────────────
    // Pass 'redact' mode explicitly to match new two-mode script.
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $cmd_array = [$python, $py_script, 'redact', $abs_input, $abs_out, $doc_type];
    $process   = proc_open($cmd_array, $descriptors, $pipes);

    if (!is_resource($process)) {
        return ['success' => false, 'error' => 'Failed to start Python process.'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit_code = proc_close($process);

    $raw = trim($stdout . "\n" . $stderr);

    // Find the last JSON object in output (skip any pip/deprecation lines)
    $json_start = strrpos($raw, '{');
    $json_str   = $json_start !== false ? substr($raw, $json_start) : $raw;
    $parsed     = json_decode($json_str, true);

    if ($exit_code !== 0 || !$parsed || !($parsed['success'] ?? false)) {
        $error = $parsed['error'] ?? $raw ?: "Redaction script failed (exit $exit_code).";
        return ['success' => false, 'error' => $error];
    }

    if (!file_exists($abs_out)) {
        return ['success' => false,
                'error'   => 'Redacted file was not created at: ' . $abs_out];
    }

    return [
        'success'       => true,
        'redacted_path' => $abs_out,
        'original_path' => $abs_input,
        'doc_type'      => $doc_type,
    ];
}
