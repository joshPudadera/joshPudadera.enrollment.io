// ============================================================
//  DASHBOARD.JS
//  Dashboard-specific interactions (CRUD, modals, charts).
//  Sidebar/navbar is handled by sidebar.js (loaded via sidebar.php).
//
//  SECTIONS:
//    1. CHART
//    2. FORM VALIDATION HELPERS
//    3. MODAL HELPERS
//    4. TOAST NOTIFICATIONS
//    5. NOTIFICATION BELL
//    6. BULK SELECTION
//    7. VIEW MODAL
//    8. ADD MODAL
//    9. EDIT MODAL
//   10. DELETE
// ============================================================


// ============================================================
//  1. CHART
// ============================================================
var chartCanvas = document.getElementById('reportChart');
if (chartCanvas && typeof Chart !== 'undefined') {
    var hasRealData = window._dashChartLabels && window._dashChartLabels.length > 0;
    var chartLabels   = hasRealData ? window._dashChartLabels   : ['BSIT','BSCS','BSIS','Other'];
    var activeData    = hasRealData ? window._dashChartActive   : [10, 8, 5, 2];
    var inactiveData  = hasRealData ? window._dashChartInactive : [3, 2, 1, 0];

    new Chart(chartCanvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels: chartLabels,
            datasets: [
                { label: 'Active',   data: activeData,   backgroundColor: '#22c55e', borderRadius: 4 },
                { label: 'Inactive', data: inactiveData, backgroundColor: '#ef4444', borderRadius: 4 }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } },
            scales: {
                y: { beginAtZero: true, ticks: { font: { size: 10 } }, grid: { color: '#f0f0f0' } },
                x: { ticks: { font: { size: 10 } }, grid: { display: false } }
            }
        }
    });
}


// ============================================================
//  2. FORM VALIDATION HELPERS
// ============================================================
function validateForm(form, requiredFields) {
    var valid = true;
    Object.entries(requiredFields).forEach(function(entry) {
        var name  = entry[0];
        var label = entry[1];
        var input = form.querySelector('[name="' + name + '"]');
        if (!input) return;
        var field   = input.closest('.form-field');
        var value   = input.value.trim();
        var errorEl = field ? field.querySelector('.field-error') : null;
        if (field && !errorEl) {
            errorEl = document.createElement('span');
            errorEl.className = 'field-error';
            field.appendChild(errorEl);
        }
        if (!value) {
            input.classList.add('input-error');
            if (field) field.classList.add('has-error');
            if (errorEl) errorEl.textContent = label + ' is required.';
            valid = false;
        } else {
            input.classList.remove('input-error');
            if (field) field.classList.remove('has-error');
            if (errorEl) errorEl.textContent = '';
        }
    });
    return valid;
}

function clearFieldError(input) {
    if (input.value.trim()) {
        input.classList.remove('input-error');
        var field = input.closest('.form-field');
        if (field) field.classList.remove('has-error');
        var errorEl = field ? field.querySelector('.field-error') : null;
        if (errorEl) errorEl.textContent = '';
    }
}

function attachLiveValidation(form) {
    form.querySelectorAll('input, select').forEach(function(input) {
        input.addEventListener('focus',  function() { if (!this.classList.contains('input-error')) this.style.borderColor = '#2563eb'; });
        input.addEventListener('blur',   function() { if (!this.classList.contains('input-error')) this.style.borderColor = ''; });
        input.addEventListener('input',  function() { clearFieldError(this); });
        input.addEventListener('change', function() { clearFieldError(this); });
    });
}


// ============================================================
//  3. MODAL HELPERS
// ============================================================
function openModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.add('active');
}
function closeModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.remove('active');
}

document.addEventListener('click', function(e) {
    // Prevent clicks inside a modal from closing it via the overlay handler.
    // We do NOT stopPropagation here — that would break buttons inside modals.
    if (e.target.closest('.modal')) return;
    var closeBtn = e.target.closest('[data-close]');
    if (closeBtn) { closeModal(closeBtn.dataset.close); return; }
    if (e.target.classList.contains('modal-overlay')) e.target.classList.remove('active');
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active').forEach(function(overlay) {
            overlay.classList.remove('active');
        });
    }
});



