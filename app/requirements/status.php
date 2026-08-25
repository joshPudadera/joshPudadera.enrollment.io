<?php
session_start();
$uploaded = $_SESSION['uploaded_docs'] ?? [];
if (empty($uploaded)) { header('Location: upload.php'); exit; }

$current_step = 3;
include __DIR__ . '/header.php';

$doc_types = [
    'Form138'            => 'Form 138 (Report Card)',
    'Form137'            => 'Form 137',
    'GoodMoral'          => 'Certificate of Good Moral Character',
    'BirthCertificate'   => 'PSA Birth Certificate',
    'IDPhoto'            => 'ID Photo',
    'BarangayClearance'  => 'Barangay Clearance',
    'TranscriptOfRecords'=> 'Transcript of Records',
    'HonorableDismissal' => 'Honorable Dismissal',
    'NCEEResult'         => 'NCAE Result',
    'ESCCertificate'     => 'ESC Certificate',
    'Diploma'            => 'Photocopy of Diploma',
    'Other'              => 'Other Document',
];

$total_size   = array_sum(array_column($uploaded, 'file_size'));
$inspected    = count(array_filter($uploaded, fn($d) => !empty($d['ai_result'])));
$all_passed   = $inspected > 0 && count(array_filter($uploaded, fn($d) => ($d['ai_result']['is_authentic'] ?? null) === false)) === 0;
?>

