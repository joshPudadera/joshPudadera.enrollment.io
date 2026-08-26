/* validation.js — Enrollment Validation page logic
   Loaded after dashboard.js so openModal/closeModal/showAlertModal/escapeHtml are available. */
(function () {
    'use strict';

    var API = '../shared/enrollment_actions.php';

    /* ── Button click handler (capture phase, no stopImmediatePropagation)
          Capture phase runs before dashboard.js bubble listeners so our
          handler fires first. We do NOT call stopImmediatePropagation so
          that dashboard.js's [data-close] and .modal-overlay handlers
          still work normally for Cancel / X / overlay-click.            */
    document.addEventListener('click', function (e) {
        var ab = e.target.closest('.val-approve-btn');
        var rb = e.target.closest('.val-reject-btn');
        if (!ab && !rb) return;
        /* Stop the event here so dashboard.js section-7 (.btn-view fetch)
           and section-10 (.btn-delete) don't also fire on this click.
           We use stopPropagation (not Immediate) so other capture
           listeners (like the .modal stopPropagation one) still run
           on subsequent clicks inside the now-open modal.              */
        e.stopPropagation();
        openVal(ab ? ab.dataset.id : rb.dataset.id, ab ? 'Approved' : 'Rejected');
    }, true);

    /* ── Open validate modal ──────────────────────────────── */
    function openVal(id, status) {
        if (!id) return;

        document.getElementById('valPreRegId').value = id;
        document.getElementById('valStatus').value   = status;
        document.getElementById('validateTitle').textContent =
            status === 'Approved' ? 'Approve Application' : 'Reject Application';

        /* clear previous warnings */
        var body = document.getElementById('validateModalBody');
        body.querySelectorAll('.val-warning').forEach(function (el) { el.remove(); });
        document.getElementById('valRemarks').value = '';

        /* reset confirm button */
        var cb = document.getElementById('btnConfirmValidate');
        cb.disabled    = false;
        cb.textContent = 'Confirm';

        openModal('validateModal');

        if (status !== 'Approved') return;
        checkEmail(id, body);
        checkName(id, body);
    }

    /* ── Email conflict check ─────────────────────────────── */
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

    /* ── Name conflict check ──────────────────────────────── */
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
                            + '<span style="font-size:.7rem;font-weight:700;padding:2px 8px;'
                            + 'border-radius:20px;' + bg + '">'
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
                    + '<th style="text-align:left;font-size:.68rem;color:#991b1b;'
                        + 'padding-bottom:4px;border-bottom:1px solid #fca5a5;">Name</th>'
                    + '<th style="text-align:left;font-size:.68rem;color:#991b1b;'
                        + 'padding-bottom:4px;border-bottom:1px solid #fca5a5;padding-left:8px;">'
                        + 'Course / Track</th>'
                    + '<th style="text-align:left;font-size:.68rem;color:#991b1b;'
                        + 'padding-bottom:4px;border-bottom:1px solid #fca5a5;padding-left:8px;">'
                        + 'Ref No.</th>'
                    + '<th style="text-align:left;font-size:.68rem;color:#991b1b;'
                        + 'padding-bottom:4px;border-bottom:1px solid #fca5a5;">Status</th>'
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
            ? 'background:#fff1f2;border:1px solid #fca5a5;border-radius:8px;'
                + 'padding:10px 14px;margin-bottom:10px;font-size:.8rem;color:#991b1b;'
            : 'background:#fff7ed;border:1px solid #fcd34d;border-radius:8px;'
                + 'padding:10px 14px;margin-bottom:10px;font-size:.8rem;color:#92400e;';
        return el;
    }

    /* ── Confirm (Approve / Reject) ───────────────────────── */
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
                        console.error('Non-JSON response from server:', text);
                        showAlertModal(
                            'Server error - check browser console.\n\n'
                            + text.substring(0, 200),
                            'error'
                        );
                        btn.disabled    = false;
                        btn.textContent = 'Confirm';
                        return;
                    }

                    closeModal('validateModal');
                    btn.disabled    = false;
                    btn.textContent = 'Confirm';

                    if (data.success) {
                        if (data.login_url) {
                            /* Show login-link modal */
                            var urlEl  = document.getElementById('loginLinkUrl');
                            var userEl = document.getElementById('loginLinkUsername');
                            if (urlEl)  { urlEl.href = data.login_url; urlEl.textContent = data.login_url; }
                            if (userEl) { userEl.textContent = data.username || ''; }
                            openModal('loginLinkModal');
                        } else {
                            location.reload();
                        }
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

    /* ── Copy link ────────────────────────────────────────── */
    var copyBtn = document.getElementById('btnCopyLink');
    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            var url = document.getElementById('loginLinkUrl').href;
            var btn = this;
            function done() {
                btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
                setTimeout(function () {
                    btn.innerHTML = '<i class="fa-solid fa-copy"></i> Copy Link';
                }, 2000);
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(done).catch(fallback);
            } else {
                fallback();
            }
            function fallback() {
                var ta = document.createElement('textarea');
                ta.value = url;
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                done();
            }
        });
    }

    /* Reload table when login-link modal closes */
    document.querySelectorAll('[data-close="loginLinkModal"]').forEach(function (el) {
        el.addEventListener('click', function () { location.reload(); });
    });

}());