// ============================================================
//  3b. ALERT MODAL (replaces native alert())
// ============================================================
function ensureSharedModals() {
    if (!document.getElementById('alertModalOverlay')) {
        var html = '' +
'<div class="modal-overlay" id="alertModalOverlay">' +
'  <div class="modal modal-sm">' +
'    <div class="modal-header"><span id="alertModalTitle">Alert</span><button class="modal-close" data-close="alertModalOverlay">&times;</button></div>' +
'    <div class="modal-body" id="alertModalBody" style="padding:24px 22px;"></div>' +
'    <div class="modal-footer"><button class="btn-modal-submit" data-close="alertModalOverlay" style="flex:0 0 auto;padding:10px 48px;">OK</button></div>' +
'  </div>' +
'</div>' +
'<div class="modal-overlay" id="confirmModalOverlay">' +
'  <div class="modal modal-sm">' +
'    <div class="modal-header"><span id="confirmModalTitle">Confirm</span><button class="modal-close" data-close="confirmModalOverlay">&times;</button></div>' +
'    <div class="modal-body" id="confirmModalBody" style="padding:24px 22px 12px;"></div>' +
'    <div class="modal-footer modal-footer-split">' +
'      <button class="btn-modal-cancel" id="confirmModalCancel">Cancel</button>' +
'      <button class="btn-modal-confirm" id="confirmModalOk">Confirm</button>' +
'    </div>' +
'  </div>' +
'</div>';
        var div = document.createElement('div');
        div.innerHTML = html;
        while (div.firstChild) document.body.appendChild(div.firstChild);
    }
}

function showAlertModal(message, type, title) {
    ensureSharedModals();
    var overlay = document.getElementById('alertModalOverlay');
    var titleEl = document.getElementById('alertModalTitle');
    var bodyEl  = document.getElementById('alertModalBody');
    type = type || 'info';
    var iconMap = {
        success: '<i class="fa-solid fa-circle-check" style="color:#16a34a;font-size:2rem;display:block;margin-bottom:12px;"></i>',
        error:   '<i class="fa-solid fa-circle-xmark" style="color:#dc2626;font-size:2rem;display:block;margin-bottom:12px;"></i>',
        warning: '<i class="fa-solid fa-triangle-exclamation" style="color:#d97706;font-size:2rem;display:block;margin-bottom:12px;"></i>',
        info:    '<i class="fa-solid fa-circle-info" style="color:#2563eb;font-size:2rem;display:block;margin-bottom:12px;"></i>'
    };
    titleEl.textContent = title || ({
        success: 'Success', error: 'Error', warning: 'Warning', info: 'Information'
    }[type] || 'Alert');
    bodyEl.innerHTML = '<div style="text-align:center;">' + (iconMap[type] || iconMap.info) +
        '<p style="font-size:.88rem;color:#333;line-height:1.55;white-space:pre-wrap;word-break:break-word;">' + escapeHtml(message) + '</p></div>';
    openModal('alertModalOverlay');
}

function escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

// ============================================================
//  3c. CONFIRM MODAL (replaces native confirm())
// ============================================================
var _confirmCallback = null;

function showConfirmModal(message, onConfirm, title) {
    ensureSharedModals();
    var overlay = document.getElementById('confirmModalOverlay');
    var titleEl = document.getElementById('confirmModalTitle');
    var bodyEl  = document.getElementById('confirmModalBody');
    var okBtn   = document.getElementById('confirmModalOk');
    var cancelBtn = document.getElementById('confirmModalCancel');
    titleEl.textContent = title || 'Confirm Action';
    bodyEl.innerHTML = '<div style="display:flex;gap:12px;align-items:flex-start;">' +
        '<i class="fa-solid fa-circle-question" style="color:#2563eb;font-size:1.6rem;flex-shrink:0;margin-top:2px;"></i>' +
        '<div style="flex:1;font-size:.88rem;color:#333;line-height:1.55;">' + escapeHtml(message) + '</div></div>';
    _confirmCallback = onConfirm || null;
    if (!okBtn._hasHandler) {
        okBtn.addEventListener('click', function() {
            closeModal('confirmModalOverlay');
            var cb = _confirmCallback;
            _confirmCallback = null;
            if (cb) setTimeout(function() { cb(true); }, 50);
        });
        okBtn._hasHandler = true;
    }
    if (!cancelBtn._hasHandler) {
        cancelBtn.addEventListener('click', function() {
            closeModal('confirmModalOverlay');
            var cb = _confirmCallback;
            _confirmCallback = null;
            if (cb) setTimeout(function() { cb(false); }, 50);
        });
        cancelBtn._hasHandler = true;
    }
    openModal('confirmModalOverlay');
}


// ============================================================
//  4. TOAST NOTIFICATIONS
// ============================================================
var TOAST_LABELS = { success: 'Submitted', updated: 'Updated', warning: 'Warning', error: 'Error' };