<div class="enroll-body">
  <div class="enroll-card">

    <h2 class="enroll-card-title">Review Your Uploads</h2>
    <p style="text-align:center;font-size:.82rem;color:#666;margin-bottom:24px;">
      Confirm the documents below are correct before submitting.
      Go back to add more, inspect with AI, or delete files.
    </p>

    <!-- Summary row -->
    <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:24px;">
      <div style="flex:1;min-width:120px;background:#eff6ff;border-radius:10px;padding:16px 20px;text-align:center;">
        <div style="font-size:1.8rem;font-weight:700;color:#2563eb;"><?= count($uploaded) ?></div>
        <div style="font-size:.75rem;color:#555;margin-top:4px;">Documents</div>
      </div>
      <div style="flex:1;min-width:120px;background:#f0fdf4;border-radius:10px;padding:16px 20px;text-align:center;">
        <div style="font-size:1.8rem;font-weight:700;color:#16a34a;"><?= round($total_size / 1024, 1) ?> KB</div>
        <div style="font-size:.75rem;color:#555;margin-top:4px;">Total Size</div>
      </div>
      <div style="flex:1;min-width:120px;background:<?= $inspected>0?'#f0fdf4':'#f8fafc' ?>;border-radius:10px;padding:16px 20px;text-align:center;">
        <div style="font-size:1.8rem;font-weight:700;color:<?= $inspected>0?'#16a34a':'#94a3b8' ?>;"><?= $inspected ?>/<?= count($uploaded) ?></div>
        <div style="font-size:.75rem;color:#555;margin-top:4px;">AI Inspected</div>
      </div>
    </div>

    <!-- Document table -->
    <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:.82rem;">
      <thead style="background:#1a3a8c;color:#fff;">
        <tr>
          <th style="padding:10px 14px;text-align:left;">#</th>
          <th style="padding:10px 14px;text-align:left;">Document Type</th>
          <th style="padding:10px 14px;text-align:left;">File</th>
          <th style="padding:10px 14px;text-align:left;">AI Result</th>
          <th style="padding:10px 14px;text-align:left;">Extracted Info</th>
          <th style="padding:10px 14px;text-align:left;">Uploaded</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($uploaded as $i => $doc):
          $ai      = $doc['ai_result'] ?? null;
          $verdict = $ai['is_authentic'] ?? null;
          $ext_data = $ai['extracted'] ?? [];

          if ($verdict === true)          { $vc='#16a34a'; $vi='fa-circle-check';    $vl='Authentic'; $conf=$ai['confidence']??0; }
          elseif ($verdict === false)     { $vc='#dc2626'; $vi='fa-circle-xmark';    $vl='Fake/Altered'; $conf=$ai['confidence']??0; }
          elseif ($verdict==='uncertain') { $vc='#f59e0b'; $vi='fa-circle-question'; $vl='Uncertain'; $conf=$ai['confidence']??0; }
          else                            { $vc='#94a3b8'; $vi='fa-robot';           $vl='Not Inspected'; $conf=0; }
        ?>
        <tr style="border-bottom:1px solid #f0f2f5;<?= $i%2===0?'background:#fafafa;':'' ?>">
          <td style="padding:10px 14px;color:#aaa;"><?= $i+1 ?></td>

          <td style="padding:10px 14px;font-weight:600;color:#1a1a2e;">
            <i class="fa-solid fa-file-lines" style="color:#2563eb;margin-right:6px;"></i>
            <?= htmlspecialchars($doc_types[$doc['type']] ?? $doc['type']) ?>
          </td>

          <td style="padding:10px 14px;color:#555;font-size:.75rem;max-width:160px;
                     overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
            <?= htmlspecialchars($doc['file_name']) ?><br>
            <span style="color:#aaa;"><?= round($doc['file_size']/1024,1) ?> KB</span>
          </td>

          <td style="padding:10px 14px;">
            <span style="font-size:.75rem;color:<?= $vc ?>;font-weight:700;
                         display:inline-flex;align-items:center;gap:5px;">
              <i class="fa-solid <?= $vi ?>"></i> <?= $vl ?>
            </span>
            <?php if ($conf > 0): ?>
            <div style="margin-top:4px;font-size:.68rem;color:#aaa;"><?= $conf ?>% confidence</div>
            <?php endif; ?>
            <?php if (!empty($ai['red_flags'])): ?>
            <div style="margin-top:4px;">
              <?php foreach ($ai['red_flags'] as $flag): ?>
              <div style="font-size:.68rem;color:#dc2626;background:#fff1f2;
                          border-radius:4px;padding:2px 7px;margin-top:2px;display:inline-block;">
                <i class="fa-solid fa-xmark"></i> <?= htmlspecialchars($flag) ?>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </td>

          <td style="padding:10px 14px;">
            <?php if (!empty($ext_data)): ?>
            <div style="font-size:.75rem;color:#1a1a2e;line-height:1.9;">
              <?php if (!empty($ext_data['full_name'])): ?>
              <div><span style="color:#aaa;font-size:.68rem;">NAME</span><br>
                <strong><?= htmlspecialchars($ext_data['full_name']) ?></strong></div>
              <?php endif; ?>
              <?php if (!empty($ext_data['date_of_birth'])): ?>
              <div><span style="color:#aaa;font-size:.68rem;">BIRTHDAY</span><br>
                <?= htmlspecialchars($ext_data['date_of_birth']) ?></div>
              <?php endif; ?>
              <?php if (!empty($ext_data['sex'])): ?>
              <div><span style="color:#aaa;font-size:.68rem;">SEX</span><br>
                <?= htmlspecialchars($ext_data['sex']) ?></div>
              <?php endif; ?>
              <?php if (!empty($ext_data['place_of_birth'])): ?>
              <div><span style="color:#aaa;font-size:.68rem;">PLACE OF BIRTH</span><br>
                <?= htmlspecialchars($ext_data['place_of_birth']) ?></div>
              <?php endif; ?>
              <?php if (!empty($ext_data['registration_number'])): ?>
              <div><span style="color:#aaa;font-size:.68rem;">REG. NO.</span><br>
                <?= htmlspecialchars($ext_data['registration_number']) ?></div>
              <?php endif; ?>
            </div>
            <?php else: ?>
            <span style="font-size:.75rem;color:#ccc;font-style:italic;">
              <?= $ai ? '—' : 'Inspect to extract' ?>
            </span>
            <?php endif; ?>
          </td>

          <td style="padding:10px 14px;color:#aaa;font-size:.72rem;white-space:nowrap;">
            <?= $doc['uploaded'] ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <?php if (!empty($uploaded[0]['ref_number'])): ?>
    <div style="margin-top:16px;background:#eff6ff;border-radius:8px;padding:12px 16px;
                font-size:.82rem;color:#1d4ed8;border:1px solid #bfdbfe;">
      <i class="fa-solid fa-hashtag"></i>
      Application Reference: <strong><?= htmlspecialchars($uploaded[0]['ref_number']) ?></strong>
    </div>
    <?php endif; ?>

    <?php if ($inspected < count($uploaded)): ?>
    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;
                padding:12px 16px;margin-top:14px;font-size:.8rem;color:#1d4ed8;">
      <i class="fa-solid fa-robot"></i>
      <strong><?= count($uploaded) - $inspected ?> document<?= (count($uploaded)-$inspected)!==1?'s':'' ?></strong>
      not yet AI-inspected. Go back to <a href="upload.php" style="color:#2563eb;font-weight:700;">Upload</a>
      and click <strong>Inspect</strong> to verify them before submitting.
    </div>
    <?php endif; ?>

    <div style="background:#fff7ed;border:1px solid #fcd34d;border-radius:8px;
                padding:12px 16px;margin-top:12px;font-size:.8rem;color:#92400e;">
      <i class="fa-solid fa-triangle-exclamation"></i>
      Once submitted, files cannot be replaced through this portal. Contact the registrar for corrections.
    </div>

    <div class="enroll-actions">
      <a href="upload.php" class="btn-back">
        <i class="fa-solid fa-arrow-left"></i> Add / Inspect More
      </a>
      <form method="POST" action="submit.php" style="display:inline;">
        <button type="submit" class="btn-proceed">
          Submit Requirements <i class="fa-solid fa-paper-plane"></i>
        </button>
      </form>
    </div>

  </div>
</div>

</body>
</html>
