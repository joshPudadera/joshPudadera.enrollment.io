<?php
// ============================================================
//  REDACT_DOCUMENT.PHP  (shared/)
//  Draws black rectangles over PII zones on a Birth Certificate.
//
//  Strategy:
//    1. Try Python + Pillow (best quality, all platforms)
//    2. Fall back to pure PHP GD (works on any PHP host with gd extension)
//
//  The PHP GD fallback means redaction works even when Python
//  is unavailable or proc_open is disabled by the host.
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

    $dir     = dirname($abs_input);
    $base    = pathinfo($abs_input, PATHINFO_FILENAME);
    $abs_out = $dir . DIRECTORY_SEPARATOR . $base . '_redacted.' . $ext;

    // ── 1. Try Python ──────────────────────────────────────────
    if (function_exists('proc_open')) {
        $python = _find_python();
        if ($python) {
            $py_script   = __DIR__ . DIRECTORY_SEPARATOR . 'redact_document.py';
            $descriptors = [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']];
            $cmd_array   = [$python, $py_script, 'redact', $abs_input, $abs_out, $doc_type];
            $process     = proc_open($cmd_array, $descriptors, $pipes);

            if (is_resource($process)) {
                fclose($pipes[0]);
                $stdout    = stream_get_contents($pipes[1]);
                $stderr    = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exit_code = proc_close($process);

                $raw        = trim($stdout . "\n" . $stderr);
                $json_start = strrpos($raw, '{');
                $json_str   = $json_start !== false ? substr($raw, $json_start) : $raw;
                $parsed     = json_decode($json_str, true);

                if ($exit_code === 0 && $parsed && ($parsed['success'] ?? false) && file_exists($abs_out)) {
                    return ['success' => true, 'redacted_path' => $abs_out, 'original_path' => $abs_input, 'doc_type' => $doc_type];
                }
                // Python failed — fall through to GD
                error_log('[BCP Redact] Python failed (exit ' . $exit_code . '): ' . $raw . ' — falling back to GD');
            }
        }
    }

    // ── 2. PHP GD fallback ─────────────────────────────────────
    return _redact_gd($abs_input, $abs_out, $doc_type, $ext);
}

// ── Locate Python executable ──────────────────────────────────
function _find_python(): ?string {
    $candidates = [
        // Linux / cPanel hosting
        '/usr/bin/python3',
        '/usr/local/bin/python3',
        '/usr/bin/python',
        '/usr/local/bin/python',
        '/opt/python3/bin/python3',
        '/opt/cpanel/ea-python311/root/usr/bin/python3',
        '/opt/cpanel/ea-python310/root/usr/bin/python3',
        // Windows XAMPP
        'C:\\Program Files\\Python312\\python.exe',
        'C:\\Program Files\\Python311\\python.exe',
        'C:\\Program Files\\Python310\\python.exe',
        'C:\\Python312\\python.exe',
        'C:\\Python311\\python.exe',
        'python3',
        'python',
    ];

    foreach ($candidates as $c) {
        // Absolute path — just check it exists
        if (str_starts_with($c, '/') || str_contains($c, ':\\')) {
            if (file_exists($c) && is_executable($c)) return $c;
            continue;
        }
        // Short name — probe with proc_open (no shell)
        if (!function_exists('proc_open')) continue;
        $ph = [['pipe','r'],['pipe','w'],['pipe','w']];
        $pr = @proc_open([$c, '--version'], $ph, $pipes2);
        if (is_resource($pr)) {
            fclose($pipes2[0]); fclose($pipes2[1]); fclose($pipes2[2]);
            if (proc_close($pr) === 0) return $c;
        }
    }
    return null;
}