function showToast(message, type) {
    type = type || 'success';
    var toast = document.querySelector('.toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.className = 'toast';
        toast.innerHTML = '<div class="toast-label"><span class="toast-dot"></span><span class="toast-title"></span></div><div class="toast-msg"></div>';
        document.body.appendChild(toast);
    }
    toast.querySelector('.toast-title').textContent = TOAST_LABELS[type] || type;
    toast.querySelector('.toast-msg').textContent   = message;
    toast.className = 'toast ' + type;
    void toast.offsetWidth;
    toast.classList.add('show');
    clearTimeout(toast._hideTimer);
    toast._hideTimer = setTimeout(function() { toast.classList.remove('show'); }, 3500);
}

function reloadWithToast(message, type) {
    sessionStorage.setItem('pending_toast', JSON.stringify({ message: message, type: type }));
    location.reload();
}

window.addEventListener('DOMContentLoaded', function() {
    var pending = sessionStorage.getItem('pending_toast');
    if (pending) {
        sessionStorage.removeItem('pending_toast');
        var d = JSON.parse(pending);
        setTimeout(function() { showToast(d.message, d.type); }, 150);
    }

    // Auto-open Add Student modal if ?action=add in URL
    if (new URLSearchParams(window.location.search).get('action') === 'add') {
        setTimeout(function() {
            var btn = document.getElementById('btnAddStudent');
            if (btn) btn.click();
        }, 100);
    }
});


// ============================================================
//  5. NOTIFICATION BELL
// ============================================================
var bellBtn      = document.getElementById('bellBtn');
var bellBadge    = document.getElementById('bellBadge');
var notifPanel   = document.getElementById('notifPanel');
var notifOverlay = document.getElementById('notifOverlay');
var notifClose   = document.getElementById('notifClose');
var notifMarkAll = document.getElementById('notifMarkAll');

function openNotifPanel()  { if (notifPanel) notifPanel.classList.add('active');    if (notifOverlay) notifOverlay.classList.add('active'); }
function closeNotifPanel() { if (notifPanel) notifPanel.classList.remove('active'); if (notifOverlay) notifOverlay.classList.remove('active'); }

function updateBellBadge() {
    if (!bellBadge) return;
    var hasUnread = !!document.querySelector('#notifList .notif-item.unread');
    bellBadge.classList.toggle('has-notif', hasUnread);
}
updateBellBadge();

if (bellBtn) {
    bellBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        if (notifPanel && notifPanel.classList.contains('active')) closeNotifPanel();
        else openNotifPanel();
    });
}
if (notifClose)   notifClose.addEventListener('click', closeNotifPanel);
if (notifOverlay) notifOverlay.addEventListener('click', closeNotifPanel);

var notifList = document.getElementById('notifList');
if (notifList) {
    notifList.addEventListener('click', function(e) {
        var item = e.target.closest('.notif-item');
        if (item) { item.classList.remove('unread'); updateBellBadge(); }
    });
}
if (notifMarkAll) {
    notifMarkAll.addEventListener('click', function() {
        document.querySelectorAll('#notifList .notif-item.unread').forEach(function(el) { el.classList.remove('unread'); });
        updateBellBadge();
    });
}


// ============================================================
//  6. BULK SELECTION
// ============================================================
var checkAllBox = document.getElementById('checkAll');
var bulkToolbar = document.getElementById('bulkToolbar');
var bulkCountEl = document.getElementById('bulkCount');

function getCheckedIds() {
    return Array.from(document.querySelectorAll('.row-check:checked')).map(function(cb) { return cb.value; });
}

function updateBulkToolbar() {
    var ids  = getCheckedIds();
    var all  = document.querySelectorAll('.row-check');
    if (bulkCountEl) bulkCountEl.textContent = ids.length + ' selected';
    if (bulkToolbar) bulkToolbar.classList.toggle('visible', ids.length > 0);
    if (checkAllBox) {
        checkAllBox.indeterminate = ids.length > 0 && ids.length < all.length;
        checkAllBox.checked       = all.length > 0 && ids.length === all.length;
    }
}

if (checkAllBox) {
    checkAllBox.addEventListener('change', function() {
        document.querySelectorAll('.row-check').forEach(function(cb) { cb.checked = checkAllBox.checked; });
        updateBulkToolbar();
    });
}

var crudTbody = document.getElementById('crudTbody');
if (crudTbody) {
    crudTbody.addEventListener('change', function(e) {
        if (e.target.classList.contains('row-check')) updateBulkToolbar();
    });
}

var API_URL = typeof STUDENT_API !== 'undefined' ? STUDENT_API : '../shared/student_actions.php';

