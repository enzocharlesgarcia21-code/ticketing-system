var TMTicketModal = (function () {
  var chatInterval = null;
  var chatBadgeInterval = null;
  var messageActionMenusBound = false;
  var chatBadgeTicketId = null;
  var chatModalOpen = false;
  var messengerOpen = false;
  var messengerInterval = null;
  var messengerTicketId = null;
  var messengerConfirmAction = null;
  var messengerEditSubmitAction = null;
  var messengerReturnContext = null;
  var currentTicketId = null;
  var lastTicketMeta = null;
  var chatModalAttachmentFile = null;
  var messengerAttachmentFile = null;
  var attachmentCategorySeq = 0;
  var sapDisplaySeq = 0;
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
        '  <button class="preview-close" onclick="TMTicketModal.closeImagePreview(event)">Ã—</button>' +
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
  function bindMessageActionMenuCloser() {
    if (messageActionMenusBound) return;
    messageActionMenusBound = true;
    document.addEventListener('click', function () {
      var openMenus = document.querySelectorAll('.tm-msg-actions-menu.show');
      openMenus.forEach(function (menu) { menu.classList.remove('show'); });
    });
  }
  function createMessageActionsNode(msg, ticketId, onDone) {
    if (!msg || !ticketId) return null;
    if (!msg.is_me) {
      return null;
    }
    var canEdit = msg.can_edit === true;
    var canDelete = msg.can_delete === true;
    if (!canEdit && !canDelete) return null;
    bindMessageActionMenuCloser();

    var wrap = document.createElement('div');
    wrap.className = 'tm-msg-actions';

    var toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'tm-msg-actions-toggle';
    toggle.setAttribute('aria-label', 'Message actions');
    toggle.innerHTML = '<i class="fas fa-ellipsis-v"></i>';

    var menu = document.createElement('div');
    menu.className = 'tm-msg-actions-menu';
    menu.addEventListener('click', function (e) { e.stopPropagation(); });

    if (canEdit) {
      var editBtn = document.createElement('button');
      editBtn.type = 'button';
      editBtn.className = 'tm-msg-actions-item';
      editBtn.textContent = 'Edit';
      editBtn.addEventListener('click', function () {
        menu.classList.remove('show');
        var current = String(msg.message || '');
        showMessengerMessageEditor({
          value: current,
          hasAttachment: !!(msg && msg.attachment && msg.attachment.stored_name),
          onSubmit: function (updated, done, unlock) {
            var fd = new FormData();
            fd.append('ticket_id', String(ticketId));
            fd.append('message_id', String(msg.id || ''));
            fd.append('message', updated);
            var t = getCsrfToken();
            if (t) fd.append('csrf_token', t);
            postJson('chat_message_update.php', fd)
              .then(function (res) {
                if (!(res && res.success)) {
                  unlock();
                  showMessengerConfirm({
                    title: 'Edit Failed',
                    message: (res && res.error) ? String(res.error) : 'Unable to edit this message.',
                    confirmText: 'OK',
                    hideCancel: true
                  });
                  return;
                }
                done();
                if (typeof onDone === 'function') onDone();
              })
              .catch(function () {
                unlock();
                showMessengerConfirm({
                  title: 'Edit Failed',
                  message: 'Unable to edit this message.',
                  confirmText: 'OK',
                  hideCancel: true
                });
              });
          }
        });
      });
      menu.appendChild(editBtn);
    }

    if (canDelete) {
      var delBtn = document.createElement('button');
      delBtn.type = 'button';
      delBtn.className = 'tm-msg-actions-item danger';
      delBtn.textContent = 'Delete';
      delBtn.addEventListener('click', function () {
        menu.classList.remove('show');
        showMessengerConfirm({
          title: 'Delete Message',
          message: 'Delete this message?',
          confirmText: 'Delete',
          cancelText: 'Cancel',
          danger: true,
          onConfirm: function () {
            var fd = new FormData();
            fd.append('ticket_id', String(ticketId));
            fd.append('message_id', String(msg.id || ''));
            var t = getCsrfToken();
            if (t) fd.append('csrf_token', t);
            postJson('chat_message_delete.php', fd)
              .then(function (res) {
                if (!(res && res.success)) {
                  showMessengerConfirm({
                    title: 'Delete Failed',
                    message: (res && res.error) ? String(res.error) : 'Unable to delete this message.',
                    confirmText: 'OK',
                    hideCancel: true
                  });
                  return;
                }
                if (typeof onDone === 'function') onDone();
              })
              .catch(function () {
                showMessengerConfirm({
                  title: 'Delete Failed',
                  message: 'Unable to delete this message.',
                  confirmText: 'OK',
                  hideCancel: true
                });
              });
          }
        });
      });
      menu.appendChild(delBtn);
    }

    toggle.addEventListener('click', function (e) {
      e.stopPropagation();
      var openMenus = document.querySelectorAll('.tm-msg-actions-menu.show');
      openMenus.forEach(function (m) { if (m !== menu) m.classList.remove('show'); });
      menu.classList.toggle('show');
    });

    wrap.appendChild(toggle);
    wrap.appendChild(menu);
    return wrap;
  }
  function setModalVariant(modalContent, variant) {
    if (!modalContent || !modalContent.classList) return;
    modalContent.classList.remove('tm-unavailable-modal');
    modalContent.classList.remove('tm-sap-ticket-modal');
    if (variant === 'unavailable') modalContent.classList.add('tm-unavailable-modal');
  }
  function buildUnavailableHtml(data) {
    var title = data && data.unavailable_title ? String(data.unavailable_title) : 'This ticket is no longer available.';
    var message = data && data.unavailable_message ? String(data.unavailable_message) : 'You can no longer view or respond to this ticket.';
    return '' +
      '<div class="tm-unavailable-state">' +
      '  <div class="tm-unavailable-head">' +
      '    <span class="tm-unavailable-icon" aria-hidden="true">' +
      '      <svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24" focusable="false" aria-hidden="true">' +
      '        <path d="M17 9h-1V7a4 4 0 10-8 0v2H7a2 2 0 00-2 2v8a2 2 0 002 2h10a2 2 0 002-2v-8a2 2 0 00-2-2zm-6 0V7a2 2 0 114 0v2h-4z"></path>' +
      '      </svg>' +
      '    </span>' +
      '    <h2 class="tm-unavailable-title">' + escapeHtml(title) + '</h2>' +
      '  </div>' +
      '  <p class="tm-unavailable-message">' + escapeHtml(message) + '</p>' +
      '</div>';
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
    var normalized = normalizeCompanyValue(companyValue);
    return normalized === '@leadsagri.com';
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
  function getHrAttachmentSlides(groups) {
    return (Array.isArray(groups) ? groups : []).map(function (group) {
      var attachments = Array.isArray(group && group.attachments) ? group.attachments.map(function (att) {
        var n = normalizeAttachment(att);
        if (!n.filename) return null;
        return {
          filename: String(n.filename),
          displayName: String(n.displayName || n.filename),
          isImage: isImageFile(n.filename)
        };
      }).filter(function (att) { return !!att; }) : [];
      if (!attachments.length) return null;
      return {
        title: String((group && group.title) || 'Attachment'),
        attachments: attachments
      };
    }).filter(function (group) { return !!group; });
  }
  function renderHrAttachmentCategoryCarousel(groups) {
    var slides = getHrAttachmentSlides(groups);
    if (!slides.length) return '';
    var carouselId = 'tmHrAttachmentCategory-' + String(++attachmentCategorySeq);
    return '<div class="tm-hr-category-carousel" id="' + carouselId + '" data-index="0">' +
      slides.map(function (group, index) {
        var activeClass = index === 0 ? ' is-active' : '';
        return '<section class="tm-hr-category-slide' + activeClass + '" data-index="' + String(index) + '" aria-hidden="' + (index === 0 ? 'false' : 'true') + '">' +
          '<div class="tm-hr-category-card">' +
          '<div class="tm-hr-category-top">' +
          '<div class="tm-hr-category-title">' + escapeHtml(group.title) + '</div>' +
          '</div>' +
          '<div class="tm-hr-category-media-grid' + (group.attachments.length === 1 ? ' is-single' : '') + '">' +
          group.attachments.map(function (item) {
            var src = '../uploads/' + encodeURIComponent(item.filename);
            if (item.isImage) {
              return '<button type="button" class="tm-hr-category-media is-image" data-src="' + src + '" onclick="TMTicketModal.viewImage(this.dataset.src)">' +
                '<img class="tm-hr-category-image" src="' + src + '" alt="' + escapeHtml(item.displayName) + '">' +
                '</button>';
            }
            return '<a class="tm-hr-category-media is-file" href="' + src + '" target="_blank" rel="noopener noreferrer">' +
              '<span class="tm-hr-category-file-icon"><i class="fas fa-file-alt"></i></span>' +
              '<span class="tm-hr-category-file-name">' + escapeHtml(item.displayName) + '</span>' +
              '</a>';
          }).join('') +
          '</div>' +
          '<div class="tm-hr-category-bottom">' +
          '<div></div>' +
          (slides.length > 1
            ? '<div class="tm-hr-category-nav">' +
              '<button type="button" class="tm-hr-category-arrow" aria-label="Previous attachment category" onclick="TMTicketModal.stepHrAttachmentCategory(\'' + carouselId + '\', -1)"><span aria-hidden="true">â€¹</span></button>' +
              '<button type="button" class="tm-hr-category-arrow" aria-label="Next attachment category" onclick="TMTicketModal.stepHrAttachmentCategory(\'' + carouselId + '\', 1)"><span aria-hidden="true">â€º</span></button>' +
              '</div>'
            : '') +
          '</div>' +
          '</div>' +
          '</section>';
      }).join('') +
      '</div>';
  }
  function stepHrAttachmentCategory(id, delta) {
    var root = document.getElementById(String(id || ''));
    if (!root) return;
    var slides = root.querySelectorAll('.tm-hr-category-slide');
    if (!slides.length) return;
    var total = slides.length;
    var current = Number(root.getAttribute('data-index') || 0);
    if (!isFinite(current)) current = 0;
    var nextIndex = ((current + Number(delta || 0)) % total + total) % total;
    root.setAttribute('data-index', String(nextIndex));
    slides.forEach(function (slide, index) {
      var active = index === nextIndex;
      slide.classList.toggle('is-active', active);
      slide.setAttribute('aria-hidden', active ? 'false' : 'true');
    });
  }
  function renderAttachmentsBlock(data, options) {
    options = options && typeof options === 'object' ? options : {};
    var hideSectionTitles = !!options.hideSectionTitles;
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
      html += '<div class="tm-attachment-section">';
      if (!hideSectionTitles) {
        html += '<div class="tm-attachment-section-title">Images</div>';
      }
      html += '<div class="tm-attachment-gallery">';
      html += images.map(function (n) {
        var src = '../uploads/' + escapeHtml(n.filename);
        return '<button type="button" class="tm-attachment-thumb" data-src="' + src + '" onclick="TMTicketModal.viewImage(this.dataset.src)">' +
               '<img class="tm-attachment-img" src="' + src + '" alt="' + escapeHtml(n.displayName || '') + '">' +
               '</button>';
      }).join('');
      html += '</div></div>';
    }
    if (others.length) {
      html += '<div class="tm-attachment-section">';
      if (!hideSectionTitles) {
        html += '<div class="tm-attachment-section-title">Files</div>';
      }
      html += others.map(function (a) { return renderAttachment(a); }).join('') + '</div>';
    }
    return html;
  }
  function getHrDisplay(data) {
    if (!data || !data.hr_display || typeof data.hr_display !== 'object') return null;
    return data.hr_display;
  }
  function normalizeDisplaySubject(subject) {
    var text = subject == null ? '' : String(subject).trim();
    if (!text) return 'Ticket';
    var previous = '';
    while (text !== previous) {
      previous = text;
      text = text.replace(/\b([A-Za-z]+)\s+\1\b$/i, '$1').trim();
    }
    return text || 'Ticket';
  }
  function getDisplaySubject(data) {
    if (!data || typeof data !== 'object') return 'Ticket';
    if (data.subject_display) return normalizeDisplaySubject(data.subject_display);
    if (data.subject) return normalizeDisplaySubject(data.subject);
    return 'Ticket';
  }
  function isSapTicket(data, descriptionText) {
    var assignedCompany = String((data && data.assigned_company) || '').trim().toLowerCase();
    var assignedGroup = String((data && (data.assigned_group || data.assigned_department)) || '').trim().toLowerCase();
    var category = String((data && data.category) || '').trim().toLowerCase();
    var subject = String((data && data.subject) || '').trim().toLowerCase();
    var text = String(descriptionText || '').trim().toLowerCase();
    return assignedCompany === '@leadsagri.com'
      && assignedGroup === 'it'
      && (category === 'sap' || subject === 'sap' || text.indexOf('sap form') === 0);
  }
  function parseSapDescription(descriptionText) {
    var lines = String(descriptionText || '').split(/\r?\n/).map(function (line) {
      return String(line || '').trim();
    }).filter(function (line) {
      return line !== '';
    });
    var reports = [];
    var current = null;
    lines.forEach(function (line) {
      if (/^sap form$/i.test(line)) return;
      var employeeMatch = line.match(/^Employee Details(?:\s+(\d+))?$/i);
      if (employeeMatch) {
        current = { index: employeeMatch[1] || String(reports.length + 1), fields: {} };
        reports.push(current);
        return;
      }
      var colonIndex = line.indexOf(':');
      if (colonIndex > 0) {
        if (!current) {
          current = { index: String(reports.length + 1), fields: {} };
          reports.push(current);
        }
        var label = line.slice(0, colonIndex).trim();
        var value = line.slice(colonIndex + 1).trim();
        current.fields[label.toLowerCase()] = value;
      }
    });
    return reports;
  }
  function parseSapReportsFromMeta(data) {
    var raw = data && data.request_meta ? data.request_meta.sap_reports : '';
    if (!raw) return [];
    var decoded = null;
    try {
      decoded = typeof raw === 'string' ? JSON.parse(raw) : raw;
    } catch (e) {
      decoded = null;
    }
    if (!Array.isArray(decoded)) return [];
    return decoded.map(function (report, index) {
      if (!report || typeof report !== 'object') return null;
      var fields = {
        'full name': String(report.name || '').trim(),
        'position': String(report.position || '').trim(),
        'immediate supervisor': String(report.immediate_head || report.immediate_supervisor || '').trim(),
        'company': String(report.company || '').trim(),
        'department': String(report.department || '').trim()
      };
      var hasValue = Object.keys(fields).some(function (key) { return fields[key] !== ''; });
      if (!hasValue) return null;
      return { index: String(index + 1), fields: fields };
    }).filter(function (report) { return !!report; });
  }
  function getSapFieldValue(report, keys) {
    var fields = report && report.fields ? report.fields : {};
    for (var i = 0; i < keys.length; i++) {
      var value = fields[String(keys[i]).toLowerCase()];
      if (value !== undefined && value !== null && String(value).trim() !== '') return String(value).trim();
    }
    return '';
  }
  function dashIfUnknown(value) {
    var text = String(value == null ? '' : value).trim();
    return (!text || text.toLowerCase() === 'unknown') ? '-' : text;
  }
  function formatSapCompanyValue(value, departmentValue) {
    var company = String(value || '').trim();
    var department = String(departmentValue || '').trim();
    if (!company && department && department !== '-' && department.toLowerCase() !== 'unknown') company = '@leadsagri.com';
    if (!company) return '-';
    var normalized = company.toLowerCase();
    var labels = {
      '@leads-farmex.com': 'FARMEX (@leads-farmex.com)',
      '@farmasee.ph': 'FARMASEE (@farmasee.ph)',
      '@gpsci.net': 'GPSCI (@gpsci.net)',
      '@leadsagri.com': 'LAPC (@leadsagri.com)',
      '@leadsav.com': 'LAV (@leadsav.com)',
      '@leadstech-corp.com': 'LTC (@leadstech-corp.com)',
      '@lingapleads.org': 'LINGAP (@lingapleads.org)',
      '@malvedaholdings.com': 'MHC (@malvedaholdings.com)',
      '@malvedaproperties.com': 'MPDC (@malvedaproperties.com)',
      '@primestocks.ph': 'PCC (@primestocks.ph)'
    };
    return labels[normalized] || company;
  }
  function renderSapDescriptionHtml(data, descriptionText) {
    if (!isSapTicket(data, descriptionText)) return '';
    var reports = parseSapReportsFromMeta(data);
    if (!reports.length) reports = parseSapDescription(descriptionText);
    if (!reports.length) return '';
    var carouselId = 'tmSapDisplay-' + String(++sapDisplaySeq);
    var fieldConfig = [
      { key: 'full name', label: 'Full Name' },
      { key: 'position', label: 'Position' },
      { key: 'immediate supervisor', label: 'Supervisor' },
      { key: 'company', label: 'Company' },
      { key: 'department', label: 'Department', wide: true }
    ];
    return '<div class="tm-sap-display">' +
      '<div class="tm-sap-carousel" id="' + carouselId + '" data-index="0">' +
      reports.map(function (report, reportIndex) {
        var rawDepartmentValue = getSapFieldValue(report, ['department', 'dept']);
        var departmentValue = dashIfUnknown(rawDepartmentValue);
        var companyValue = formatSapCompanyValue(getSapFieldValue(report, ['company', 'company name', 'company domain']), rawDepartmentValue);
        return '<div class="tm-sap-card' + (reportIndex === 0 ? ' is-active' : '') + '" data-index="' + String(reportIndex) + '" aria-hidden="' + (reportIndex === 0 ? 'false' : 'true') + '">' +
          '<div class="tm-sap-card-title">Employee Details' + (reports.length > 1 ? ' ' + escapeHtml(report.index) : '') + '</div>' +
          '<div class="tm-sap-field-grid">' +
          fieldConfig.map(function (field) {
            var value = field.key === 'company'
              ? companyValue
              : (field.key === 'department' ? departmentValue : (getSapFieldValue(report, [field.key]) || '-'));
            return '<div class="tm-sap-field' + (field.wide ? ' is-wide' : '') + '">' +
              '<div class="tm-sap-label">' + escapeHtml(field.label) + '</div>' +
              '<div class="tm-sap-value">' + escapeHtml(value) + '</div>' +
              '</div>';
          }).join('') +
          '</div>' +
          '</div>';
      }).join('') +
      (reports.length > 1
        ? '<div class="tm-sap-actions">' +
          '<button type="button" class="tm-sap-nav-btn" onclick="TMTicketModal.stepSapDisplay(\'' + carouselId + '\', -1)">Previous</button>' +
          '<span class="tm-sap-counter" data-sap-counter>1 of ' + String(reports.length) + '</span>' +
          '<button type="button" class="tm-sap-nav-btn primary" onclick="TMTicketModal.stepSapDisplay(\'' + carouselId + '\', 1)">Next</button>' +
          '</div>'
        : '') +
      '</div>' +
      '</div>';
  }
  function stepSapDisplay(id, delta) {
    var root = document.getElementById(String(id || ''));
    if (!root) return;
    var cards = root.querySelectorAll('.tm-sap-card');
    if (!cards.length) return;
    var total = cards.length;
    var current = Number(root.getAttribute('data-index') || 0);
    if (!isFinite(current)) current = 0;
    var nextIndex = ((current + Number(delta || 0)) % total + total) % total;
    root.setAttribute('data-index', String(nextIndex));
    cards.forEach(function (card, index) {
      var active = index === nextIndex;
      card.classList.toggle('is-active', active);
      card.setAttribute('aria-hidden', active ? 'false' : 'true');
    });
    var counter = root.querySelector('[data-sap-counter]');
    if (counter) counter.textContent = String(nextIndex + 1) + ' of ' + String(total);
  }
  function getChatAttachmentUrl(storedName) {
    return '../uploads/' + encodeURIComponent(String(storedName || ''));
  }
  function formatAttachmentSize(bytes) {
    var size = Number(bytes || 0);
    if (!isFinite(size) || size <= 0) return '';
    if (size < 1024) return String(Math.max(1, Math.round(size))) + ' B';
    if (size < (1024 * 1024)) return String(Math.round(size / 102.4) / 10) + ' KB';
    return String(Math.round(size / (1024 * 102.4)) / 10) + ' MB';
  }
  function isImageAttachmentFile(file) {
    if (!file) return false;
    var mime = String(file.type || '').toLowerCase();
    if (mime.indexOf('image/') === 0) return true;
    var name = String(file.name || '').toLowerCase();
    return /\.(jpe?g|png|gif|webp|bmp)$/i.test(name);
  }
  function renderSelectedComposerAttachment(label, file, onRemove) {
    if (!label) return;
    var oldUrl = label.getAttribute('data-preview-url');
    if (oldUrl && typeof URL !== 'undefined' && URL.revokeObjectURL) {
      try { URL.revokeObjectURL(oldUrl); } catch (e) { }
    }
    label.removeAttribute('data-preview-url');
    label.innerHTML = '';
    label.classList.remove('has-file');
    if (!file) return;

    var card = document.createElement('div');
    card.className = 'tm-selected-attachment';
    var isImage = isImageAttachmentFile(file);
    if (isImage) {
      var thumb = document.createElement('img');
      thumb.className = 'tm-selected-attachment-thumb';
      thumb.alt = String(file.name || 'Attachment');
      if (typeof URL !== 'undefined' && URL.createObjectURL) {
        var previewUrl = URL.createObjectURL(file);
        thumb.src = previewUrl;
        label.setAttribute('data-preview-url', previewUrl);
      }
      card.appendChild(thumb);
    } else {
      var icon = document.createElement('span');
      icon.className = 'tm-selected-attachment-file-icon';
      icon.innerHTML = '<i class="fas fa-paperclip"></i>';
      card.appendChild(icon);
    }

    var meta = document.createElement('div');
    meta.className = 'tm-selected-attachment-meta';
    var name = document.createElement('div');
    name.className = 'tm-selected-attachment-name';
    name.textContent = String(file.name || 'Attachment');
    var size = document.createElement('div');
    size.className = 'tm-selected-attachment-size';
    size.textContent = formatAttachmentSize(file.size || 0);
    meta.appendChild(name);
    meta.appendChild(size);
    card.appendChild(meta);

    var removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'tm-selected-attachment-remove';
    removeBtn.setAttribute('aria-label', 'Remove attachment');
    removeBtn.innerHTML = '<i class="fas fa-times"></i>';
    removeBtn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      if (typeof onRemove === 'function') onRemove();
    });
    card.appendChild(removeBtn);

    label.classList.add('has-file');
    label.appendChild(card);
  }
  function createMessageAttachmentNode(attachment) {
    if (!attachment || !attachment.stored_name) return null;
    var wrap = document.createElement('div');
    wrap.className = 'tm-chat-attachment';
    var attachmentUrl = getChatAttachmentUrl(attachment.stored_name);
    if (attachment.is_image) {
      var link = document.createElement('button');
      link.type = 'button';
      link.className = 'tm-chat-attachment-link tm-chat-attachment-button';
      link.setAttribute('role', 'button');
      link.setAttribute('aria-label', 'Preview image attachment');
      var openPreview = function (event) {
        event.preventDefault();
        event.stopPropagation();
        viewImage(attachmentUrl);
      };
      link.addEventListener('click', openPreview);
      var img = document.createElement('img');
      img.className = 'tm-chat-attachment-image';
      img.src = attachmentUrl;
      img.alt = String(attachment.original_name || 'Attachment');
      img.addEventListener('click', openPreview);
      link.appendChild(img);
      wrap.appendChild(link);
    } else {
      var link = document.createElement('a');
      link.className = 'tm-chat-attachment-link';
      link.href = attachmentUrl;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      var icon = document.createElement('span');
      icon.className = 'tm-chat-attachment-icon';
      icon.innerHTML = '<i class="fas fa-paperclip"></i>';
      var text = document.createElement('span');
      text.className = 'tm-chat-attachment-name';
      text.textContent = String(attachment.original_name || attachment.stored_name);
      link.appendChild(icon);
      link.appendChild(text);
      wrap.appendChild(link);
    }
    return wrap;
  }
  function setChatModalAttachment(file) {
    chatModalAttachmentFile = file || null;
    var label = qs('chatModalAttachmentName');
    renderSelectedComposerAttachment(label, chatModalAttachmentFile, function () {
      var input = qs('chatModalAttachmentInput');
      if (input) input.value = '';
      setChatModalAttachment(null);
    });
  }
  function setMessengerAttachment(file) {
    messengerAttachmentFile = file || null;
    var label = qs('tmMessengerAttachmentName');
    renderSelectedComposerAttachment(label, messengerAttachmentFile, function () {
      var input = qs('tmMessengerAttachmentInput');
      if (input) input.value = '';
      setMessengerAttachment(null);
    });
  }
  function renderHrRequestDetailsCard(data) {
    var hr = getHrDisplay(data);
    if (!hr || !hr.is_hr_special) return '';
    var items = [];
    var summaryFields = Array.isArray(hr.summary_fields) ? hr.summary_fields : [];
    summaryFields.forEach(function (field) {
      if (!field || !field.label || !field.value) return;
      items.push('<div class="tm-info-label">' + escapeHtml(String(field.label)).toUpperCase() + '</div><div class="tm-info-value">' + escapeHtml(String(field.value)) + '</div>');
    });
    var descriptionText = hr && typeof hr.detail_text !== 'undefined'
      ? String(hr.detail_text || '')
      : String((data && data.description) || '');
    var descriptionHtml = descriptionText
      ? '<div class="tm-hr-section"><div class="tm-info-label">' + escapeHtml(String(hr.detail_label || 'Description')).toUpperCase() + '</div><div class="tm-info-value">' + escapeHtml(descriptionText).replace(/\n/g, '<br>') + '</div></div>'
      : '';
    var attachmentGroups = Array.isArray(hr.attachment_groups) ? hr.attachment_groups : [];
    var attachmentsHtml = renderHrAttachmentCategoryCarousel(attachmentGroups);
    if (!items.length && !descriptionHtml && !attachmentsHtml) return '';
    return '<div class="tm-card tm-card-request-details"><div class="tm-card-header"><span class="tm-card-title">' + escapeHtml(String(hr.request_section_title || 'Request Details')) + '</span></div><div class="tm-card-body">' +
      (items.length ? '<div class="tm-info-grid tm-info-grid-compact">' + items.join('') + '</div>' : '') +
      descriptionHtml +
      attachmentsHtml +
      '</div></div>';
  }
  function renderDescriptionCard(data) {
    var hr = getHrDisplay(data);
    if (hr && hr.is_hr_special) return '';
    var title = 'Description';
    var descriptionText = String((data && data.description) || '');
    var descriptionHtml = '';
    if (descriptionText) {
      var sapDescriptionHtml = renderSapDescriptionHtml(data, descriptionText);
      if (sapDescriptionHtml) {
        title = 'SAP Form';
        descriptionHtml = sapDescriptionHtml;
      } else {
      var lines = descriptionText.split(/\r?\n/).map(function (line) { return String(line || '').trim(); }).filter(function (line) { return line !== ''; });
      var assignedCompany = String((data && data.assigned_company) || '').trim().toLowerCase();
      var assignedGroup = String((data && (data.assigned_group || data.assigned_department)) || '').trim();
      var isLapcHrTicket = assignedCompany === '@leadsagri.com' && assignedGroup === 'HR';
      if (isLapcHrTicket && lines.length > 1 && lines[0].indexOf(':') === -1) {
        title = lines[0];
        lines = lines.slice(1);
      }
      if (lines.length > 0) {
        descriptionHtml = '<div class="tm-desc-text">';
        lines.forEach(function (line, index) {
          var colonIndex = line.indexOf(':');
          if (colonIndex > 0 && colonIndex < line.length - 1) {
            var label = line.slice(0, colonIndex).trim();
            var value = line.slice(colonIndex + 1).trim();
            descriptionHtml += ''
              + '<div class="tm-desc-row">'
              + '  <span class="tm-desc-label">' + escapeHtml(label) + ':</span>'
              + '  <span class="tm-desc-value">' + escapeHtml(value) + '</span>'
              + '</div>';
          } else {
            descriptionHtml += '<div class="tm-desc-lead' + (index > 0 ? ' is-muted' : '') + '">' + escapeHtml(line) + '</div>';
          }
        });
        descriptionHtml += '</div>';
      }
      }
    }
    var attachmentsHtml = renderAttachmentsBlock(data);
    var emptyHtml = (!descriptionHtml && !attachmentsHtml) ? '<div class="tm-info-value">-</div>' : '';
    return '<div class="tm-card tm-card-description"><div class="tm-card-header"><span class="tm-card-title">' + escapeHtml(title) + '</span></div><div class="tm-card-body">' + descriptionHtml + attachmentsHtml + emptyHtml + '</div></div>';
  }
  function renderHrAttachmentCards(data) {
    var hr = getHrDisplay(data);
    if (hr && hr.is_hr_special) return '';
    return '';
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
    var deptMirrorEl = form.querySelector('input[type="hidden"][data-dept-mirror="1"]');

    function normalizeCompanyValueForCompare(value) {
      return normalizeCompanyValue(value);
    }

    function normalizeDepartmentValueForCompare(value, companyValue) {
      var normalizedCompany = normalizeCompanyValue(companyValue);
      if (normalizedCompany !== '@leadsagri.com') {
        return '';
      }
      return preferredDeptValueForCompany(value, companyValue);
    }

    function getDeptRawValue() {
      var mirrorEl = form.querySelector('input[type="hidden"][data-dept-mirror="1"]');
      if (mirrorEl) return String(mirrorEl.value || '');
      return deptEl ? String(deptEl.value || '') : '';
    }

    var initialStatus = statusEl ? String(statusEl.value || '') : String((data && data.status) || '');
    var initialCompany = normalizeCompanyValueForCompare(companyEl ? String(companyEl.value || '') : '');
    var initialDeptRaw = getDeptRawValue();
    var initialDept = normalizeDepartmentValueForCompare(initialDeptRaw, initialCompany);
    var initialNote = noteEl ? String(noteEl.value || '').trim() : String((data && data.admin_note) || '').trim();

    function showNotice(message) {
      if (!noticeEl) return;
      noticeEl.textContent = message;
      noticeEl.classList.add('show');
    }

    function hideNotice() {
      if (!noticeEl) return;
      noticeEl.classList.remove('show');
      noticeEl.textContent = '';
      if (deptEl) deptEl.classList.remove('tm-invalid');
    }

    [statusEl, deptEl, companyEl, noteEl].forEach(function (el) {
      if (!el) return;
      el.addEventListener('change', hideNotice);
      el.addEventListener('input', hideNotice);
    });

    form.addEventListener('submit', function (e) {
      var currentStatus = statusEl ? String(statusEl.value || '') : initialStatus;
      var currentCompany = normalizeCompanyValueForCompare(companyEl ? String(companyEl.value || '') : initialCompany);
      var currentDeptRaw = getDeptRawValue();
      var currentDept = normalizeDepartmentValueForCompare(currentDeptRaw, currentCompany);
      var currentNote = noteEl ? String(noteEl.value || '').trim() : initialNote;
      if (currentCompany === '@leadsagri.com' && currentDept === '') {
        e.preventDefault();
        if (deptEl) {
          deptEl.classList.add('tm-invalid');
          deptEl.focus();
        }
        showNotice('Please choose a department.');
        return;
      }
      if (currentStatus === initialStatus && currentDept === initialDept && currentCompany === initialCompany && currentNote === initialNote) {
        e.preventDefault();
        showNotice('No changes were made.');
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
    var normalized = value == null ? '' : String(value).trim().toLowerCase();
    if (normalized === '') return '';
    var companyAliases = {
      'lapc': '@leadsagri.com',
      'lapc (@leadsagri.com)': '@leadsagri.com',
      'leads agricultural products corporation - lapc': '@leadsagri.com',
      'leadsagri.com': '@leadsagri.com',
      '@leadsagri.com': '@leadsagri.com',
      'lah': '@leadsanimalhealth.com',
      'lah (@leadsanimalhealth.com)': '@leadsanimalhealth.com',
      'leads animal health - lah': '@leadsanimalhealth.com',
      'leadsanimalhealth.com': '@leadsanimalhealth.com',
      '@leadsanimalhealth.com': '@leadsanimalhealth.com',
      'leh': '@leads-eh.com',
      'leh (@leads-eh.com)': '@leads-eh.com',
      'leads-eh.com': '@leads-eh.com',
      '@leads-eh.com': '@leads-eh.com',
      'gpsci': '@gpsci.net',
      'gpsci (@gpsci.net)': '@gpsci.net',
      'gpci': '@gpsci.net',
      'gpsci.net': '@gpsci.net',
      '@gpsci.net': '@gpsci.net',
      'farmasee': '@farmasee.ph',
      'farmasee (@farmasee.ph)': '@farmasee.ph',
      'farmasee.ph': '@farmasee.ph',
      '@farmasee.ph': '@farmasee.ph',
      'farmex': '@leads-farmex.com',
      'farmex (@leads-farmex.com)': '@leads-farmex.com',
      'farmex corp': '@leads-farmex.com',
      'leads-farmex.com': '@leads-farmex.com',
      '@leads-farmex.com': '@leads-farmex.com',
      'mhc': '@malvedaholdings.com',
      'mhc (@malvedaholdings.com)': '@malvedaholdings.com',
      'malvedaholdings.com': '@malvedaholdings.com',
      '@malvedaholdings.com': '@malvedaholdings.com',
      'mpdc': '@malvedaproperties.com',
      'mpdc (@malvedaproperties.com)': '@malvedaproperties.com',
      'malvedaproperties.com': '@malvedaproperties.com',
      '@malvedaproperties.com': '@malvedaproperties.com',
      'ltc': '@leadstech-corp.com',
      'ltc (@leadstech-corp.com)': '@leadstech-corp.com',
      'leadstech-corp.com': '@leadstech-corp.com',
      '@leadstech-corp.com': '@leadstech-corp.com',
      'lingap': '@lingapleads.org',
      'lingap (@lingapleads.org)': '@lingapleads.org',
      'lingapleads.org': '@lingapleads.org',
      '@lingapleads.org': '@lingapleads.org',
      'lav': '@leadsav.com',
      'lav (@leadsav.com)': '@leadsav.com',
      'leadsav.com': '@leadsav.com',
      '@leadsav.com': '@leadsav.com',
      'pcc': '@primestocks.ph',
      'pcc (@primestocks.ph)': '@primestocks.ph',
      'primestocks.ph': '@primestocks.ph',
      '@primestocks.ph': '@primestocks.ph'
    };
    return companyAliases[normalized] || normalized;
  }
  function getDeptOptionsForCompany(companyValue) {
    if (typeof window !== 'undefined' && window.TM_FORCE_LAPC_DEPARTMENTS === true) return lapcDeptOptions;
    return normalizeCompanyValue(companyValue) === '@leadsagri.com' ? lapcDeptOptions : [];
  }
  function preferredDeptValueForCompany(selectedValue, companyValue) {
    var raw = selectedValue == null ? '' : String(selectedValue).trim();
    var isLapcCompany = normalizeCompanyValue(companyValue) === '@leadsagri.com' || (typeof window !== 'undefined' && window.TM_FORCE_LAPC_DEPARTMENTS === true);
    if (raw.toLowerCase() === 'no departments available') {
      raw = '';
    }
    if (!raw) return isLapcCompany ? '' : '';
    var options = getDeptOptionsForCompany(companyValue);
    for (var i = 0; i < options.length; i++) {
      if (String(options[i].value).toLowerCase() === raw.toLowerCase()) return String(options[i].value);
    }
    var deptKey = deptKeyFromValue(raw);
    if (!isLapcCompany) return '';
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
    return isLapcCompany ? raw : '';
  }
  function buildDeptOptionsHtml(companyValue, selectedValue) {
    var normalizedCompany = normalizeCompanyValue(companyValue);
    var isLapcCompany = normalizedCompany === '@leadsagri.com' || (typeof window !== 'undefined' && window.TM_FORCE_LAPC_DEPARTMENTS === true);
    if (!isLapcCompany) {
      return '                  <option value="" selected>No departments available</option>';
    }
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
      var hiddenValue = isLapcCompany ? selectedValue : '';

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
        hiddenMirror.value = hiddenValue;
      } else if (hiddenMirror) {
        hiddenMirror.parentNode.removeChild(hiddenMirror);
      }
    }
    var initialPreferredDept = deptEl.value || (data && (data.assigned_department || data.assigned_group)) || '';
    syncDeptOptions(initialPreferredDept);
    syncDeptAvailability(initialPreferredDept);
    companyEl.addEventListener('change', function () {
      var normalizedCompany = normalizeCompanyValue(companyEl.value);
        var nextPreferred = normalizedCompany === '@leadsagri.com' ? '' : '';
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
    var assignedCompanyValue = normalizeCompanyValue(data.assigned_company || '') || String(data.assigned_company || '');
    var deptOptionsHtml = buildDeptOptionsHtml(assignedCompanyValue, data.assigned_department || data.assigned_group || '');
    var noteValue = data && data.admin_note != null ? String(data.admin_note) : '';
    var trimmedNoteValue = noteValue.trim();
    var requesterAdminNoteHtml = (isRequesterPOV && trimmedNoteValue !== '')
      ? (
        '      <div class="tm-card tm-card-admin-notes"><div class="tm-card-header"><div class="tm-card-header-actions"><span class="tm-card-title">Action Taken/Comments</span>' + (hideRequesterAdminChatButton ? '' : ('<button type="button" class="tm-inline-chat-btn" onclick="TMTicketModal.openConversation(' + String(data.id) + ')">Chat with Admin</button>')) + '</div></div><div class="tm-card-body">' +
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
    var sapHeaderClass = isSapTicket(data, data && data.description ? data.description : '') ? ' tm-sap-header' : '';
    return '' +
      '<div class="tm-header' + sapHeaderClass + '">' +
      '  <div class="tm-header-left">' +
      '    <div class="tm-title">' + escapeHtml(getDisplaySubject(data)) + '</div>' +
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
      '        <div class="tm-info-label">DEPARTMENT</div><div class="tm-info-value">' + escapeHtml(dashIfUnknown(data.department)) + '</div>' +
      '        <div class="tm-info-label">CREATED AT</div><div class="tm-info-value">' + (data.created_at ? formatTimelineTime(data.created_at) : '-') + '</div>' +
      '        <div class="tm-info-label">LAST UPDATED</div><div class="tm-info-value">' + (data.updated_at ? formatTimelineTime(data.updated_at) : '-') + '</div>' +
      '        <div class="tm-info-label">ASSIGNED TO</div><div class="tm-info-value">' + buildAssignedTargetHtml(data) + '</div>' +
      '      </div></div></div>' +
      '      <div class="tm-card tm-card-ticket-activity"><div class="tm-card-header"><span class="tm-card-title">Ticket Activity</span></div><div class="tm-card-body">' + renderTimeline(data) + '</div></div>' +
      '    </div>' +
      '    <div class="tm-desc-col">' +
      requesterAdminNoteHtml +
      '      ' + renderHrRequestDetailsCard(data) +
      '      ' + renderDescriptionCard(data) +
      '      ' + renderHrAttachmentCards(data) +
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
      '          <label class="tm-control-label">Ticket Recipient</label>' +
      '          <div class="tm-select-wrapper">' +
      '            <select class="tm-select tm-dept-select" name="assigned_company">' +
      ( !assignedCompanyValue ? '                  <option value="" disabled selected hidden>Select Recipient</option>' : '' ) +
      ( assignedCompanyValue && ['@gpsci.net','@farmasee.ph','@leads-eh.com','@leads-farmex.com','@leadsagri.com','@leadsanimalhealth.com','@leadsav.com','@malvedaholdings.com','@malvedaproperties.com','@leadstech-corp.com','@lingapleads.org','@primestocks.ph'].indexOf(String(assignedCompanyValue).toLowerCase()) === -1
          ? ('                  <option value="' + escapeHtml(assignedCompanyValue) + '" selected>' + escapeHtml(assignedCompanyValue) + '</option>')
          : '' ) +
      '                  <option value="@gpsci.net" ' + (String(assignedCompanyValue || '').toLowerCase() === '@gpsci.net' ? 'selected' : '') + '>GPSCI (@gpsci.net)</option>' +
      '                  <option value="@farmasee.ph" ' + (String(assignedCompanyValue || '').toLowerCase() === '@farmasee.ph' ? 'selected' : '') + '>FARMASEE (@farmasee.ph)</option>' +
      '                  <option value="@leads-eh.com" ' + (String(assignedCompanyValue || '').toLowerCase() === '@leads-eh.com' ? 'selected' : '') + '>LEH (@leads-eh.com)</option>' +
      '                  <option value="@leads-farmex.com" ' + (String(assignedCompanyValue || '').toLowerCase() === '@leads-farmex.com' ? 'selected' : '') + '>FARMEX (@leads-farmex.com)</option>' +
      '                  <option value="@leadsagri.com" ' + (String(assignedCompanyValue || '').toLowerCase() === '@leadsagri.com' ? 'selected' : '') + '>LAPC (@leadsagri.com)</option>' +
      '                  <option value="@leadsanimalhealth.com" ' + (String(assignedCompanyValue || '').toLowerCase() === '@leadsanimalhealth.com' ? 'selected' : '') + '>LAH (@leadsanimalhealth.com)</option>' +
      '                  <option value="@leadsav.com" ' + (String(assignedCompanyValue || '').toLowerCase() === '@leadsav.com' ? 'selected' : '') + '>LAV (@leadsav.com)</option>' +
      '                  <option value="@malvedaholdings.com" ' + (String(assignedCompanyValue || '').toLowerCase() === '@malvedaholdings.com' ? 'selected' : '') + '>MHC (@malvedaholdings.com)</option>' +
      '                  <option value="@malvedaproperties.com" ' + (String(assignedCompanyValue || '').toLowerCase() === '@malvedaproperties.com' ? 'selected' : '') + '>MPDC (@malvedaproperties.com)</option>' +
      '                  <option value="@leadstech-corp.com" ' + (String(assignedCompanyValue || '').toLowerCase() === '@leadstech-corp.com' ? 'selected' : '') + '>LTC (@leadstech-corp.com)</option>' +
      '                  <option value="@lingapleads.org" ' + (String(assignedCompanyValue || '').toLowerCase() === '@lingapleads.org' ? 'selected' : '') + '>LINGAP (@lingapleads.org)</option>' +
      '                  <option value="@primestocks.ph" ' + (String(assignedCompanyValue || '').toLowerCase() === '@primestocks.ph' ? 'selected' : '') + '>PCC (@primestocks.ph)</option>' +
      '            </select>' +
      '          </div>' +
      '        </div>' +
      '        <div class="tm-field">' +
      '          <label class="tm-control-label tm-control-label-department">' + deptLabelHtml + '</label>' +
      '          <div class="tm-select-wrapper">' +
      '            <select class="tm-select tm-dept-select" name="assigned_department">' +
      deptOptionsHtml +
      '            </select>' +
      '          </div>' +
      '        </div>' +
      '      </div>' +
      '      <div class="tm-note-group">' +
      '        <div class="tm-note-label">Action Taken/Comments</div>' +
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
    var subject = getDisplaySubject(safe);
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
      '      ' + renderHrRequestDetailsCard(safe) +
      '      ' + renderDescriptionCard(safe) +
      '      ' + renderHrAttachmentCards(safe) +
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
      '    <button class="modal-close" onclick="TMTicketModal.closeChatModal()">Ã—</button>' +
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
      '      <input type="file" id="chatModalAttachmentInput" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" style="display:none;">' +
      '      <button id="chatModalAttachBtn" class="ticket-chat-attach" type="button" title="Attach file"><i class="fas fa-paperclip"></i></button>' +
      '      <input type="text" id="chatModalInput" class="ticket-chat-input" placeholder="Type a message..." onkeypress="if(event.key===\'Enter\') TMTicketModal.sendChatModalMessage()">' +
      '      <span id="chatModalAttachmentName" class="tm-chat-attachment-selected"></span>' +
      '      <button id="chatModalSendBtn" class="ticket-chat-send" type="button" onclick="TMTicketModal.sendChatModalMessage()"><i class="fas fa-paper-plane"></i></button>' +
      '    </div>' +
      '  </div>' +
      '</div>';
    document.body.appendChild(el);
    var attachBtn = qs('chatModalAttachBtn');
    var attachInput = qs('chatModalAttachmentInput');
    if (attachBtn && attachInput) {
      attachBtn.addEventListener('click', function () {
        if (!attachBtn.disabled) attachInput.click();
      });
      attachInput.addEventListener('change', function () {
        var file = attachInput.files && attachInput.files[0] ? attachInput.files[0] : null;
        setChatModalAttachment(file);
      });
    }
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
    return parts.filter(function (x) { return x && String(x).trim() !== ''; }).join(' â€¢ ');
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
    var attachBtn = qs('chatModalAttachBtn');
    var attachInput = qs('chatModalAttachmentInput');
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
    if (attachBtn) attachBtn.disabled = !allowed;
    if (attachInput) attachInput.disabled = !allowed;
    if (!allowed) {
      if (attachInput) attachInput.value = '';
      setChatModalAttachment(null);
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
  function loadChatModalMeta(ticketId, silentRefresh) {
    if (silentRefresh !== true) {
      setChatComposerState(false, 'Checking ticket handler...');
      setChatModalMetaHtml('<span class="chat-meta-loading">Loading detailsâ€¦</span>');
    }
    fetch('get_ticket_details.php?id=' + ticketId)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || data.error) return;
        lastTicketMeta = {
          id: data && data.id != null ? data.id : ticketId,
          subject: getDisplaySubject(data)
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
            (assigneeHtml ? ('<span class="chat-meta-dot">â€¢</span>' + assigneeHtml) : '') +
            (handlerParts.length ? ('<span class="chat-meta-dot">â€¢</span><span class="chat-meta-details">' + escapeHtml(handlerParts.join(' â€¢ ')) + '</span>') : '')
          );
          return;
        }
        var adminParts = handlerParts.slice();
        var isLockedForViewer = data && data.can_chat !== true && !!data.assigned_to_name;
        if (!adminParts.length && data.status === 'Open') adminParts.push('Waiting for IT');
        setChatModalMetaHtml(
          headerHtml +
          (assigneeHtml ? ('<span class="chat-meta-dot">â€¢</span>' + assigneeHtml) : '') +
          (isLockedForViewer ? '' : (adminParts.length ? ('<span class="chat-meta-dot">â€¢</span><span class="chat-meta-details">' + escapeHtml(adminParts.join(' â€¢ ')) + '</span>') : '')) +
          (isLockedForViewer ? '' : (requesterMeta.length ? ('<span class="chat-meta-dot">â€¢</span><span class="chat-meta-details">' + escapeHtml(requesterName + ' â€¢ ' + requesterMeta.join(' â€¢ ')) + '</span>') : ''))
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
              (rest.length ? ('<span class="chat-meta-dot">â€¢</span><span class="chat-meta-details">' + escapeHtml(rest.join(' â€¢ ')) + '</span>') : '')
            );
          } else {
            setChatModalMetaHtml('<span class="chat-meta-with">Chat with <span class="chat-meta-name">Support</span></span>');
          }
        } else {
          // Admin/Assigned POV: show requester and their details, keep assigned context compact
          setChatModalMetaHtml(
            '<span class="chat-meta-with">Chat with <span class="chat-meta-name">' + escapeHtml(requesterName) + '</span></span>' +
            (requesterMeta.length ? ('<span class="chat-meta-dot">â€¢</span><span class="chat-meta-details">' + escapeHtml(requesterMeta.join(' â€¢ ')) + '</span>') : '') +
            (assignedParts.length ? ('<span class="chat-meta-dot">â€¢</span><span class="chat-meta-details">Assigned: ' + escapeHtml(assignedParts.join(' â€¢ ')) + '</span>') : '')
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
    loadChatModalMeta(ticketId, false);
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
    loadChatModalMeta(ticketId, true);
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
      var ticketIdEl = qs('chatModalTicketId');
      var ticketId = ticketIdEl ? String(ticketIdEl.value || '') : '';
      var actionsNode = createMessageActionsNode(msg, ticketId, function () {
        loadTicketMessages(ticketId, false);
        loadConversationsAndMaybeSelect();
      });
      if (actionsNode) bubble.appendChild(actionsNode);
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
      if (msg && msg.message) {
        var contentDiv = document.createElement('div');
        contentDiv.textContent = msg.message;
        bubble.appendChild(contentDiv);
      }
      var attachmentNode = createMessageAttachmentNode(msg && msg.attachment ? msg.attachment : null);
      if (attachmentNode) bubble.appendChild(attachmentNode);
      var timeDiv = document.createElement('div');
      timeDiv.classList.add('chat-time');
      timeDiv.textContent = msg.created_at;
      bubble.appendChild(timeDiv);
      container.appendChild(bubble);
    });
    if (scrollBottom || isNearBottom) container.scrollTop = container.scrollHeight;
  }
  function sendChatModalMessage() {
    var input = qs('chatModalInput');
    var ticketIdEl = qs('chatModalTicketId');
    var attachInput = qs('chatModalAttachmentInput');
    if (!input || !ticketIdEl) return;
    if (input.disabled || input.readOnly) return false;
    var message = input.value.trim();
    var btn = qs('chatModalSendBtn');
    var hasAttachment = !!chatModalAttachmentFile;
    if (!message && !hasAttachment) return;
    if (btn && btn.disabled) return;
    var ticketId = String(ticketIdEl.value || '');
    if (btn) btn.disabled = true;
    var formData = new FormData();
    formData.append('ticket_id', ticketId);
    formData.append('message', message);
    if (chatModalAttachmentFile) formData.append('attachment', chatModalAttachmentFile);
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
          input.value = '';
          if (attachInput) attachInput.value = '';
          setChatModalAttachment(null);
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
  function ensureMessengerEditExists() {
    if (qs('tmMessengerEdit')) return;
    var dialog = document.createElement('div');
    dialog.id = 'tmMessengerEdit';
    dialog.className = 'tm-messenger-edit-overlay';
    dialog.style.position = 'fixed';
    dialog.style.inset = '0';
    dialog.style.zIndex = '2147483647';
    dialog.style.display = 'none';
    dialog.style.alignItems = 'center';
    dialog.style.justifyContent = 'center';
    dialog.innerHTML =
      '<div class="tm-messenger-edit-box" role="dialog" aria-modal="true" aria-labelledby="tmMessengerEditTitle">' +
      '  <div class="tm-messenger-edit-title" id="tmMessengerEditTitle">Edit message</div>' +
      '  <textarea id="tmMessengerEditInput" class="tm-messenger-edit-input" rows="4" placeholder="Edit message..."></textarea>' +
      '  <div class="tm-messenger-edit-actions">' +
      '    <button type="button" class="tm-messenger-edit-btn tm-messenger-edit-cancel" id="tmMessengerEditCancel">Cancel</button>' +
      '    <button type="button" class="tm-messenger-edit-btn tm-messenger-edit-save" id="tmMessengerEditSave">Save</button>' +
      '  </div>' +
      '</div>';
    document.body.appendChild(dialog);
    dialog.addEventListener('click', function (e) {
      if (e.target === dialog) hideMessengerMessageEditor();
    });
    var cancelBtn = qs('tmMessengerEditCancel');
    var saveBtn = qs('tmMessengerEditSave');
    var input = qs('tmMessengerEditInput');
    var box = dialog.querySelector('.tm-messenger-edit-box');
    if (cancelBtn) cancelBtn.addEventListener('click', hideMessengerMessageEditor);
    if (box) {
      box.style.position = 'relative';
      box.style.zIndex = '2147483647';
    }
    if (saveBtn) {
      saveBtn.addEventListener('click', function () {
        var ctx = messengerEditSubmitAction;
        if (!ctx || !input) return;
        var updated = String(input.value || '').trim();
        if (updated === String(ctx.original || '').trim()) {
          hideMessengerMessageEditor();
          return;
        }
        if (!updated && !ctx.hasAttachment) {
          showMessengerConfirm({
            title: 'Edit Failed',
            message: 'Message cannot be empty.',
            confirmText: 'OK',
            hideCancel: true
          });
          return;
        }
        saveBtn.disabled = true;
        if (typeof ctx.onSubmit === 'function') {
          ctx.onSubmit(updated, function () {
            hideMessengerMessageEditor();
          }, function () {
            saveBtn.disabled = false;
          });
        } else {
          hideMessengerMessageEditor();
        }
      });
    }
    if (input) {
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
          e.preventDefault();
          hideMessengerMessageEditor();
          return;
        }
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
          e.preventDefault();
          var trigger = qs('tmMessengerEditSave');
          if (trigger) trigger.click();
        }
      });
    }
  }
  function hideMessengerMessageEditor() {
    var dialog = qs('tmMessengerEdit');
    var input = qs('tmMessengerEditInput');
    var saveBtn = qs('tmMessengerEditSave');
    if (dialog) dialog.style.display = 'none';
    if (input) input.value = '';
    if (saveBtn) saveBtn.disabled = false;
    messengerEditSubmitAction = null;
  }
  function showMessengerMessageEditor(options) {
    ensureMessengerEditExists();
    var dialog = qs('tmMessengerEdit');
    var input = qs('tmMessengerEditInput');
    var saveBtn = qs('tmMessengerEditSave');
    var title = qs('tmMessengerEditTitle');
    if (!dialog || !input || !saveBtn) return;
    dialog.style.zIndex = '2147483647';
    dialog.style.display = 'flex';
    var opts = options || {};
    if (title) title.textContent = opts.title || 'Edit message';
    input.value = String(opts.value || '');
    saveBtn.disabled = false;
    messengerEditSubmitAction = {
      original: String(opts.value || ''),
      hasAttachment: !!opts.hasAttachment,
      onSubmit: (typeof opts.onSubmit === 'function') ? opts.onSubmit : null
    };
    setTimeout(function () {
      try {
        input.focus();
        var length = input.value.length;
        input.setSelectionRange(length, length);
      } catch (e) { }
    }, 0);
  }
  function ensureMessengerModalExists() {
    if (qs('tmMessengerModal')) return;

    if (!document.getElementById('tmMessengerStyles')) {
      var style = document.createElement('style');
      style.id = 'tmMessengerStyles';
      style.textContent =
        '.tm-messenger-overlay{position:fixed;inset:0;background:rgba(15,23,42,.45);display:none;align-items:center;justify-content:center;z-index:9999;padding:18px;}' +
        '.tm-messenger-confirm-overlay{position:fixed;inset:0;background:rgba(15,23,42,.52);display:none;align-items:center;justify-content:center;z-index:2147483646;padding:20px;}' +
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
        '.tm-messenger-edit-overlay{position:fixed;inset:0;background:rgba(15,23,42,.58);display:none;align-items:center;justify-content:center;z-index:2147483647;padding:18px;backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);}' +
        '.tm-messenger-edit-box{width:min(520px,92vw);background:#ffffff;border:1px solid #dbe7df;border-radius:20px;box-shadow:0 28px 72px rgba(15,23,42,.24);padding:22px 22px 18px;display:flex;flex-direction:column;gap:14px;}' +
        '.tm-messenger-edit-title{font-size:18px;font-weight:900;color:#0f172a;line-height:1.2;letter-spacing:-.02em;}' +
        '.tm-messenger-edit-input{width:100%;min-height:128px;resize:vertical;border:1.5px solid #dbe3ec;border-radius:14px;padding:14px 16px;font-size:14px;color:#0f172a;outline:none;background:#ffffff;line-height:1.55;font-family:inherit;box-shadow:inset 0 1px 2px rgba(15,23,42,.04);appearance:none;-webkit-appearance:none;}' +
        '.tm-messenger-edit-input:focus{border-color:#86efac;box-shadow:0 0 0 4px rgba(34,197,94,.12);}' +
        '.tm-messenger-edit-actions{display:flex;justify-content:flex-end;align-items:center;gap:10px;flex-wrap:wrap;}' +
        '.tm-messenger-edit-btn{appearance:none;-webkit-appearance:none;border:none;border-radius:12px;padding:10px 16px;font-size:14px;font-weight:800;cursor:pointer;min-width:96px;line-height:1;}' +
        '.tm-messenger-edit-cancel{background:#f1f5f9;color:#334155;border:1px solid #e2e8f0;}' +
        '.tm-messenger-edit-cancel:hover{background:#e2e8f0;}' +
        '.tm-messenger-edit-save{background:#166534;color:#ffffff;box-shadow:0 12px 22px rgba(22,101,52,.18);}' +
        '.tm-messenger-edit-save:hover{background:#14532d;}' +
        '.tm-messenger-edit-save:disabled{opacity:.65;cursor:not-allowed;box-shadow:none;}' +
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
        '.tm-messenger-item.locked-ticket{border-color:#e2e8f0;background:#ffffff;}' +
        '.tm-messenger-item.active-locked{border-color:#22c55e;background:#e8f8ee;box-shadow:0 10px 22px rgba(34,197,94,.12);}' +
        '.tm-messenger-item.unread-chat{background:#fff;border-left:1px solid #e5e7eb;}' +
        '.tm-messenger-item.unread-chat .tm-messenger-item-subject{font-weight:800;}' +
        '.tm-messenger-item-top{display:flex;align-items:center;justify-content:space-between;gap:10px;}' +
        '.tm-messenger-item-subject{font-size:13px;font-weight:800;color:#0f172a;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}' +
        '.tm-messenger-item-right{display:flex;align-items:center;gap:8px;flex:0 0 auto;}' +
        '.tm-messenger-item-time{font-size:11px;font-weight:700;color:#64748b;flex:0 0 auto;}' +
        '.unread-badge{background:#22c55e;color:#ffffff;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:900;line-height:1;display:inline-flex;align-items:center;justify-content:center;min-width:20px;}' +
        '.tm-messenger-item-preview{font-size:12px;color:#475569;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}' +
        '.tm-messenger-item-preview.locked{display:flex;align-items:center;gap:6px;color:#64748b;font-weight:700;}' +
        '.tm-messenger-item-preview .lock-icon{display:inline-flex;align-items:center;justify-content:center;font-size:12px;opacity:.9;}' +
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
        '.tm-messenger-compose{border-top:1px solid #e5e7eb;padding:12px;background:#fff;display:flex;gap:10px;align-items:center;flex-wrap:nowrap;position:relative;padding-bottom:64px;}' +
        '.tm-messenger-compose input{flex:1 1 auto;min-width:0;border:1px solid #e5e7eb;border-radius:12px;padding:12px 14px;font-size:14px;outline:none;background:#fff;}' +
        '.tm-messenger-compose input:focus{border-color:#22c55e;box-shadow:0 0 0 4px rgba(34,197,94,.12);}' +
        '.tm-messenger-compose input:disabled{background:#f8fafc;border-color:#dbe3ec;color:#64748b;box-shadow:none;cursor:not-allowed;opacity:.8;}' +
        '.tm-messenger-attach{flex:0 0 auto;width:46px;height:46px;border:none;border-radius:14px;background:#f1f5f9;color:#475569;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:inset 0 0 0 1px #e2e8f0;}' +
        '.tm-messenger-attach:hover{background:#e2e8f0;color:#0f172a;}' +
        '.tm-messenger-attach:disabled{background:#e2e8f0;color:#94a3b8;cursor:not-allowed;box-shadow:none;}' +
        '.tm-chat-attachment-selected{position:absolute;left:68px;right:98px;bottom:8px;min-height:46px;display:none;}' +
        '.tm-chat-attachment-selected.has-file{display:block;}' +
        '.tm-selected-attachment{display:flex;align-items:center;gap:10px;width:100%;min-height:46px;border:1px solid #dbe3ec;border-radius:14px;background:#f8fafc;box-shadow:0 6px 14px rgba(15,23,42,.06);padding:5px 8px 5px 6px;}' +
        '.tm-selected-attachment-thumb{width:34px;height:34px;border-radius:10px;border:1px solid #e2e8f0;object-fit:cover;display:block;flex:0 0 auto;}' +
        '.tm-selected-attachment-file-icon{width:34px;height:34px;border-radius:10px;background:#e2e8f0;color:#334155;display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;}' +
        '.tm-selected-attachment-meta{min-width:0;flex:1 1 auto;display:flex;flex-direction:column;gap:1px;}' +
        '.tm-selected-attachment-name{color:#0f172a;font-size:12px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}' +
        '.tm-selected-attachment-size{color:#64748b;font-size:11px;font-weight:700;}' +
        '.tm-selected-attachment-remove{width:28px;height:28px;border:none;border-radius:999px;background:#e2e8f0;color:#334155;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;flex:0 0 auto;}' +
        '.tm-selected-attachment-remove:hover{background:#fecaca;color:#b91c1c;}' +
        '.tm-chat-attachment{display:flex;flex-direction:column;gap:8px;margin-top:2px;}' +
        '.tm-chat-attachment-link{display:inline-flex;align-items:center;gap:10px;color:inherit;text-decoration:none;max-width:100%;}' +
        '.tm-chat-attachment-button{appearance:none;-webkit-appearance:none;padding:0;border:none;background:transparent;cursor:zoom-in;position:relative;z-index:1;}' +
        '.tm-chat-attachment-icon{width:34px;height:34px;border-radius:12px;background:rgba(255,255,255,.18);display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;}' +
        '.tm-messenger-overlay .chat-bubble.other .tm-chat-attachment-icon{background:#e2e8f0;color:#334155;}' +
        '.tm-chat-attachment-name{font-size:13px;font-weight:700;line-height:1.35;word-break:break-word;}' +
        '.tm-chat-attachment-image{display:block;max-width:min(260px,100%);max-height:220px;border-radius:14px;border:1px solid rgba(148,163,184,.28);object-fit:cover;background:#fff;cursor:zoom-in;pointer-events:auto;position:relative;z-index:1;}' +
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
          '.tm-messenger-compose{display:flex;gap:10px;padding:12px;border-top:1px solid #eee;background:#fff;position:sticky;bottom:0;flex-wrap:nowrap;padding-bottom:62px;}' +
          '.tm-messenger-compose input{flex:1 1 auto;min-width:0;border-radius:12px;padding:10px 12px;border:1px solid #ddd;min-height:44px;}' +
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
        '.tm-messenger-overlay.employee-style .tm-messenger-item.locked-ticket{border-color:#e2e8f0;background:#ffffff;box-shadow:0 6px 14px rgba(15,23,42,.04);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-item.active-locked{border-color:#d9efe0;background:linear-gradient(180deg,#fcfefd 0%,#f4fbf6 100%);box-shadow:inset 4px 0 0 #22a55a,0 10px 22px rgba(34,197,94,.10);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-item.active .tm-messenger-item-subject{color:#0f172a;font-weight:700;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-item.active .tm-messenger-item-preview{color:#475569;font-weight:500;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-item.unread-chat{border-color:#e7edf3;background:#fff;box-shadow:0 6px 14px rgba(15,23,42,.04);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-item.unread-chat.active{border-color:#d9efe0;background:linear-gradient(180deg,#fcfefd 0%,#f4fbf6 100%);box-shadow:inset 4px 0 0 #22a55a,0 10px 22px rgba(34,197,94,.10);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-item.unread-chat .tm-messenger-item-subject{color:#0f172a;font-weight:700;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-item.unread-chat .tm-messenger-item-preview{color:#64748b;font-weight:500;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-item.unread-chat .tm-messenger-item-time{color:#475569;font-weight:800;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-item-subject{font-size:15px;font-weight:700;color:#0f172a;letter-spacing:-.02em;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-item-preview{margin-top:7px;font-size:13px;color:#64748b;line-height:1.42;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-item-preview.locked{display:flex;align-items:center;gap:7px;color:#475569;font-weight:700;}' +
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
        '.tm-messenger-overlay.employee-style .chat-bubble{position:relative;overflow:visible;display:flex;flex-direction:column;max-width:min(66%,420px);padding:13px 15px;border-radius:20px;border:1px solid #e6edf3;box-shadow:0 10px 24px rgba(15,23,42,.06);gap:6px;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.other{background:#ffffff;color:#0f172a;border-bottom-left-radius:10px;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.me{background:#174d1b;color:#fff;border-color:#174d1b;border-bottom-right-radius:10px;box-shadow:0 12px 28px rgba(23,77,27,.22);}' +
        '.tm-messenger-overlay.employee-style .tm-msg-actions{position:absolute;top:50%;z-index:12;opacity:1;pointer-events:auto;transform:translateY(-50%);}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.other .tm-msg-actions{right:-38px;left:auto;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.me .tm-msg-actions{left:-38px;right:auto;}' +
        '.tm-messenger-overlay.employee-style .tm-msg-actions-toggle{appearance:none;-webkit-appearance:none;width:28px;height:28px;border:none;border-radius:10px;background:#ffffff;color:#475569;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 8px 18px rgba(15,23,42,.14);transition:background .18s ease,color .18s ease,box-shadow .18s ease;}' +
        '.tm-messenger-overlay.employee-style .tm-msg-actions-toggle:hover{background:#eef2f7;color:#0f172a;}' +
        '.tm-messenger-overlay.employee-style .tm-msg-actions-menu{position:absolute;top:50%;min-width:132px;background:#ffffff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 18px 34px rgba(15,23,42,.16);display:none;padding:8px;transform:translateY(-50%);}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.other .tm-msg-actions-menu{left:36px;right:auto;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.me .tm-msg-actions-menu{right:36px;left:auto;}' +
        '.tm-messenger-overlay.employee-style .tm-msg-actions-menu.show{display:flex;flex-direction:column;gap:4px;opacity:1;pointer-events:auto;}' +
        '.tm-messenger-overlay.employee-style .tm-msg-actions-item{appearance:none;-webkit-appearance:none;width:100%;border:none;background:transparent;border-radius:10px;padding:10px 12px;text-align:left;font-size:13px;font-weight:700;color:#334155;cursor:pointer;white-space:nowrap;line-height:1.2;display:block;}' +
        '.tm-messenger-overlay.employee-style .tm-msg-actions-item:hover{background:#f8fafc;}' +
        '.tm-messenger-overlay.employee-style .tm-msg-actions-item.danger{color:#dc2626;}' +
        '.tm-messenger-overlay.employee-style .tm-msg-actions-item.danger:hover{background:#fef2f2;}' +
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
        '.tm-messenger-overlay.employee-style .tm-messenger-compose{padding:14px 14px 16px;border-top:1px solid #e5e7eb;background:#fff;gap:12px;flex-wrap:nowrap;position:relative;padding-bottom:68px;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-compose input{flex:1 1 auto;min-width:0;border-radius:16px;border:1.5px solid #86efac;padding:13px 16px;font-size:14px;min-height:50px;box-shadow:0 0 0 4px rgba(34,197,94,.08);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-compose input:focus{border-color:#22c55e;box-shadow:0 0 0 5px rgba(34,197,94,.12);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-compose input:disabled{border-color:#dbe3ec;background:#f8fafc;color:#64748b;box-shadow:none;cursor:not-allowed;opacity:.8;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-attach{width:50px;height:50px;border-radius:16px;background:#f8fafc;color:#475569;box-shadow:inset 0 0 0 1px #dbe3ec;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-attach:hover{background:#eef2f7;color:#0f172a;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-attach:disabled{background:#e2e8f0;color:#94a3b8;box-shadow:none;}' +
        '.tm-messenger-overlay.employee-style .tm-chat-attachment-selected{left:76px;right:110px;bottom:8px;}' +
        '.tm-messenger-overlay.employee-style .tm-chat-attachment-image{max-width:min(280px,100%);border-radius:16px;box-shadow:0 8px 18px rgba(15,23,42,.08);}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.me .tm-chat-attachment-icon{background:rgba(255,255,255,.16);color:#fff;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.me .tm-chat-attachment-name{color:#fff;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.other .tm-chat-attachment-name{color:#0f172a;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-send{min-width:96px;min-height:50px;padding:0 20px;border-radius:16px;background:linear-gradient(180deg,#1f5f23 0%,#174d1b 100%);font-size:14px;letter-spacing:.01em;box-shadow:0 14px 26px rgba(23,77,27,.22);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-send:hover{background:linear-gradient(180deg,#205d24 0%,#154819 100%);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-send:disabled{background:#cbd5e1;color:#fff;box-shadow:none;cursor:not-allowed;opacity:1;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-empty{font-size:15px;color:#94a3b8;}' +
        '@media (max-width: 980px){.tm-messenger-overlay.employee-style{padding:10px}.tm-messenger-overlay.employee-style .tm-messenger-panel{width:min(94vw,940px);height:min(84vh,720px);border-radius:20px}.tm-messenger-overlay.employee-style .tm-messenger-left{width:300px;min-width:300px;max-width:300px}.tm-messenger-overlay.employee-style .tm-messenger-title-main{font-size:18px;font-weight:700}.tm-messenger-overlay.employee-style .chat-bubble{max-width:80%;}}' +
        '@media (max-width: 768px){.tm-messenger-overlay.employee-style{padding:0;align-items:flex-end}.tm-messenger-overlay.employee-style .tm-messenger-panel{height:88vh;border-radius:22px 22px 0 0}.tm-messenger-overlay.employee-style .tm-messenger-panel::before{content:none;display:none}.tm-messenger-overlay.employee-style .tm-messenger-left{width:100%;min-width:0;max-width:none;height:40%}.tm-messenger-overlay.employee-style .tm-messenger-right{height:60%}.tm-messenger-overlay.employee-style .tm-messenger-left-header{padding:20px 16px 10px}.tm-messenger-overlay.employee-style .tm-messenger-right-header{padding:20px 16px 12px}.tm-messenger-overlay.employee-style .tm-messenger-title-main{font-size:17px}.tm-messenger-overlay.employee-style .tm-messenger-search{padding:0 12px 10px}.tm-messenger-overlay.employee-style .tm-messenger-search::before{left:25px}.tm-messenger-overlay.employee-style .tm-messenger-filters{padding:0 12px 12px;gap:6px;overflow-x:auto;}.tm-messenger-overlay.employee-style .tm-messenger-filter-btn{padding:9px 12px;font-size:12px;border-radius:12px}.tm-messenger-overlay.employee-style .tm-messenger-messages{padding:16px 14px}.tm-messenger-overlay.employee-style .tm-messenger-compose{padding:12px 12px 14px;padding-bottom:64px}.tm-messenger-overlay.employee-style .tm-chat-attachment-selected{left:70px;right:100px}.tm-messenger-overlay.employee-style .tm-messenger-send{min-width:86px;min-height:48px;border-radius:15px;}}';
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
      '      <input type="file" id="tmMessengerAttachmentInput" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" style="display:none;">' +
      '      <button type="button" class="tm-messenger-attach" id="tmMessengerAttachBtn" aria-label="Attach file"><i class="fas fa-paperclip"></i></button>' +
      '      <input type="text" id="tmMessengerInput" placeholder="Type a message..." autocomplete="off" disabled>' +
      '      <span id="tmMessengerAttachmentName" class="tm-chat-attachment-selected"></span>' +
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
    var attachBtn = qs('tmMessengerAttachBtn');
    var attachInput = qs('tmMessengerAttachmentInput');
    if (attachBtn && attachInput) {
      attachBtn.addEventListener('click', function () {
        if (!attachBtn.disabled) attachInput.click();
      });
      attachInput.addEventListener('change', function () {
        var file = attachInput.files && attachInput.files[0] ? attachInput.files[0] : null;
        setMessengerAttachment(file);
      });
    }
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
      if (qs('tmMessengerEdit') && qs('tmMessengerEdit').style.display === 'flex') {
        if (e.key === 'Escape') hideMessengerMessageEditor();
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
    function getCurrentMessengerUser() {
      if (typeof window === 'undefined' || !window.TM_CURRENT_USER) return null;
      return window.TM_CURRENT_USER;
    }
    function isLockedConversation(conv) {
      if (!conv) return false;
      if (conv.can_chat === false) return true;
      if (conv.chat_locked_message && String(conv.chat_locked_message).trim() !== '') return true;

      var currentUser = getCurrentMessengerUser();
      if (!currentUser) return false;

      var currentEmail = String(currentUser.email || '').trim().toLowerCase();
      var currentName = String(currentUser.name || '').trim().toLowerCase();
      var requesterEmail = String(conv.requester_email || '').trim().toLowerCase();
      var assigneeName = String(conv.assigned_to_name || '').trim().toLowerCase();

      if (currentEmail && requesterEmail && currentEmail === requesterEmail) {
        return false;
      }
      if (currentName && assigneeName && currentName === assigneeName) {
        return false;
      }

      return !!assigneeName;
    }
    list.innerHTML = '';
    convs.forEach(function (c) {
      var unread = 0;
      if (c && c.unread_count != null) {
        unread = parseInt(String(c.unread_count), 10);
        if (isNaN(unread) || unread < 0) unread = 0;
      }
      var isCurrent = !!(messengerTicketId && String(c.id) === String(messengerTicketId));
      var isLockedByData = isLockedConversation(c);
      var isLocked = isLockedByData || !!(isCurrent && messengerPermissionState.canChat === false && !messengerPermissionState.isChecking);
      var hasVisibleMessage = !!(c && c.last_message && String(c.last_message).trim() !== '');
      // Never show unread badge if this conversation is locked/restricted or has no visible message preview.
      if (!hasVisibleMessage) unread = 0;
      if (isLocked) unread = 0;
      var previewText = isLocked
        ? "You can't message. This ticket is already assigned."
        : (c.last_message ? ((c.last_sender_name ? (String(c.last_sender_name) + ': ') : '') + String(c.last_message)) : 'No messages yet.');
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'tm-messenger-item' +
        (isLocked ? ' locked-ticket' : '') +
        ((!isLocked && unread > 0) ? ' unread-chat' : '') +
        (isCurrent ? (isLocked ? ' active-locked' : ' active') : '');
      btn.dataset.ticketId = String(c.id);
      btn.innerHTML =
        '<div class="tm-messenger-item-top">' +
        '  <div class="tm-messenger-item-subject" title="' + escapeHtml(c.subject) + '">#' + String(c.id).padStart(6, '0') + ' â€¢ ' + escapeHtml(c.subject) + '</div>' +
        '  <div class="tm-messenger-item-right">' +
        '    <div class="tm-messenger-item-time">' + escapeHtml(toRelative(c.last_message_time || c.ticket_created_at)) + '</div>' +
        (unread > 0 ? ('<span class="unread-badge">' + escapeHtml(String(unread)) + '</span>') : '') +
        '  </div>' +
        '</div>' +
        '<div class="tm-messenger-item-preview' + (isLocked ? ' locked' : '') + '">' +
          (isLocked
            ? '<span class="lock-icon"><i class="fas fa-lock"></i></span>'
            : '') +
          escapeHtml(previewText) +
        '</div>';
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
    if (title) title.textContent = conv ? ('#' + String(conv.id).padStart(6, '0') + ' â€¢ ' + String(conv.subject || '')) : 'Select a conversation';
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
              : ((rel ? ('<span class="tm-messenger-sub-sep">â€¢</span><span>' + escapeHtml(rel) + '</span>') : '') +
                (requesterEmail ? ('<span class="tm-messenger-sub-sep">â€¢</span><span>' + escapeHtml(requesterEmail) + '</span>') : '')));
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
    var attachBtn = qs('tmMessengerAttachBtn');
    var attachInput = qs('tmMessengerAttachmentInput');
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
    if (attachBtn) attachBtn.disabled = true;
    if (attachInput) attachInput.value = '';
    setMessengerAttachment(null);
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
      var ticketIdEl = qs('tmMessengerTicketId');
      var ticketId = ticketIdEl ? String(ticketIdEl.value || '') : '';
      var actionsNode = createMessageActionsNode(msg, ticketId, function () {
        loadMessengerMessages(ticketId, false);
        loadConversationsAndMaybeSelect();
      });
      if (actionsNode) bubble.appendChild(actionsNode);
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
      if (msg && msg.message) {
        var contentDiv = document.createElement('div');
        contentDiv.textContent = msg.message;
        bubble.appendChild(contentDiv);
      }
      var attachmentNode = createMessageAttachmentNode(msg && msg.attachment ? msg.attachment : null);
      if (attachmentNode) bubble.appendChild(attachmentNode);
      var timeDiv = document.createElement('div');
      timeDiv.classList.add('chat-time');
      timeDiv.textContent = formatChatTimeDisplay(msg.created_at);
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
    if (message) {
      if (typeof message === 'object' && message.message) {
        var contentDiv = document.createElement('div');
        contentDiv.textContent = message.message;
        bubble.appendChild(contentDiv);
      } else if (typeof message === 'string' && message) {
        var textDiv = document.createElement('div');
        textDiv.textContent = message;
        bubble.appendChild(textDiv);
      }
      if (typeof message === 'object' && message.attachment) {
        var attachmentNode = createMessageAttachmentNode(message.attachment);
        if (attachmentNode) bubble.appendChild(attachmentNode);
      }
    }
    var timeDiv = document.createElement('div');
    timeDiv.classList.add('chat-time');
    timeDiv.textContent = timeText || formatHHMM(new Date());
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
    var attachBtn = qs('tmMessengerAttachBtn');
    var attachInput = qs('tmMessengerAttachmentInput');
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
    if (attachBtn) attachBtn.disabled = !allowed;
    if (attachInput) attachInput.disabled = !allowed;
    if (!allowed) {
      if (attachInput) attachInput.value = '';
      setMessengerAttachment(null);
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
          subject: getDisplaySubject(data)
        };
        setMessengerComposerState(data && data.can_chat === true, data && data.chat_locked_message ? String(data.chat_locked_message) : '');

        var conv = Array.isArray(window.__tmConversations)
          ? window.__tmConversations.find(function (c) { return c && String(c.id) === String(ticketId); })
          : null;
        var headerConv = conv;
        if (!conv) {
          headerConv = {
            id: String(ticketId),
            subject: getDisplaySubject(data),
            status: data && data.status ? String(data.status) : '',
            requester_email: data && data.created_by_email ? String(data.created_by_email) : ''
          };
        }
        if (conv) {
          conv.subject = getDisplaySubject(data);
          if (data && data.status) conv.status = String(data.status);
          if (data && data.created_by_email) conv.requester_email = String(data.created_by_email);
          conv.assigned_to_name = data && data.assigned_to_name ? String(data.assigned_to_name) : '';
          conv.can_chat = data && data.can_chat === true;
          conv.chat_locked_message = data && data.chat_locked_message ? String(data.chat_locked_message) : '';
        } else if (headerConv) {
          headerConv.assigned_to_name = data && data.assigned_to_name ? String(data.assigned_to_name) : '';
          headerConv.can_chat = data && data.can_chat === true;
          headerConv.chat_locked_message = data && data.chat_locked_message ? String(data.chat_locked_message) : '';
        }
        messengerPermissionState.statusLabel = (headerConv && headerConv.status) ? headerConv.status : '';
        setMessengerHeader(headerConv);
        if (conv) {
          updateMessengerFilterButtons();
          renderConversations(qs('tmMessengerSearch') ? qs('tmMessengerSearch').value : '');
        }
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
    var attachInput = qs('tmMessengerAttachmentInput');
    if (!input || !ticketIdEl) return;
    var ticketId = String(ticketIdEl.value || '');
    var message = input.value.trim();
    if (!ticketId || (!message && !messengerAttachmentFile)) return;
    if (input.disabled || input.readOnly || messengerPermissionState.canChat !== true) return;
    if (btn && btn.disabled) return;
    if (btn) btn.disabled = true;
    var formData = new FormData();
    formData.append('ticket_id', ticketId);
    formData.append('message', message);
    if (messengerAttachmentFile) formData.append('attachment', messengerAttachmentFile);
    var t = getCsrfToken();
    if (t) formData.append('csrf_token', t);
    postJson('chat_send.php', formData)
      .then(function (data) {
        if (btn) btn.disabled = false;
        if (data && data.success) {
          input.value = '';
          if (attachInput) attachInput.value = '';
          setMessengerAttachment(null);
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
    ensureTicketModalExists();
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
    ensureTicketModalExists();
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

    var existing = Array.isArray(window.__tmConversations)
      ? window.__tmConversations.find(function (c) { return c && String(c.id) === String(ticketId); })
      : null;
    if (existing) {
      renderConversations(qs('tmMessengerSearch') ? qs('tmMessengerSearch').value : '');
      selectConversation(existing, true);
    } else {
      var subject = (lastTicketMeta && String(lastTicketMeta.id) === String(ticketId) && lastTicketMeta.subject)
        ? String(lastTicketMeta.subject)
        : 'Ticket';
      selectConversation({ id: String(ticketId), subject: subject }, true);
    }
    setTimeout(function () { loadConversationsAndMaybeSelect(); }, 0);
  }
  function closeMessengerChat() {
    var modal = qs('tmMessengerModal');
    if (modal) modal.style.display = 'none';
    hideMessengerMessageEditor();
    hideMessengerConfirm();
    messengerOpen = false;
    messengerReturnContext = null;
    stopMessenger();
  }
  function restoreMessengerAfterTicketClose() {
    if (!messengerReturnContext || !messengerReturnContext.ticketId) return;
    ensureTicketModalExists();
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
      var subject = (lastTicketMeta && String(lastTicketMeta.id) === String(ticketId) && lastTicketMeta.subject)
        ? String(lastTicketMeta.subject)
        : 'Ticket';
      selectConversation({ id: ticketId, subject: subject }, true);
    }
    setTimeout(function () { loadConversationsAndMaybeSelect(); }, 0);
  }
  function open(id, options) {
    ensureTicketModalExists();
    var modal = qs('ticketModal');
    var modalContent = qs('modalContent');
    if (!modal || !modalContent) return;
    modal.style.display = 'flex';
    setModalVariant(modalContent, 'default');
    modalContent.innerHTML = '<div style="padding:40px; text-align:center; color:#64748b;">Loading details...</div>';
    stopChat();
    ensureChatModalExists();
    fetch('get_ticket_details.php?id=' + id)
      .then(function (r) { return r.text(); })
      .then(function (text) { return parseTicketDetailsResponse(text); })
      .then(function (data) {
        if (data && data.error) {
          if (data.error_code === 'ticket_reassigned') {
            setModalVariant(modalContent, 'unavailable');
            modalContent.innerHTML = buildUnavailableHtml(data);
          } else {
            setModalVariant(modalContent, 'default');
            modalContent.innerHTML = '<div style="padding:40px; text-align:center; color:#ef4444;">' + escapeHtml(data.error) + '</div>';
          }
          return;
        }
        setModalVariant(modalContent, 'default');
        if (isSapTicket(data, data && data.description ? data.description : '')) {
          modalContent.classList.add('tm-sap-ticket-modal');
        }
        setCurrentTicketId(data && data.id != null ? data.id : id);
        lastTicketMeta = { id: data && data.id != null ? data.id : id, subject: getDisplaySubject(data) };
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
        setModalVariant(modalContent, 'default');
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
    stepHrAttachmentCategory: stepHrAttachmentCategory,
    stepSapDisplay: stepSapDisplay,
    viewImage: viewImage,
    closeImagePreview: closeImagePreview,
    getCurrentTicketId: getCurrentTicketId
  };
})(); 
