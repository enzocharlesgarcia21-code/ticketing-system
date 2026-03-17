var TMTicketModal = (function () {
  var chatInterval = null;
  var chatBadgeInterval = null;
  var chatBadgeTicketId = null;
  var chatModalOpen = false;
  var messengerOpen = false;
  var messengerInterval = null;
  var messengerTicketId = null;
  var currentTicketId = null;
  var lastTicketMeta = null;
  function qs(id) { return document.getElementById(id); }
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
  function renderTimeline(ticket) {
    var createdAt = ticket.created_at ? new Date(ticket.created_at) : null;
    var updatedAt = ticket.updated_at ? new Date(ticket.updated_at) : null;
    var fallbackWhen = updatedAt || createdAt;
    var events = [{ title: 'Ticket created', when: createdAt }];
    if (ticket.assigned_department) events.push({ title: 'Assigned to ' + ticket.assigned_department, when: fallbackWhen });
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
    var noticeEl = form.querySelector('#tmNoChangeNotice');

    var initialStatus = statusEl ? String(statusEl.value || '') : String((data && data.status) || '');
    var initialDept = deptEl ? String(deptEl.value || '') : '';
    var initialCompany = companyEl ? String(companyEl.value || '') : '';

    function hideNotice() {
      if (!noticeEl) return;
      noticeEl.classList.remove('show');
      noticeEl.textContent = '';
    }

    [statusEl, deptEl, companyEl].forEach(function (el) {
      if (!el) return;
      el.addEventListener('change', hideNotice);
      el.addEventListener('input', hideNotice);
    });

    form.addEventListener('submit', function (e) {
      var currentStatus = statusEl ? String(statusEl.value || '') : initialStatus;
      var currentDept = deptEl ? String(deptEl.value || '') : initialDept;
      var currentCompany = companyEl ? String(companyEl.value || '') : initialCompany;
      if (currentStatus === initialStatus && currentDept === initialDept && currentCompany === initialCompany) {
        e.preventDefault();
        if (noticeEl) {
          noticeEl.textContent = 'No changes were made.';
          noticeEl.classList.add('show');
        }
      }
    });
  }
  function buildHtml(data) {
    var statusSlug = data.status ? data.status.toLowerCase().replace(/\s+/g, '') : 'default';
    var prioritySlug = data.priority ? data.priority.toLowerCase() : 'default';
    var endForTotal = (data && data.status && (/^(Resolved|Closed)$/i).test(String(data.status)) && data.updated_at) ? data.updated_at : new Date();
    var resMinutesAll = computeResolutionMinutes(data.created_at, endForTotal);
    var backendStr = data && data.duration && !/^(in progress|not started)$/i.test(String(data.duration)) ? String(data.duration) : null;
    var displayStr = backendStr || formatResolutionString(resMinutesAll);
    var cls = getDurationClass(backendStr, resMinutesAll);
    var isRunning = (endForTotal instanceof Date);
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
        '            <select class="tm-select tm-status-select" name="status" onchange="TMTicketModal.updateStatusColor(this)">' +
        '                  <option value="Open" ' + (data.status === 'Open' ? 'selected' : '') + '>Open</option>' +
        '                  <option value="In Progress" ' + (data.status === 'In Progress' ? 'selected' : '') + '>In Progress</option>' +
        '                  <option value="Resolved" ' + (data.status === 'Resolved' ? 'selected' : '') + '>Resolved</option>' +
        '                  <option value="Closed" ' + (data.status === 'Closed' ? 'selected' : '') + '>Closed</option>' +
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
    var deptKeys = ['ACCOUNTING','ADMIN','BIDDING','E-COMM','HR','IT','LINGAP','MARKETING','SUPPLY CHAIN','TECHNICAL'];
    var selectedDeptKey = deptKeyFromValue(data.assigned_department || '');
    var deptOptionsHtml = '';
    if (selectedDeptKey && deptKeys.indexOf(selectedDeptKey) === -1) {
      deptOptionsHtml += '                  <option value="' + escapeHtml(selectedDeptKey) + '" selected>' + escapeHtml(selectedDeptKey) + '</option>';
    }
    deptOptionsHtml += deptKeys.map(function (k) {
      return '                  <option value="' + escapeHtml(k) + '" ' + (String(selectedDeptKey || '') === String(k) ? 'selected' : '') + '>' + escapeHtml(k) + '</option>';
    }).join('');
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
      '  <div class="tm-tab" data-tab="actions" onclick="TMTicketModal.switchTab(\'actions\')">Update</div>' +
      '  <div class="tm-tab" data-tab="conversation" onclick="TMTicketModal.openConversation(' + String(data.id) + ')">Conversation</div>' +
      '</div>' +
      '<div class="tm-body">' +
      '  <div id="tab-info" class="tm-tab-content active">' +
      '    <div class="tm-info-col">' +
      '      <div class="tm-card"><div class="tm-card-header"><span class="tm-card-title">Ticket Information</span></div><div class="tm-card-body"><div class="tm-info-grid">' +
      '        <div class="tm-info-label">CREATED BY</div><div class="tm-info-value">' + (data.created_by_name ? escapeHtml(String(data.created_by_name)) : '-') + '</div>' +
      '        <div class="tm-info-label">EMAIL</div><div class="tm-info-value">' + (data.created_by_email ? escapeHtml(String(data.created_by_email)) : '-') + '</div>' +
      '        <div class="tm-info-label">DEPARTMENT</div><div class="tm-info-value">' + (data.department ? escapeHtml(String(data.department)) : '-') + '</div>' +
      '        <div class="tm-info-label">CREATED AT</div><div class="tm-info-value">' + (data.created_at ? formatTimelineTime(data.created_at) : '-') + '</div>' +
      '        <div class="tm-info-label">LAST UPDATED</div><div class="tm-info-value">' + (data.updated_at ? formatTimelineTime(data.updated_at) : '-') + '</div>' +
      '        <div class="tm-info-label">ASSIGNED TO</div><div class="tm-info-value">' + (data.assigned_department ? escapeHtml(String(data.assigned_department)) : '-') + (data.assigned_company ? '<br><small class="text-muted">(' + escapeHtml(String(data.assigned_company)) + ')</small>' : '') + '</div>' +
      '      </div></div></div>' +
      '      <div class="tm-card"><div class="tm-card-header"><span class="tm-card-title">Ticket Activity</span></div><div class="tm-card-body">' + renderTimeline(data) + '</div></div>' +
      '    </div>' +
      '    <div class="tm-desc-col">' +
      '      <div class="tm-card"><div class="tm-card-header"><span class="tm-card-title">Description</span></div><div class="tm-card-body"><div class="tm-desc-text">' + escapeHtml(data.description).replace(/\n/g, '<br>') + '</div>' + renderAttachmentsBlock(data) + '</div></div>' +
      '      ' + ((data.impact && data.impact !== '-') ? '<div class="tm-card"><div class="tm-card-header"><span class="tm-card-title">Impact</span></div><div class="tm-card-body"><div class="tm-info-value">' + escapeHtml(String(data.impact)) + '</div></div></div>' : '') +
      '      ' + ((data.urgency && data.urgency !== '-') ? '<div class="tm-card"><div class="tm-card-header"><span class="tm-card-title">Urgency</span></div><div class="tm-card-body"><div class="tm-info-value">' + escapeHtml(String(data.urgency)) + '</div></div></div>' : '') +
      '      <div class="tm-card"><div class="tm-card-header"><span class="tm-card-title">Resolution</span></div><div class="tm-card-body">' +
      '        <div class="tm-resolution-row">' +
      '          <div class="tm-res-item"><div class="tm-res-label">Start</div><div class="tm-res-value">' + (data.created_at ? formatTimelineTime(data.created_at) : '-') + '</div></div>' +
      '          <div class="tm-res-item"><div class="tm-res-label">End</div><div class="tm-res-value">' + ((data.status && (/^(Resolved|Closed)$/i).test(String(data.status)) && data.updated_at) ? formatTimelineTime(data.updated_at) : 'Pending') + '</div></div>' +
      '          <div class="tm-res-item"><div class="tm-res-label">Duration</div><div class="tm-res-value"><span class="tm-duration-dot"></span>' + (displayStr ? escapeHtml(displayStr) : '-') + '</div></div>' +
      '        </div>' +
      '      </div></div>' +
      '    </div>' +
      '  </div>' +
      '  <div id="tab-actions" class="tm-tab-content">' +
      '    <div class="tm-card"><div class="tm-card-header"><span class="tm-card-title">Ticket Update</span></div><div class="tm-card-body">' +
      '    <form id="ticketUpdateForm" method="POST" action="update_ticket.php" class="tm-actions-form">' +
      '      <input type="hidden" name="id" value="' + data.id + '">' +
      '      <input type="hidden" name="csrf_token" value="' + escapeHtml(getCsrfToken()) + '">' +
      '      <div class="tm-actions-fields">' +
      '        <div class="tm-field">' +
      '          <label class="tm-control-label">Status</label>' +
      statusControlHtml +
      '        </div>' +
      '        <div class="tm-field">' +
      '          <label class="tm-control-label">Assigned Department</label>' +
      '          <div class="tm-select-wrapper">' +
      '            <select class="tm-select tm-dept-select" name="assigned_department">' +
      ( !data.assigned_department ? '                  <option value="" disabled selected hidden>Assign Department</option>' : '' ) +
      deptOptionsHtml +
      '            </select>' +
      '          </div>' +
      '        </div>' +
      '        <div class="tm-field">' +
      '          <label class="tm-control-label">Ticket Recipient</label>' +
      '          <div class="tm-select-wrapper">' +
      '            <select class="tm-select tm-dept-select" name="assigned_company">' +
      ( !data.assigned_company ? '                  <option value="" disabled selected hidden>Select Recipient</option>' : '' ) +
      ( data.assigned_company && ['@gpsci.net','@farmasee.ph','@gmail.com','@leads-eh.com','@leads-farmex.com','@leadsagri.com','@leadsanimalhealth.com','@leadsav.com','@leadstech-corp.com','@lingapleads.org','@primestocks.ph'].indexOf(String(data.assigned_company).toLowerCase()) === -1
          ? ('                  <option value="' + escapeHtml(data.assigned_company) + '" selected>' + escapeHtml(data.assigned_company) + '</option>')
          : '' ) +
      '                  <option value="@gpsci.net" ' + (String(data.assigned_company || '').toLowerCase() === '@gpsci.net' ? 'selected' : '') + '>@gpsci.net</option>' +
      '                  <option value="@farmasee.ph" ' + (String(data.assigned_company || '').toLowerCase() === '@farmasee.ph' ? 'selected' : '') + '>@farmasee.ph</option>' +
      '                  <option value="@gmail.com" ' + (String(data.assigned_company || '').toLowerCase() === '@gmail.com' ? 'selected' : '') + '>@gmail.com</option>' +
      '                  <option value="@leads-eh.com" ' + (String(data.assigned_company || '').toLowerCase() === '@leads-eh.com' ? 'selected' : '') + '>@leads-eh.com</option>' +
      '                  <option value="@leads-farmex.com" ' + (String(data.assigned_company || '').toLowerCase() === '@leads-farmex.com' ? 'selected' : '') + '>@leads-farmex.com</option>' +
      '                  <option value="@leadsagri.com" ' + (String(data.assigned_company || '').toLowerCase() === '@leadsagri.com' ? 'selected' : '') + '>@leadsagri.com</option>' +
      '                  <option value="@leadsanimalhealth.com" ' + (String(data.assigned_company || '').toLowerCase() === '@leadsanimalhealth.com' ? 'selected' : '') + '>@leadsanimalhealth.com</option>' +
      '                  <option value="@leadsav.com" ' + (String(data.assigned_company || '').toLowerCase() === '@leadsav.com' ? 'selected' : '') + '>@leadsav.com</option>' +
      '                  <option value="@leadstech-corp.com" ' + (String(data.assigned_company || '').toLowerCase() === '@leadstech-corp.com' ? 'selected' : '') + '>@leadstech-corp.com</option>' +
      '                  <option value="@lingapleads.org" ' + (String(data.assigned_company || '').toLowerCase() === '@lingapleads.org' ? 'selected' : '') + '>@lingapleads.org</option>' +
      '                  <option value="@primestocks.ph" ' + (String(data.assigned_company || '').toLowerCase() === '@primestocks.ph' ? 'selected' : '') + '>@primestocks.ph</option>' +
      '            </select>' +
      '          </div>' +
      '        </div>' +
      '      </div>' +
      '      <div class="tm-nochange" id="tmNoChangeNotice"></div>' +
      '      <div class="tm-actions-buttons">' +
      '        <button type="button" class="tm-btn tm-btn-secondary" onclick="TMTicketModal.close()">Close</button>' +
      '        <button type="submit" class="tm-btn tm-btn-primary">Save Ticket</button>' +
      '      </div>' +
      '    </form>' +
      '    </div></div>' +
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
      '    <div class="ticket-chat-input-wrapper">' +
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
  function loadChatModalMeta(ticketId) {
    setChatModalMetaHtml('<span class="chat-meta-loading">Loading details…</span>');
    fetch('get_ticket_details.php?id=' + ticketId)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || data.error) return;
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

        var assignedParts = [];
        if (data.assigned_department) assignedParts.push(String(data.assigned_department));
        if (data.assigned_company) assignedParts.push(String(data.assigned_company));

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
    var formData = new FormData();
    formData.append('ticket_id', ticketId);
    var t = getCsrfToken();
    if (t) formData.append('csrf_token', t);
    postJson('chat_fetch.php', formData)
      .then(function (data) {
        if (data && data.error) {
          stopChat();
          var container = qs('chatModalMessages');
          if (container) {
            container.innerHTML = '<div class="chat-empty">Chat is available only between the requester and assigned user.</div>';
          }
          var input = qs('chatModalInput');
          var btn = qs('chatModalSendBtn');
          if (input) input.disabled = true;
          if (btn) btn.disabled = true;
          return;
        }
        var msgs = data || [];
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
        if (data && data.error) {
          if (bubble) {
            bubble.style.border = '1px solid #ef4444';
            bubble.style.opacity = '0.75';
          }
          if (container) {
            container.innerHTML = '<div class="chat-empty">' + escapeHtml(String(data.error || 'Failed to send message.')) + '</div>';
          }
          return;
        }
        if (data && data.success) {
          setTimeout(function () { loadTicketMessages(ticketId, true); }, 0);
        }
      })
      .catch(function () {
        if (btn) btn.disabled = false;
        if (bubble) {
          bubble.style.border = '1px solid #ef4444';
          bubble.style.opacity = '0.75';
        }
      });
  }
  function stopMessenger() {
    if (messengerInterval) {
      clearInterval(messengerInterval);
      messengerInterval = null;
    }
  }
  function ensureMessengerModalExists() {
    if (qs('tmMessengerModal')) return;

    if (!document.getElementById('tmMessengerStyles')) {
      var style = document.createElement('style');
      style.id = 'tmMessengerStyles';
      style.textContent =
        '.tm-messenger-overlay{position:fixed;inset:0;background:rgba(15,23,42,.45);display:none;align-items:center;justify-content:center;z-index:9999;padding:18px;}' +
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
        '.tm-messenger-close{border:none;background:#f1f5f9;color:#0f172a;border-radius:10px;padding:8px 10px;cursor:pointer;font-weight:900;display:inline-flex;align-items:center;justify-content:center;}' +
        '.tm-messenger-close:hover{background:#e2e8f0;}' +
        '.tm-messenger-messages{flex:1;overflow:auto;padding:16px;background:#f9fafb;display:flex;flex-direction:column;gap:12px;}' +
        '.tm-messenger-empty{color:#94a3b8;font-weight:700;text-align:center;margin-top:26px;}' +
        '.tm-messenger-compose{border-top:1px solid #e5e7eb;padding:12px;background:#fff;display:flex;gap:10px;align-items:center;}' +
        '.tm-messenger-compose input{flex:1;border:1px solid #e5e7eb;border-radius:12px;padding:12px 14px;font-size:14px;outline:none;background:#fff;}' +
        '.tm-messenger-compose input:focus{border-color:#22c55e;box-shadow:0 0 0 4px rgba(34,197,94,.12);}' +
        '.tm-messenger-send{border:none;background:#1B5E20;color:#fff;border-radius:12px;padding:12px 14px;font-weight:900;cursor:pointer;min-width:86px;}' +
        '.tm-messenger-send:disabled{opacity:.6;cursor:not-allowed;}' +
        '.tm-messenger-overlay .chat-bubble{max-width:80%;padding:10px 14px;border-radius:16px;font-size:14px;line-height:1.5;word-wrap:break-word;display:flex;flex-direction:column;gap:4px;box-shadow:0 1px 2px rgba(0,0,0,.04);}' +
        '.tm-messenger-overlay .chat-bubble.me{align-self:flex-end;background:#1B5E20;color:#fff;border-bottom-right-radius:4px;}' +
        '.tm-messenger-overlay .chat-bubble.other{align-self:flex-start;background:#f1f5f9;color:#0f172a;border-bottom-left-radius:4px;}' +
        '.tm-messenger-overlay .chat-sender{font-size:12px;font-weight:800;opacity:.9;}' +
        '.tm-messenger-overlay .chat-time{font-size:11px;font-weight:700;opacity:.75;margin-top:2px;align-self:flex-end;}' +
        '@media (max-width: 820px){.tm-messenger-panel{width:96vw;height:86vh}.tm-messenger-left{width:260px;min-width:260px;max-width:260px}}' +
        '@media (max-width: 640px){.tm-messenger-panel{flex-direction:column}.tm-messenger-left{width:100%;min-width:0;max-width:none;height:44%}.tm-messenger-right{height:56%}}';
      document.head.appendChild(style);
    }

    var overlay = document.createElement('div');
    overlay.id = 'tmMessengerModal';
    overlay.className = 'tm-messenger-overlay';
    overlay.innerHTML =
      '<div class="tm-messenger-panel" role="dialog" aria-modal="true" aria-label="Ticket Conversations">' +
      '  <div class="tm-messenger-left">' +
      '    <div class="tm-messenger-left-header">' +
      '      <div class="tm-messenger-left-title">Conversations</div>' +
      '    </div>' +
      '    <div class="tm-messenger-search"><input type="text" id="tmMessengerSearch" placeholder="Search tickets..."></div>' +
      '    <div class="tm-messenger-list" id="tmMessengerList"><div class="tm-messenger-empty">Loading...</div></div>' +
      '  </div>' +
      '  <div class="tm-messenger-right">' +
      '    <div class="tm-messenger-right-header">' +
      '      <div class="tm-messenger-right-title">' +
      '        <div class="tm-messenger-title-main" id="tmMessengerHeaderTitle">Select a conversation</div>' +
      '        <div class="tm-messenger-title-sub" id="tmMessengerHeaderSub"> </div>' +
      '      </div>' +
      '      <button type="button" class="tm-messenger-close" id="tmMessengerCloseBtn" aria-label="Close">×</button>' +
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

    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) closeMessengerChat();
    });
    var closeBtn = qs('tmMessengerCloseBtn');
    if (closeBtn) closeBtn.addEventListener('click', closeMessengerChat);
    document.addEventListener('keydown', function (e) {
      if (!messengerOpen) return;
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
            window.__tmConversations.unshift({ id: String(messengerTicketId), subject: subject, last_message_time: '', unread_count: 0, last_message: '', last_sender_name: '' });
          }
        }
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
    var q = (query || '').trim().toLowerCase();
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
    if (title) title.textContent = conv ? ('#' + String(conv.id).padStart(6, '0') + ' • ' + String(conv.subject || '')) : 'Select a conversation';
    if (sub) sub.textContent = conv && conv.last_message_time ? ('Last message: ' + String(conv.last_message_time)) : '';
  }
  function selectConversation(conv, noReloadConversations) {
    if (!conv || conv.id == null) return;
    messengerTicketId = String(conv.id);
    setCurrentTicketId(messengerTicketId);
    var idEl = qs('tmMessengerTicketId');
    if (idEl) idEl.value = messengerTicketId;
    setMessengerHeader(conv);

    var input = qs('tmMessengerInput');
    var sendBtn = qs('tmMessengerSendBtn');
    if (input) input.disabled = false;
    if (sendBtn) sendBtn.disabled = false;

    renderConversations(qs('tmMessengerSearch') ? qs('tmMessengerSearch').value : '');
    stopMessenger();
    loadMessengerMessages(messengerTicketId, true);
    messengerInterval = setInterval(function () { loadMessengerMessages(messengerTicketId, false); }, 3000);
    if (!noReloadConversations) {
      setTimeout(function () { loadConversationsAndMaybeSelect(); }, 0);
    }
  }
  function loadMessengerMessages(ticketId, scrollBottom) {
    var formData = new FormData();
    formData.append('ticket_id', ticketId);
    var t = getCsrfToken();
    if (t) formData.append('csrf_token', t);
    postJson('chat_fetch.php', formData)
      .then(function (data) {
        if (data && data.error) return;
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
      timeDiv.textContent = msg.created_at;
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
    return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
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
  function sendMessengerMessage() {
    var input = qs('tmMessengerInput');
    var ticketIdEl = qs('tmMessengerTicketId');
    var btn = qs('tmMessengerSendBtn');
    if (!input || !ticketIdEl) return;
    var ticketId = String(ticketIdEl.value || '');
    var message = input.value.trim();
    if (!ticketId || !message) return;
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
        if (bubble) {
          bubble.style.border = '1px solid #ef4444';
          bubble.style.opacity = '0.75';
        }
      })
      .catch(function () {
        if (btn) btn.disabled = false;
        if (bubble) {
          bubble.style.border = '1px solid #ef4444';
          bubble.style.opacity = '0.75';
        }
      });
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
    stopMessenger();
  }
  function open(id, options) {
    var modal = qs('ticketModal');
    var modalContent = qs('modalContent');
    if (!modal || !modalContent) return;
    modal.style.display = 'flex';
    modalContent.innerHTML = '<div style="padding:40px; text-align:center; color:#64748b;">Loading details...</div>';
    stopChat();
    ensureChatModalExists();
    fetch('get_ticket_details.php?id=' + id)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.error) {
          modalContent.innerHTML = '<div style="padding:40px; text-align:center; color:#ef4444;">' + escapeHtml(data.error) + '</div>';
          return;
        }
        setCurrentTicketId(data && data.id != null ? data.id : id);
        lastTicketMeta = { id: data && data.id != null ? data.id : id, subject: data && data.subject ? String(data.subject) : '' };
        modalContent.innerHTML = buildHtml(data);
        bindNoChangeGuard(modalContent, data);
        setTimeout(function () {
          var statusSelect = modalContent.querySelector('.tm-status-select');
          if (statusSelect) updateStatusColor(statusSelect);
          startChatBadge(data.id);
        }, 0);
      })
      .catch(function () {
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