var btnBulkDelete = document.getElementById('btnBulkDelete');
if (btnBulkDelete) {
    btnBulkDelete.addEventListener('click', function() {
        var ids = getCheckedIds();
        if (!ids.length) return;
        var el = document.getElementById('bulkDeleteCount');
        if (el) el.textContent = ids.length;
        openModal('bulkDeleteModal');
    });
}

var btnConfirmBulkDelete = document.getElementById('btnConfirmBulkDelete');
if (btnConfirmBulkDelete) {
    btnConfirmBulkDelete.addEventListener('click', function() {
        var fd = new FormData();
        fd.set('action', 'bulk_delete');
        fd.set('ids', getCheckedIds().join(','));
        fetch(API_URL, { method: 'POST', body: fd }).then(function(r) { return r.json(); }).then(function(d) {
            if (d.success) { closeModal('bulkDeleteModal'); reloadWithToast(d.message, 'warning'); }
            else showToast(d.message, 'error');
        }).catch(function() { showToast('Request failed.', 'error'); });
    });
}

var btnBulkActive = document.getElementById('btnBulkActive');
if (btnBulkActive) {
    btnBulkActive.addEventListener('click', function() {
        var ids = getCheckedIds(); if (!ids.length) return;
        var fd = new FormData(); fd.set('action','bulk_status'); fd.set('ids',ids.join(',')); fd.set('status','Active');
        fetch(API_URL, { method:'POST', body:fd }).then(function(r){return r.json();}).then(function(d){
            if (d.success) reloadWithToast(d.message,'updated'); else showToast(d.message,'error');
        }).catch(function(){showToast('Request failed.','error');});
    });
}

var btnBulkInactive = document.getElementById('btnBulkInactive');
if (btnBulkInactive) {
    btnBulkInactive.addEventListener('click', function() {
        var ids = getCheckedIds(); if (!ids.length) return;
        var fd = new FormData(); fd.set('action','bulk_status'); fd.set('ids',ids.join(',')); fd.set('status','Inactive');
        fetch(API_URL, { method:'POST', body:fd }).then(function(r){return r.json();}).then(function(d){
            if (d.success) reloadWithToast(d.message,'updated'); else showToast(d.message,'error');
        }).catch(function(){showToast('Request failed.','error');});
    });
}


// ============================================================
//  7. VIEW MODAL
// ============================================================
document.querySelectorAll('.btn-view').forEach(function(btn) {
    btn.addEventListener('click', function() {
        fetch(API_URL + '?action=get&id=' + this.dataset.id)
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d.success) { showToast(d.message, 'error'); return; }
                var s = d.student;
                var set = function(id, val) { var el = document.getElementById(id); if (el) el.textContent = val; };
                set('vName',    s.first_name + ' ' + s.last_name);
                set('vBirthday', s.birthday);
                set('vPhone',   s.phone);
                set('vCourse',  s.course);
                set('vYear',    s.year_level);
                set('vSection', s.section);
                openModal('viewModal');
            })
            .catch(function() { showToast('Failed to load student.', 'error'); });
    });
});


// ============================================================
//  8. ADD MODAL
// ============================================================
var modalRequired = {
    first_name: 'First Name', last_name: 'Last Name', birthday: 'Birthday',
    course: 'Course', year_level: 'Year Level', section: 'Section', phone: 'Phone'
};

var btnAddStudent = document.getElementById('btnAddStudent');
if (btnAddStudent) {
    btnAddStudent.addEventListener('click', function() {
        var form = document.getElementById('studentCrudForm');
        if (!form) return;
        var titleEl = document.getElementById('formModalTitle');
        if (titleEl) titleEl.textContent = 'Add Student';
        form.reset();
        form.querySelectorAll('.input-error').forEach(function(el) { el.classList.remove('input-error'); });
        form.querySelectorAll('.form-field.has-error').forEach(function(el) { el.classList.remove('has-error'); });
        form.querySelectorAll('.field-error').forEach(function(el) { el.textContent = ''; });
        var idEl = document.getElementById('crudId'); if (idEl) idEl.value = '';
        var actEl = document.getElementById('crudAction'); if (actEl) actEl.value = 'add';
        attachLiveValidation(form);
        openModal('formModal');
    });
}


