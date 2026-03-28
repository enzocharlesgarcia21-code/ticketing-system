var TMTicketModal = (function () {
  var chatInterval = null;
  var chatBadgeInterval = null;
  var chatBadgeTicketId = null;
  var chatModalOpen = false;
  var messengerOpen = false;
  var messengerInterval = null;
  var messengerTicketId = null;
  var messengerConfirmAction = null;
  var messengerReturnContext = null;
  var currentTicketId = null;
  var lastTicketMeta = null;
  var chatPermissionState = { canChat: true, lockedMessage: '', handlerName: '', statusLabel: '' };
  var messengerPermissionState = { canChat: false, lockedMessage: '', handlerName: '', statusLabel: '', isChecking: false };
  function qs(id) { return document.getElementById(id); }
  function ensureTicketModalExists() {
    if (!document || !document.body) return;

    if (!document.getElementById('tmSharedViewTicketsCss')) {
      var link = document.createElement('link');
      link.id = 'tmSharedViewTicketsCss';
      link.rel = 'stylesheet';
      link.href = '../css/view-tickets.css?v=' + Date.now();
      document.head.appendChild(link);
    }

    if (!qs('ticketModal')) {
      var overlay = document.createElement('div');
      overlay.id = 'ticketModal';
      overlay.className = 'modal-overlay';
      overlay.innerHTML = '<div class="modal-content" id="modalContent"></div>';
      document.body.appendChild(overlay);
    }

    if (!qs('imagePreviewModal')) {
      var imageModal = document.createElement('div');
      imageModal.id = 'imagePreviewModal';
      imageModal.className = 'image-preview-modal';
      imageModal.setAttribute('onclick', 'TMTicketModal.closeImagePreview(event)');
      imageModal.innerHTML =
        '<div class="image-preview-content">' +
        '  <button class="preview-close" onclick="TMTicketModal.closeImagePreview(event)">×</button>' +
        '  <img id="previewImage" src="" alt="Preview">' +
        '</div>';
      document.body.appendChild(imageModal);
    }
  }
  function getCsrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (meta && meta.getAttribute) {
      var v = meta.getAttribute('content');
      if (v) return String(v);
    }
    if (typeof window !== 'undefined' && window.TM_CSRF_TOKEN) return String(window.TM_CSRF_TOKEN);
    return '';
  }
  function postJson(url, formData) {
    var token = getCsrfToken();
    var headers = { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' };
    if (token) headers['X-CSRF-Token'] = String(token);
    return fetch(url, { method: 'POST', body: formData, headers: headers, credentials: 'same-origin' })
      .then(function (r) {
        return r.text().then(function (txt) {
          var data = null;
          try { data = JSON.parse(txt); } catch (e) { data = { error: 'Invalid server response.' }; }
          if (data && typeof data === 'object') {
            data._http_status = r.status;
            data._http_ok = r.ok;
          }
          return data;
        });
      });
  }
  function setCurrentTicketId(id) {
    if (id === null || id === undefined || id === '') return;
    currentTicketId = String(id);
    try { localStorage.setItem('tm_current_ticket_id', currentTicketId); } catch (e) { }
  }
  function getCurrentTicketId() {
    if (currentTicketId) return String(currentTicketId);
    try {
      var v = localStorage.getItem('tm_current_ticket_id');
      if (v) return String(v);
    } catch (e) { }
    return null;
  }
  function escapeHtml(text) {
    if (!text) return '';
    return String(text)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }
  function parseTicketDetailsResponse(text) {
    var raw = text == null ? '' : String(text);
    try {
      return JSON.parse(raw);
    } catch (e) {
      var firstBrace = raw.indexOf('{');
      var lastBrace = raw.lastIndexOf('}');
      if (firstBrace !== -1 && lastBrace > firstBrace) {
        var candidate = raw.slice(firstBrace, lastBrace + 1);
        try {
          return JSON.parse(candidate);
        } catch (inner) { }
      }
    }
    throw new Error(raw ? raw.slice(0, 300) : 'Invalid server response.');
  }
  function formatTimelineTime(dateLike) {
    if (!dateLike) return '-';
    var d = dateLike instanceof Date ? dateLike : new Date(dateLike);
    if (isNaN(d.getTime())) return '-';
    return d.toLocaleString(undefined, {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit'
    });
  }
  function assignedCompanyUsesDepartment(companyValue) {
    var normalized = companyValue == null ? '' : String(companyValue).trim().toLowerCase();
    return normalized === '@leadsagri.com' || normalized === 'lapc';
  }
  function companyDisplayName(companyValue) {
    var value = companyValue == null ? '' : String(companyValue).trim();
    if (!value) return '';
    var key = value.toLowerCase();
    var map = {
      '@farmasee.ph': 'FARMASEE',
      'farmasee.ph': 'FARMASEE',
      '@gmail.com': 'Gmail',
      'gmail.com': 'Gmail',
      '@gpsci.net': 'GPSCI',
      'gpsci.net': 'GPSCI',
      '@leads-eh.com': 'LEH',
      'leads-eh.com': 'LEH',
      '@leads-farmex.com': 'FARMEX',
      'leads-farmex.com': 'FARMEX',
      '@leadsagri.com': 'LAPC',
      'leadsagri.com': 'LAPC',
      'lapc': 'LAPC',
      '@leadsanimalhealth.com': 'LAH',
      'leadsanimalhealth.com': 'LAH',
      '@leadsav.com': 'LAV',
      'leadsav.com': 'LAV',
      '@leadstech-corp.com': 'LTC',
      'leadstech-corp.com': 'LTC',
      '@lingapleads.org': 'LINGAP',
      'lingapleads.org': 'LINGAP',
      '@malvedaholdings.com': 'MHC',
      'malvedaholdings.com': 'MHC',
      '@malvedaproperties.com': 'MPDC',
      'malvedaproperties.com': 'MPDC',
      '@primestocks.ph': 'PCC',
      'primestocks.ph': 'PCC'
    };
    return map[key] || value;
  }
  function getAssignedTargetParts(ticket) {
    var assignedDepartment = ticket && (ticket.assigned_department || ticket.assigned_group) ? String(ticket.assigned_department || ticket.assigned_group) : '';
    var assignedCompany = ticket && ticket.assigned_company ? String(ticket.assigned_company) : '';
    var handledBy = ticket && ticket.assigned_to_name ? String(ticket.assigned_to_name) : '';
    var showDepartment = assignedCompanyUsesDepartment(assignedCompany);
    var primary = showDepartment ? assignedDepartment : assignedCompany;
    if (!primary && assignedDepartment) primary = assignedDepartment;
    if (!primary && assignedCompany) primary = assignedCompany;
    return {
      primary: primary,
      department: assignedDepartment,
      company: assignedCompany,
      handledBy: handledBy,
      showDepartment: showDepartment
    };
  }
  function buildAssignedTargetHtml(ticket) {
    var info = getAssignedTargetParts(ticket);
    var lines = [];
    if (info.primary) {
      var primaryLabel = (!info.showDepartment && info.company && info.primary === info.company)
        ? companyDisplayName(info.primary)
        : info.primary;
      lines.push(escapeHtml(primaryLabel));
    }
    else lines.push('-');
    if (info.showDepartment && info.company) {
      lines.push('<small class="text-muted">(' + escapeHtml(companyDisplayName(info.company)) + ')</small>');
    }
    if (info.handledBy) {
      lines.push('<small class="text-muted">Handled by: ' + escapeHtml(info.handledBy) + '</small>');
    }
    return lines.join('<br>');
  }
  function renderTimeline(ticket) {
    var createdAt = ticket.created_at ? new Date(ticket.created_at) : null;
    var updatedAt = ticket.updated_at ? new Date(ticket.updated_at) : null;
    var fallbackWhen = updatedAt || createdAt;
    var events = [{ title: 'Ticket created', when: createdAt }];
    var assignedInfo = getAssignedTargetParts(ticket);
    if (assignedInfo.primary) events.push({ title: 'Assigned to ' + assignedInfo.primary, when: fallbackWhen });
    if (ticket.admin_note && String(ticket.admin_note).trim() !== '') events.push({ title: 'Admin added a note', when: fallbackWhen });
    if (ticket.status && ticket.status !== 'Open') events.push({ title: 'Status changed to ' + ticket.status, when: fallbackWhen });
    return '<div class="tm-timeline">' + events.map(function (e) {
      return '<div class="tm-timeline-item"><div class="tm-timeline-content"><div class="tm-timeline-title">' + escapeHtml(e.title) + '</div><div class="tm-timeline-time">' + formatTimelineTime(e.when) + '</div></div></div>';
    }).join('') + '</div>';
  }
  function viewButtonIfImage(filename) {
    var ext = filename.split('.').pop().toLowerCase();
    var isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext);
    if (!isImage) return '';
    var src = '../uploads/' + escapeHtml(filename);
    return '<button type="button" class="tm-action-btn tm-view-btn" data-src="' + src + '" onclick="TMTicketModal.viewImage(this.dataset.src)">' +
           '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>' +
           'View</button>';
  }
  function renderAttachment(att) {
    var filename = '';
    var displayName = '';
    if (typeof att === 'string') {
      filename = att;
      displayName = att;
    } else if (att && typeof att === 'object') {
      filename = att.stored_name || att.filename || att.file || '';
      displayName = att.original_name || att.display_name || filename;
    }
    if (!filename) return '';
    return '<div class="tm-attachment">' +
           '  <div class="tm-att-icon">' +
           '    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path></svg>' +
           '  </div>' +
           '  <div class="tm-att-details">' +
           '    <div class="tm-att-name" title="' + escapeHtml(displayName) + '">' + escapeHtml(displayName) + '</div>' +
           '    <div class="tm-att-actions">' +
           viewButtonIfImage(filename) +
           '      <a href="../uploads/' + filename + '" class="tm-action-btn tm-download-btn" download>' +
           '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>' +
           'Download</a>' +
           '    </div>' +
           '  </div>' +
           '</div>';
  }
  function normalizeAttachment(att) {
    var filename = '';
    var displayName = '';
    if (typeof att === 'string') {
      filename = att;
      displayName = att;
    } else if (att && typeof att === 'object') {
      filename = att.stored_name || att.filename || att.file || '';
      displayName = att.original_name || att.display_name || filename;
    }
    return { filename: filename, displayName: displayName };
  }
  function isImageFile(filename) {
    var ext = String(filename || '').split('.').pop().toLowerCase();
    return ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext);
  }
  function renderAttachmentsBlock(data) {
    var list = [];
    if (data && Array.isArray(data.attachments) && data.attachments.length) {
      list = data.attachments.slice();
    } else if (data && data.attachment) {
      list = [data.attachment];
    }
    if (!list.length) return '';
    var images = [];
    var others = [];
    list.forEach(function (att) {
      var n = normalizeAttachment(att);
      if (!n.filename) return;
      if (isImageFile(n.filename)) images.push(n);
      else others.push(att);
    });
    var html = '';
    if (images.length) {
      html += '<div class="tm-attachment-gallery">';
      html += images.map(function (n) {
        var src = '../uploads/' + escapeHtml(n.filename);
        return '<button type="button" class="tm-attachment-thumb" data-src="' + src + '" onclick="TMTicketModal.viewImage(this.dataset.src)">' +
               '<img class="tm-attachment-img" src="' + src + '" alt="' + escapeHtml(n.displayName || '') + '">' +
               '</button>';
      }).join('');
      html += '</div>';
    }
    if (others.length) {
      html += others.map(function (a) { return renderAttachment(a); }).join('');
    }
    return html;
  }
  function hasImageAttachments(data) {
    var list = [];
    if (data && Array.isArray(data.attachments) && data.attachments.length) {
      list = data.attachments.slice();
    } else if (data && data.attachment) {
      list = [data.attachment];
    }
    if (!list.length) return false;
    return list.some(function (att) {
      var n = normalizeAttachment(att);
      return !!n.filename && isImageFile(n.filename);
    });
  }
  function computeResolutionMinutes(createdAt, updatedAt) {
    if (!createdAt || !updatedAt) return null;
    var c = new Date(createdAt);
    var u = new Date(updatedAt);
    if (isNaN(c.getTime()) || isNaN(u.getTime())) return null;
    var diffMs = u.getTime() - c.getTime();
    if (diffMs <= 0) return 0;
    return Math.round(diffMs / 60000);
  }
  function formatResolutionString(minutes) {
    if (minutes == null) return null;
    if (minutes < 60) {
      var m = Math.max(0, Math.round(minutes));
      if (m === 0) return '0 min';
      if (m === 1) return '1 min';
      return m + ' mins';
    }
    var hrs = Math.floor(minutes / 60);
    var mins = minutes % 60;
    if (mins === 0) return hrs + ' ' + (hrs === 1 ? 'hr' : 'hrs');
    return hrs + ' ' + (hrs === 1 ? 'hr' : 'hrs') + ' ' + mins + ' ' + (mins === 1 ? 'min' : 'mins');
  }
  function computeResolutionSeconds(createdAt, updatedAt) {
    if (!createdAt || !updatedAt) return null;
    var c = new Date(createdAt);
    var u = new Date(updatedAt);
    if (isNaN(c.getTime()) || isNaN(u.getTime())) return null;
    var diffMs = u.getTime() - c.getTime();
    if (diffMs <= 0) return 0;
    return Math.round(diffMs / 1000);
  }
  function formatResolutionStringWithSeconds(totalSeconds) {
    if (totalSeconds == null) return null;
    var seconds = Math.max(0, Math.round(totalSeconds));
    var hrs = Math.floor(seconds / 3600);
    var mins = Math.floor((seconds % 3600) / 60);
    var secs = seconds % 60;
    var parts = [];
    if (hrs > 0) parts.push(hrs + ' ' + (hrs === 1 ? 'hr' : 'hrs'));
    if (mins > 0 || hrs > 0) parts.push(mins + ' ' + (mins === 1 ? 'min' : 'mins'));
    parts.push(secs + ' ' + (secs === 1 ? 'sec' : 'secs'));
    return parts.join(' ');
  }
  function getDurationClass(durationStr, minutes) {
    if (typeof minutes === 'number') {
      if (minutes < 30) return 'green';
      if (minutes <= 120) return 'yellow';
      return 'red';
    }
    if (!durationStr) return 'neutral';
    var s = String(durationStr).toLowerCase();
    if (s.includes('in progress') || s.includes('not started')) return 'neutral';
    var hrs = 0, mins = 0;
    var hMatch = s.match(/(\d+)\s*h(?:r|our)s?/);
    var mMatch = s.match(/(\d+)\s*m(?:in)?s?/);
    if (hMatch) hrs = parseInt(hMatch[1], 10) || 0;
    if (mMatch) mins = parseInt(mMatch[1], 10) || 0;
    var total = hrs * 60 + mins;
    if (total === 0) return 'neutral';
    if (total < 30) return 'green';
    if (total <= 120) return 'yellow';
    return 'red';
  }
  function updateStatusColor(select) {
    if (!select) return;
    var status = select.value;
    select.classList.remove('status-open', 'status-progress', 'status-resolved', 'status-closed');
    if (status === 'Open') select.classList.add('status-open');
    else if (status === 'In Progress') select.classList.add('status-progress');
    else if (status === 'Resolved') select.classList.add('status-resolved');
    else if (status === 'Closed') select.classList.add('status-closed');
  }
  function bindNoChangeGuard(container, data) {
    if (!container) return;
    var form = container.querySelector('#ticketUpdateForm');
    if (!form || form.dataset.nochangeBound === '1') return;
    form.dataset.nochangeBound = '1';

    var statusEl = form.querySelector('select[name="status"]');
    var deptEl = form.querySelector('select[name="assigned_department"]');
    var companyEl = form.querySelector('select[name="assigned_company"]');
    var noteEl = form.querySelector('textarea[name="admin_note"]');
    var noticeEl = form.querySelector('#tmNoChangeNotice');

    var initialStatus = statusEl ? String(statusEl.value || '') : String((data && data.status) || '');
    var initialDept = deptEl ? String(deptEl.value || '') : '';
    var initialCompany = companyEl ? String(companyEl.value || '') : '';
    var initialNote = noteEl ? String(noteEl.value || '').trim() : String((data && data.admin_note) || '').trim();

    function hideNotice() {
      if (!noticeEl) return;
      noticeEl.classList.remove('show');
      noticeEl.textContent = '';
    }

    [statusEl, deptEl, companyEl, noteEl].forEach(function (el) {
      if (!el) return;
      el.addEventListener('change', hideNotice);
      el.addEventListener('input', hideNotice);
    });

    form.addEventListener('submit', function (e) {
      var currentStatus = statusEl ? String(statusEl.value || '') : initialStatus;
      var currentDept = deptEl ? String(deptEl.value || '') : initialDept;
      var currentCompany = companyEl ? String(companyEl.value || '') : initialCompany;
      var currentNote = noteEl ? String(noteEl.value || '').trim() : initialNote;
      if (currentStatus === initialStatus && currentDept === initialDept && currentCompany === initialCompany && currentNote === initialNote) {
        e.preventDefault();
        if (noticeEl) {
          noticeEl.textContent = 'No changes were made.';
          noticeEl.classList.add('show');
        }
      }
    });
  }
  function bindAdminNote(container, data) {
    if (!container) return;
    var form = container.querySelector('#ticketUpdateForm');
    if (!form || form.dataset.noteBound === '1') return;
    form.dataset.noteBound = '1';
    var textarea = form.querySelector('#tmAdminNote');
    var tags = form.querySelectorAll('.tm-quick-tag');
    if (tags && tags.length && textarea) {
      tags.forEach(function (btn) {
        btn.addEventListener('click', function () {
          var tag = btn.getAttribute('data-tag') || '';
          if (!tag) return;
          var current = String(textarea.value || '');
          var next = current.trim() === '' ? tag : (current + (current.endsWith('\n') ? '' : '\n') + tag);
          textarea.value = next;
          textarea.focus();
        });
      });
    }
  }
  var standardDeptOptions = [
    { value: 'ACCOUNTING', label: 'Finance and Accounting' },
    { value: 'ADMIN', label: 'Admin & Legal' },
    { value: 'BIDDING', label: 'Bidding' },
    { value: 'E-COMM', label: 'E-Commerce' },
    { value: 'HR', label: 'HR' },
    { value: 'IT', label: 'IT' },
    { value: 'LINGAP', label: 'Diagnostics / Lingap' },
    { value: 'MARKETING', label: 'Marketing' },
    { value: 'SUPPLY CHAIN', label: 'Supply Chain' },
    { value: 'TECHNICAL', label: 'Technical' }
  ];
  var lapcDeptOptions = [
    { value: 'Admin & Legal', label: 'Admin & Legal' },
    { value: 'Banana Farm Operations', label: 'Banana Farm Operations' },
    { value: 'Diagnostics / Lingap', label: 'Diagnostics / Lingap' },
    { value: 'Digital Agri Solutions and Innovations', label: 'Digital Agri Solutions and Innovations' },
    { value: 'E-Commerce', label: 'E-Commerce' },
    { value: 'Executive', label: 'Executive' },
    { value: 'Finance and Accounting', label: 'Finance and Accounting' },
    { value: 'HR', label: 'HR' },
    { value: 'IT', label: 'IT' },
    { value: 'Institutional Sales', label: 'Institutional Sales' },
    { value: 'Management', label: 'Management' },
    { value: 'Marketing', label: 'Marketing' },
    { value: 'New Business Segment', label: 'New Business Segment' },
    { value: 'Seed Production', label: 'Seed Production' },
    { value: 'Supply Chain', label: 'Supply Chain' },
    { value: 'Supply Chain Innovation', label: 'Supply Chain Innovation' },
    { value: 'Technical', label: 'Technical' }
  ];
  function normalizeCompanyValue(value) {
    return value == null ? '' : String(value).trim().toLowerCase();
  }
  function getDeptOptionsForCompany(companyValue) {
    if (typeof window !== 'undefined' && window.TM_FORCE_LAPC_DEPARTMENTS === true) return lapcDeptOptions;
    return normalizeCompanyValue(companyValue) === '@leadsagri.com' ? lapcDeptOptions : standardDeptOptions;
  }
  function preferredDeptValueForCompany(selectedValue, companyValue) {
    var raw = selectedValue == null ? '' : String(selectedValue).trim();
    var isLapcCompany = normalizeCompanyValue(companyValue) === '@leadsagri.com' || (typeof window !== 'undefined' && window.TM_FORCE_LAPC_DEPARTMENTS === true);
    if (!raw) return isLapcCompany ? '' : 'IT';
    var options = getDeptOptionsForCompany(companyValue);
    for (var i = 0; i < options.length; i++) {
      if (String(options[i].value).toLowerCase() === raw.toLowerCase()) return String(options[i].value);
    }
    var deptKey = deptKeyFromValue(raw);
    if (!isLapcCompany) {
      for (var k = 0; k < options.length; k++) {
        if (String(options[k].value).toUpperCase() === deptKey) return String(options[k].value);
      }
      return 'IT';
    }
    var preferredMap = {
      'ACCOUNTING': 'Finance and Accounting',
      'ADMIN': 'Admin & Legal',
      'BIDDING': 'Bidding',
      'E-COMM': 'E-Commerce',
      'HR': 'HR',
      'IT': 'IT',
      'LINGAP': 'Diagnostics / Lingap',
      'MARKETING': 'Marketing',
      'SUPPLY CHAIN': 'Supply Chain',
      'TECHNICAL': 'Technical'
    };
    var preferredValue = preferredMap[deptKey] || '';
    if (preferredValue) {
      for (var j = 0; j < options.length; j++) {
        if (String(options[j].value).toLowerCase() === preferredValue.toLowerCase()) return String(options[j].value);
      }
    }
    return isLapcCompany ? raw : 'IT';
  }
  function buildDeptOptionsHtml(companyValue, selectedValue) {
    var options = getDeptOptionsForCompany(companyValue);
    var forcePlaceholder = typeof window !== 'undefined' && window.TM_FORCE_DEPARTMENT_PLACEHOLDER === true;
    var matchedValue = forcePlaceholder ? '' : preferredDeptValueForCompany(selectedValue, companyValue);
    var hasSelection = matchedValue !== '';
    var hasMatchedOption = false;
    var html = '';
    if (!hasSelection) {
      html += '                  <option value="" disabled selected hidden>Choose department</option>';
    }
    for (var i = 0; i < options.length; i++) {
      if (String(options[i].value) === String(matchedValue)) {
        hasMatchedOption = true;
        break;
      }
    }
    if (hasSelection && !hasMatchedOption) {
      html += '                  <option value="' + escapeHtml(matchedValue) + '" selected>' + escapeHtml(matchedValue) + '</option>';
    }
    html += options.map(function (option) {
      return '                  <option value="' + escapeHtml(option.value) + '" ' + (String(option.value) === String(matchedValue) ? 'selected' : '') + '>' + escapeHtml(option.label) + '</option>';
    }).join('');
    return html;
  }
  function bindDepartmentOptions(container, data) {
    if (!container) return;
    var form = container.querySelector('#ticketUpdateForm');
    if (!form || form.dataset.deptBound === '1') return;
    form.dataset.deptBound = '1';
    var deptEl = form.querySelector('select[name="assigned_department"]');
    var companyEl = form.querySelector('select[name="assigned_company"]');
    if (!deptEl || !companyEl) return;
    function syncDeptOptions(preferredValue) {
      deptEl.innerHTML = buildDeptOptionsHtml(companyEl.value, preferredValue);
    }
    function syncDeptAvailability(preferredValue) {
      var normalizedCompany = normalizeCompanyValue(companyEl.value);
      var isLapcCompany = normalizedCompany === '@leadsagri.com' || (typeof window !== 'undefined' && window.TM_FORCE_LAPC_DEPARTMENTS === true);
      var hiddenMirror = form.querySelector('input[type="hidden"][data-dept-mirror="1"]');
      var selectedValue = preferredDeptValueForCompany(preferredValue, companyEl.value);

      deptEl.value = selectedValue;
      deptEl.disabled = !isLapcCompany;

      if (!isLapcCompany) {
        if (!hiddenMirror) {
          hiddenMirror = document.createElement('input');
          hiddenMirror.type = 'hidden';
          hiddenMirror.name = 'assigned_department';
          hiddenMirror.setAttribute('data-dept-mirror', '1');
          form.appendChild(hiddenMirror);
        }
        hiddenMirror.value = selectedValue;
      } else if (hiddenMirror) {
        hiddenMirror.parentNode.removeChild(hiddenMirror);
      }
    }
    var initialPreferredDept = deptEl.value || (data && (data.assigned_department || data.assigned_group)) || '';
    syncDeptOptions(initialPreferredDept);
    syncDeptAvailability(initialPreferredDept);
    companyEl.addEventListener('change', function () {
      var nextPreferred = deptEl.value || (data && (data.assigned_department || data.assigned_group)) || '';
      syncDeptOptions(nextPreferred);
      syncDeptAvailability(nextPreferred);
    });
    deptEl.addEventListener('change', function () {
      syncDeptAvailability(deptEl.value || '');
    });
  }
  function buildHtml(data) {
    var hideUpdateTab = typeof window !== 'undefined' && window.TM_HIDE_UPDATE_TAB === true;
    var hideAdminChat = typeof window !== 'undefined' && window.TM_HIDE_ADMIN_CHAT === true;
    var hideRequesterAdminChatButton = typeof window !== 'undefined' && window.TM_HIDE_REQUESTOR_ADMIN_CHAT_BUTTON === true;
    var hideQuickTags = typeof window !== 'undefined' && window.TM_HIDE_QUICK_TAGS === true;
    var deptLabelText = (typeof window !== 'undefined' && window.TM_DEPARTMENT_LABEL_TEXT) ? String(window.TM_DEPARTMENT_LABEL_TEXT) : 'Assigned Department';
    var deptRequired = typeof window !== 'undefined' && window.TM_DEPARTMENT_REQUIRED === true;
    var deptLabelHtml = escapeHtml(deptLabelText) + (deptRequired ? ' <span class="tm-required-star">*</span>' : '');
    var statusSlug = data.status ? data.status.toLowerCase().replace(/\s+/g, '') : 'default';
    var prioritySlug = data.priority ? data.priority.toLowerCase() : 'default';
    var resolutionStart = (data && (data.started_at || data.created_at)) ? (data.started_at || data.created_at) : null;
    var resolutionEnd = (data && data.status && (/^(Resolved|Closed)$/i).test(String(data.status)))
      ? (data.resolved_at || data.updated_at || null)
      : null;
    var resMinutesAll = computeResolutionMinutes(resolutionStart, resolutionEnd);
    var resSecondsAll = computeResolutionSeconds(resolutionStart, resolutionEnd);
    var backendStr = data && data.duration ? String(data.duration) : null;
    var displayStr = resolutionEnd
      ? (formatResolutionStringWithSeconds(resSecondsAll) || formatResolutionString(resMinutesAll))
      : backendStr;
    var cls = getDurationClass(displayStr, resMinutesAll);
    var isRunning = !resolutionEnd && !!(data && data.started_at);
    var resBadge = displayStr ? '<span class="tm-duration-badge ' + cls + (isRunning ? ' running' : '') + '">' + escapeHtml(displayStr) + '</span>' : '<span class="tm-duration-badge neutral">-</span>';
    var current = (typeof window !== 'undefined' && window.TM_CURRENT_USER) ? window.TM_CURRENT_USER : null;
    var isRequesterPOV = false;
    if (current && current.id != null && data && data.user_id != null) {
      isRequesterPOV = String(current.id) === String(data.user_id);
    } else if (current && current.email && data && data.created_by_email) {
      isRequesterPOV = String(current.email).toLowerCase() === String(data.created_by_email).toLowerCase();
    }
    var statusControlHtml = '';
    if (isRequesterPOV) {
      statusControlHtml =
        '          <div class="tm-info-value">' +
        '            <span class="tm-chip tm-chip-' + statusSlug + '">' + escapeHtml(data.status) + '</span>' +
        '          </div>';
    } else {
      statusControlHtml =
        '          <div class="tm-select-wrapper">' +
        '            <select class="tm-select tm-status-select" name="status">' +
        '                  <option value="Open" ' + (data.status === 'Open' ? 'selected' : '') + '>Open</option>' +
        '                  <option value="In Progress" ' + (data.status === 'In Progress' ? 'selected' : '') + '>In Progress</option>' +
        '                  <option value="Resolved" ' + (data.status === 'Resolved' ? 'selected' : '') + '>Resolved</option>' +
        '            </select>' +
        '          </div>';
    }
    function deptKeyFromValue(val) {
      var v = (val == null ? '' : String(val)).trim();
      if (!v) return '';
      var u = v.toUpperCase();
      var map = {
        'ACCOUNTING': ['ACCOUNTING', 'FINANCE AND ACCOUNTING', 'FINANCE & ACCOUNTING'],
        'ADMIN': ['ADMIN', 'ADMINISTRATION', 'ADMIN & LEGAL', 'FINANCE AND ADMIN', 'FINANCE & ADMIN'],
        'E-COMM': ['E-COMM', 'E-COMMERCE', 'E COMMERCE', 'ECOMM'],
        'HR': ['HR', 'HUMAN RESOURCE', 'HUMAN RESOURCES', 'HUMAN RESOURCE AND TRANSFORMATION'],
        'IT': ['IT'],
        'LINGAP': ['LINGAP', 'DIAGNOSTICS / LINGAP', 'DIAGNOSTICS/LINGAP'],
        'MARKETING': ['MARKETING', 'SALES AND MARKETING'],
        'SUPPLY CHAIN': ['SUPPLY CHAIN', 'SUPPLY CHAIN INNOVATION', 'LOGISTICS', 'SERVICES & LOGISTICS (LUZON)'],
        'TECHNICAL': ['TECHNICAL']
      };
      var keys = Object.keys(map);
      for (var i = 0; i < keys.length; i++) {
        var k = keys[i];
        if (u === k) return k;
        var aliases = map[k] || [];
        for (var j = 0; j < aliases.length; j++) {
          if (u === String(aliases[j]).toUpperCase()) return k;
        }
      }
      return u;
    }
    var deptOptionsHtml = buildDeptOptionsHtml(data.assigned_company || '', data.assigned_department || data.assigned_group || '');
    var noteValue = data && data.admin_note != null ? String(data.admin_note) : '';
    var trimmedNoteValue = noteValue.trim();
    var requesterAdminNoteHtml = (isRequesterPOV && trimmedNoteValue !== '')
      ? (
        '      <div class="tm-card tm-card-admin-notes"><div class="tm-card-header"><div class="tm-card-header-actions"><span class="tm-card-title">Admin Notes / Comments</span>' + (hideRequesterAdminChatButton ? '' : ('<button type="button" class="tm-inline-chat-btn" onclick="TMTicketModal.openConversation(' + String(data.id) + ')">Chat with Admin</button>')) + '</div></div><div class="tm-card-body">' +
        '        <div class="tm-requestor-note">' + escapeHtml(noteValue).replace(/\n/g, '<br>') + '</div>' +
        '      </div></div>'
      )
      : '';
    var resolutionCardHtml =
      '      <div class="tm-card tm-card-resolution"><div class="tm-card-header"><span class="tm-card-title">Resolution</span></div><div class="tm-card-body">' +
      '        <div class="tm-resolution-row">' +
      '          <div class="tm-res-item"><div class="tm-res-label">Start</div><div class="tm-res-value">' + (resolutionStart ? formatTimelineTime(resolutionStart) : '-') + '</div></div>' +
      '          <div class="tm-res-item"><div class="tm-res-label">End</div><div class="tm-res-value">' + (resolutionEnd ? formatTimelineTime(resolutionEnd) : 'Pending') + '</div></div>' +
      '          <div class="tm-res-item"><div class="tm-res-label">Duration</div><div class="tm-res-value"><span class="tm-duration-dot"></span>' + (displayStr ? escapeHtml(displayStr) : '-') + '</div></div>' +
      '        </div>' +
      '      </div></div>';
    return '' +
      '<div class="tm-header">' +
      '  <div class="tm-header-left">' +
      '    <div class="tm-title">' + escapeHtml(data.subject) + '</div>' +
      '    <div class="tm-chips">' +
      '      <span class="tm-chip tm-chip-' + statusSlug + '">' + escapeHtml(data.status) + '</span>' +
      '      <span class="tm-chip tm-chip-' + prioritySlug + '">' + escapeHtml(data.priority) + '</span>' +
      '      <span class="tm-id">#' + String(data.id).padStart(6, '0') + '</span>' +
      '    </div>' +
      '  </div>' +
      '  <button class="tm-close-btn" onclick="TMTicketModal.close()"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>' +
      '</div>' +
      '<div class="tm-tabs">' +
      '  <div class="tm-tab active" data-tab="info" onclick="TMTicketModal.switchTab(\'info\')">Information</div>' +
      (hideUpdateTab ? '' : '  <div class="tm-tab" data-tab="actions" onclick="TMTicketModal.switchTab(\'actions\')">Update</div>') +
      (hideAdminChat ? '' : '  <div class="tm-tab" data-tab="conversation" onclick="TMTicketModal.openConversation(' + String(data.id) + ')">Go to Chat</div>') +
      '</div>' +
      '<div class="tm-body">' +
      '  <div id="tab-info" class="tm-tab-content active">' +
      '    <div class="tm-info-col">' +
      '      <div class="tm-card tm-card-ticket-info"><div class="tm-card-header"><span class="tm-card-title">Ticket Information</span></div><div class="tm-card-body"><div class="tm-info-grid">' +
      '        <div class="tm-info-label">CREATED BY</div><div class="tm-info-value">' + (data.created_by_name ? escapeHtml(String(data.created_by_name)) : '-') + '</div>' +
      '        <div class="tm-info-label">EMAIL</div><div class="tm-info-value">' + (data.created_by_email ? escapeHtml(String(data.created_by_email)) : '-') + '</div>' +
      '        <div class="tm-info-label">DEPARTMENT</div><div class="tm-info-value">' + (data.department ? escapeHtml(String(data.department)) : '-') + '</div>' +
      '        <div class="tm-info-label">CREATED AT</div><div class="tm-info-value">' + (data.created_at ? formatTimelineTime(data.created_at) : '-') + '</div>' +
      '        <div class="tm-info-label">LAST UPDATED</div><div class="tm-info-value">' + (data.updated_at ? formatTimelineTime(data.updated_at) : '-') + '</div>' +
      '        <div class="tm-info-label">ASSIGNED TO</div><div class="tm-info-value">' + buildAssignedTargetHtml(data) + '</div>' +
      '      </div></div></div>' +
      '      <div class="tm-card tm-card-ticket-activity"><div class="tm-card-header"><span class="tm-card-title">Ticket Activity</span></div><div class="tm-card-body">' + renderTimeline(data) + '</div></div>' +
      '    </div>' +
      '    <div class="tm-desc-col">' +
      requesterAdminNoteHtml +
      '      <div class="tm-card tm-card-description"><div class="tm-card-header"><span class="tm-card-title">Description</span></div><div class="tm-card-body"><div class="tm-desc-text">' + escapeHtml(data.description).replace(/\n/g, '<br>') + '</div>' + renderAttachmentsBlock(data) + '</div></div>' +
      resolutionCardHtml +
      '      ' + ((data.impact && data.impact !== '-') ? '<div class="tm-card tm-card-impact"><div class="tm-card-header"><span class="tm-card-title">Impact</span></div><div class="tm-card-body"><div class="tm-info-value">' + escapeHtml(String(data.impact)) + '</div></div></div>' : '') +
      '      ' + ((data.urgency && data.urgency !== '-') ? '<div class="tm-card tm-card-urgency"><div class="tm-card-header"><span class="tm-card-title">Urgency</span></div><div class="tm-card-body"><div class="tm-info-value">' + escapeHtml(String(data.urgency)) + '</div></div></div>' : '') +
      '    </div>' +
      '  </div>' +
      (hideUpdateTab ? '' : '  <div id="tab-actions" class="tm-tab-content">' +
      '    <div class="tm-card tm-card-ticket-update"><div class="tm-card-header"><span class="tm-card-title">Ticket Update</span></div><div class="tm-card-body">' +
      '    <form id="ticketUpdateForm" method="POST" action="update_ticket.php" class="tm-actions-form">' +
      '      <input type="hidden" name="id" value="' + data.id + '">' +
      '      <input type="hidden" name="csrf_token" value="' + escapeHtml(getCsrfToken()) + '">' +
      '      <div class="tm-nochange" id="tmNoChangeNotice"></div>' +
      '      <div class="tm-actions-fields">' +
      '        <div class="tm-field">' +
      '          <label class="tm-control-label">Status</label>' +
      statusControlHtml +
      '        </div>' +
      '        <div class="tm-field">' +
      '          <label class="tm-control-label tm-control-label-department">' + deptLabelHtml + '</label>' +
      '          <div class="tm-select-wrapper">' +
      '            <select class="tm-select tm-dept-select" name="assigned_department">' +
      deptOptionsHtml +
      '            </select>' +
      '          </div>' +
      '        </div>' +
      '        <div class="tm-field">' +
      '          <label class="tm-control-label">Ticket Recipient</label>' +
      '          <div class="tm-select-wrapper">' +
      '            <select class="tm-select tm-dept-select" name="assigned_company">' +
      ( !data.assigned_company ? '                  <option value="" disabled selected hidden>Select Recipient</option>' : '' ) +
      ( data.assigned_company && ['@gpsci.net','@farmasee.ph','@gmail.com','@leads-eh.com','@leads-farmex.com','@leadsagri.com','@leadsanimalhealth.com','@leadsav.com','@malvedaholdings.com','@malvedaproperties.com','@leadstech-corp.com','@lingapleads.org','@primestocks.ph'].indexOf(String(data.assigned_company).toLowerCase()) === -1
          ? ('                  <option value="' + escapeHtml(data.assigned_company) + '" selected>' + escapeHtml(data.assigned_company) + '</option>')
          : '' ) +
      '                  <option value="@gpsci.net" ' + (String(data.assigned_company || '').toLowerCase() === '@gpsci.net' ? 'selected' : '') + '>GPSCI (@gpsci.net)</option>' +
      '                  <option value="@farmasee.ph" ' + (String(data.assigned_company || '').toLowerCase() === '@farmasee.ph' ? 'selected' : '') + '>FARMASEE (@farmasee.ph)</option>' +
      '                  <option value="@gmail.com" ' + (String(data.assigned_company || '').toLowerCase() === '@gmail.com' ? 'selected' : '') + '>@gmail.com</option>' +
      '                  <option value="@leads-eh.com" ' + (String(data.assigned_company || '').toLowerCase() === '@leads-eh.com' ? 'selected' : '') + '>LEH (@leads-eh.com)</option>' +
      '                  <option value="@leads-farmex.com" ' + (String(data.assigned_company || '').toLowerCase() === '@leads-farmex.com' ? 'selected' : '') + '>FARMEX (@leads-farmex.com)</option>' +
      '                  <option value="@leadsagri.com" ' + (String(data.assigned_company || '').toLowerCase() === '@leadsagri.com' ? 'selected' : '') + '>LAPC (@leadsagri.com)</option>' +
      '                  <option value="@leadsanimalhealth.com" ' + (String(data.assigned_company || '').toLowerCase() === '@leadsanimalhealth.com' ? 'selected' : '') + '>LAH (@leadsanimalhealth.com)</option>' +
      '                  <option value="@leadsav.com" ' + (String(data.assigned_company || '').toLowerCase() === '@leadsav.com' ? 'selected' : '') + '>LAV (@leadsav.com)</option>' +
      '                  <option value="@malvedaholdings.com" ' + (String(data.assigned_company || '').toLowerCase() === '@malvedaholdings.com' ? 'selected' : '') + '>MHC (@malvedaholdings.com)</option>' +
      '                  <option value="@malvedaproperties.com" ' + (String(data.assigned_company || '').toLowerCase() === '@malvedaproperties.com' ? 'selected' : '') + '>MPDC (@malvedaproperties.com)</option>' +
      '                  <option value="@leadstech-corp.com" ' + (String(data.assigned_company || '').toLowerCase() === '@leadstech-corp.com' ? 'selected' : '') + '>LTC (@leadstech-corp.com)</option>' +
      '                  <option value="@lingapleads.org" ' + (String(data.assigned_company || '').toLowerCase() === '@lingapleads.org' ? 'selected' : '') + '>LINGAP (@lingapleads.org)</option>' +
      '                  <option value="@primestocks.ph" ' + (String(data.assigned_company || '').toLowerCase() === '@primestocks.ph' ? 'selected' : '') + '>PCC (@primestocks.ph)</option>' +
      '            </select>' +
      '          </div>' +
      '        </div>' +
      '      </div>' +
      '      <div class="tm-note-group">' +
      '        <div class="tm-note-label">Reason of Concern / Action Taken</div>' +
      '        <textarea class="tm-textarea" name="admin_note" id="tmAdminNote" placeholder="Provide details of the issue or actions performed to resolve it.">' + escapeHtml(noteValue) + '</textarea>' +
      '        <div class="tm-note-footer">' +
      (hideQuickTags ? '' : (
      '          <div class="tm-quick-tags">' +
      '            <button type="button" class="tm-quick-tag" data-tag="Investigation">Investigation</button>' +
      '            <button type="button" class="tm-quick-tag" data-tag="Resolved">Resolved</button>' +
      '            <button type="button" class="tm-quick-tag" data-tag="Escalated">Escalated</button>' +
      '          </div>'
      )) +
      '          <div class="tm-actions-buttons">' +
      '            <button type="button" class="tm-btn tm-btn-secondary" onclick="TMTicketModal.close()">Close</button>' +
      '            <button type="submit" class="tm-btn tm-btn-primary">Save Ticket</button>' +
      '          </div>' +
      '        </div>' +
      '      </div>' +
      '    </form>' +
      '    </div></div>' +
      '  </div>') +
      '</div>';
  }
  function buildFallbackHtml(data) {
    var safe = data && typeof data === 'object' ? data : {};
    var ticketId = safe && safe.id != null ? String(safe.id) : '-';
    var ticketIdLabel = /^\d+$/.test(ticketId) ? String(ticketId).padStart(6, '0') : ticketId;
    var subject = safe && safe.subject ? String(safe.subject) : 'Ticket';
    var status = safe && safe.status ? String(safe.status) : '-';
    var priority = safe && safe.priority ? String(safe.priority) : '-';
    var requester = safe && safe.created_by_name ? String(safe.created_by_name) : '-';
    var requesterEmail = safe && safe.created_by_email ? String(safe.created_by_email) : '-';
    var assignedInfo = getAssignedTargetParts(safe);
    var department = assignedInfo.primary
      ? ((!assignedInfo.showDepartment && assignedInfo.company && assignedInfo.primary === assignedInfo.company)
          ? companyDisplayName(assignedInfo.primary)
          : assignedInfo.primary)
      : '-';
    var company = assignedInfo.showDepartment && assignedInfo.company ? companyDisplayName(assignedInfo.company) : '-';
    var createdAt = safe && safe.created_at ? formatTimelineTime(safe.created_at) : '-';
    var description = safe && safe.description ? String(safe.description) : '-';
    return '' +
      '<div class="tm-header">' +
      '  <div class="tm-header-left">' +
      '    <div class="tm-title">' + escapeHtml(subject) + '</div>' +
      '    <div class="tm-chips">' +
      '      <span class="tm-chip">' + escapeHtml(status) + '</span>' +
      '      <span class="tm-chip">' + escapeHtml(priority) + '</span>' +
      '      <span class="tm-id">#' + escapeHtml(ticketIdLabel) + '</span>' +
      '    </div>' +
      '  </div>' +
      '  <button class="tm-close-btn" onclick="TMTicketModal.close()"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>' +
      '</div>' +
      '<div class="tm-body">' +
      '  <div id="tab-info" class="tm-tab-content active">' +
      '    <div class="tm-info-col">' +
      '      <div class="tm-card tm-card-ticket-info"><div class="tm-card-header"><span class="tm-card-title">Ticket Information</span></div><div class="tm-card-body"><div class="tm-info-grid">' +
      '        <div class="tm-info-label">CREATED BY</div><div class="tm-info-value">' + escapeHtml(requester) + '</div>' +
      '        <div class="tm-info-label">EMAIL</div><div class="tm-info-value">' + escapeHtml(requesterEmail) + '</div>' +
      '        <div class="tm-info-label">ASSIGNED TO</div><div class="tm-info-value">' + escapeHtml(department) + '</div>' +
      '        <div class="tm-info-label">RECIPIENT</div><div class="tm-info-value">' + escapeHtml(company) + '</div>' +
      '        <div class="tm-info-label">CREATED AT</div><div class="tm-info-value">' + escapeHtml(createdAt) + '</div>' +
      '      </div></div></div>' +
      '    </div>' +
      '    <div class="tm-desc-col">' +
      '      <div class="tm-card tm-card-description"><div class="tm-card-header"><span class="tm-card-title">Description</span></div><div class="tm-card-body"><div class="tm-desc-text">' + escapeHtml(description).replace(/\n/g, '<br>') + '</div>' + renderAttachmentsBlock(safe) + '</div></div>' +
      '    </div>' +
      '  </div>' +
      '</div>';
  }
  function startChat(ticketId) {
    stopChat();
    loadMessages(ticketId, true);
    chatInterval = setInterval(function () { loadMessages(ticketId, false); }, 3000);
  }
  function stopChat() {
    if (chatInterval) {
      clearInterval(chatInterval);
      chatInterval = null;
    }
  }
  function loadMessages(ticketId, scrollBottom) {
    var formData = new FormData();
    formData.append('ticket_id', ticketId);
    var t = getCsrfToken();
    if (t) formData.append('csrf_token', t);
    postJson('chat_fetch.php', formData)
      .then(function (data) {
        if (data && data.error) return;
        renderMessages(data || [], scrollBottom);
      })
      .catch(function () { });
  }
  function renderMessages(messages, scrollBottom) {
    var container = qs('chatMessages');
    if (!container) return;
    var isNearBottom = container.scrollHeight - container.scrollTop - container.clientHeight < 100;
    container.innerHTML = '';
    if (!messages || messages.length === 0) {
      container.innerHTML = '<div class="chat-empty">No messages yet.</div>';
      return;
    }
    messages.forEach(function (msg) {
      var bubble = document.createElement('div');
      bubble.classList.add('chat-bubble', (msg.is_me ? 'me' : 'other'));
      var contentDiv = document.createElement('div');
      contentDiv.textContent = msg.message;
      var timeDiv = document.createElement('div');
      timeDiv.classList.add('chat-time');
      timeDiv.textContent = msg.created_at;
      bubble.appendChild(contentDiv);
      bubble.appendChild(timeDiv);
      container.appendChild(bubble);
    });
    if (scrollBottom || isNearBottom) container.scrollTop = container.scrollHeight;
  }
  function sendMessage() {
    var input = qs('chatInput');
    var ticketIdEl = qs('chatTicketId');
    if (!input || !ticketIdEl) return;
    var message = input.value.trim();
    var btn = qs('chatSendBtn');
    if (!message) return;
    if (btn && btn.disabled) return;
    if (btn) {
      btn.disabled = true;
    }
    var formData = new FormData();
    formData.append('ticket_id', ticketIdEl.value);
    formData.append('message', message);
    var t = getCsrfToken();
    if (t) formData.append('csrf_token', t);
    postJson('chat_send.php', formData)
      .then(function (data) {
        if (btn) btn.disabled = false;
        if (data && data.success) {
          input.value = '';
          loadMessages(ticketIdEl.value, true);
        }
      })
      .catch(function () {
        if (btn) btn.disabled = false;
      });
  }
  function switchTab(tabName) {
    document.querySelectorAll('.tm-tab-content').forEach(function (c) { c.classList.remove('active'); });
    document.querySelectorAll('.tm-tab').forEach(function (t) { t.classList.remove('active'); });
    var content = qs('tab-' + tabName);
    var tab = document.querySelector('.tm-tab[data-tab="' + tabName + '"]');
    if (content) content.classList.add('active');
    if (tab) tab.classList.add('active');
    if (tabName === 'chat') { /* no-op: chat now opens in separate modal */ }
  }
  function ensureChatModalExists() {
    if (qs('chatModal')) return;
    var el = document.createElement('div');
    el.id = 'chatModal';
    el.className = 'modal-overlay';
    el.style.display = 'none';
    el.innerHTML = '' +
      '<div class="modal-content chat-modal-content">' +
      '  <div class="modal-header">' +
      '    <div>' +
      '      <div class="modal-title">Ticket Conversation</div>' +
      '      <div id="chatModalMeta" class="chat-modal-meta"></div>' +
      '    </div>' +
      '    <button class="modal-close" onclick="TMTicketModal.closeChatModal()">×</button>' +
      '  </div>' +
      '  <div class="modal-body">' +
      '    <div class="ticket-chat-container">' +
      '      <div id="chatModalMessages" class="chat-messages ticket-chat-messages"></div>' +
      '    </div>' +
      '  </div>' +
      '  <div class="modal-footer">' +
      '    <div id="chatModalNotice" class="chat-empty" style="display:none;"></div>' +
      '    <div id="chatModalComposer" class="ticket-chat-input-wrapper">' +
      '      <input type="hidden" id="chatModalTicketId" value="">' +
      '      <input type="text" id="chatModalInput" class="ticket-chat-input" placeholder="Type a message..." onkeypress="if(event.key===\'Enter\') TMTicketModal.sendChatModalMessage()">' +
      '      <button id="chatModalSendBtn" class="ticket-chat-send" type="button" onclick="TMTicketModal.sendChatModalMessage()"><i class="fas fa-paper-plane"></i></button>' +
      '    </div>' +
      '  </div>' +
      '</div>';
    document.body.appendChild(el);
    window.addEventListener('click', function (e) { var cm = qs('chatModal'); if (cm && e.target === cm) TMTicketModal.closeChatModal(); });
  }
  function getSeenKey(ticketId) {
    return 'tm_chat_seen_' + String(ticketId);
  }
  function getSeenId(ticketId) {
    try {
      var v = localStorage.getItem(getSeenKey(ticketId));
      if (!v) return 0;
      var n = parseInt(v, 10);
      return isNaN(n) ? 0 : n;
    } catch (e) {
      return 0;
    }
  }
  function setSeenId(ticketId, lastId) {
    try {
      localStorage.setItem(getSeenKey(ticketId), String(lastId || 0));
    } catch (e) { }
  }
  function setChatButtonBadge(count) {
    var n = parseInt(String(count || 0), 10) || 0;
    [qs('chatBtnBadge')].forEach(function (b) {
      if (!b) return;
      if (n <= 0) {
        b.classList.remove('is-visible');
        b.textContent = '';
        return;
      }
      b.classList.add('is-visible');
      b.textContent = n > 99 ? '99+' : String(n);
    });
  }
  function updateChatBadgeFromMessages(ticketId, messages) {
    if (!ticketId) return;
    var seenId = getSeenId(ticketId);
    var unseen = 0;
    var lastId = seenId;
    (messages || []).forEach(function (m) {
      var mid = m && m.id != null ? parseInt(String(m.id), 10) : 0;
      if (!isNaN(mid) && mid > lastId) lastId = mid;
      if (m && !m.is_me && mid > seenId) unseen += 1;
    });
    if (chatModalOpen && String(chatBadgeTicketId) === String(ticketId)) {
      if (lastId > seenId) setSeenId(ticketId, lastId);
      setChatButtonBadge(0);
      return;
    }
    setChatButtonBadge(unseen);
  }
  function pollChatBadge(ticketId) {
    if (!ticketId) return;
    var formData = new FormData();
    formData.append('ticket_id', ticketId);
    var t = getCsrfToken();
    if (t) formData.append('csrf_token', t);
    postJson('chat_fetch.php', formData)
      .then(function (data) {
        if (data && data.error) {
          stopChatBadge();
          return;
        }
        updateChatBadgeFromMessages(ticketId, data || []);
      })
      .catch(function () { });
  }
  function startChatBadge(ticketId) {
    stopChatBadge();
    chatBadgeTicketId = ticketId;
    pollChatBadge(ticketId);
    chatBadgeInterval = setInterval(function () { pollChatBadge(ticketId); }, 5000);
  }
  function stopChatBadge() {
    if (chatBadgeInterval) {
      clearInterval(chatBadgeInterval);
      chatBadgeInterval = null;
    }
    chatBadgeTicketId = null;
    setChatButtonBadge(0);
  }
  function toMetaParts(p) {
    var parts = [];
    if (p && p.department) parts.push(String(p.department));
    if (p && p.company) parts.push(String(p.company));
    if (p && p.email) parts.push(String(p.email));
    return parts.filter(function (x) { return x && String(x).trim() !== ''; }).join(' • ');
  }
  function setChatModalMetaHtml(html) {
    var el = qs('chatModalMeta');
    if (el) el.innerHTML = html || '';
  }
  function extractHandlerName(message) {
    var raw = String(message || '');
    var match = raw.match(/(?:handled by|assigned to)\s+(.+?)(?:\.)?$/i);
    return match && match[1] ? match[1].trim() : '';
  }
  function renderLockedChatState(message) {
    var container = qs('chatModalMessages');
    if (!container) return;
    var handlerName = extractHandlerName(message || chatPermissionState.lockedMessage);
    container.innerHTML =
      '<div class="chat-locked-state">' +
      '  <div class="chat-lock-title-row"><span class="chat-locked-icon"><i class="fas fa-lock"></i></span><div class="chat-lock-title">You can\'t message.</div></div>' +
      '  <div class="chat-lock-subtitle">This ticket is already assigned to <strong>' + escapeHtml(handlerName || 'another IT staff') + '</strong>.</div>' +
      '</div>';
  }
  function setChatComposerState(canChat, lockedMessage) {
    var composer = qs('chatModalComposer');
    var input = qs('chatModalInput');
    var btn = qs('chatModalSendBtn');
    var notice = qs('chatModalNotice');
    var allowed = canChat === true;
    var handlerName = extractHandlerName(lockedMessage);
    chatPermissionState.canChat = allowed;
    chatPermissionState.lockedMessage = String(lockedMessage || '');
    chatPermissionState.handlerName = handlerName;
    if (composer) composer.style.display = 'flex';
    if (input) {
      input.disabled = !allowed;
      input.readOnly = !allowed;
      input.tabIndex = allowed ? 0 : -1;
      input.value = allowed ? input.value : '';
      input.placeholder = allowed ? 'Type a message...' : 'You can\'t message. This ticket is already assigned.';
      input.style.cursor = allowed ? 'text' : 'not-allowed';
      input.style.opacity = allowed ? '1' : '0.65';
      input.style.backgroundColor = allowed ? '' : '#f3f4f6';
      input.style.pointerEvents = allowed ? 'auto' : 'none';
    }
    if (btn) {
      btn.disabled = !allowed;
      btn.tabIndex = allowed ? 0 : -1;
      btn.style.cursor = allowed ? 'pointer' : 'not-allowed';
      btn.style.opacity = allowed ? '1' : '0.7';
      btn.style.backgroundColor = allowed ? '' : '#cbd5e1';
      btn.style.pointerEvents = allowed ? 'auto' : 'none';
    }
    if (notice) {
      if (allowed) {
        notice.style.display = 'none';
        notice.innerHTML = '';
      } else {
        notice.style.display = 'none';
        notice.innerHTML = '';
      }
    }
  }
  function loadChatModalMeta(ticketId) {
    setChatComposerState(false, 'Checking ticket handler...');
    setChatModalMetaHtml('<span class="chat-meta-loading">Loading details…</span>');
    fetch('get_ticket_details.php?id=' + ticketId)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || data.error) return;
        lastTicketMeta = {
          id: data && data.id != null ? data.id : ticketId,
          subject: data && data.subject ? String(data.subject) : ''
        };
        setChatComposerState(data && data.can_chat === true, data && data.chat_locked_message ? String(data.chat_locked_message) : '');
        var current = (typeof window !== 'undefined' && window.TM_CURRENT_USER) ? window.TM_CURRENT_USER : null;
        var currentId = current && current.id != null ? String(current.id) : null;
        var isRequesterPOV = false;
        if (currentId && data.user_id != null) {
          isRequesterPOV = String(data.user_id) === String(currentId);
        } else if (current && current.email && data.created_by_email) {
          isRequesterPOV = String(current.email).toLowerCase() === String(data.created_by_email).toLowerCase();
        }

        var requesterName = data.created_by_name ? String(data.created_by_name) : 'Unknown';
        var requesterMeta = [];
        if (data.department) requesterMeta.push(String(data.department));
        if (data.company) requesterMeta.push(String(data.company));
        if (data.created_by_email) requesterMeta.push(String(data.created_by_email));
        var statusLabel = 'Waiting for IT';
        if (data.status === 'In Progress') statusLabel = 'In Progress';
        else if (data.status && data.status !== 'Open') statusLabel = String(data.status);
        chatPermissionState.statusLabel = statusLabel;
        var assigneeHtml = '';
        if (data.assigned_to_name) assigneeHtml = '<span class="chat-assignee-line">Assigned to: <strong>' + escapeHtml(String(data.assigned_to_name)) + '</strong></span>';
        var handlerInfo = getAssignedTargetParts(data);
        var handlerParts = [];
        if (handlerInfo.primary) {
          handlerParts.push((!handlerInfo.showDepartment && handlerInfo.company && handlerInfo.primary === handlerInfo.company)
            ? companyDisplayName(handlerInfo.primary)
            : String(handlerInfo.primary));
        }
        if (handlerInfo.showDepartment && handlerInfo.company) handlerParts.push(companyDisplayName(handlerInfo.company));
        var headerHtml = '<span class="chat-status-pill"><span class="chat-status-dot"></span>' + escapeHtml(statusLabel) + '</span>';
        if (isRequesterPOV) {
          setChatModalMetaHtml(
            headerHtml +
            (assigneeHtml ? ('<span class="chat-meta-dot">•</span>' + assigneeHtml) : '') +
            (handlerParts.length ? ('<span class="chat-meta-dot">•</span><span class="chat-meta-details">' + escapeHtml(handlerParts.join(' • ')) + '</span>') : '')
          );
          return;
        }
        var adminParts = handlerParts.slice();
        var isLockedForViewer = data && data.can_chat !== true && !!data.assigned_to_name;
        if (!adminParts.length && data.status === 'Open') adminParts.push('Waiting for IT');
        setChatModalMetaHtml(
          headerHtml +
          (assigneeHtml ? ('<span class="chat-meta-dot">•</span>' + assigneeHtml) : '') +
          (isLockedForViewer ? '' : (adminParts.length ? ('<span class="chat-meta-dot">•</span><span class="chat-meta-details">' + escapeHtml(adminParts.join(' • ')) + '</span>') : '')) +
          (isLockedForViewer ? '' : (requesterMeta.length ? ('<span class="chat-meta-dot">•</span><span class="chat-meta-details">' + escapeHtml(requesterName + ' • ' + requesterMeta.join(' • ')) + '</span>') : ''))
        );
        return;

        var assignedParts = [];
        if (data.assigned_department) assignedParts.push(String(data.assigned_department));
        if (data.assigned_company) assignedParts.push(String(data.assigned_company));
        if (data.assigned_to_name) assignedParts.push('Handled by: ' + String(data.assigned_to_name));

        if (isRequesterPOV) {
          // Requester POV: show the other party (assigned department/company)
          if (assignedParts.length) {
            var main = assignedParts[0];
            var rest = assignedParts.slice(1);
            setChatModalMetaHtml(
              '<span class="chat-meta-with">Chat with <span class="chat-meta-name">' + escapeHtml(main) + '</span></span>' +
              (rest.length ? ('<span class="chat-meta-dot">•</span><span class="chat-meta-details">' + escapeHtml(rest.join(' • ')) + '</span>') : '')
            );
          } else {
            setChatModalMetaHtml('<span class="chat-meta-with">Chat with <span class="chat-meta-name">Support</span></span>');
          }
        } else {
          // Admin/Assigned POV: show requester and their details, keep assigned context compact
          setChatModalMetaHtml(
            '<span class="chat-meta-with">Chat with <span class="chat-meta-name">' + escapeHtml(requesterName) + '</span></span>' +
            (requesterMeta.length ? ('<span class="chat-meta-dot">•</span><span class="chat-meta-details">' + escapeHtml(requesterMeta.join(' • ')) + '</span>') : '') +
            (assignedParts.length ? ('<span class="chat-meta-dot">•</span><span class="chat-meta-details">Assigned: ' + escapeHtml(assignedParts.join(' • ')) + '</span>') : '')
          );
        }
      })
      .catch(function () { });
  }
  function openChatModal(ticketId) {
    ensureChatModalExists();
    var modal = qs('chatModal');
    var idEl = qs('chatModalTicketId');
    if (!modal || !idEl) return;
    setCurrentTicketId(ticketId);
    idEl.value = String(ticketId);
    modal.style.display = 'flex';
    chatModalOpen = true;
    setChatComposerState(false, 'Checking ticket handler...');
    stopChatBadge();
    stopChat();
    loadChatModalMeta(ticketId);
    loadTicketMessages(ticketId, true);
    chatInterval = setInterval(function () { loadTicketMessages(ticketId, false); }, 3000);
  }
  function closeChatModal() {
    var modal = qs('chatModal');
    if (modal) modal.style.display = 'none';
    chatModalOpen = false;
    stopChat();
    var ticketIdEl = qs('chatModalTicketId');
    var tid = ticketIdEl ? ticketIdEl.value : null;
    var ticketModal = qs('ticketModal');
    if (ticketModal && ticketModal.style.display === 'flex' && tid) {
      startChatBadge(tid);
    }
  }
  function loadTicketMessages(ticketId, scrollBottom) {
    loadChatModalMeta(ticketId);
    var formData = new FormData();
    formData.append('ticket_id', ticketId);
    var t = getCsrfToken();
    if (t) formData.append('csrf_token', t);
    postJson('chat_fetch.php', formData)
      .then(function (data) {
        if (data && data.error) {
          stopChat();
          var errMsg = data && data.error ? String(data.error) : '';
          setChatComposerState(false, errMsg);
          renderLockedChatState(errMsg);
          return;
        }
        var msgs = data || [];
        if (!chatPermissionState.canChat) {
          renderLockedChatState(chatPermissionState.lockedMessage);
          return;
        }
        renderChatModalMessages(msgs, scrollBottom);
        var lastId = 0;
        msgs.forEach(function (m) {
          var mid = m && m.id != null ? parseInt(String(m.id), 10) : 0;
          if (!isNaN(mid) && mid > lastId) lastId = mid;
        });
        if (chatModalOpen && lastId > 0) setSeenId(ticketId, lastId);
      })
      .catch(function () { });
  }
  function renderChatModalMessages(messages, scrollBottom) {
    var container = qs('chatModalMessages');
    if (!container) return;
    var isNearBottom = container.scrollHeight - container.scrollTop - container.clientHeight < 100;
    container.innerHTML = '';
    if (!messages || messages.length === 0) {
      container.innerHTML = '<div class="chat-empty">No messages yet.</div>';
      return;
    }
    messages.forEach(function (msg) {
      var bubble = document.createElement('div');
      bubble.classList.add('chat-bubble', (msg.is_me ? 'me' : 'other'));
      var senderLabel = '';
      if (msg && msg.sender_name && String(msg.sender_name).trim() !== '') {
        senderLabel = String(msg.sender_name);
      } else if (msg && msg.is_me) {
        senderLabel = (window.TM_CURRENT_USER && window.TM_CURRENT_USER.name) ? String(window.TM_CURRENT_USER.name) : 'You';
      }
      if (senderLabel) {
        var sDiv = document.createElement('div');
        sDiv.classList.add('chat-sender');
        sDiv.textContent = senderLabel;
        bubble.appendChild(sDiv);
      }
      var contentDiv = document.createElement('div');
      contentDiv.textContent = msg.message;
      var timeDiv = document.createElement('div');
      timeDiv.classList.add('chat-time');
      timeDiv.textContent = msg.created_at;
      bubble.appendChild(contentDiv);
      bubble.appendChild(timeDiv);
      container.appendChild(bubble);
    });
    if (scrollBottom || isNearBottom) container.scrollTop = container.scrollHeight;
  }
  function sendChatModalMessage() {
    var input = qs('chatModalInput');
    var ticketIdEl = qs('chatModalTicketId');
    if (!input || !ticketIdEl) return;
    if (input.disabled || input.readOnly) return false;
    var message = input.value.trim();
    var btn = qs('chatModalSendBtn');
    if (!message) return;
    if (btn && btn.disabled) return;
    var ticketId = String(ticketIdEl.value || '');
    var senderName = (window.TM_CURRENT_USER && window.TM_CURRENT_USER.name) ? String(window.TM_CURRENT_USER.name) : 'You';
    input.value = '';
    var bubble = null;
    var container = qs('chatModalMessages');
    if (container) {
      if (container.querySelector('.chat-empty')) container.innerHTML = '';
      bubble = document.createElement('div');
      bubble.classList.add('chat-bubble', 'me');
      var sDiv = document.createElement('div');
      sDiv.classList.add('chat-sender');
      sDiv.textContent = senderName;
      var contentDiv = document.createElement('div');
      contentDiv.textContent = message;
      var timeDiv = document.createElement('div');
      timeDiv.classList.add('chat-time');
      timeDiv.textContent = formatHHMM(new Date());
      bubble.appendChild(sDiv);
      bubble.appendChild(contentDiv);
      bubble.appendChild(timeDiv);
      container.appendChild(bubble);
      container.scrollTop = container.scrollHeight;
    }
    updateConversationPreview(ticketId, message, senderName);
    if (btn) btn.disabled = true;
    var formData = new FormData();
    formData.append('ticket_id', ticketId);
    formData.append('message', message);
    var t = getCsrfToken();
    if (t) formData.append('csrf_token', t);
    postJson('chat_send.php', formData)
      .then(function (data) {
        if (btn) btn.disabled = false;
        if (data && (data.error || data.success === false)) {
          var errMsg = String((data && (data.message || data.error)) || 'You are not allowed to send messages');
          setChatComposerState(false, errMsg);
          renderLockedChatState(errMsg);
          loadChatModalMeta(ticketId);
          return;
        }
        if (data && data.success) {
          setTimeout(function () { loadTicketMessages(ticketId, true); }, 0);
        }
      })
      .catch(function () {
        if (btn) btn.disabled = false;
        loadTicketMessages(ticketId, false);
      });
  }
  function stopMessenger() {
    if (messengerInterval) {
      clearInterval(messengerInterval);
      messengerInterval = null;
    }
  }
  function ensureMessengerConfirmExists() {
    if (qs('tmMessengerConfirm')) return;
    var dialog = document.createElement('div');
    dialog.id = 'tmMessengerConfirm';
    dialog.className = 'tm-messenger-confirm-overlay';
    dialog.innerHTML =
      '<div class="tm-messenger-confirm-box" role="dialog" aria-modal="true" aria-labelledby="tmMessengerConfirmTitle">' +
      '  <div class="tm-messenger-confirm-icon">!</div>' +
      '  <div class="tm-messenger-confirm-title" id="tmMessengerConfirmTitle">Confirm Action</div>' +
      '  <div class="tm-messenger-confirm-text" id="tmMessengerConfirmText"></div>' +
      '  <div class="tm-messenger-confirm-actions">' +
      '    <button type="button" class="tm-messenger-confirm-btn tm-messenger-confirm-cancel" id="tmMessengerConfirmCancel">Cancel</button>' +
      '    <button type="button" class="tm-messenger-confirm-btn tm-messenger-confirm-ok" id="tmMessengerConfirmOk">OK</button>' +
      '  </div>' +
      '</div>';
    document.body.appendChild(dialog);
    dialog.addEventListener('click', function (e) {
      if (e.target === dialog) hideMessengerConfirm();
    });
    var cancelBtn = qs('tmMessengerConfirmCancel');
    var okBtn = qs('tmMessengerConfirmOk');
    if (cancelBtn) cancelBtn.addEventListener('click', hideMessengerConfirm);
    if (okBtn) {
      okBtn.addEventListener('click', function () {
        var action = messengerConfirmAction;
        hideMessengerConfirm();
        if (typeof action === 'function') action();
      });
    }
  }
  function hideMessengerConfirm() {
    var dialog = qs('tmMessengerConfirm');
    if (dialog) dialog.style.display = 'none';
    messengerConfirmAction = null;
  }
  function hideMessengerMenu() {
    var menu = qs('tmMessengerMenu');
    var btn = qs('tmMessengerMenuBtn');
    if (menu) menu.classList.remove('show');
    if (btn) btn.setAttribute('aria-expanded', 'false');
  }
  function toggleMessengerMenu() {
    var menu = qs('tmMessengerMenu');
    var btn = qs('tmMessengerMenuBtn');
    if (!menu || !btn || btn.disabled) return;
    var willShow = !menu.classList.contains('show');
    hideMessengerMenu();
    if (willShow) {
      menu.classList.add('show');
      btn.setAttribute('aria-expanded', 'true');
    }
  }
  function showMessengerConfirm(options) {
    ensureMessengerConfirmExists();
    var dialog = qs('tmMessengerConfirm');
    var titleEl = qs('tmMessengerConfirmTitle');
    var textEl = qs('tmMessengerConfirmText');
    var cancelBtn = qs('tmMessengerConfirmCancel');
    var okBtn = qs('tmMessengerConfirmOk');
    if (!dialog || !titleEl || !textEl || !okBtn || !cancelBtn) return;

    var opts = options || {};
    titleEl.textContent = opts.title || 'Confirm Action';
    textEl.textContent = opts.message || '';
    okBtn.textContent = opts.confirmText || 'OK';
    cancelBtn.textContent = opts.cancelText || 'Cancel';
    cancelBtn.style.display = opts.hideCancel ? 'none' : 'inline-flex';
    okBtn.classList.toggle('danger', !!opts.danger);
    messengerConfirmAction = (typeof opts.onConfirm === 'function') ? opts.onConfirm : null;
    dialog.style.display = 'flex';
  }
  function ensureMessengerModalExists() {
    if (qs('tmMessengerModal')) return;

    if (!document.getElementById('tmMessengerStyles')) {
      var style = document.createElement('style');
      style.id = 'tmMessengerStyles';
      style.textContent =
        '.tm-messenger-overlay{position:fixed;inset:0;background:rgba(15,23,42,.45);display:none;align-items:center;justify-content:center;z-index:9999;padding:18px;}' +
        '.tm-messenger-confirm-overlay{position:fixed;inset:0;background:rgba(15,23,42,.52);display:none;align-items:center;justify-content:center;z-index:10001;padding:20px;}' +
        '.tm-messenger-confirm-box{width:min(420px,92vw);background:#fff;border-radius:20px;box-shadow:0 28px 70px rgba(2,6,23,.28);padding:28px 24px 22px;border:1px solid rgba(226,232,240,.95);display:flex;flex-direction:column;align-items:center;text-align:center;gap:14px;}' +
        '.tm-messenger-confirm-icon{width:84px;height:84px;border-radius:999px;border:4px solid #fdba74;color:#f97316;display:flex;align-items:center;justify-content:center;font-size:48px;font-weight:800;line-height:1;}' +
        '.tm-messenger-confirm-title{font-size:18px;font-weight:900;color:#334155;}' +
        '.tm-messenger-confirm-text{font-size:14px;line-height:1.6;color:#64748b;max-width:320px;}' +
        '.tm-messenger-confirm-actions{display:flex;align-items:center;justify-content:center;gap:12px;margin-top:4px;flex-wrap:wrap;}' +
        '.tm-messenger-confirm-btn{border:none;border-radius:12px;padding:11px 18px;font-size:14px;font-weight:800;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;min-width:110px;}' +
        '.tm-messenger-confirm-cancel{background:#f1f5f9;color:#334155;border:1px solid #e2e8f0;}' +
        '.tm-messenger-confirm-cancel:hover{background:#e2e8f0;}' +
        '.tm-messenger-confirm-ok{background:#166534;color:#fff;}' +
        '.tm-messenger-confirm-ok:hover{background:#14532d;}' +
        '.tm-messenger-confirm-ok.danger{background:#dc2626;}' +
        '.tm-messenger-confirm-ok.danger:hover{background:#b91c1c;}' +
        '.tm-messenger-panel{width:min(1100px,96vw);height:min(78vh,720px);background:#fff;border-radius:16px;box-shadow:0 30px 80px rgba(2,6,23,.25);overflow:hidden;display:flex;border:1px solid rgba(226,232,240,.9);}' +
        '.tm-messenger-left{width:300px;min-width:300px;max-width:300px;border-right:1px solid #e5e7eb;display:flex;flex-direction:column;background:#fbfbfc;}' +
        '.tm-messenger-left-header{padding:14px 14px 10px;display:flex;align-items:center;justify-content:flex-start;gap:10px;border-bottom:1px solid #eef2f7;}' +
        '.tm-messenger-left-title{font-size:14px;font-weight:800;color:#0f172a;}' +
        '.tm-messenger-search{padding:0 14px 12px;border-bottom:1px solid #eef2f7;}' +
        '.tm-messenger-search input{width:100%;border:1px solid #e5e7eb;border-radius:10px;padding:10px 12px;font-size:13px;outline:none;background:#fff;}' +
        '.tm-messenger-search input:focus{border-color:#22c55e;box-shadow:0 0 0 4px rgba(34,197,94,.12);}' +
        '.tm-messenger-list{flex:1;overflow:auto;padding:8px;display:flex;flex-direction:column;gap:6px;}' +
        '.tm-messenger-item{width:100%;border:1px solid #e5e7eb;border-radius:12px;background:#fff;padding:10px 10px;text-align:left;cursor:pointer;display:flex;flex-direction:column;gap:6px;transition:transform .12s,box-shadow .12s,border-color .12s;}' +
        '.tm-messenger-item:hover{transform:translateY(-1px);box-shadow:0 10px 22px rgba(2,6,23,.08);border-color:#bbf7d0;}' +
        '.tm-messenger-item.active{border-color:#22c55e;box-shadow:0 10px 22px rgba(34,197,94,.12);}' +
        '.tm-messenger-item.unread-chat{background:#e8f8ee;border-left:4px solid #22c55e;}' +
        '.tm-messenger-item.unread-chat .tm-messenger-item-subject{font-weight:900;}' +
        '.tm-messenger-item-top{display:flex;align-items:center;justify-content:space-between;gap:10px;}' +
        '.tm-messenger-item-subject{font-size:13px;font-weight:800;color:#0f172a;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}' +
        '.tm-messenger-item-right{display:flex;align-items:center;gap:8px;flex:0 0 auto;}' +
        '.tm-messenger-item-time{font-size:11px;font-weight:700;color:#64748b;flex:0 0 auto;}' +
        '.unread-badge{background:#22c55e;color:#ffffff;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:900;line-height:1;display:inline-flex;align-items:center;justify-content:center;min-width:20px;}' +
        '.tm-messenger-item-preview{font-size:12px;color:#475569;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}' +
        '.tm-messenger-right{flex:1;min-width:0;display:flex;flex-direction:column;background:#fff;}' +
        '.tm-messenger-right-header{padding:14px 16px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between;gap:12px;}' +
        '.tm-messenger-right-title{display:flex;flex-direction:column;gap:3px;min-width:0;}' +
        '.tm-messenger-title-main{font-size:14px;font-weight:900;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}' +
        '.tm-messenger-title-sub{font-size:12px;font-weight:700;color:#64748b;}' +
        '.tm-messenger-header-actions{display:flex;align-items:center;gap:8px;flex:0 0 auto;}' +
        '.tm-messenger-menu-wrap{position:relative;display:flex;align-items:center;}' +
        '.tm-messenger-menu-btn{border:1px solid #e2e8f0;background:#ffffff;color:#334155;border-radius:12px;width:42px;height:42px;cursor:pointer;font-weight:900;display:inline-flex;align-items:center;justify-content:center;font-size:22px;line-height:1;}' +
        '.tm-messenger-menu-btn:hover{background:#f8fafc;border-color:#cbd5e1;}' +
        '.tm-messenger-menu-btn:disabled{opacity:.55;cursor:not-allowed;}' +
        '.tm-messenger-menu{position:absolute;top:calc(100% + 8px);right:0;min-width:170px;background:#fff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 18px 40px rgba(2,6,23,.16);padding:8px;display:none;flex-direction:column;gap:6px;z-index:2;}' +
        '.tm-messenger-menu.show{display:flex;}' +
        '.tm-messenger-menu-item{width:100%;border:none;background:#fff;color:#334155;border-radius:10px;padding:10px 12px;cursor:pointer;font-size:13px;font-weight:800;display:flex;align-items:center;justify-content:flex-start;text-align:left;}' +
        '.tm-messenger-menu-item:hover{background:#f8fafc;}' +
        '.tm-messenger-menu-item.danger{color:#dc2626;}' +
        '.tm-messenger-menu-item.danger:hover{background:#fef2f2;}' +
        '.tm-messenger-close{border:none;background:#f1f5f9;color:#0f172a;border-radius:10px;padding:8px 10px;cursor:pointer;font-weight:900;display:inline-flex;align-items:center;justify-content:center;}' +
        '.tm-messenger-close:hover{background:#e2e8f0;}' +
        '.tm-messenger-messages{flex:1;overflow:auto;padding:16px;background:#f9fafb;display:flex;flex-direction:column;gap:12px;}' +
        '.tm-messenger-empty{color:#94a3b8;font-weight:700;text-align:center;margin-top:26px;}' +
        '.tm-messenger-compose{border-top:1px solid #e5e7eb;padding:12px;background:#fff;display:flex;gap:10px;align-items:center;}' +
        '.tm-messenger-compose input{flex:1;border:1px solid #e5e7eb;border-radius:12px;padding:12px 14px;font-size:14px;outline:none;background:#fff;}' +
        '.tm-messenger-compose input:focus{border-color:#22c55e;box-shadow:0 0 0 4px rgba(34,197,94,.12);}' +
        '.tm-messenger-compose input:disabled{background:#f8fafc;border-color:#dbe3ec;color:#64748b;box-shadow:none;cursor:not-allowed;opacity:.8;}' +
        '.tm-messenger-send{border:none;background:#1B5E20;color:#fff;border-radius:12px;padding:12px 14px;font-weight:900;cursor:pointer;min-width:86px;}' +
        '.tm-messenger-send:disabled{opacity:.6;cursor:not-allowed;}' +
        '.tm-messenger-overlay .chat-bubble{max-width:80%;padding:10px 14px;border-radius:16px;font-size:14px;line-height:1.5;word-wrap:break-word;display:flex;flex-direction:column;gap:4px;box-shadow:0 1px 2px rgba(0,0,0,.04);}' +
        '.tm-messenger-overlay .chat-bubble.me{align-self:flex-end;background:#1B5E20;color:#fff;border-bottom-right-radius:4px;}' +
        '.tm-messenger-overlay .chat-bubble.other{align-self:flex-start;background:#f1f5f9;color:#0f172a;border-bottom-left-radius:4px;}' +
        '.tm-messenger-overlay .chat-sender{font-size:12px;font-weight:800;opacity:.9;}' +
        '.tm-messenger-overlay .chat-bubble.me .chat-sender{color:#fff;}' +
        '.tm-messenger-overlay .chat-bubble.other .chat-sender{color:#475569;}' +
        '.tm-messenger-overlay .chat-time{font-size:11px;font-weight:700;opacity:.75;margin-top:2px;align-self:flex-end;}' +
        '@media (max-width: 820px){.tm-messenger-panel{width:96vw;height:86vh}.tm-messenger-left{width:260px;min-width:260px;max-width:260px}}' +
        '@media (max-width: 768px){' +
          '.tm-messenger-overlay{align-items:flex-end;justify-content:center;padding:0;}' +
          '.tm-messenger-panel{width:100vw;height:85vh;max-height:90vh;border-radius:18px 18px 0 0;border-bottom-left-radius:0;border-bottom-right-radius:0;box-shadow:0 -10px 30px rgba(0,0,0,.2);flex-direction:column;}' +
          '.tm-messenger-left{width:100%;min-width:0;max-width:none;height:38%;border-right:none;border-bottom:1px solid #e5e7eb;}' +
          '.tm-messenger-left-header{padding:12px 16px;position:sticky;top:0;background:#fff;z-index:2;}' +
          '.tm-messenger-search{padding:0 16px 10px;border-bottom:1px solid #eef2f7;position:sticky;top:49px;background:#fbfbfc;z-index:2;}' +
          '.tm-messenger-search input{font-size:14px;padding:10px 12px;border-radius:12px;}' +
          '.tm-messenger-list{flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch;padding:10px;}' +
          '.tm-messenger-item{padding:12px;border-radius:14px;}' +
          '.tm-messenger-right{height:62%;min-height:0;}' +
          '.tm-messenger-right-header{padding:12px 16px;font-size:16px;font-weight:600;position:sticky;top:0;background:#fff;z-index:2;}' +
          '.tm-messenger-title-main{font-size:15px;}' +
          '.tm-messenger-title-sub{font-size:12px;}' +
          '.tm-messenger-messages{flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch;padding:12px;gap:10px;}' +
          '.tm-messenger-overlay .chat-bubble{max-width:88%;}' +
          '.tm-messenger-confirm-box{padding:24px 18px 18px;border-radius:18px;}' +
          '.tm-messenger-confirm-icon{width:72px;height:72px;font-size:40px;}' +
          '.tm-messenger-confirm-actions{width:100%;}' +
          '.tm-messenger-confirm-btn{flex:1 1 140px;min-height:44px;}' +
          '.tm-messenger-compose{display:flex;gap:10px;padding:12px;border-top:1px solid #eee;background:#fff;position:sticky;bottom:0;}' +
          '.tm-messenger-compose input{flex:1;border-radius:12px;padding:10px 12px;border:1px solid #ddd;min-height:44px;}' +
          '.tm-messenger-send{padding:10px 16px;border-radius:10px;min-width:72px;min-height:44px;}' +
          '.tm-messenger-header-actions{width:auto;}' +
          '.tm-messenger-menu-btn{min-width:40px;min-height:40px;border-radius:10px;}' +
          '.tm-messenger-menu{right:0;min-width:160px;}' +
          '.tm-messenger-close{min-width:40px;min-height:40px;border-radius:10px;padding:8px;}' +
        '}';
      document.head.appendChild(style);
    }

    if (typeof window !== 'undefined' && window.TM_MESSENGER_STYLE === 'employee' && !document.getElementById('tmMessengerEmployeeStyles')) {
      var employeeStyle = document.createElement('style');
      employeeStyle.id = 'tmMessengerEmployeeStyles';
      employeeStyle.textContent =
        '.tm-messenger-overlay.employee-style{background:rgba(15,23,42,.32);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);padding:16px;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-panel{position:relative;width:min(86vw,980px);height:min(86vh,760px);border-radius:22px;overflow:hidden;background:#ffffff;border:1px solid rgba(220,235,226,.95);box-shadow:0 26px 60px rgba(15,23,42,.16),0 12px 26px rgba(15,23,42,.08);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-panel::before{content:none;display:none;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-left{width:320px;min-width:320px;max-width:320px;background:linear-gradient(180deg,#fcfcfd 0%,#f8fafc 100%);border-right:1px solid #e2e8f0;padding-top:0;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-left-header{padding:24px 18px 12px;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-left-title{font-size:17px;font-weight:900;color:#0f172a;letter-spacing:-.03em;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-search{padding:0 14px 10px;position:relative;background:transparent;border-bottom:none;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-search::before{content:\"\";position:absolute;left:28px;top:50%;width:16px;height:16px;transform:translateY(-50%);background:no-repeat center/contain url(\"data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2718%27 height=%2718%27 fill=%27none%27 viewBox=%270 0 24 24%27%3E%3Cpath stroke=%2794a3b8%27 stroke-linecap=%27round%27 stroke-linejoin=%27round%27 stroke-width=%272%27 d=%27m21 21-4.35-4.35M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z%27/%3E%3C/svg%3E\");pointer-events:none;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-search input{border-radius:14px;border:1px solid #dbe3ec;background:#fff;padding:12px 14px 12px 42px;font-size:14px;color:#0f172a;box-shadow:0 4px 12px rgba(15,23,42,.04);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-search input:focus{border-color:#86efac;box-shadow:0 0 0 4px rgba(34,197,94,.12);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-filters{display:flex;gap:8px;flex-wrap:nowrap;white-space:nowrap;padding:0 14px 12px;overflow:hidden;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-filter-btn{flex:0 0 auto;border:1px solid #e2e8f0;background:#fff;color:#475569;border-radius:14px;padding:10px 14px;font-size:13px;font-weight:800;line-height:1;cursor:pointer;box-shadow:0 4px 12px rgba(15,23,42,.04);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-filter-btn:hover{background:#f8fafc;border-color:#cbd5e1;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-filter-btn.active{background:#f8fafc;color:#0f172a;border-color:#d7e3da;box-shadow:inset 0 0 0 1px rgba(34,197,94,.08);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-list{padding:6px 12px 14px;gap:8px;background:transparent;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-item{padding:13px 13px 12px;border-radius:16px;border:1px solid #e7edf3;background:#fff;box-shadow:0 6px 14px rgba(15,23,42,.04);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-item:hover{transform:none;border-color:#d9e2ec;background:#fbfdff;box-shadow:0 10px 18px rgba(15,23,42,.06);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-item.active{border-color:#d9efe0;background:linear-gradient(180deg,#fcfefd 0%,#f4fbf6 100%);box-shadow:inset 4px 0 0 #22a55a,0 10px 22px rgba(34,197,94,.10);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-item.active .tm-messenger-item-subject{color:#0f172a;font-weight:700;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-item.active .tm-messenger-item-preview{color:#475569;font-weight:500;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-item.unread-chat{border-color:#86efac;background:linear-gradient(180deg,#fbfffc 0%,#ecfdf3 100%);box-shadow:inset 5px 0 0 #16a34a,0 12px 24px rgba(22,163,74,.16);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-item.unread-chat.active{border-color:#6ee7b7;background:linear-gradient(180deg,#f9fffb 0%,#e8fbef 100%);box-shadow:inset 5px 0 0 #16a34a,0 12px 24px rgba(22,163,74,.18);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-item.unread-chat .tm-messenger-item-subject{color:#052e16;font-weight:900;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-item.unread-chat .tm-messenger-item-preview{color:#14532d;font-weight:800;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-item.unread-chat .tm-messenger-item-time{color:#14532d;font-weight:900;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-item-subject{font-size:15px;font-weight:700;color:#0f172a;letter-spacing:-.02em;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-item-preview{margin-top:7px;font-size:13px;color:#64748b;line-height:1.42;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-item-time{font-size:12px;font-weight:800;color:#475569;}' +
        '.tm-messenger-overlay.employee-style .unread-badge{width:10px;height:10px;min-width:10px;border-radius:999px;background:#16a34a;color:transparent;font-size:0;display:inline-flex;align-items:center;justify-content:center;padding:0;box-shadow:0 0 0 3px rgba(22,163,74,.14);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-right{background:linear-gradient(180deg,#ffffff 0%,#fbfcfd 100%);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-right-header{padding:26px 22px 16px;border-bottom:1px solid #e5e7eb;background:#fff;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-title-main{font-size:20px;font-weight:700;color:#0f172a;letter-spacing:-.02em;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-title-sub{margin-top:8px;font-size:13px;color:#475569;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-status-pill{display:inline-flex;align-items:center;gap:7px;padding:6px 12px;border-radius:999px;background:#f6fbf7;border:1px solid #d9efe0;color:#0f172a;font-size:13px;font-weight:800;line-height:1;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-status-pill::before{content:\"\";width:10px;height:10px;border-radius:999px;background:#22a55a;display:inline-block;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-status-pill.status-in-progress::before{background:#16a34a;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-status-pill.status-closed::before{background:#94a3b8;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-assignee{font-size:15px;color:#475569;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-assignee strong{color:#0f172a;font-weight:800;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-sub-sep{color:#cbd5e1;font-weight:800;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-menu-btn,.tm-messenger-overlay.employee-style .tm-messenger-close{width:48px;height:48px;border-radius:15px;border:1px solid #dbe3ec;background:#fff;box-shadow:0 8px 18px rgba(15,23,42,.08);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-close{display:inline-flex;font-size:28px;line-height:1;color:#dc2626;border-color:#fecaca;background:#fff5f5;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-close:hover{background:#fee2e2;border-color:#fca5a5;color:#b91c1c;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-menu{top:calc(100% + 10px);min-width:208px;border-radius:18px;padding:10px;box-shadow:0 20px 46px rgba(15,23,42,.16);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-menu-item{padding:12px 14px;font-size:14px;font-weight:800;border-radius:12px;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-messages{padding:20px 22px 16px;background:linear-gradient(180deg,#ffffff 0%,#fbfbfd 100%);gap:14px;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble{max-width:min(66%,420px);padding:13px 15px;border-radius:20px;border:1px solid #e6edf3;box-shadow:0 10px 24px rgba(15,23,42,.06);gap:6px;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.other{background:#ffffff;color:#0f172a;border-bottom-left-radius:10px;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.me{background:#174d1b;color:#fff;border-color:#174d1b;border-bottom-right-radius:10px;box-shadow:0 12px 28px rgba(23,77,27,.22);}' +
        '.tm-messenger-overlay.employee-style .chat-sender{font-size:14px;font-weight:900;line-height:1.2;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.me .chat-sender{color:#ffffff;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.other .chat-sender{color:#0f172a;}' +
        '.tm-messenger-overlay.employee-style .chat-time{font-size:12px;font-weight:700;opacity:.72;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-locked-state{min-height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:16px;padding:32px 20px;color:#475569;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-lock-title-row{display:inline-flex;align-items:center;gap:12px;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-locked-icon{width:34px;height:34px;border-radius:10px;background:#f3f4f6;color:#4b5563;display:inline-flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-lock-title{font-size:16px;font-weight:500;color:#4b5563;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-lock-subtitle{font-size:14px;color:#475569;max-width:520px;line-height:1.55;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-lock-subtitle strong{color:#0f172a;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-compose{padding:14px 14px 16px;border-top:1px solid #e5e7eb;background:#fff;gap:12px;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-compose input{border-radius:16px;border:1.5px solid #86efac;padding:13px 16px;font-size:14px;min-height:50px;box-shadow:0 0 0 4px rgba(34,197,94,.08);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-compose input:focus{border-color:#22c55e;box-shadow:0 0 0 5px rgba(34,197,94,.12);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-compose input:disabled{border-color:#dbe3ec;background:#f8fafc;color:#64748b;box-shadow:none;cursor:not-allowed;opacity:.8;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-send{min-width:96px;min-height:50px;padding:0 20px;border-radius:16px;background:linear-gradient(180deg,#1f5f23 0%,#174d1b 100%);font-size:14px;letter-spacing:.01em;box-shadow:0 14px 26px rgba(23,77,27,.22);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-send:hover{background:linear-gradient(180deg,#205d24 0%,#154819 100%);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-send:disabled{background:#cbd5e1;color:#fff;box-shadow:none;cursor:not-allowed;opacity:1;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-empty{font-size:15px;color:#94a3b8;}' +
        '@media (max-width: 980px){.tm-messenger-overlay.employee-style{padding:10px}.tm-messenger-overlay.employee-style .tm-messenger-panel{width:min(94vw,940px);height:min(84vh,720px);border-radius:20px}.tm-messenger-overlay.employee-style .tm-messenger-left{width:300px;min-width:300px;max-width:300px}.tm-messenger-overlay.employee-style .tm-messenger-title-main{font-size:18px;font-weight:700}.tm-messenger-overlay.employee-style .chat-bubble{max-width:80%;}}' +
        '@media (max-width: 768px){.tm-messenger-overlay.employee-style{padding:0;align-items:flex-end}.tm-messenger-overlay.employee-style .tm-messenger-panel{height:88vh;border-radius:22px 22px 0 0}.tm-messenger-overlay.employee-style .tm-messenger-panel::before{content:none;display:none}.tm-messenger-overlay.employee-style .tm-messenger-left{width:100%;min-width:0;max-width:none;height:40%}.tm-messenger-overlay.employee-style .tm-messenger-right{height:60%}.tm-messenger-overlay.employee-style .tm-messenger-left-header{padding:20px 16px 10px}.tm-messenger-overlay.employee-style .tm-messenger-right-header{padding:20px 16px 12px}.tm-messenger-overlay.employee-style .tm-messenger-title-main{font-size:17px}.tm-messenger-overlay.employee-style .tm-messenger-search{padding:0 12px 10px}.tm-messenger-overlay.employee-style .tm-messenger-search::before{left:25px}.tm-messenger-overlay.employee-style .tm-messenger-filters{padding:0 12px 12px;gap:6px;overflow-x:auto;}.tm-messenger-overlay.employee-style .tm-messenger-filter-btn{padding:9px 12px;font-size:12px;border-radius:12px}.tm-messenger-overlay.employee-style .tm-messenger-messages{padding:16px 14px}.tm-messenger-overlay.employee-style .tm-messenger-compose{padding:12px 12px 14px}.tm-messenger-overlay.employee-style .tm-messenger-send{min-width:86px;min-height:48px;border-radius:15px;}}';
      document.head.appendChild(employeeStyle);
    }

    var overlay = document.createElement('div');
    overlay.id = 'tmMessengerModal';
    overlay.className = 'tm-messenger-overlay' + ((typeof window !== 'undefined' && window.TM_MESSENGER_STYLE === 'employee') ? ' employee-style' : '');
    overlay.innerHTML =
      '<div class="tm-messenger-panel" role="dialog" aria-modal="true" aria-label="Ticket Conversations">' +
      '  <div class="tm-messenger-left">' +
      '    <div class="tm-messenger-left-header">' +
      '      <div class="tm-messenger-left-title">Conversations</div>' +
      '    </div>' +
      '    <div class="tm-messenger-search"><input type="text" id="tmMessengerSearch" placeholder="Search tickets..."></div>' +
      ((typeof window !== 'undefined' && window.TM_MESSENGER_STYLE === 'employee')
        ? ('    <div class="tm-messenger-filters" id="tmMessengerFilters">' +
           '      <button type="button" class="tm-messenger-filter-btn active" data-filter="all" id="tmMessengerFilterAll">All (0)</button>' +
           '      <button type="button" class="tm-messenger-filter-btn" data-filter="open" id="tmMessengerFilterOpen">Open (0)</button>' +
           '      <button type="button" class="tm-messenger-filter-btn" data-filter="in_progress" id="tmMessengerFilterInProgress">In Progress (0)</button>' +
           '    </div>')
        : '') +
      '    <div class="tm-messenger-list" id="tmMessengerList"><div class="tm-messenger-empty">Loading...</div></div>' +
      '  </div>' +
      '  <div class="tm-messenger-right">' +
      '    <div class="tm-messenger-right-header">' +
      '      <div class="tm-messenger-right-title">' +
      '        <div class="tm-messenger-title-main" id="tmMessengerHeaderTitle">Select a conversation</div>' +
      '        <div class="tm-messenger-title-sub" id="tmMessengerHeaderSub"> </div>' +
      '      </div>' +
      '      <div class="tm-messenger-header-actions">' +
      '        <div class="tm-messenger-menu-wrap">' +
      '          <button type="button" class="tm-messenger-menu-btn" id="tmMessengerMenuBtn" aria-label="Chat options" aria-expanded="false" disabled>&#8942;</button>' +
      '          <div class="tm-messenger-menu" id="tmMessengerMenu">' +
      '            <button type="button" class="tm-messenger-menu-item" id="tmMessengerViewTicketBtn">View Ticket</button>' +
      '            <button type="button" class="tm-messenger-menu-item danger" id="tmMessengerDeleteBtn">Delete Conversation</button>' +
      '          </div>' +
      '        </div>' +
      '        <button type="button" class="tm-messenger-close" id="tmMessengerCloseBtn" aria-label="Close">&times;</button>' +
      '      </div>' +
      '    </div>' +
      '    <div class="tm-messenger-messages" id="tmMessengerMessages"><div class="tm-messenger-empty">Select a ticket on the left.</div></div>' +
      '    <div class="tm-messenger-compose">' +
      '      <input type="hidden" id="tmMessengerTicketId" value="">' +
      '      <input type="text" id="tmMessengerInput" placeholder="Type a message..." autocomplete="off" disabled>' +
      '      <button type="button" class="tm-messenger-send" id="tmMessengerSendBtn" disabled>Send</button>' +
      '    </div>' +
      '  </div>' +
      '</div>';
    document.body.appendChild(overlay);
    ensureMessengerConfirmExists();

    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) closeMessengerChat();
    });
    var closeBtn = qs('tmMessengerCloseBtn');
    if (closeBtn) closeBtn.addEventListener('click', closeMessengerChat);
    var deleteBtn = qs('tmMessengerDeleteBtn');
    if (deleteBtn) deleteBtn.addEventListener('click', deleteMessengerConversation);
    var menuBtn = qs('tmMessengerMenuBtn');
    if (menuBtn) menuBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      toggleMessengerMenu();
    });
    var viewTicketBtn = qs('tmMessengerViewTicketBtn');
    if (viewTicketBtn) viewTicketBtn.addEventListener('click', viewMessengerTicket);
    document.addEventListener('click', function (e) {
      var wrap = qs('tmMessengerMenu') ? qs('tmMessengerMenu').parentElement : null;
      if (wrap && !wrap.contains(e.target)) hideMessengerMenu();
    });
    document.addEventListener('keydown', function (e) {
      if (!messengerOpen) return;
      if (qs('tmMessengerConfirm') && qs('tmMessengerConfirm').style.display === 'flex') {
        if (e.key === 'Escape') hideMessengerConfirm();
        return;
      }
      if (e.key === 'Escape' && qs('tmMessengerMenu') && qs('tmMessengerMenu').classList.contains('show')) {
        hideMessengerMenu();
        return;
      }
      if (e.key === 'Escape') closeMessengerChat();
    });

    var sendBtn = qs('tmMessengerSendBtn');
    var input = qs('tmMessengerInput');
    if (sendBtn) sendBtn.addEventListener('click', sendMessengerMessage);
    if (input) {
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          sendMessengerMessage();
        }
      });
    }

    var search = qs('tmMessengerSearch');
    if (search) {
      search.addEventListener('input', function () {
        renderConversations(search.value);
      });
    }
    if (typeof window !== 'undefined' && window.TM_MESSENGER_STYLE === 'employee') {
      var filterWrap = qs('tmMessengerFilters');
      if (filterWrap) {
        filterWrap.addEventListener('click', function (e) {
          var btn = e.target && e.target.closest ? e.target.closest('.tm-messenger-filter-btn') : null;
          if (!btn) return;
          window.__tmMessengerFilter = String(btn.getAttribute('data-filter') || 'all');
          updateMessengerFilterButtons();
          renderConversations(search ? search.value : '');
        });
      }
    }
  }
  function normalizeMessengerStatus(status) {
    var s = String(status || '').trim().toLowerCase();
    if (s === 'in progress' || s === 'inprogress') return 'in_progress';
    if (s === 'open') return 'open';
    return 'other';
  }
  function updateMessengerFilterButtons() {
    var activeFilter = (typeof window !== 'undefined' && window.__tmMessengerFilter) ? String(window.__tmMessengerFilter) : 'all';
    var convs = Array.isArray(window.__tmConversations) ? window.__tmConversations : [];
    var counts = { all: convs.length, open: 0, in_progress: 0 };
    convs.forEach(function (c) {
      var normalized = normalizeMessengerStatus(c && c.status ? c.status : '');
      if (normalized === 'open') counts.open += 1;
      if (normalized === 'in_progress') counts.in_progress += 1;
    });
    var defs = [
      { id: 'tmMessengerFilterAll', key: 'all', label: 'All' },
      { id: 'tmMessengerFilterOpen', key: 'open', label: 'Open' },
      { id: 'tmMessengerFilterInProgress', key: 'in_progress', label: 'In Progress' }
    ];
    defs.forEach(function (def) {
      var el = qs(def.id);
      if (!el) return;
      el.textContent = def.label + ' (' + String(counts[def.key] || 0) + ')';
      el.classList.toggle('active', activeFilter === def.key);
    });
  }
  function toRelative(ts) {
    if (!ts) return '';
    var then = new Date(String(ts).replace(' ', 'T'));
    if (isNaN(then.getTime())) return '';
    var now = new Date();
    var diff = Math.max(0, Math.floor((now.getTime() - then.getTime()) / 1000));
    if (diff < 10) return 'Just now';
    if (diff < 60) return diff + 's ago';
    var m = Math.floor(diff / 60);
    if (m < 60) return m + 'm ago';
    var h = Math.floor(diff / 3600);
    if (h < 24) return h + 'h ago';
    var d = Math.floor(diff / 86400);
    return d + 'd ago';
  }
  function formatChatTimeDisplay(value) {
    if (!value) return '';
    var parsed = value instanceof Date ? value : new Date(String(value).replace(' ', 'T'));
    if (isNaN(parsed.getTime())) {
      var raw = String(value).trim();
      var match = raw.match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);
      if (!match) return raw;
      var hours = parseInt(match[1], 10);
      var minutes = match[2];
      var suffix = hours >= 12 ? 'PM' : 'AM';
      var hour12 = hours % 12;
      if (hour12 === 0) hour12 = 12;
      return String(hour12) + ':' + minutes + ' ' + suffix;
    }
    return parsed.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', hour12: true });
  }
  function messengerStatusClass(status) {
    var s = normalizeMessengerStatus(status);
    if (s === 'in_progress') return 'status-in-progress';
    if (s === 'open') return 'status-open';
    return 'status-closed';
  }
  function loadConversationsAndMaybeSelect() {
    var formData = new FormData();
    formData.append('action', 'conversations');
    var t = getCsrfToken();
    if (t) formData.append('csrf_token', t);
    postJson('chat_fetch.php', formData)
      .then(function (data) {
        var searchEl = qs('tmMessengerSearch');
        if (data && data.error) {
          if (Array.isArray(window.__tmConversations) && window.__tmConversations.length) {
            renderConversations(searchEl ? searchEl.value : '');
            return;
          }
          var list = qs('tmMessengerList');
          if (list) list.innerHTML = '<div class="tm-messenger-empty">' + escapeHtml(String(data.error || 'Unable to load conversations.')) + '</div>';
          return;
        }
        window.__tmConversations = Array.isArray(data) ? data : [];

        if (messengerTicketId) {
          var has = window.__tmConversations.some(function (c) { return c && String(c.id) === String(messengerTicketId); });
          if (!has) {
            var subject = (lastTicketMeta && String(lastTicketMeta.id) === String(messengerTicketId) && lastTicketMeta.subject) ? String(lastTicketMeta.subject) : 'Ticket';
            window.__tmConversations.unshift({ id: String(messengerTicketId), subject: subject, status: '', last_message_time: '', unread_count: 0, last_message: '', last_sender_name: '' });
          }
        }
        updateMessengerFilterButtons();
        renderConversations(searchEl ? searchEl.value : '');
        if (!messengerTicketId && window.__tmConversations.length) {
          selectConversation(window.__tmConversations[0]);
        } else if (messengerTicketId) {
          var found = window.__tmConversations.find(function (c) { return String(c.id) === String(messengerTicketId); });
          if (found) selectConversation(found, true);
        }
      })
      .catch(function () {
        var searchEl = qs('tmMessengerSearch');
        if (Array.isArray(window.__tmConversations) && window.__tmConversations.length) {
          renderConversations(searchEl ? searchEl.value : '');
          return;
        }
        var list = qs('tmMessengerList');
        if (list) list.innerHTML = '<div class="tm-messenger-empty">Unable to load conversations.</div>';
      });
  }
  function renderConversations(query) {
    var list = qs('tmMessengerList');
    if (!list) return;
    var convs = Array.isArray(window.__tmConversations) ? window.__tmConversations : [];
    var activeFilter = (typeof window !== 'undefined' && window.__tmMessengerFilter) ? String(window.__tmMessengerFilter) : 'all';
    var q = (query || '').trim().toLowerCase();
    if (activeFilter !== 'all') {
      convs = convs.filter(function (c) {
        return normalizeMessengerStatus(c && c.status ? c.status : '') === activeFilter;
      });
    }
    if (q) {
      convs = convs.filter(function (c) {
        var s = (c && c.subject) ? String(c.subject) : '';
        var id = (c && c.id != null) ? String(c.id) : '';
        return s.toLowerCase().includes(q) || id.includes(q);
      });
    }
    if (!convs.length) {
      list.innerHTML = '<div class="tm-messenger-empty">No conversations.</div>';
      return;
    }
    list.innerHTML = '';
    convs.forEach(function (c) {
      var unread = 0;
      if (c && c.unread_count != null) {
        unread = parseInt(String(c.unread_count), 10);
        if (isNaN(unread) || unread < 0) unread = 0;
      }
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'tm-messenger-item' +
        (unread > 0 ? ' unread-chat' : '') +
        (messengerTicketId && String(c.id) === String(messengerTicketId) ? ' active' : '');
      btn.dataset.ticketId = String(c.id);
      btn.innerHTML =
        '<div class="tm-messenger-item-top">' +
        '  <div class="tm-messenger-item-subject" title="' + escapeHtml(c.subject) + '">#' + String(c.id).padStart(6, '0') + ' • ' + escapeHtml(c.subject) + '</div>' +
        '  <div class="tm-messenger-item-right">' +
        '    <div class="tm-messenger-item-time">' + escapeHtml(toRelative(c.last_message_time || c.ticket_created_at)) + '</div>' +
        (unread > 0 ? ('<span class="unread-badge">' + escapeHtml(String(unread)) + '</span>') : '') +
        '  </div>' +
        '</div>' +
        '<div class="tm-messenger-item-preview">' + escapeHtml((c.last_message ? ((c.last_sender_name ? (String(c.last_sender_name) + ': ') : '') + String(c.last_message)) : 'No messages yet.')) + '</div>';
      btn.addEventListener('click', function () {
        selectConversation(c);
      });
      list.appendChild(btn);
    });
  }
  function setMessengerHeader(conv) {
    var title = qs('tmMessengerHeaderTitle');
    var sub = qs('tmMessengerHeaderSub');
    var menuBtn = qs('tmMessengerMenuBtn');
    if (title) title.textContent = conv ? ('#' + String(conv.id).padStart(6, '0') + ' • ' + String(conv.subject || '')) : 'Select a conversation';
    if (sub) {
      if (conv) {
        if (typeof window !== 'undefined' && window.TM_MESSENGER_STYLE === 'employee') {
          var rel = toRelative(conv.last_message_time || conv.ticket_created_at || '');
          var statusLabel = String(conv.status || 'Open').trim() || 'Open';
          var requesterEmail = conv && conv.requester_email ? String(conv.requester_email) : '';
          var assignedName = conv && conv.assigned_to_name ? String(conv.assigned_to_name) : '';
          sub.innerHTML =
            '<span class="tm-messenger-status-pill ' + messengerStatusClass(statusLabel) + '">' + escapeHtml(statusLabel) + '</span>' +
            (assignedName
              ? ('<span class="tm-messenger-assignee">Assigned to: <strong>' + escapeHtml(assignedName) + '</strong></span>')
              : ((rel ? ('<span class="tm-messenger-sub-sep">•</span><span>' + escapeHtml(rel) + '</span>') : '') +
                (requesterEmail ? ('<span class="tm-messenger-sub-sep">•</span><span>' + escapeHtml(requesterEmail) + '</span>') : '')));
        } else {
          sub.textContent = conv.last_message_time ? ('Last message: ' + String(conv.last_message_time)) : '';
        }
      } else {
        sub.textContent = '';
      }
    }
    if (menuBtn) menuBtn.disabled = !conv;
    if (!conv) hideMessengerMenu();
  }
  function clearMessengerSelection() {
    messengerTicketId = null;
    messengerPermissionState.canChat = false;
    messengerPermissionState.lockedMessage = '';
    messengerPermissionState.handlerName = '';
    messengerPermissionState.statusLabel = '';
    messengerPermissionState.isChecking = false;
    var idEl = qs('tmMessengerTicketId');
    if (idEl) idEl.value = '';
    var input = qs('tmMessengerInput');
    var sendBtn = qs('tmMessengerSendBtn');
    if (input) {
      input.value = '';
      input.disabled = true;
      input.readOnly = true;
      input.placeholder = 'Type a message...';
      input.style.cursor = '';
      input.style.opacity = '';
      input.style.backgroundColor = '';
      input.style.pointerEvents = '';
    }
    if (sendBtn) sendBtn.disabled = true;
    setMessengerHeader(null);
    var container = qs('tmMessengerMessages');
    if (container) container.innerHTML = '<div class="tm-messenger-empty">Select a ticket on the left.</div>';
    hideMessengerMenu();
    renderConversations(qs('tmMessengerSearch') ? qs('tmMessengerSearch').value : '');
  }
  function selectConversation(conv, noReloadConversations) {
    if (!conv || conv.id == null) return;
    messengerTicketId = String(conv.id);
    setCurrentTicketId(messengerTicketId);
    var idEl = qs('tmMessengerTicketId');
    if (idEl) idEl.value = messengerTicketId;
    setMessengerHeader(conv);
    setMessengerComposerState(false, 'Checking ticket handler...');

    renderConversations(qs('tmMessengerSearch') ? qs('tmMessengerSearch').value : '');
    stopMessenger();
    loadMessengerMessages(messengerTicketId, true);
    messengerInterval = setInterval(function () { loadMessengerMessages(messengerTicketId, false); }, 3000);
    if (!noReloadConversations) {
      setTimeout(function () { loadConversationsAndMaybeSelect(); }, 0);
    }
  }
  function loadMessengerMessages(ticketId, scrollBottom, skipMeta) {
    if (skipMeta !== true) {
      loadMessengerMeta(ticketId, scrollBottom);
    }
    var formData = new FormData();
    formData.append('ticket_id', ticketId);
    var t = getCsrfToken();
    if (t) formData.append('csrf_token', t);
    postJson('chat_fetch.php', formData)
      .then(function (data) {
        if (data && data.error) {
          var errMsg = String(data.error || '');
          setMessengerComposerState(false, errMsg);
          renderMessengerLockedState(errMsg);
          return;
        }
        if (messengerPermissionState.isChecking) {
          return;
        }
        if (!messengerPermissionState.canChat) {
          renderMessengerLockedState(messengerPermissionState.lockedMessage);
          return;
        }
        renderMessengerMessages(data || [], scrollBottom);
      })
      .catch(function () { });
  }
  function renderMessengerMessages(messages, scrollBottom) {
    var container = qs('tmMessengerMessages');
    if (!container) return;
    var isNearBottom = container.scrollHeight - container.scrollTop - container.clientHeight < 120;
    container.innerHTML = '';
    if (!messages || messages.length === 0) {
      container.innerHTML = '<div class="tm-messenger-empty">No messages yet.</div>';
      return;
    }
    messages.forEach(function (msg) {
      var bubble = document.createElement('div');
      bubble.classList.add('chat-bubble', (msg.is_me ? 'me' : 'other'));
      var senderLabel = '';
      if (msg && msg.sender_name && String(msg.sender_name).trim() !== '') {
        senderLabel = String(msg.sender_name);
      } else if (msg && msg.is_me) {
        senderLabel = (window.TM_CURRENT_USER && window.TM_CURRENT_USER.name) ? String(window.TM_CURRENT_USER.name) : 'You';
      }
      if (senderLabel) {
        var sDiv = document.createElement('div');
        sDiv.classList.add('chat-sender');
        sDiv.textContent = senderLabel;
        bubble.appendChild(sDiv);
      }
      var contentDiv = document.createElement('div');
      contentDiv.textContent = msg.message;
      var timeDiv = document.createElement('div');
      timeDiv.classList.add('chat-time');
      timeDiv.textContent = formatChatTimeDisplay(msg.created_at);
      bubble.appendChild(contentDiv);
      bubble.appendChild(timeDiv);
      container.appendChild(bubble);
    });
    if (scrollBottom || isNearBottom) container.scrollTop = container.scrollHeight;
  }
  function formatHHMM(d) {
    var dt = d instanceof Date ? d : new Date();
    if (isNaN(dt.getTime())) dt = new Date();
    var h = dt.getHours();
    var m = dt.getMinutes();
    var suffix = h >= 12 ? 'PM' : 'AM';
    var hour12 = h % 12;
    if (hour12 === 0) hour12 = 12;
    return String(hour12) + ':' + String(m).padStart(2, '0') + ' ' + suffix;
  }
  function appendMessengerBubble(message, isMe, senderName, timeText) {
    var container = qs('tmMessengerMessages');
    if (!container) return null;
    if (container.querySelector('.tm-messenger-empty')) container.innerHTML = '';
    var bubble = document.createElement('div');
    bubble.classList.add('chat-bubble', (isMe ? 'me' : 'other'));
    if (senderName) {
      var sDiv = document.createElement('div');
      sDiv.classList.add('chat-sender');
      sDiv.textContent = senderName;
      bubble.appendChild(sDiv);
    }
    var contentDiv = document.createElement('div');
    contentDiv.textContent = message;
    var timeDiv = document.createElement('div');
    timeDiv.classList.add('chat-time');
    timeDiv.textContent = timeText || formatHHMM(new Date());
    bubble.appendChild(contentDiv);
    bubble.appendChild(timeDiv);
    container.appendChild(bubble);
    container.scrollTop = container.scrollHeight;
    return bubble;
  }
  function updateConversationPreview(ticketId, message, senderName) {
    if (!ticketId) return;
    if (!Array.isArray(window.__tmConversations)) window.__tmConversations = [];
    var nowIso = new Date().toISOString().slice(0, 19).replace('T', ' ');
    var found = window.__tmConversations.find(function (c) { return c && String(c.id) === String(ticketId); });
    if (!found) {
      found = { id: String(ticketId), subject: (lastTicketMeta && String(lastTicketMeta.id) === String(ticketId) && lastTicketMeta.subject) ? String(lastTicketMeta.subject) : 'Ticket' };
      window.__tmConversations.unshift(found);
    }
    found.last_message = String(message || '');
    found.last_sender_name = String(senderName || '');
    found.last_message_time = nowIso;
    renderConversations(qs('tmMessengerSearch') ? qs('tmMessengerSearch').value : '');
  }
  function setMessengerComposerState(canChat, lockedMessage) {
    var input = qs('tmMessengerInput');
    var btn = qs('tmMessengerSendBtn');
    var allowed = canChat === true;
    var waiting = !allowed && String(lockedMessage || '') === 'Checking ticket handler...';
    var handlerName = extractHandlerName(lockedMessage);
    messengerPermissionState.canChat = allowed;
    messengerPermissionState.lockedMessage = String(lockedMessage || '');
    messengerPermissionState.handlerName = handlerName;
    messengerPermissionState.isChecking = waiting;
    if (input) {
      input.disabled = !allowed;
      input.readOnly = !allowed;
      input.tabIndex = allowed ? 0 : -1;
      if (!allowed) input.value = '';
      input.placeholder = allowed ? 'Type a message...' : (waiting ? 'Checking ticket handler...' : 'You can\'t message. This ticket is already assigned.');
      input.style.cursor = allowed ? 'text' : 'not-allowed';
      input.style.opacity = allowed ? '1' : '0.75';
      input.style.backgroundColor = allowed ? '' : '#f8fafc';
      input.style.pointerEvents = allowed ? 'auto' : 'none';
    }
    if (btn) {
      btn.disabled = !allowed;
      btn.tabIndex = allowed ? 0 : -1;
      btn.style.cursor = allowed ? 'pointer' : 'not-allowed';
      btn.style.opacity = allowed ? '1' : '1';
      btn.style.pointerEvents = allowed ? 'auto' : 'none';
    }
  }
  function renderMessengerLockedState(message) {
    var container = qs('tmMessengerMessages');
    if (!container) return;
    var handlerName = extractHandlerName(message || messengerPermissionState.lockedMessage);
    container.innerHTML =
      '<div class="tm-messenger-locked-state">' +
      '  <div class="tm-messenger-lock-title-row"><span class="tm-messenger-locked-icon"><i class="fas fa-lock"></i></span><div class="tm-messenger-lock-title">You can\'t message.</div></div>' +
      '  <div class="tm-messenger-lock-subtitle">This ticket is already assigned to <strong>' + escapeHtml(handlerName || 'another IT staff') + '</strong>.</div>' +
      '</div>';
  }
  function loadMessengerMeta(ticketId, scrollBottom) {
    fetch('get_ticket_details.php?id=' + ticketId)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || data.error) return;
        lastTicketMeta = {
          id: data && data.id != null ? data.id : ticketId,
          subject: data && data.subject ? String(data.subject) : ''
        };
        setMessengerComposerState(data && data.can_chat === true, data && data.chat_locked_message ? String(data.chat_locked_message) : '');

        var conv = Array.isArray(window.__tmConversations)
          ? window.__tmConversations.find(function (c) { return c && String(c.id) === String(ticketId); })
          : null;
        if (!conv) {
          conv = {
            id: String(ticketId),
            subject: data && data.subject ? String(data.subject) : 'Ticket',
            status: data && data.status ? String(data.status) : '',
            requester_email: data && data.created_by_email ? String(data.created_by_email) : ''
          };
          if (!Array.isArray(window.__tmConversations)) window.__tmConversations = [];
          window.__tmConversations.unshift(conv);
        }
        if (data && data.subject) conv.subject = String(data.subject);
        if (data && data.status) conv.status = String(data.status);
        if (data && data.created_by_email) conv.requester_email = String(data.created_by_email);
        conv.assigned_to_name = data && data.assigned_to_name ? String(data.assigned_to_name) : '';
        messengerPermissionState.statusLabel = conv.status || '';
        setMessengerHeader(conv);
        updateMessengerFilterButtons();
        renderConversations(qs('tmMessengerSearch') ? qs('tmMessengerSearch').value : '');
        if (messengerTicketId && String(messengerTicketId) === String(ticketId)) {
          if (messengerPermissionState.canChat === true) {
            loadMessengerMessages(ticketId, scrollBottom === true, true);
          } else {
            renderMessengerLockedState(messengerPermissionState.lockedMessage);
          }
        }
      })
      .catch(function () { });
  }
  function sendMessengerMessage() {
    var input = qs('tmMessengerInput');
    var ticketIdEl = qs('tmMessengerTicketId');
    var btn = qs('tmMessengerSendBtn');
    if (!input || !ticketIdEl) return;
    var ticketId = String(ticketIdEl.value || '');
    var message = input.value.trim();
    if (!ticketId || !message) return;
    if (input.disabled || input.readOnly || messengerPermissionState.canChat !== true) return;
    if (btn && btn.disabled) return;
    var senderName = (window.TM_CURRENT_USER && window.TM_CURRENT_USER.name) ? String(window.TM_CURRENT_USER.name) : 'You';
    input.value = '';
    var bubble = appendMessengerBubble(message, true, senderName, formatHHMM(new Date()));
    updateConversationPreview(ticketId, message, senderName);
    if (btn) btn.disabled = true;
    var formData = new FormData();
    formData.append('ticket_id', ticketId);
    formData.append('message', message);
    var t = getCsrfToken();
    if (t) formData.append('csrf_token', t);
    postJson('chat_send.php', formData)
      .then(function (data) {
        if (btn) btn.disabled = false;
        if (data && data.success) {
          setTimeout(function () { loadMessengerMessages(ticketId, true); }, 0);
          return;
        }
        loadMessengerMessages(ticketId, false);
      })
      .catch(function () {
        if (btn) btn.disabled = false;
        loadMessengerMessages(ticketId, false);
      });
  }
  function deleteMessengerConversation() {
    var ticketIdEl = qs('tmMessengerTicketId');
    var deleteBtn = qs('tmMessengerDeleteBtn');
    var ticketId = ticketIdEl ? String(ticketIdEl.value || '') : '';
    if (!ticketId) return;
    if (deleteBtn && deleteBtn.disabled) return;
    hideMessengerMenu();
    showMessengerConfirm({
      title: 'Delete Conversation',
      message: 'Delete this conversation?',
      confirmText: 'Delete',
      cancelText: 'Cancel',
      danger: true,
      onConfirm: function () {
        if (deleteBtn) deleteBtn.disabled = true;
        var formData = new FormData();
        formData.append('ticket_id', ticketId);
        var t = getCsrfToken();
        if (t) formData.append('csrf_token', t);
        postJson('chat_delete.php', formData)
          .then(function (data) {
            if (!(data && data.success)) {
              if (deleteBtn) deleteBtn.disabled = false;
              showMessengerConfirm({
                title: 'Delete Failed',
                message: (data && data.error) ? String(data.error) : 'Failed to delete chat.',
                confirmText: 'OK',
                hideCancel: true
              });
              return;
            }
            if (Array.isArray(window.__tmConversations)) {
              window.__tmConversations = window.__tmConversations.filter(function (c) {
                return !(c && String(c.id) === String(ticketId));
              });
            }
            stopMessenger();
            clearMessengerSelection();
            var remaining = Array.isArray(window.__tmConversations) ? window.__tmConversations : [];
            if (remaining.length) {
              selectConversation(remaining[0], true);
            } else {
              var list = qs('tmMessengerList');
              if (list) list.innerHTML = '<div class="tm-messenger-empty">No conversations.</div>';
            }
          })
          .catch(function () {
            if (deleteBtn) deleteBtn.disabled = false;
            showMessengerConfirm({
              title: 'Delete Failed',
              message: 'Failed to delete chat.',
              confirmText: 'OK',
              hideCancel: true
            });
          });
      }
    });
  }
  function viewMessengerTicket() {
    var ticketIdEl = qs('tmMessengerTicketId');
    var ticketId = ticketIdEl ? String(ticketIdEl.value || '') : '';
    if (!ticketId) return;
    hideMessengerMenu();
    messengerReturnContext = { ticketId: ticketId };
    var modal = qs('tmMessengerModal');
    if (modal) modal.style.display = 'none';
    messengerOpen = false;
    stopMessenger();
    open(ticketId);
  }
  function openMessengerChat() {
    ensureMessengerModalExists();
    var modal = qs('tmMessengerModal');
    if (!modal) return;
    modal.style.display = 'flex';
    messengerOpen = true;
    stopChat();
    stopChatBadge();
    loadConversationsAndMaybeSelect();
    var input = qs('tmMessengerInput');
    if (input) setTimeout(function () { input.focus(); }, 0);
  }
  function openConversation(ticketId) {
    if (ticketId == null || ticketId === '') return;
    messengerTicketId = String(ticketId);
    setCurrentTicketId(messengerTicketId);
    close();
    ensureMessengerModalExists();
    var modal = qs('tmMessengerModal');
    if (!modal) return;
    modal.style.display = 'flex';
    messengerOpen = true;
    stopChat();
    stopChatBadge();
    var list = qs('tmMessengerList');
    if (list && list.innerHTML.trim() === '') {
      list.innerHTML = '<div class="tm-messenger-empty">Loading...</div>';
    }

    var subject = 'Ticket';
    if (lastTicketMeta && String(lastTicketMeta.id) === String(ticketId) && lastTicketMeta.subject) {
      subject = String(lastTicketMeta.subject);
    }
    var conv = { id: String(ticketId), subject: subject, last_message_time: '', unread_count: 0, last_message: '', last_sender_name: '' };
    if (!Array.isArray(window.__tmConversations)) window.__tmConversations = [];
    var existing = window.__tmConversations.find(function (c) { return c && String(c.id) === String(ticketId); });
    if (!existing) window.__tmConversations.unshift(conv);
    renderConversations(qs('tmMessengerSearch') ? qs('tmMessengerSearch').value : '');
    selectConversation(existing || conv, true);
    setTimeout(function () { loadConversationsAndMaybeSelect(); }, 0);
  }
  function closeMessengerChat() {
    var modal = qs('tmMessengerModal');
    if (modal) modal.style.display = 'none';
    messengerOpen = false;
    messengerReturnContext = null;
    stopMessenger();
  }
  function restoreMessengerAfterTicketClose() {
    if (!messengerReturnContext || !messengerReturnContext.ticketId) return;
    ensureMessengerModalExists();
    var ticketId = String(messengerReturnContext.ticketId);
    messengerReturnContext = null;
    messengerTicketId = ticketId;
    var modal = qs('tmMessengerModal');
    if (!modal) return;
    modal.style.display = 'flex';
    messengerOpen = true;
    stopChat();
    stopChatBadge();
    var found = Array.isArray(window.__tmConversations)
      ? window.__tmConversations.find(function (c) { return c && String(c.id) === ticketId; })
      : null;
    if (found) {
      selectConversation(found, true);
    } else {
      var subject = (lastTicketMeta && String(lastTicketMeta.id) === String(ticketId) && lastTicketMeta.subject) ? String(lastTicketMeta.subject) : 'Ticket';
      if (!Array.isArray(window.__tmConversations)) window.__tmConversations = [];
      found = { id: ticketId, subject: subject, last_message_time: '', unread_count: 0, last_message: '', last_sender_name: '' };
      window.__tmConversations.unshift(found);
      renderConversations(qs('tmMessengerSearch') ? qs('tmMessengerSearch').value : '');
      selectConversation(found, true);
    }
    setTimeout(function () { loadConversationsAndMaybeSelect(); }, 0);
  }
  function open(id, options) {
    ensureTicketModalExists();
    var modal = qs('ticketModal');
    var modalContent = qs('modalContent');
    if (!modal || !modalContent) return;
    modal.style.display = 'flex';
    modalContent.innerHTML = '<div style="padding:40px; text-align:center; color:#64748b;">Loading details...</div>';
    stopChat();
    ensureChatModalExists();
    fetch('get_ticket_details.php?id=' + id)
      .then(function (r) { return r.text(); })
      .then(function (text) { return parseTicketDetailsResponse(text); })
      .then(function (data) {
        if (data && data.error) {
          modalContent.innerHTML = '<div style="padding:40px; text-align:center; color:#ef4444;">' + escapeHtml(data.error) + '</div>';
          return;
        }
        setCurrentTicketId(data && data.id != null ? data.id : id);
        lastTicketMeta = { id: data && data.id != null ? data.id : id, subject: data && data.subject ? String(data.subject) : '' };
        try {
          modalContent.innerHTML = buildHtml(data);
        } catch (renderError) {
          console.error('Ticket modal render failed:', renderError, data);
          modalContent.innerHTML = buildFallbackHtml(data);
        }
        try {
          bindNoChangeGuard(modalContent, data);
        } catch (noChangeError) {
          console.error('Ticket modal no-change guard failed:', noChangeError, data);
        }
        try {
          bindAdminNote(modalContent, data);
        } catch (adminNoteError) {
          console.error('Ticket modal admin note binding failed:', adminNoteError, data);
        }
        try {
          bindDepartmentOptions(modalContent, data);
        } catch (deptBindError) {
          console.error('Ticket modal department binding failed:', deptBindError, data);
        }
        setTimeout(function () {
          var statusSelect = modalContent.querySelector('.tm-status-select');
          if (statusSelect) updateStatusColor(statusSelect);
          startChatBadge(data.id);
        }, 0);
      })
      .catch(function (err) {
        console.error('Ticket details load failed:', err);
        modalContent.innerHTML = '<div style="padding:40px; text-align:center; color:#ef4444;">Failed to load details.</div>';
      });
    if (!modal.dataset.boundClose) {
      window.addEventListener('click', function (e) { if (e.target === modal) close(); });
      modal.dataset.boundClose = '1';
    }
  }
  function close() {
    var modal = qs('ticketModal');
    if (modal) modal.style.display = 'none';
    stopChat();
    stopChatBadge();
    closeChatModal();
    restoreMessengerAfterTicketClose();
  }
  function viewImage(src) {
    var modal = qs('imagePreviewModal');
    var img = qs('previewImage');
    if (!modal || !img) return;
    img.src = src;
    modal.classList.add('show');
  }
  function closeImagePreview(e) {
    var modal = qs('imagePreviewModal');
    if (!modal) return;
    if (!e || e.target.id === 'imagePreviewModal' || (e.target && e.target.classList.contains('preview-close'))) {
      modal.classList.remove('show');
      setTimeout(function () {
        var img = qs('previewImage');
        if (img) img.src = '';
      }, 300);
    }
  }
  return {
    open: open,
    close: close,
    switchTab: switchTab,
    sendMessage: sendMessage,
    openChatModal: openChatModal,
    closeChatModal: closeChatModal,
    sendChatModalMessage: sendChatModalMessage,
    openConversation: openConversation,
    openMessengerChat: openMessengerChat,
    closeMessengerChat: closeMessengerChat,
    updateStatusColor: updateStatusColor,
    viewImage: viewImage,
    closeImagePreview: closeImagePreview,
    getCurrentTicketId: getCurrentTicketId
  };
})(); 
