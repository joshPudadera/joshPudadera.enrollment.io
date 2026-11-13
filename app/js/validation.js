/* validation.js — Enrollment Validation page logic
   Loaded after dashboard.js so openModal/closeModal/showAlertModal/escapeHtml are available. */
(function () {
    'use strict';

    var API = '../shared/enrollment_actions.php';

    // ── Approve / Reject button handler ──────────────────────────
    document.addEventListener('click', function (e) {
        var ab = e.target.closest('.val-approve-btn');
        var rb = e.target.closest('.val-reject-btn');
        if (!ab && !rb) return;
        e.stopPropagation();
        openVal(ab ? ab.dataset.id : rb.dataset.id, ab ? 'Approved' : 'Rejected');
    }, true);

    // ── Open validate modal ───────────────────────────────────────
    function openVal(id, status) {
        if (!id) return;
        document.getElementById('valPreRegId').value = id;
        document.getElementById('valStatus').value   = status;
        document.getElementById('validateTitle').textContent =
            status === 'Approved' ? 'Approve Application' : 'Reject Application';

        var body = document.getElementById('validateModalBody');
        body.querySelectorAll('.val-warning').forEach(function (el) { el.remove(); });
        document.getElementById('valRemarks').value = '';

        var cb = document.getElementById('btnConfirmValidate');
        cb.disabled    = false;
        cb.textContent = 'Confirm';

        openModal('validateModal');

        if (status !== 'Approved') return;
        checkEmail(id, body);
        checkName(id, body);
    }

    // ── Email conflict check ──────────────────────────────────────
    function checkEmail(id, body) {
        var fd = new FormData();
        fd.set('action', 'check_email_conflict');
        fd.set('pre_reg_id', id);
        fetch(API, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success || !d.conflict) return;
                var div = mkWarn('amber');
                div.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> '
                    + '<strong>Email conflict:</strong> ' + escapeHtml(d.message);
                body.insertBefore(div, body.firstChild);
            })
            .catch(function () {});
    }

    // ── Name conflict check ───────────────────────────────────────
    function checkName(id, body) {
        var fd = new FormData();
        fd.set('action', 'check_name_conflict');
        fd.set('pre_reg_id', id);
        fetch(API, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success || !d.conflict) return;
                var rows = d.matches.map(function (m) {
                    var bg = m.status === 'Enrolled'
                        ? 'background:#dbeafe;color:#1d4ed8;'
                        : 'background:#dcfce7;color:#15803d;';
                    return '<tr>'
                        + '<td style="padding:5px 8px 5px 0;font-weight:700;font-size:.8rem;">'
                            + escapeHtml(m.name) + '</td>'
                        + '<td style="padding:5px 8px;color:#555;font-size:.75rem;">'
                            + escapeHtml(m.course) + '</td>'
                        + '<td style="padding:5px 8px;font-size:.75rem;">'
                            + '<code style="background:#f3f4f6;padding:2px 6px;border-radius:4px;font-size:.7rem;">'
                            + escapeHtml(m.ref || '-') + '</code></td>'
                        + '<td style="padding:5px 0;">'
                            + '<span style="font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:20px;' + bg + '">'
                            + escapeHtml(m.status) + '</span></td>'
                        + '</tr>';
                }).join('');
                var div = mkWarn('red');
                div.innerHTML =
                    '<div style="font-weight:700;margin-bottom:8px;">'
                        + '<i class="fa-solid fa-user-xmark"></i>'
                        + ' Duplicate name already on record</div>'
                    + '<div style="overflow-x:auto;">'
                    + '<table style="width:100%;border-collapse:collapse;">'
                    + '<thead><tr>'
                    + '<th style="text-align:left;font-size:.68rem;color:#991b1b;padding-bottom:4px;border-bottom:1px solid #fca5a5;">Name</th>'
                    + '<th style="text-align:left;font-size:.68rem;color:#991b1b;padding-bottom:4px;border-bottom:1px solid #fca5a5;padding-left:8px;">Course / Track</th>'
                    + '<th style="text-align:left;font-size:.68rem;color:#991b1b;padding-bottom:4px;border-bottom:1px solid #fca5a5;padding-left:8px;">Ref No.</th>'
                    + '<th style="text-align:left;font-size:.68rem;color:#991b1b;padding-bottom:4px;border-bottom:1px solid #fca5a5;">Status</th>'
                    + '</tr></thead>'
                    + '<tbody>' + rows + '</tbody>'
                    + '</table></div>';
                body.insertBefore(div, body.firstChild);
            })
            .catch(function () {});
    }

    function mkWarn(color) {
        var el = document.createElement('div');
        el.className = 'val-warning';
        el.style.cssText = color === 'red'
            ? 'background:#fff1f2;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;margin-bottom:10px;font-size:.8rem;color:#991b1b;'
            : 'background:#fff7ed;border:1px solid #fcd34d;border-radius:8px;padding:10px 14px;margin-bottom:10px;font-size:.8rem;color:#92400e;';
        return el;
    }

    // ── Confirm (Approve / Reject) ────────────────────────────────
    var confirmBtn = document.getElementById('btnConfirmValidate');
    if (confirmBtn) {
        confirmBtn.addEventListener('click', function () {
            var btn = this;
            btn.disabled    = true;
            btn.textContent = 'Processing...';

            var fd = new FormData();
            fd.set('action',     'validate_application');
            fd.set('pre_reg_id', document.getElementById('valPreRegId').value);
            fd.set('status',     document.getElementById('valStatus').value);
            fd.set('remarks',    document.getElementById('valRemarks').value);

            fetch(API, { method: 'POST', body: fd })
                .then(function (r) { return r.text(); })
                .then(function (text) {
                    var data;
                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        console.error('Non-JSON from server:', text);
                        showAlertModal('Server error.\n\n' + text.substring(0, 200), 'error');
                        btn.disabled    = false;
                        btn.textContent = 'Confirm';
                        return;
                    }

                    closeModal('validateModal');
                    btn.disabled    = false;
                    btn.textContent = 'Confirm';

                    if (data.success) {
                        // ── Show credentials modal (username + temp password) ──
                        var userEl   = document.getElementById('loginLinkUsername');
                        var passEl   = document.getElementById('loginLinkTempPass');
                        var bannerEl = document.getElementById('emailStatusBanner');

                        if (userEl) userEl.textContent = data.username || '';
                        if (passEl) passEl.textContent = data.temp_pass || '';

                        // Email delivery banner
                        if (bannerEl) {
                            if (data.email_sent) {
                                bannerEl.style.cssText = 'display:block;margin-bottom:14px;border-radius:8px;padding:10px 14px;font-size:.82rem;background:#f0fdf4;border:1px solid #86efac;color:#166534;';
                                bannerEl.innerHTML = '<i class="fa-solid fa-envelope-circle-check"></i> '
                                    + 'Credentials emailed to <strong>' + escapeHtml(data.email_to || '') + '</strong>';
                            } else {
                                bannerEl.style.cssText = 'display:block;margin-bottom:14px;border-radius:8px;padding:10px 14px;font-size:.82rem;background:#fff7ed;border:1px solid #fcd34d;color:#92400e;';
                                bannerEl.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> '
                                    + 'Email could not be sent — share credentials below manually.'
                                    + (data.send_error || data.email_error
                                        ? '<div style="margin-top:5px;font-size:.72rem;opacity:.8;">' + escapeHtml(data.send_error || data.email_error) + '</div>'
                                        : '');
                            }
                        }

                        openModal('loginLinkModal');
                    } else {
                        showAlertModal(data.message, 'error');
                    }
                })
                .catch(function () {
                    btn.disabled    = false;
                    btn.textContent = 'Confirm';
                    showAlertModal('Request failed. Please try again.', 'error');
                });
        });
    }

    // ── Copy individual credential ────────────────────────────────
    window.copyCredential = function (elId, btnId) {
        var el  = document.getElementById(elId);
        var btn = document.getElementById(btnId);
        if (!el || !btn) return;
        var val = el.textContent.trim();
        var orig = btn.innerHTML;

        function done() {
            btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
            setTimeout(function () { btn.innerHTML = orig; }, 2000);
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(val).then(done).catch(function () {
                fallbackCopy(val, done);
            });
        } else {
            fallbackCopy(val, done);
        }
    };

    // ── Copy Both button ──────────────────────────────────────────
    var copyBothBtn = document.getElementById('btnCopyBoth');
    if (copyBothBtn) {
        copyBothBtn.addEventListener('click', function () {
            var u = (document.getElementById('loginLinkUsername')  || {}).textContent || '';
            var p = (document.getElementById('loginLinkTempPass')  || {}).textContent || '';
            var text = 'Username: ' + u.trim() + '\nTemporary Password: ' + p.trim();
            var btn  = this;
            var orig = btn.innerHTML;

            function done() {
                btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
                setTimeout(function () { btn.innerHTML = orig; }, 2000);
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done).catch(function () { fallbackCopy(text, done); });
            } else {
                fallbackCopy(text, done);
            }
        });
    }

    function fallbackCopy(text, cb) {
        var ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        if (cb) cb();
    }

    // ── Reload table when credentials modal closes ────────────────
    document.querySelectorAll('[data-close="loginLinkModal"]').forEach(function (el) {
        el.addEventListener('click', function () { location.reload(); });
    });

}());