// ============================================================
//  9. EDIT MODAL
// ============================================================
document.querySelectorAll('.btn-edit').forEach(function(btn) {
    btn.addEventListener('click', function() {
        fetch(API_URL + '?action=get&id=' + this.dataset.id)
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d.success) { showToast(d.message, 'error'); return; }
                var s    = d.student;
                var form = document.getElementById('studentCrudForm');
                if (!form) return;
                var titleEl = document.getElementById('formModalTitle'); if (titleEl) titleEl.textContent = 'Edit Student';
                var set = function(id, val) { var el = document.getElementById(id); if (el) el.value = val; };
                set('crudId',     s.id);
                set('crudAction', 'edit');
                set('cFirst',     s.first_name);
                set('cLast',      s.last_name);
                set('cBday',      s.birthday);
                set('cCourse',    s.course);
                set('cYear',      s.year_level);
                set('cSection',   s.section);
                set('cPhone',     s.phone);
                set('cStatus',    s.status);
                form.querySelectorAll('.input-error').forEach(function(el) { el.classList.remove('input-error'); });
                form.querySelectorAll('.form-field.has-error').forEach(function(el) { el.classList.remove('has-error'); });
                form.querySelectorAll('.field-error').forEach(function(el) { el.textContent = ''; });
                attachLiveValidation(form);
                openModal('formModal');
            })
            .catch(function() { showToast('Failed to load student.', 'error'); });
    });
});

var btnCrudSubmit = document.getElementById('btnCrudSubmit');
if (btnCrudSubmit) {
    btnCrudSubmit.addEventListener('click', function() {
        var form = document.getElementById('studentCrudForm');
        if (!form) return;
        var actEl  = document.getElementById('crudAction');
        var isEdit = actEl && actEl.value === 'edit';
        if (!validateForm(form, modalRequired)) return;
        var fd = new FormData(form);
        fetch(API_URL, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.success) { closeModal('formModal'); reloadWithToast(d.message, isEdit ? 'updated' : 'success'); }
                else showToast(d.message, 'error');
            })
            .catch(function() { showToast('Request failed.', 'error'); });
    });
}


// ============================================================
//  10. DELETE
// ============================================================
var pendingDeleteId = null;

document.querySelectorAll('.btn-delete').forEach(function(btn) {
    btn.addEventListener('click', function() {
        pendingDeleteId = this.dataset.id;
        var nameEl = document.getElementById('deleteStudentName');
        if (nameEl) nameEl.textContent = this.dataset.name;
        openModal('deleteModal');
    });
});

var btnConfirmDelete = document.getElementById('btnConfirmDelete');
if (btnConfirmDelete) {
    btnConfirmDelete.addEventListener('click', function() {
        if (!pendingDeleteId) return;
        var fd = new FormData(); fd.set('action','delete'); fd.set('id', pendingDeleteId);
        fetch(API_URL, { method:'POST', body:fd })
            .then(function(r){return r.json();})
            .then(function(d){
                if (d.success) { closeModal('deleteModal'); reloadWithToast(d.message,'warning'); }
                else showToast(d.message,'error');
            })
            .catch(function(){showToast('Request failed.','error');});
    });
}


// ============================================================
//  11. GLOBAL CONFIRM MODAL (replaces browser confirm())
// ============================================================
function showConfirm(message, onConfirm, onCancel) {
    var overlay = document.getElementById('globalConfirmModal');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'globalConfirmModal';
        overlay.className = 'modal-overlay';
        overlay.innerHTML =
            '<div class="modal modal-sm">' +
                '<div class="modal-body" style="padding:28px 24px 16px;">' +
                    '<h3 style="font-size:1rem;font-weight:700;margin-bottom:10px;">Confirm Action</h3>' +
                    '<p id="globalConfirmMsg" style="font-size:.85rem;color:#555;"></p>' +
                '</div>' +
                '<div class="modal-footer modal-footer-split">' +
                    '<button class="btn-modal-cancel" id="globalConfirmCancel">Cancel</button>' +
                    '<button class="btn-modal-confirm" id="globalConfirmOk">Confirm</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(overlay);
    }
    document.getElementById('globalConfirmMsg').textContent = message;
    overlay.classList.add('active');

    var ok     = document.getElementById('globalConfirmOk');
    var cancel = document.getElementById('globalConfirmCancel');

    var cleanup = function() {
        overlay.classList.remove('active');
        var newOk     = ok.cloneNode(true);
        var newCancel = cancel.cloneNode(true);
        ok.parentNode.replaceChild(newOk, ok);
        cancel.parentNode.replaceChild(newCancel, cancel);
        ok     = newOk;
        cancel = newCancel;
    };

    document.getElementById('globalConfirmOk').addEventListener('click', function() {
        cleanup();
        if (onConfirm) onConfirm();
    });
    document.getElementById('globalConfirmCancel').addEventListener('click', function() {
        cleanup();
        if (onCancel) onCancel();
    });
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) { cleanup(); if (onCancel) onCancel(); }
    });
}
