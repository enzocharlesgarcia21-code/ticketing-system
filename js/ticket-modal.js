var TMTicketModal = (function () {
  var chatInterval = null;
  var chatBadgeInterval = null;
  var chatBadgeTicketId = null;
  var chatModalOpen = false;
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
  function renderAttachment(filename) {
    return '<div class="tm-attachment">' +
           '  <div class="tm-att-icon">' +
           '    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path></svg>' +
           '  </div>' +
           '  <div class="tm-att-details">' +
           '    <div class="tm-att-name" title="' + escapeHtml(filename) + '">' + escapeHtml(filename) + '</div>' +
           '    <div class="tm-att-actions">' +
           viewButtonIfImage(filename) +
           '      <a href="../uploads/' + filename + '" class="tm-action-btn tm-download-btn" download>' +
           '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>' +
           'Download</a>' +
           '    </div>' +
           '  </div>' +
           '</div>';
  }
  function computeResolutionMinutes(createdAt, updatedAt) {
    if (!createdAt || !updatedAt) return null;
    var c = new Date(createdAt);
    var u = new Date(updatedAt);
    if (isNaN(c.getTime()) || isNaN(u.getTime())) return null;
    var diffMs = u.getTime() - c.getTime();
    if (diffMs <= 0) return null;
    return Math.round(diffMs / 60000);
  }
  function formatResolutionString(minutes) {
    if (minutes == null) return null;
    if (minutes < 60) return minutes + ' mins';
    var hrs = Math.floor(minutes / 60);
    var mins = minutes % 60;
    if (mins === 0) return hrs + ' ' + (hrs === 1 ? 'hr' : 'hrs');
    return hrs + ' ' + (hrs === 1 ? 'hr' : 'hrs') + ' ' + mins + ' mins';
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
  function buildHtml(data) {
    var statusSlug = data.status ? data.status.toLowerCase().replace(/\s+/g, '') : 'default';
    var prioritySlug = data.priority ? data.priority.toLowerCase() : 'default';
    var endForTotal = (data && data.status && (/^(Resolved|Closed)$/i).test(String(data.status)) && data.updated_at) ? data.updated_at : new Date();
    var resMinutesAll = computeResolutionMinutes(data.created_at, endForTotal);
    var backendStr = data && data.duration && !/^(in progress|not started)$/i.test(String(data.duration)) ? String(data.duration) : null;
    var displayStr = backendStr || formatResolutionString(resMinutesAll);
    var cls = getDurationClass(backendStr, resMinutesAll);
    var isRunning = (endForTotal instanceof Date);
    var resBadge = displayStr ? '<span class="tm-duration-badge ' + cls + (isRunning ? ' running' : '') + '">' + escapeHtml(displayStr + (isRunning ? ' (so far)' : '')) + '</span>' : '<span class="tm-duration-badge neutral">-</span>';
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
      '  <div class="tm-tab" data-tab="actions" onclick="TMTicketModal.switchTab(\'actions\')">Actions</div>' +
      '  <button type="button" class="chat-open-btn" onclick="TMTicketModal.openChatModal(' + data.id + ')">💬 Chat<span id="chatBtnBadge" class="chat-badge"></span></button>' +
      '</div>' +
      '<div class="tm-body">' +
      '  <div id="tab-info" class="tm-tab-content active">' +
      '    <div class="tm-info-col">' +
      '      <div class="tm-card"><div class="tm-card-header"><span class="tm-card-title">Ticket Information</span></div><div class="tm-card-body"><div class="tm-info-grid">' +
      '        <div class="tm-info-label">CREATED BY</div><div class="tm-info-value">' + (data.created_by_name ? escapeHtml(String(data.created_by_name)) : '-') + '</div>' +
      '        <div class="tm-info-label">EMAIL</div><div class="tm-info-value">' + (data.created_by_email ? escapeHtml(String(data.created_by_email)) : '-') + '</div>' +
      '        <div class="tm-info-label">DEPARTMENT</div><div class="tm-info-value">' + (data.department ? escapeHtml(String(data.department)) : '-') + '</div>' +
      '        <div class="tm-info-label">COMPANY</div><div class="tm-info-value">' + (data.company ? escapeHtml(String(data.company)) : '-') + '</div>' +
      '        <div class="tm-info-label">CREATED AT</div><div class="tm-info-value">' + (data.created_at ? formatTimelineTime(data.created_at) : '-') + '</div>' +
      '        <div class="tm-info-label">LAST UPDATED</div><div class="tm-info-value">' + (data.updated_at ? formatTimelineTime(data.updated_at) : '-') + '</div>' +
      '        <div class="tm-info-label">ASSIGNED TO</div><div class="tm-info-value">' + (data.assigned_department ? escapeHtml(String(data.assigned_department)) : '-') + (data.assigned_company ? '<br><small class="text-muted">(' + escapeHtml(String(data.assigned_company)) + ')</small>' : '') + '</div>' +
      '      </div></div></div>' +
      '      <div class="tm-card"><div class="tm-card-header"><span class="tm-card-title">Ticket Activity</span></div><div class="tm-card-body">' + renderTimeline(data) + '</div></div>' +
      '    </div>' +
      '    <div class="tm-desc-col">' +
      '      <div class="tm-card"><div class="tm-card-header"><span class="tm-card-title">Description</span></div><div class="tm-card-body"><div class="tm-desc-text">' + escapeHtml(data.description).replace(/\n/g, '<br>') + '</div>' + (data.attachment ? renderAttachment(data.attachment) : '') + '</div></div>' +
      '      ' + ((data.impact && data.impact !== '-') ? '<div class="tm-card"><div class="tm-card-header"><span class="tm-card-title">Impact</span></div><div class="tm-card-body"><div class="tm-info-value">' + escapeHtml(String(data.impact)) + '</div></div></div>' : '') +
      '      ' + ((data.urgency && data.urgency !== '-') ? '<div class="tm-card"><div class="tm-card-header"><span class="tm-card-title">Urgency</span></div><div class="tm-card-body"><div class="tm-info-value">' + escapeHtml(String(data.urgency)) + '</div></div></div>' : '') +
      '      <div class="tm-card"><div class="tm-card-header"><span class="tm-card-title">Resolution</span></div><div class="tm-card-body">' +
      '        <div class="tm-resolution-row">' +
      '          <div class="tm-res-item"><div class="tm-res-label">Start</div><div class="tm-res-value">' + (data.created_at ? formatTimelineTime(data.created_at) : '-') + '</div></div>' +
      '          <div class="tm-res-item"><div class="tm-res-label">End</div><div class="tm-res-value">' + ((data.status && (/^(Resolved|Closed)$/i).test(String(data.status)) && data.updated_at) ? formatTimelineTime(data.updated_at) : 'Pending') + '</div></div>' +
      '          <div class="tm-res-item"><div class="tm-res-label">Duration</div><div class="tm-res-value"><span class="tm-duration-dot"></span>' + (displayStr ? escapeHtml(displayStr + (isRunning ? ' (so far)' : '')) : '-') + '</div></div>' +
      '        </div>' +
      '      </div></div>' +
      '    </div>' +
      '  </div>' +
      '  <div id="tab-actions" class="tm-tab-content">' +
      '    <div class="tm-card"><div class="tm-card-header"><span class="tm-card-title">Ticket Actions</span></div><div class="tm-card-body">' +
      '    <form id="ticketUpdateForm" method="POST" action="update_ticket.php" class="tm-actions-form">' +
      '      <input type="hidden" name="id" value="' + data.id + '">' +
      '      <input type="hidden" name="csrf_token" value="' + escapeHtml(getCsrfToken()) + '">' +
      '      <div class="tm-actions-fields">' +
      '        <div class="tm-field">' +
      '          <label class="tm-control-label">Status</label>' +
      statusControlHtml +
      '        </div>' +
      '        <div class="tm-field">' +
      '          <label class="tm-control-label">Assign Dept</label>' +
      '          <div class="tm-select-wrapper">' +
      '            <select class="tm-select tm-dept-select" name="assigned_department">' +
      ( !data.assigned_department ? '                  <option value="" disabled selected hidden>Assign Department</option>' : '' ) +
      '                  <option value="Accounting" ' + (data.assigned_department === 'Accounting' ? 'selected' : '') + '>Accounting</option>' +
'                  <option value="Admin" ' + (data.assigned_department === 'Admin' ? 'selected' : '') + '>Admin</option>' +
'                  <option value="Bidding" ' + (data.assigned_department === 'Bidding' ? 'selected' : '') + '>Bidding</option>' +
'                  <option value="E-Comm" ' + (data.assigned_department === 'E-Comm' ? 'selected' : '') + '>E-Comm</option>' +
'                  <option value="HR" ' + (data.assigned_department === 'HR' ? 'selected' : '') + '>HR</option>' +
'                  <option value="IT" ' + (data.assigned_department === 'IT' ? 'selected' : '') + '>IT</option>' +
'                  <option value="Marketing" ' + (data.assigned_department === 'Marketing' ? 'selected' : '') + '>Marketing</option>' +
'                  <option value="Sales" ' + (data.assigned_department === 'Sales' ? 'selected' : '') + '>Sales</option>' +
      '            </select>' +
      '          </div>' +
      '        </div>' +
      '        <div class="tm-field">' +
      '          <label class="tm-control-label">Assign Company</label>' +
      '          <div class="tm-select-wrapper">' +
      '            <select class="tm-select tm-dept-select" name="assigned_company">' +
      ( !data.assigned_company ? '                  <option value="" disabled selected hidden>Select Company</option>' : '' ) +
      '                  <option value="FARMEX" ' + (data.assigned_company === 'FARMEX' ? 'selected' : '') + '>FARMEX</option>' +
      '                  <option value="FARMASEE" ' + (data.assigned_company === 'FARMASEE' ? 'selected' : '') + '>FARMASEE</option>' +
      '                  <option value="Golden Primestocks Chemical Inc - GPSCI" ' + (data.assigned_company === 'Golden Primestocks Chemical Inc - GPSCI' ? 'selected' : '') + '>Golden Primestocks Chemical Inc - GPSCI</option>' +
      '                  <option value="Leads Animal Health - LAH" ' + (data.assigned_company === 'Leads Animal Health - LAH' ? 'selected' : '') + '>Leads Animal Health - LAH</option>' +
      '                  <option value="Leads Environmental Health - LEH" ' + (data.assigned_company === 'Leads Environmental Health - LEH' ? 'selected' : '') + '>Leads Environmental Health - LEH</option>' +
      '                  <option value="Leads Tech Corporation - LTC" ' + (data.assigned_company === 'Leads Tech Corporation - LTC' ? 'selected' : '') + '>Leads Tech Corporation - LTC</option>' +
      '                  <option value="LINGAP LEADS FOUNDATION - Lingap" ' + (data.assigned_company === 'LINGAP LEADS FOUNDATION - Lingap' ? 'selected' : '') + '>LINGAP LEADS FOUNDATION - Lingap</option>' +
      '                  <option value="Malveda Holdings Corporation - MHC" ' + (data.assigned_company === 'Malveda Holdings Corporation - MHC' ? 'selected' : '') + '>Malveda Holdings Corporation - MHC</option>' +
      '                  <option value="Malveda Properties & Development Corporation - MPDC" ' + (data.assigned_company === 'Malveda Properties & Development Corporation - MPDC' ? 'selected' : '') + '>Malveda Properties & Development Corporation - MPDC</option>' +
      '                  <option value="Primestocks Chemical Corporation - PCC" ' + (data.assigned_company === 'Primestocks Chemical Corporation - PCC' ? 'selected' : '') + '>Primestocks Chemical Corporation - PCC</option>' +
      '            </select>' +
      '          </div>' +
      '        </div>' +
      '      </div>' +
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
    fetch('chat_fetch.php', { method: 'POST', body: formData })
      .then(function (r) { return r.json(); })
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
    fetch('chat_send.php', { method: 'POST', body: formData })
      .then(function (r) { return r.json(); })
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
    var b = qs('chatBtnBadge');
    if (!b) return;
    var n = parseInt(String(count || 0), 10) || 0;
    if (n <= 0) {
      b.classList.remove('is-visible');
      b.textContent = '';
      return;
    }
    b.classList.add('is-visible');
    b.textContent = n > 99 ? '99+' : String(n);
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
    fetch('chat_fetch.php', { method: 'POST', body: formData })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.error) return;
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
    fetch('chat_fetch.php', { method: 'POST', body: formData })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.error) return;
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
    if (btn) btn.disabled = true;
    var formData = new FormData();
    formData.append('ticket_id', ticketIdEl.value);
    formData.append('message', message);
    var t = getCsrfToken();
    if (t) formData.append('csrf_token', t);
    fetch('chat_send.php', { method: 'POST', body: formData })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (btn) btn.disabled = false;
        if (data && data.success) {
          input.value = '';
          loadTicketMessages(ticketIdEl.value, true);
        }
      })
      .catch(function () { if (btn) btn.disabled = false; });
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
        modalContent.innerHTML = buildHtml(data);
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
    updateStatusColor: updateStatusColor,
    viewImage: viewImage,
    closeImagePreview: closeImagePreview
  };
})(); 