// ── Pure PHP GD redaction ─────────────────────────────────────
// Draws solid black rectangles over PII zones using the same
// percentage-based coordinates as redact_document.py.
function _redact_gd(string $abs_input, string $abs_out, string $doc_type, string $ext): array {
    if (!extension_loaded('gd')) {
        return ['success' => false, 'error' => 'Neither Python nor PHP GD extension is available on this server.'];
    }

    // Load image
    $img = null;
    switch ($ext) {
        case 'jpg': case 'jpeg': $img = @imagecreatefromjpeg($abs_input); break;
        case 'png':              $img = @imagecreatefrompng($abs_input);  break;
        default:
            return ['success' => false, 'error' => 'Unsupported image type for GD redaction: ' . $ext];
    }
    if (!$img) {
        return ['success' => false, 'error' => 'GD could not load image: ' . $abs_input];
    }

    $W = imagesx($img);
    $H = imagesy($img);
    $black = imagecolorallocate($img, 0, 0, 0);

    // Redaction zones — same as redact_document.py BirthCertificate zones
    $zones = [
        [0.10,0.083,0.36,0.022],[0.68,0.083,0.26,0.022],[0.17,0.105,0.46,0.022],
        [0.06,0.136,0.25,0.026],[0.32,0.136,0.22,0.026],[0.56,0.136,0.24,0.026],
        [0.07,0.162,0.15,0.022],[0.37,0.162,0.07,0.022],[0.45,0.162,0.13,0.022],
        [0.59,0.162,0.08,0.022],[0.06,0.198,0.76,0.024],[0.06,0.222,0.76,0.020],
        [0.07,0.246,0.13,0.020],[0.07,0.274,0.30,0.020],[0.40,0.274,0.22,0.020],
        [0.06,0.316,0.25,0.026],[0.32,0.316,0.22,0.026],[0.56,0.316,0.24,0.026],
        [0.06,0.348,0.27,0.022],[0.40,0.348,0.25,0.022],[0.07,0.376,0.09,0.020],
        [0.28,0.376,0.09,0.020],[0.53,0.376,0.09,0.020],[0.06,0.404,0.32,0.022],
        [0.56,0.404,0.13,0.022],[0.06,0.432,0.76,0.022],[0.06,0.452,0.76,0.018],
        [0.06,0.490,0.25,0.026],[0.32,0.490,0.22,0.026],[0.56,0.490,0.24,0.026],
        [0.06,0.522,0.27,0.022],[0.40,0.522,0.25,0.022],[0.06,0.550,0.32,0.022],
        [0.56,0.550,0.13,0.022],[0.06,0.578,0.76,0.022],[0.48,0.632,0.15,0.020],
        [0.06,0.670,0.33,0.024],[0.40,0.670,0.39,0.024],[0.06,0.694,0.31,0.020],
        [0.40,0.694,0.39,0.020],[0.06,0.714,0.31,0.018],[0.40,0.710,0.21,0.018],
        [0.06,0.746,0.33,0.024],[0.40,0.746,0.39,0.024],[0.06,0.770,0.31,0.020],
        [0.40,0.770,0.39,0.020],[0.40,0.790,0.21,0.018],[0.06,0.830,0.31,0.024],
        [0.06,0.854,0.31,0.020],[0.06,0.874,0.31,0.018],[0.06,0.893,0.19,0.016],
        [0.40,0.830,0.39,0.024],[0.40,0.854,0.39,0.020],[0.40,0.874,0.39,0.018],
        [0.40,0.893,0.19,0.016],
    ];

    // Only apply zones for BirthCertificate; others have no PII zones
    if ($doc_type === 'BirthCertificate') {
        foreach ($zones as [$xp, $yp, $wp, $hp]) {
            $x0 = max(0, (int)($xp * $W));
            $y0 = max(0, (int)($yp * $H));
            $x1 = min($W - 1, (int)(($xp + $wp) * $W));
            $y1 = min($H - 1, (int)(($yp + $hp) * $H));
            imagefilledrectangle($img, $x0, $y0, $x1, $y1, $black);
        }
    }

    // Save
    $ok = false;
    switch ($ext) {
        case 'jpg': case 'jpeg': $ok = imagejpeg($img, $abs_out, 92); break;
        case 'png':              $ok = imagepng($img, $abs_out);       break;
    }
    imagedestroy($img);

    if (!$ok || !file_exists($abs_out)) {
        return ['success' => false, 'error' => 'GD could not save redacted image to: ' . $abs_out];
    }

    return ['success' => true, 'redacted_path' => $abs_out, 'original_path' => $abs_input, 'doc_type' => $doc_type];
}
