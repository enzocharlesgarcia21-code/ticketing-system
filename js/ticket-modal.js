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
  var messengerMessagesSignature = '';
  var messengerComposerSignature = '';
  var currentTicketId = null;
  var lastTicketMeta = null;
  var chatModalAttachmentFile = null;
  var messengerAttachmentFiles = [];
  var chatReplyContext = null;
  var messengerReplyContext = null;
  var chatTypingTimers = { chat: null, modal: null, messenger: null };
  var chatTypingLastSent = { chat: 0, modal: 0, messenger: 0 };
  var attachmentCategorySeq = 0;
  var sapDisplaySeq = 0;
  var CHAT_ATTACHMENT_MAX_BYTES = 10 * 1024 * 1024;
  var CHAT_ATTACHMENT_MAX_LABEL = '10 MB';
  var imagePreviewSources = [];
  var imagePreviewIndex = -1;
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
        '  <button type="button" class="preview-close" onclick="TMTicketModal.closeImagePreview(event)" aria-label="Close preview">X</button>' +
        '  <button type="button" class="preview-nav preview-prev" onclick="TMTicketModal.stepImagePreview(-1)" aria-label="Previous attachment"><i class="fas fa-chevron-left"></i></button>' +
        '  <img id="previewImage" src="" alt="Preview">' +
        '  <button type="button" class="preview-nav preview-next" onclick="TMTicketModal.stepImagePreview(1)" aria-label="Next attachment"><i class="fas fa-chevron-right"></i></button>' +
        '</div>';
      document.body.appendChild(imageModal);
    }
    ensureImagePreviewControls();

    if (!qs('filePreviewModal')) {
      var fileModal = document.createElement('div');
      fileModal.id = 'filePreviewModal';
      fileModal.className = 'file-preview-modal';
      fileModal.innerHTML =
        '<div class="file-preview-shell">' +
        '  <div class="file-preview-head">' +
        '    <div class="file-preview-title-wrap">' +
        '      <div id="filePreviewTitle" class="file-preview-title">Attachment</div>' +
        '      <a id="filePreviewDownload" class="file-preview-download" href="#" download><i class="fas fa-download"></i><span>Download</span></a>' +
        '    </div>' +
        '    <button type="button" class="file-preview-close" onclick="TMTicketModal.closeFilePreview()" aria-label="Close preview">X</button>' +
        '  </div>' +
        '  <iframe id="filePreviewFrame" class="file-preview-frame" src="" title="Attachment preview"></iframe>' +
        '</div>';
      document.body.appendChild(fileModal);
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
  function chatTypingTicketId(kind) {
    var el = null;
    if (kind === 'messenger') el = qs('tmMessengerTicketId');
    else if (kind === 'modal') el = qs('chatModalTicketId');
    else el = qs('chatTicketId');
    return el ? String(el.value || '') : '';
  }
  function postChatTyping(ticketId, action) {
    if (!ticketId) return Promise.resolve(null);
    var formData = new FormData();
    formData.append('ticket_id', String(ticketId));
    formData.append('action', String(action || 'status'));
    var t = getCsrfToken();
    if (t) formData.append('csrf_token', t);
    return postJson('chat_typing.php', formData).catch(function () { return null; });
  }
  function clearTypingTimer(kind) {
    if (chatTypingTimers[kind]) {
      clearTimeout(chatTypingTimers[kind]);
      chatTypingTimers[kind] = null;
    }
  }
  function clearOwnTyping(kind) {
    clearTypingTimer(kind);
    var ticketId = chatTypingTicketId(kind);
    chatTypingLastSent[kind] = 0;
    if (ticketId) postChatTyping(ticketId, 'clear');
  }
  function markOwnTyping(kind) {
    var ticketId = chatTypingTicketId(kind);
    if (!ticketId) return;
    var now = Date.now();
    if (!chatTypingLastSent[kind] || now - chatTypingLastSent[kind] > 1200) {
      chatTypingLastSent[kind] = now;
      postChatTyping(ticketId, 'update');
    }
    clearTypingTimer(kind);
    chatTypingTimers[kind] = setTimeout(function () {
      clearOwnTyping(kind);
    }, 2200);
  }
  function bindTypingInput(input, kind) {
    if (!input || input.dataset.tmTypingBound === '1') return;
    input.dataset.tmTypingBound = '1';
    input.addEventListener('input', function () {
      if (input.disabled || input.readOnly || String(input.value || '').trim() === '') {
        clearOwnTyping(kind);
        return;
      }
      markOwnTyping(kind);
    });
    input.addEventListener('blur', function () {
      clearOwnTyping(kind);
    });
  }
  function ensureChatTypingStyles() {
    if (!document || !document.head || document.getElementById('tmChatTypingStyles')) return;
    var style = document.createElement('style');
    style.id = 'tmChatTypingStyles';
    style.textContent =
      '.chat-typing-indicator{align-self:flex-start;display:inline-flex;align-items:flex-end;gap:12px;min-height:38px;margin:4px 0 12px 8px;box-sizing:border-box;}' +
      '.chat-typing-avatar{width:32px;height:32px;min-width:32px;border-radius:999px;background:var(--tm-typing-avatar-bg,#008a63);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:900;line-height:1;text-transform:uppercase;box-shadow:0 3px 8px rgba(15,23,42,.14),inset 0 0 0 1px rgba(255,255,255,.4);}' +
      '.chat-typing-bubble{height:34px;min-width:62px;padding:0 15px;border-radius:17px;background:#eaf6ee;display:inline-flex;align-items:center;justify-content:center;gap:7px;box-shadow:0 6px 14px rgba(15,23,42,.06);box-sizing:border-box;}' +
      '.chat-typing-bubble span{width:7px;height:7px;border-radius:999px;background:#16a34a;display:block;animation:tmTypingDot 1.15s infinite ease-in-out;}' +
      '.chat-typing-bubble span:nth-child(2){animation-delay:.16s;}' +
      '.chat-typing-bubble span:nth-child(3){animation-delay:.32s;}' +
      '@keyframes tmTypingDot{0%,80%,100%{opacity:.45;transform:translateY(0);}40%{opacity:1;transform:translateY(-2px);}}';
    document.head.appendChild(style);
  }
  function syncTypingIndicatorAvatar(container, indicator) {
    if (!container || !indicator) return;
    var avatar = indicator.querySelector('.chat-typing-avatar');
    if (!avatar) return;
    var bubbles = container.querySelectorAll('.chat-bubble.other.tm-has-letter-avatar');
    var source = bubbles.length ? bubbles[bubbles.length - 1] : null;
    var initials = source ? String(source.getAttribute('data-avatar') || '').trim() : '';
    var bg = source ? String(source.style.getPropertyValue('--tm-avatar-bg') || '').trim() : '';
    avatar.textContent = initials || 'U';
    indicator.style.setProperty('--tm-typing-avatar-bg', bg || '#008a63');
  }
  function setTypingIndicator(containerId, visible) {
    var container = qs(containerId);
    if (!container) return;
    var existing = container.querySelector('.chat-typing-indicator');
    if (!visible) {
      if (existing) existing.remove();
      return;
    }
    if (!existing) {
      ensureChatTypingStyles();
      existing = document.createElement('div');
      existing.className = 'chat-typing-indicator';
      existing.setAttribute('aria-label', 'Typing');
      existing.innerHTML = '<span class="chat-typing-avatar" aria-hidden="true">U</span><span class="chat-typing-bubble" aria-hidden="true"><span></span><span></span><span></span></span>';
      container.appendChild(existing);
    }
    syncTypingIndicatorAvatar(container, existing);
    var isNearBottom = container.scrollHeight - container.scrollTop - container.clientHeight < 120;
    if (isNearBottom) container.scrollTop = container.scrollHeight;
  }
  function refreshTypingIndicator(kind, containerId) {
    var ticketId = chatTypingTicketId(kind);
    if (!ticketId) {
      setTypingIndicator(containerId, false);
      return;
    }
    postChatTyping(ticketId, 'status').then(function (data) {
      setTypingIndicator(containerId, !!(data && data.typing));
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
  function renderLinkedText(text) {
    if (text == null || text === '') return '';
    var escaped = escapeHtml(String(text));
    var linked = escaped.replace(/((?:https?:\/\/|www\.)[^\s<]+)/gi, function (url) {
      var trimmed = url;
      var trailing = '';
      while (/[.,!?;:)]$/.test(trimmed)) {
        trailing = trimmed.slice(-1) + trailing;
        trimmed = trimmed.slice(0, -1);
      }
      var href = /^https?:\/\//i.test(trimmed) ? trimmed : ('https://' + trimmed);
      return '<a href="' + href + '" target="_blank" rel="noopener noreferrer">' + trimmed + '</a>' + trailing;
    });
    return linked.replace(/\n/g, '<br>');
  }
  function tmAvatarInitials(name) {
    var text = String(name || '').replace(/<[^>]*>/g, ' ').trim();
    if (!text) return '?';
    var parts = text.split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    if (parts.length === 1) return parts[0].charAt(0).toUpperCase();
    return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
  }
  function tmAvatarColor(name) {
    var palette = ['#1877f2', '#16a34a', '#9333ea', '#db2777', '#ea580c', '#0891b2', '#4f46e5', '#0f766e'];
    var seed = String(name || 'User');
    var hash = 0;
    for (var i = 0; i < seed.length; i++) hash = ((hash << 5) - hash) + seed.charCodeAt(i);
    return palette[Math.abs(hash) % palette.length];
  }
  function tmAvatarNode(name, extraClass) {
    var label = String(name || 'User').trim() || 'User';
    var avatar = document.createElement('span');
    avatar.className = 'tm-user-avatar ' + (extraClass || '');
    avatar.textContent = tmAvatarInitials(label);
    avatar.title = label;
    avatar.setAttribute('aria-label', label);
    avatar.style.background = tmAvatarColor(label);
    return avatar;
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
  function copyTextToClipboard(text) {
    var value = String(text == null ? '' : text);
    if (!value) return Promise.resolve(false);
    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
      return navigator.clipboard.writeText(value).then(function () { return true; }).catch(function () {
        return false;
      });
    }
    try {
      var area = document.createElement('textarea');
      area.value = value;
      area.setAttribute('readonly', 'readonly');
      area.style.position = 'fixed';
      area.style.opacity = '0';
      area.style.pointerEvents = 'none';
      document.body.appendChild(area);
      area.focus();
      area.select();
      var ok = document.execCommand('copy');
      document.body.removeChild(area);
      return Promise.resolve(!!ok);
    } catch (e) {
      return Promise.resolve(false);
    }
  }
  function messagePrimaryImageAttachment(msg) {
    var attachments = [];
    if (Array.isArray(msg && msg.attachments)) attachments = msg.attachments.slice();
    if (msg && msg.attachment) attachments.push(msg.attachment);
    for (var i = 0; i < attachments.length; i += 1) {
      if (chatAttachmentIsImage(attachments[i])) return attachments[i];
    }
    return null;
  }
  function buildReplyContext(msg) {
    if (!msg) return null;
    var senderName = '';
    if (msg.sender_name && String(msg.sender_name).trim() !== '') {
      senderName = String(msg.sender_name).trim();
    } else if (msg.is_me) {
      senderName = 'You';
    }
    var text = String(msg.message == null ? '' : msg.message).trim();
    var imageAttachment = messagePrimaryImageAttachment(msg);
    var previewText = '';
    if (text) {
      previewText = 'Replying to ' + (senderName ? ('@' + senderName) : 'message') + ': "' + text + '"';
    } else if (senderName && imageAttachment) {
      previewText = 'Replying to @' + senderName + ': attached image';
    } else if (imageAttachment) {
      previewText = 'Replying to attached image';
    }
    if (!previewText) return null;
    return {
      messageId: msg.id != null ? String(msg.id) : '',
      senderName: senderName,
      text: text,
      hasImageAttachment: !!imageAttachment,
      attachmentStoredName: imageAttachment && imageAttachment.stored_name ? String(imageAttachment.stored_name) : '',
      previewText: previewText
    };
  }
  function sameReplyContext(a, b) {
    if (!a || !b) return false;
    return String(a.messageId || '') === String(b.messageId || '')
      && String(a.senderName || '') === String(b.senderName || '')
      && String(a.text || '') === String(b.text || '')
      && String(a.attachmentStoredName || '') === String(b.attachmentStoredName || '');
  }
  function renderReplyPreview(surface) {
    var isMessenger = surface === 'messenger';
    var context = isMessenger ? messengerReplyContext : chatReplyContext;
    var previewEl = qs(isMessenger ? 'tmMessengerReplyPreview' : 'chatModalReplyPreview');
    var textEl = qs(isMessenger ? 'tmMessengerReplyPreviewText' : 'chatModalReplyPreviewText');
    var composeEl = qs(isMessenger ? 'tmMessengerCompose' : 'chatModalComposer');
    if (!previewEl || !textEl) return;
    if (!context) {
      previewEl.style.display = 'none';
      textEl.textContent = '';
      if (composeEl) composeEl.classList.remove('has-reply');
      return;
    }
    previewEl.style.display = 'flex';
    textEl.textContent = String(context.previewText || '');
    if (composeEl) composeEl.classList.add('has-reply');
  }
  function clearReplyContext(surface) {
    if (surface === 'messenger') {
      messengerReplyContext = null;
      renderReplyPreview('messenger');
      return;
    }
    chatReplyContext = null;
    renderReplyPreview('chat');
  }
  function copyImageAttachmentToClipboard(attachment) {
    var attachmentUrl = attachment && attachment.stored_name ? getChatAttachmentUrl(attachment.stored_name) : '';
    if (!attachmentUrl) return Promise.resolve(false);
    if (!(navigator.clipboard && typeof navigator.clipboard.write === 'function' && typeof window.ClipboardItem === 'function' && typeof fetch === 'function')) {
      window.open(attachmentUrl, '_blank', 'noopener');
      return Promise.resolve(true);
    }
    return fetch(attachmentUrl, { credentials: 'same-origin' })
      .then(function (response) {
        if (!response.ok) throw new Error('Unable to load image.');
        return response.blob();
      })
      .then(function (blob) {
        var type = blob.type || 'image/png';
        var item = new window.ClipboardItem((function () {
          var payload = {};
          payload[type] = blob;
          return payload;
        })());
        return navigator.clipboard.write([item]).then(function () { return true; });
      })
      .catch(function () {
        window.open(attachmentUrl, '_blank', 'noopener');
        return true;
      });
  }
  function focusReplyComposer(ticketId, msg) {
    var context = buildReplyContext(msg);
    if (!context) return;
    var messengerTicketIdEl = qs('tmMessengerTicketId');
    var messengerInput = qs('tmMessengerInput');
    if (messengerTicketIdEl && String(messengerTicketIdEl.value || '') === String(ticketId || '') && messengerInput && !messengerInput.disabled && !messengerInput.readOnly) {
      if (!sameReplyContext(messengerReplyContext, context)) {
        messengerReplyContext = context;
        renderReplyPreview('messenger');
      }
      resizeMessengerInput();
      messengerInput.focus();
      var messengerLength = messengerInput.value.length;
      if (typeof messengerInput.setSelectionRange === 'function') messengerInput.setSelectionRange(messengerLength, messengerLength);
      return;
    }
    var chatModalTicketIdEl = qs('chatModalTicketId');
    var chatModalInput = qs('chatModalInput');
    if (chatModalTicketIdEl && String(chatModalTicketIdEl.value || '') === String(ticketId || '') && chatModalInput && !chatModalInput.disabled && !chatModalInput.readOnly) {
      if (!sameReplyContext(chatReplyContext, context)) {
        chatReplyContext = context;
        renderReplyPreview('chat');
      }
      chatModalInput.focus();
      var chatLength = chatModalInput.value.length;
      if (typeof chatModalInput.setSelectionRange === 'function') chatModalInput.setSelectionRange(chatLength, chatLength);
    }
  }
  function createMessageActionsNode(msg, ticketId, onDone) {
    if (!msg || !ticketId) return null;
    if (msg.is_me !== true) {
      if (!buildReplyContext(msg)) return null;

      var replyWrap = document.createElement('div');
      replyWrap.className = 'tm-msg-actions';

      var replyToggle = document.createElement('button');
      replyToggle.type = 'button';
      replyToggle.className = 'tm-msg-actions-toggle';
      replyToggle.setAttribute('aria-label', 'Reply to message');
      replyToggle.innerHTML = '<i class="fas fa-reply"></i>';
      replyToggle.addEventListener('click', function (e) {
        e.stopPropagation();
        focusReplyComposer(ticketId, msg);
      });

      replyWrap.appendChild(replyToggle);
      return replyWrap;
    }

    var canEdit = msg.can_edit === true;
    var imageAttachment = messagePrimaryImageAttachment(msg);
    if (!canEdit && !imageAttachment) return null;
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

    var replyBtn = document.createElement('button');
    replyBtn.type = 'button';
    replyBtn.className = 'tm-msg-actions-item';
    replyBtn.textContent = 'Reply';
    replyBtn.addEventListener('click', function () {
      menu.classList.remove('show');
      focusReplyComposer(ticketId, msg);
    });
    menu.appendChild(replyBtn);

    if (imageAttachment) {
      var copyImageBtn = document.createElement('button');
      copyImageBtn.type = 'button';
      copyImageBtn.className = 'tm-msg-actions-item';
      copyImageBtn.textContent = 'Copy image';
      copyImageBtn.addEventListener('click', function () {
        menu.classList.remove('show');
        copyImageAttachmentToClipboard(imageAttachment);
      });
      menu.appendChild(copyImageBtn);
    }

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
  function createMessageEditedNode(msg) {
    if (!(msg && msg.is_edited)) return null;
    var editedBtn = document.createElement('button');
    editedBtn.type = 'button';
    editedBtn.className = 'chat-edited';
    editedBtn.textContent = 'Edited';
    editedBtn.setAttribute('aria-label', 'View previous message');
    editedBtn.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      showMessageEditHistory(msg);
    });
    return editedBtn;
  }
  function createMessageReplyNode(msg) {
    var reply = msg && msg.reply_to ? msg.reply_to : null;
    if (!reply) return null;
    var senderName = String(reply.sender_name || '').trim();
    var replyText = String(reply.text || '').trim();
    var replyAttachmentKind = String(reply.attachment || '').trim().toLowerCase();
    var replyAttachmentStoredName = String(reply.attachment_stored_name || '').trim();
    if (!senderName && !replyText && !replyAttachmentKind && !replyAttachmentStoredName) return null;

    var wrap = document.createElement('div');
    wrap.className = 'tm-chat-reply';

    var label = document.createElement('div');
    label.className = 'tm-chat-reply-label';
    label.textContent = senderName ? ('Replying to @' + senderName) : 'Reply';
    wrap.appendChild(label);

    if (replyText) {
      var text = document.createElement('div');
      text.className = 'tm-chat-reply-text';
      text.textContent = '"' + replyText + '"';
      wrap.appendChild(text);
    } else {
      var attachmentText = document.createElement('div');
      attachmentText.className = 'tm-chat-reply-text';
      attachmentText.textContent = replyAttachmentKind === 'image' ? 'attached image' : 'attachment';
      wrap.appendChild(attachmentText);
    }

    if (replyAttachmentKind === 'image' && replyAttachmentStoredName) {
      var replyImageUrl = getChatAttachmentUrl(replyAttachmentStoredName);
      if (replyImageUrl) {
        var thumb = document.createElement('img');
        thumb.className = 'tm-chat-reply-image';
        thumb.src = replyImageUrl;
        thumb.alt = 'Replied image';
        wrap.appendChild(thumb);
      }
    }
    return wrap;
  }
  function createMessageMetaNode(msg, timeText) {
    var meta = document.createElement('div');
    meta.className = 'chat-meta';
    var editedNode = createMessageEditedNode(msg);
    var timeDiv = document.createElement('div');
    timeDiv.classList.add('chat-time');
    setMessageTimeWithStatus(timeDiv, msg, timeText);
    if (editedNode) {
      timeDiv.insertBefore(editedNode, timeDiv.firstChild);
      var dot = document.createElement('span');
      dot.className = 'chat-edited-separator';
      dot.textContent = '\u2022';
      timeDiv.insertBefore(dot, editedNode.nextSibling);
    }
    meta.appendChild(timeDiv);
    return meta;
  }
  function setMessageTimeWithStatus(timeDiv, msg, timeText) {
    if (!timeDiv) return;
    timeDiv.textContent = '';
    var timeSpan = document.createElement('span');
    timeSpan.className = 'chat-time-text';
    timeSpan.textContent = String(timeText || '');
    timeDiv.appendChild(timeSpan);
    if (!(msg && msg.is_me)) return;
    var status = document.createElement('span');
    var seen = msg.is_read === true;
    status.className = 'chat-read-status' + (seen ? ' seen' : ' delivered');
    status.setAttribute('aria-label', seen ? 'Seen' : 'Delivered');
    status.innerHTML = seen
      ? '<svg viewBox="0 0 22 16" aria-hidden="true" focusable="false"><path d="M1.5 8.6l3.7 3.7L12.8 2" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/><path d="M8.7 11.9L10 13.2 20.5 2" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
      : '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M2 8.4l3.5 3.5L14 3" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    timeDiv.appendChild(status);
    var label = document.createElement('span');
    label.className = 'chat-delivery-label ' + (seen ? 'seen' : 'delivered');
    label.textContent = seen ? 'Seen' : 'Delivered';
    timeDiv.appendChild(label);
  }
  function ensureMessageEditHistoryExists() {
    if (qs('tmMessageEditHistory')) return;
    var dialog = document.createElement('div');
    dialog.id = 'tmMessageEditHistory';
    dialog.className = 'tm-message-history-overlay';
    dialog.innerHTML =
      '<div class="tm-message-history-box" role="dialog" aria-modal="true" aria-labelledby="tmMessageEditHistoryTitle">' +
      '  <div class="tm-message-history-head">' +
      '    <div class="tm-message-history-title" id="tmMessageEditHistoryTitle">Previous message</div>' +
      '    <button type="button" class="tm-message-history-close" id="tmMessageEditHistoryClose" aria-label="Close">&times;</button>' +
      '  </div>' +
      '  <div class="tm-message-history-list" id="tmMessageEditHistoryList"></div>' +
      '</div>';
    document.body.appendChild(dialog);
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) hideMessageEditHistory();
    });
    var closeBtn = qs('tmMessageEditHistoryClose');
    if (closeBtn) closeBtn.addEventListener('click', hideMessageEditHistory);
  }
  function hideMessageEditHistory() {
    var dialog = qs('tmMessageEditHistory');
    if (dialog) dialog.style.display = 'none';
  }
  function showMessageEditHistory(msg) {
    ensureMessageEditHistoryExists();
    var dialog = qs('tmMessageEditHistory');
    var list = qs('tmMessageEditHistoryList');
    if (!dialog || !list) return;
    var currentMessage = String(msg && msg.message != null ? msg.message : '').trim();
    var seenMessages = {};
    var history = (Array.isArray(msg && msg.edit_history) ? msg.edit_history : []).filter(function (item) {
      var previousMessage = String(item && item.message != null ? item.message : '').trim();
      if (previousMessage === '' || previousMessage === currentMessage || seenMessages[previousMessage]) {
        return false;
      }
      seenMessages[previousMessage] = true;
      return true;
    });
    if (!history.length) {
      list.innerHTML = '<div class="tm-message-history-empty">No edited message is available for this chat.</div>';
    } else {
      list.innerHTML = '';
      history.forEach(function (item, index) {
        var row = document.createElement('div');
        row.className = 'tm-message-history-item';
        var meta = document.createElement('div');
        meta.className = 'tm-message-history-meta';
        meta.textContent = 'Edited chat' + (history.length > 1 ? ' ' + String(index + 1) : '') + (item && item.edited_at ? ' \u2022 ' + String(item.edited_at) : '');
        var text = document.createElement('div');
        text.className = 'tm-message-history-text';
        text.textContent = String(item && item.message != null ? item.message : '');
        row.appendChild(meta);
        row.appendChild(text);
        list.appendChild(row);
      });
    }
    dialog.style.display = 'flex';
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
    if (normalized === '@leadsagri.com' || normalized === '@malvedaholdings.com') return true;
    var sharedOptions = getSharedCompanyDepartmentOptions();
    var configuredOptions = sharedOptions[normalized];
    return Array.isArray(configuredOptions) && configuredOptions.length > 0;
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
      '@gpsci.net': 'GPCI',
      'gpsci.net': 'GPCI',
      '@leads-eh.com': 'LEH',
      'leads-eh.com': 'LEH',
      '@leads-farmex.com': 'FARMEX / LAV',
      'leads-farmex.com': 'FARMEX / LAV',
      '@leadsagri.com': 'LAPC',
      'leadsagri.com': 'LAPC',
      'lapc': 'LAPC',
      '@leadsanimalhealth.com': 'LAH',
      'leadsanimalhealth.com': 'LAH',
      '@leadsav.com': 'FARMEX / LAV',
      'leadsav.com': 'FARMEX / LAV',
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
  function formatEmailRequestType(value) {
    var key = value == null ? '' : String(value).trim().toLowerCase();
    var labels = {
      'creation of email': 'Creation of email',
      'forgot password': 'Forgot password',
      'backup of email': 'Backup of email'
    };
    return labels[key] || String(value || '').trim();
  }
  function getEmailRequestTypeDisplay(ticket) {
    if (!ticket || String(ticket.category || '').trim().toLowerCase() !== 'email') return '';
    var assignedDept = String(ticket.assigned_group || ticket.assigned_department || '').trim().toUpperCase();
    if (assignedDept !== 'IT') return '';

    var meta = ticket.request_meta && typeof ticket.request_meta === 'object' ? ticket.request_meta : {};
    var requestType = String(meta.email_request_type || ticket.email_request_type || '').trim();
    if (!requestType && ticket.description) {
      var match = String(ticket.description).match(/^\s*Email Request Type:\s*(.+)$/im);
      requestType = match && match[1] ? String(match[1]).trim() : '';
    }
    return requestType ? formatEmailRequestType(requestType) : '';
  }
  function renderTimeline(ticket) {
    var maxVisibleTimelineItems = 5;
    var createdAt = ticket.created_at ? new Date(ticket.created_at) : null;
    var updatedAt = ticket.updated_at ? new Date(ticket.updated_at) : null;
    var fallbackWhen = updatedAt || createdAt;
    var assignedInfo = getAssignedTargetParts(ticket);
    var activityItems = Array.isArray(ticket && ticket.ticket_activity) ? ticket.ticket_activity : [];
    var events = [{ title: 'Ticket created', when: createdAt }];
    var hasAssignmentEvent = false;
    var hasClaimEvent = false;

    activityItems.forEach(function (item) {
      var type = String((item && item.activity_type) || '').trim().toLowerCase();
      if (type === 'action_history') return;
      var raw = String((item && item.description) || '').trim();
      var when = item && item.created_at ? new Date(item.created_at) : fallbackWhen;
      var title = formatTimelineActivityTitle(raw);

      if (type === 'department_change') {
        var deptMatch = raw.match(/to\s+([^|]+?)(?:\s*\|\s*Handled by:\s*(.+))?$/i);
        var departmentLabel = deptMatch && deptMatch[1] ? String(deptMatch[1]).trim() : '';
        var handledByLabel = deptMatch && deptMatch[2] ? String(deptMatch[2]).trim() : '';
        if (!handledByLabel && /^Reassigned\s+to\s+[^|]+$/i.test(raw) && ticket && String(ticket.assigned_to_name || '').trim() !== '') {
          var assignedCompanyLabel = companyDisplayName(ticket.assigned_company || ticket.company || '');
          var assignedDepartmentLabel = String(ticket.assigned_group || ticket.assigned_department || '').trim();
          var assignedHandlerLabel = String(ticket.assigned_to_name || '').trim();
          handledByLabel = [assignedCompanyLabel, assignedDepartmentLabel].filter(function (part) { return part !== ''; }).join(' - ');
          handledByLabel = handledByLabel ? (handledByLabel + ' ' + assignedHandlerLabel) : assignedHandlerLabel;
        }
        title = handledByLabel ? ('Reassigned to ' + handledByLabel) : (departmentLabel ? ('Reassigned to ' + departmentLabel) : 'Ticket reassigned');
        hasAssignmentEvent = true;
      } else if (type === 'company_change') {
        title = raw !== '' ? formatTimelineCompanyChange(raw) : 'Company changed';
      } else if (type === 'status_change') {
        title = raw !== '' ? raw : 'Status updated';
      } else if (type === 'note_added') {
        title = raw !== '' ? raw : 'Admin added a note';
      } else if (type === 'priority_escalated') {
        title = raw !== '' ? raw : 'Priority escalated';
      } else if (type === 'claim_ticket') {
        title = raw !== '' ? raw : 'Ticket claimed';
        hasClaimEvent = true;
      } else if (raw !== '') {
        title = formatTimelineActivityTitle(raw);
      }

      if (title !== '') {
        events.push({ title: title, when: when });
      }
    });

    if (!hasClaimEvent && ticket && Number(ticket.assigned_to || 0) > 0 && String(ticket.assigned_to_name || '').trim() !== '') {
      var updatedAt = ticket.updated_at ? new Date(ticket.updated_at) : null;
      if (updatedAt && !Number.isNaN(updatedAt.getTime())) {
        var latestWhen = events.reduce(function (latest, item) {
          var itemWhen = item && item.when instanceof Date ? item.when : null;
          return itemWhen && !Number.isNaN(itemWhen.getTime()) && itemWhen > latest ? itemWhen : latest;
        }, createdAt instanceof Date && !Number.isNaN(createdAt.getTime()) ? createdAt : fallbackWhen);
        if (updatedAt.getTime() - latestWhen.getTime() > 30000) {
          events.push({ title: 'Claimed by ' + String(ticket.assigned_to_name || '').trim(), when: updatedAt });
        }
      }
    }

    if (activityItems.length === 0 && assignedInfo.primary) {
      var assignmentPrimary = (!assignedInfo.showDepartment && assignedInfo.company && assignedInfo.primary === assignedInfo.company)
        ? companyDisplayName(assignedInfo.primary)
        : assignedInfo.primary;
      var assignmentTitle = 'Assigned to ' + assignmentPrimary;
      if (assignedInfo.handledBy) {
        assignmentTitle += ' | Handled by: ' + assignedInfo.handledBy;
      }
      events.push({ title: assignmentTitle, when: fallbackWhen });
    }
    if (activityItems.length === 0) {
      if (ticket.admin_note && String(ticket.admin_note).trim() !== '') events.push({ title: 'Admin added a note', when: fallbackWhen });
      if (ticket.status && ticket.status !== 'Open') events.push({ title: 'Status changed to ' + ticket.status, when: fallbackWhen });
    }
    events.sort(function (a, b) {
      var aTime = a && a.when instanceof Date && !Number.isNaN(a.when.getTime()) ? a.when.getTime() : 0;
      var bTime = b && b.when instanceof Date && !Number.isNaN(b.when.getTime()) ? b.when.getTime() : 0;
      return aTime - bTime;
    });

    var timelineItemsHtml = events.map(function (e, index) {
      var hiddenClass = index >= maxVisibleTimelineItems ? ' tm-timeline-item-hidden' : '';
      var lastClass = index === events.length - 1 ? ' tm-timeline-item-last' : '';
      var lastVisibleClass = index === Math.min(events.length, maxVisibleTimelineItems) - 1 ? ' tm-timeline-item-last-visible' : '';
      var hiddenStyle = index >= maxVisibleTimelineItems ? ' style="display:none;"' : '';
      return '<div class="tm-timeline-item' + hiddenClass + lastClass + lastVisibleClass + '"' + hiddenStyle + '><div class="tm-timeline-content"><div class="tm-timeline-title">' + escapeHtml(e.title) + '</div><div class="tm-timeline-time">' + formatTimelineTime(e.when) + '</div></div></div>';
    }).join('');
    var toggleHtml = events.length > maxVisibleTimelineItems
      ? '<div class="tm-timeline-actions">' +
          '<div class="tm-timeline-toggle-shell">' +
            '<button type="button" class="tm-timeline-toggle-btn" data-expanded="false" onclick="TMTicketModal.toggleTimeline(this)"><span class="tm-timeline-toggle-label">Show more activities</span><span class="tm-timeline-toggle-icon" aria-hidden="true">&#9662;</span></button>' +
          '</div>' +
        '</div>'
      : '';
    return '<div class="tm-timeline">' + timelineItemsHtml + toggleHtml + '</div>';
  }
  function getActionHistoryItems(ticket) {
    var directItems = Array.isArray(ticket && ticket.action_history) ? ticket.action_history : [];
    var items = directItems.map(function (item) {
      return {
        note: String((item && (item.note || item.description)) || '').trim(),
        created_at: String((item && item.created_at) || '').trim()
      };
    }).filter(function (item) {
      return item.note !== '';
    });
    if (!items.length) {
      var activityItems = Array.isArray(ticket && ticket.ticket_activity) ? ticket.ticket_activity : [];
      items = activityItems.map(function (item) {
        var type = String((item && item.activity_type) || '').trim().toLowerCase();
        if (type !== 'action_history' && type !== 'note_added') return null;
        var note = String((item && item.description) || '').trim();
        if (!note) return null;
        return {
          note: note,
          created_at: String((item && item.created_at) || '').trim()
        };
      }).filter(function (item) { return !!item; });
    }
    items.sort(function (a, b) {
      var aTime = a && a.created_at ? new Date(a.created_at).getTime() : 0;
      var bTime = b && b.created_at ? new Date(b.created_at).getTime() : 0;
      if (!isFinite(aTime)) aTime = 0;
      if (!isFinite(bTime)) bTime = 0;
      return aTime - bTime;
    });
    return items;
  }
  function renderActionHistoryHtml(ticket) {
    var items = getActionHistoryItems(ticket);
    if (!items.length) {
      return '<div class="tm-info-value" style="padding:18px;border:1px dashed #d8e1ec;border-radius:16px;background:#fbfdff;color:#64748b;">No action comments have been submitted yet.</div>';
    }
    var actionLabels = ['First Action', 'Second Action', 'Third Action', 'Fourth Action', 'Fifth Action', 'Sixth Action', 'Seventh Action', 'Eighth Action', 'Ninth Action', 'Tenth Action'];
    return '<div class="tm-action-history-list" style="display:flex;flex-direction:column;gap:12px;">' +
      items.map(function (item, index) {
        var actionLabel = actionLabels[index] || ('Action ' + String(index + 1));
        return '<article class="tm-action-history-item" style="border:1px solid #dfe7f1;border-radius:16px;background:#ffffff;box-shadow:0 10px 24px rgba(15,23,42,.05);padding:16px 18px;">' +
          '<div class="tm-action-history-head" style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:9px;">' +
          '<div class="tm-action-history-label" style="display:inline-flex;align-items:center;border-radius:999px;background:#dcfce7;color:#166534;font-size:12px;font-weight:700;line-height:1.2;padding:6px 12px;white-space:nowrap;">' + escapeHtml(actionLabel) + '</div>' +
          '<div class="tm-action-history-time" style="color:#64748b;font-size:12px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;text-align:right;white-space:nowrap;line-height:1.35;">' + escapeHtml(formatTimelineTime(item.created_at)) + '</div>' +
          '</div>' +
          '<div class="tm-action-history-note" style="color:#0f172a;font-size:14px;font-weight:600;line-height:1.6;white-space:pre-wrap;overflow-wrap:anywhere;">' + escapeHtml(item.note) + '</div>' +
          '</article>';
      }).join('') +
      '</div>';
  }
  function toggleTimeline(button) {
    if (!button || !button.closest) return;
    var body = button.closest('.tm-card-body');
    var timeline = body ? body.querySelector('.tm-timeline') : null;
    if (!timeline) return;
    var hiddenItems = timeline.querySelectorAll('.tm-timeline-item-hidden');
    var expanded = String(button.getAttribute('data-expanded') || 'false') === 'true';
    timeline.classList.toggle('is-expanded', !expanded);
    hiddenItems.forEach(function (item) {
      if (expanded) {
        item.style.maxHeight = item.scrollHeight + 'px';
        item.style.opacity = '1';
        item.style.transform = 'translateY(0)';
        window.requestAnimationFrame(function () {
          item.style.maxHeight = '0px';
          item.style.opacity = '0';
          item.style.transform = 'translateY(-6px)';
        });
        window.setTimeout(function () {
          item.style.display = 'none';
          item.style.maxHeight = '';
          item.style.opacity = '';
          item.style.transform = '';
        }, 150);
      } else {
        item.style.display = '';
        item.style.maxHeight = '0px';
        item.style.opacity = '0';
        item.style.transform = 'translateY(-6px)';
        window.requestAnimationFrame(function () {
          item.style.maxHeight = item.scrollHeight + 'px';
          item.style.opacity = '1';
          item.style.transform = 'translateY(0)';
        });
        window.setTimeout(function () {
          item.style.maxHeight = '';
        }, 150);
      }
    });
    button.setAttribute('data-expanded', expanded ? 'false' : 'true');
    var label = button.querySelector('.tm-timeline-toggle-label');
    var icon = button.querySelector('.tm-timeline-toggle-icon');
    if (label) label.textContent = expanded ? 'Show more activities' : 'Show fewer activities';
    if (icon) icon.innerHTML = expanded ? '&#9662;' : '&#9652;';
  }
  function formatTimelineCompanyLabel(value) {
    var raw = value == null ? '' : String(value).trim();
    if (!raw) return '';
    if (raw.toLowerCase() === 'unassigned') return 'Unassigned';
    return companyDisplayName(raw);
  }
  function formatTimelineCompanyChange(raw) {
    var text = raw == null ? '' : String(raw).trim();
    if (!text) return '';
    var match = text.match(/^Reassigned from company\s+(.+?)\s+to\s+(.+)$/i);
    if (!match) return formatTimelineActivityTitle(text);
    var fromCompany = formatTimelineCompanyLabel(match[1]);
    var toCompany = formatTimelineCompanyLabel(match[2]);
    return 'Reassigned from ' + fromCompany + ' to ' + toCompany;
  }
  function formatTimelineActivityTitle(raw) {
    var text = raw == null ? '' : String(raw).trim();
    if (!text) return '';
    if (/^closed by requestor$/i.test(text) || /^closed by requester$/i.test(text)) {
      return 'Closed by Creator';
    }
    text = text.replace(/\bat\s+(@[^\s.]+(?:\.[^\s.]+)+)\b/gi, function (_, company) {
      return 'at ' + formatTimelineCompanyLabel(company);
    });
    text = text.replace(/\b(from company|to)\s+(@[^\s.]+(?:\.[^\s.]+)+)\b/gi, function (_, prefix, company) {
      return prefix + ' ' + formatTimelineCompanyLabel(company);
    });
    return text;
  }
  function viewButtonIfImage(filename) {
    var ext = filename.split('.').pop().toLowerCase();
    var isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext);
    if (!isImage) return '';
    var src = '../uploads/' + escapeHtml(filename);
    return '<button type="button" class="tm-action-btn tm-view-btn" data-src="' + src + '" onclick="event.stopPropagation(); TMTicketModal.viewImage(this.dataset.src)">' +
           '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>' +
           'View</button>';
  }
  function isPdfFile(filename) {
    var clean = String(filename || '').split('?')[0].split('#')[0].toLowerCase();
    return /\.pdf$/i.test(clean);
  }
  function isWordFile(filename) {
    var clean = String(filename || '').split('?')[0].split('#')[0].toLowerCase();
    return /\.(doc|docx)$/i.test(clean);
  }
  function wordFileType(filename) {
    var ext = String(filename || '').split('.').pop().toLowerCase();
    return ext === 'doc' ? 'DOC' : 'DOCX';
  }
  function attachmentStoredName(filename) {
    return String(filename || '').split(/[\\/]/).pop();
  }
  function pdfPreviewHtml(att, displayName) {
    var thumbnailUrl = String((att && (att.thumbnail_url || att.thumbnailUrl)) || '').trim();
    if (thumbnailUrl) {
      return '<div class="tm-file-card-preview">' +
        '<img class="tm-file-card-thumb" src="' + escapeHtml(thumbnailUrl) + '" alt="' + escapeHtml(displayName || 'PDF preview') + '">' +
        '</div>';
    }
    var pdfSrc = String((att && (att.pdf_src || att.pdfSrc)) || '').trim();
    return '<div class="tm-file-card-preview is-fallback" data-pdf-src="' + escapeHtml(pdfSrc) + '">' +
      '<canvas class="tm-file-card-canvas" aria-label="' + escapeHtml(displayName || 'PDF preview') + '"></canvas>' +
      '<div class="tm-file-card-icon"><i class="fas fa-file-pdf"></i><span>PDF</span></div>' +
      '</div>';
  }
  function wordPreviewHtml(displayName, typeLabel) {
    return '<div class="tm-file-card-preview tm-word-preview">' +
      '<div class="tm-file-card-icon tm-word-icon"><i class="fas fa-file-word"></i><span>' + escapeHtml(typeLabel || 'WORD') + '</span></div>' +
      '</div>';
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
    filename = attachmentStoredName(filename);
    var src = '../uploads/' + encodeURIComponent(filename);
    var isPdf = (att && att.is_pdf) || isPdfFile(filename);
    var isWord = isWordFile(filename);
    var wordType = wordFileType(filename);
    var downloadButton = '<a href="' + escapeHtml(src) + '" class="tm-action-btn tm-download-btn" download onclick="event.stopPropagation()">' +
      '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>' +
      'Download</a>';
    if (isPdf) {
      var pdfAtt = att && typeof att === 'object' ? att : {};
      pdfAtt.pdf_src = src;
      return '<article class="tm-file-card tm-pdf-card tm-attachment-clickable" data-src="' + escapeHtml(src) + '" data-name="' + escapeHtml(displayName) + '" onclick="TMTicketModal.openFilePreviewFromCard(this)">' +
        pdfPreviewHtml(pdfAtt, displayName) +
        '<div class="tm-file-card-name" title="' + escapeHtml(displayName) + '">' + escapeHtml(displayName) + '</div>' +
        '<div class="tm-file-card-actions"><span class="tm-file-card-type"><i class="fas fa-file-pdf"></i>PDF</span></div>' +
        '</article>';
    }
    if (isWord) {
      return '<article class="tm-file-card tm-word-card tm-attachment-clickable" data-src="' + escapeHtml(src) + '" data-name="' + escapeHtml(displayName) + '" onclick="TMTicketModal.openFilePreviewFromCard(this)">' +
        wordPreviewHtml(displayName, wordType) +
        '<div class="tm-file-card-name" title="' + escapeHtml(displayName) + '">' + escapeHtml(displayName) + '</div>' +
        '<div class="tm-file-card-actions"><span class="tm-file-card-type tm-file-card-type-word"><i class="fas fa-file-word"></i>' + escapeHtml(wordType) + '</span></div>' +
        '</article>';
    }
    return '<div class="tm-attachment tm-attachment-clickable" data-src="' + escapeHtml(src) + '" data-name="' + escapeHtml(displayName) + '" onclick="TMTicketModal.openFilePreviewFromCard(this)">' +
      '  <div class="tm-att-icon">' +
      '    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path></svg>' +
      '  </div>' +
      '  <div class="tm-att-details">' +
      '    <div class="tm-att-name" title="' + escapeHtml(displayName) + '">' + escapeHtml(displayName) + '</div>' +
      '    <div class="tm-att-actions">' +
      viewButtonIfImage(filename) +
      downloadButton +
      '    </div>' +
      '  </div>' +
      '</div>';
  }
  function normalizeAttachment(att) {
    var filename = '';
    var displayName = '';
    var thumbnailUrl = '';
    var thumbnailAvailable = false;
    var isPdf = false;
    var isWord = false;
    if (typeof att === 'string') {
      filename = att;
      displayName = att;
    } else if (att && typeof att === 'object') {
      filename = att.stored_name || att.filename || att.file || '';
      displayName = att.original_name || att.display_name || att.displayName || filename;
      thumbnailUrl = att.thumbnail_url || att.thumbnailUrl || '';
      thumbnailAvailable = !!att.thumbnail_available;
      isPdf = !!(att.is_pdf || att.isPdf);
      isWord = !!(att.is_word || att.isWord);
    }
    return {
      filename: attachmentStoredName(filename),
      displayName: displayName,
      thumbnailUrl: thumbnailUrl,
      thumbnail_available: thumbnailAvailable,
      isPdf: isPdf || isPdfFile(filename),
      isWord: isWord || isWordFile(filename)
    };
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
          isImage: isImageFile(n.filename),
          isPdf: n.isPdf,
          isWord: n.isWord,
          thumbnailUrl: n.thumbnailUrl
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
            var src = '../uploads/' + encodeURIComponent(attachmentStoredName(item.filename));
            if (item.isImage) {
              return '<button type="button" class="tm-hr-category-media is-image" data-src="' + src + '" onclick="TMTicketModal.viewImage(this.dataset.src)">' +
                '<img class="tm-hr-category-image" src="' + src + '" alt="' + escapeHtml(item.displayName) + '">' +
                '</button>';
            }
            return '<a class="tm-hr-category-media is-file' + (item.isPdf ? ' is-pdf' : (item.isWord ? ' is-word' : '')) + '" href="' + src + '" target="_blank" rel="noopener noreferrer">' +
              (item.isPdf
                ? pdfPreviewHtml({ thumbnail_url: item.thumbnailUrl, pdf_src: src }, item.displayName)
                : item.isWord
                  ? wordPreviewHtml(item.displayName, wordFileType(item.filename))
                : '<span class="tm-hr-category-file-icon"><i class="fas fa-file-alt"></i></span>') +
              '<span class="tm-hr-category-file-name">' + escapeHtml(item.displayName) + '</span>' +
              '</a>';
          }).join('') +
          '</div>' +
          '<div class="tm-hr-category-bottom">' +
          (slides.length > 1
            ? '<div class="tm-hr-category-nav">' +
              '<button type="button" class="tm-hr-category-arrow" onclick="TMTicketModal.stepHrAttachmentCategory(\'' + carouselId + '\', -1)">Previous</button>' +
              '<span class="tm-hr-category-counter">' + String(index + 1) + ' of ' + String(slides.length) + '</span>' +
              '<button type="button" class="tm-hr-category-arrow primary" onclick="TMTicketModal.stepHrAttachmentCategory(\'' + carouselId + '\', 1)">Next</button>' +
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
  function stepAttachmentPage(id, delta) {
    var root = document.getElementById(String(id || ''));
    if (!root) return;
    var pages = Array.prototype.slice.call(root.querySelectorAll('.tm-attachment-page'));
    if (!pages.length) return;
    var current = Number(root.getAttribute('data-index') || 0);
    if (!isFinite(current)) current = 0;
    var nextIndex = Math.max(0, Math.min(pages.length - 1, current + Number(delta || 0)));
    root.setAttribute('data-index', String(nextIndex));
    pages.forEach(function (page, index) {
      var active = index === nextIndex;
      page.classList.toggle('is-active', active);
      page.setAttribute('aria-hidden', active ? 'false' : 'true');
    });
    var counter = root.querySelector('[data-attachment-page-counter]');
    if (counter) counter.textContent = String(nextIndex + 1) + ' of ' + String(pages.length);
    var previous = root.querySelector('[data-attachment-page-previous]');
    var next = root.querySelector('[data-attachment-page-next]');
    if (previous) previous.disabled = nextIndex <= 0;
    if (next) next.disabled = nextIndex >= pages.length - 1;
  }
  function showTicketContentPage(id, pageIndex) {
    var root = document.getElementById(String(id || ''));
    if (!root) return;
    var pages = Array.prototype.slice.call(root.querySelectorAll('.tm-ticket-content-page'));
    if (!pages.length) return;
    var nextIndex = Math.max(0, Math.min(pages.length - 1, Number(pageIndex || 0)));
    root.setAttribute('data-index', String(nextIndex));
    pages.forEach(function (page, index) {
      var active = index === nextIndex;
      page.classList.toggle('is-active', active);
      page.setAttribute('aria-hidden', active ? 'false' : 'true');
    });
  }

  function syncTicketContentCardHeight(modalContent) {
    if (!modalContent) return;
    var ticketInfoCard = modalContent.querySelector('.tm-card-ticket-info');
    var pagers = modalContent.querySelectorAll('.tm-ticket-content-pager');
    if (!ticketInfoCard || !pagers.length) return;

    var applyMeasuredHeight = function () {
      var measuredHeight = Math.round(ticketInfoCard.getBoundingClientRect().height);
      if (measuredHeight <= 0) return;
      Array.prototype.forEach.call(pagers, function (pager) {
        pager.style.setProperty('--tm-ticket-content-height', measuredHeight + 'px');
      });
    };

    applyMeasuredHeight();
    if (modalContent._tmTicketContentResizeObserver) {
      modalContent._tmTicketContentResizeObserver.disconnect();
    }
    if (typeof ResizeObserver === 'function') {
      modalContent._tmTicketContentResizeObserver = new ResizeObserver(applyMeasuredHeight);
      modalContent._tmTicketContentResizeObserver.observe(ticketInfoCard);
    }
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
    var attachmentItems = [];
    list.forEach(function (att) {
      var n = normalizeAttachment(att);
      if (!n.filename) return;
      attachmentItems.push({ attachment: att, normalized: n, isImage: isImageFile(n.filename) });
    });
    if (!attachmentItems.length) return '';
    function renderAttachmentRow(att) {
      var n = normalizeAttachment(att);
      if (!n.filename) return '';
      var src = '../uploads/' + encodeURIComponent(n.filename);
      var isImage = isImageFile(n.filename);
      var previewHtml = '';
      if (isImage) {
        previewHtml = '<img class="tm-attachment-row-image" src="' + escapeHtml(src) + '" alt="' + escapeHtml(n.displayName || n.filename) + '">';
      } else if (n.isPdf) {
        previewHtml = pdfPreviewHtml({ thumbnail_url: n.thumbnailUrl, pdf_src: src }, n.displayName || n.filename);
      } else if (n.isWord) {
        previewHtml = wordPreviewHtml(n.displayName || n.filename, wordFileType(n.filename));
      } else {
        previewHtml = '<div class="tm-attachment-row-file-icon"><i class="fas fa-file-alt"></i></div>';
      }
      var openAction = isImage
        ? 'TMTicketModal.viewImage(this.dataset.src)'
        : 'TMTicketModal.openFilePreviewFromCard(this)';
      return '<article class="tm-attachment-list-row' + (isImage ? ' is-image' : ' is-file') + '" data-src="' + escapeHtml(src) + '" data-name="' + escapeHtml(n.displayName || n.filename) + '" onclick="' + openAction + '">' +
        '<div class="tm-attachment-row-preview">' + previewHtml + '</div>' +
        '<div class="tm-attachment-row-name" title="' + escapeHtml(n.displayName || n.filename) + '">' + escapeHtml(n.displayName || n.filename) + '</div>' +
        '</article>';
    }
    function renderAttachmentGroup(title, items) {
      if (!items.length) return '';
      var groupHtml = '<div class="tm-attachment-section">';
      if (!hideSectionTitles) groupHtml += '<div class="tm-attachment-section-title">' + title + '</div>';
      groupHtml += '<div class="tm-attachment-row-list">' + items.map(function (item) {
        return renderAttachmentRow(item.isImage ? item.normalized : item.attachment);
      }).join('') + '</div></div>';
      return groupHtml;
    }
    if (options.showAll) {
      var allImageItems = attachmentItems.filter(function (item) { return item.isImage; });
      var allFileItems = attachmentItems.filter(function (item) { return !item.isImage; });
      return renderAttachmentGroup('Images', allImageItems) + renderAttachmentGroup('Files', allFileItems);
    }
    var pageSize = 3;
    var pages = [];
    for (var pageStart = 0; pageStart < attachmentItems.length; pageStart += pageSize) {
      pages.push(attachmentItems.slice(pageStart, pageStart + pageSize));
    }
    var pagerId = 'tmAttachmentPager-' + String(++attachmentCategorySeq);
    var pagesHtml = pages.map(function (pageItems, pageIndex) {
      var imageItems = pageItems.filter(function (item) { return item.isImage; });
      var fileItems = pageItems.filter(function (item) { return !item.isImage; });
      return '<section class="tm-attachment-page' + (pageIndex === 0 ? ' is-active' : '') + '" data-index="' + String(pageIndex) + '" aria-hidden="' + (pageIndex === 0 ? 'false' : 'true') + '">' +
        renderAttachmentGroup('Images', imageItems) +
        renderAttachmentGroup('Files', fileItems) +
        '</section>';
    }).join('');
    return '<div class="tm-attachment-pager" id="' + pagerId + '" data-index="0">' +
      '<div class="tm-attachment-pages">' + pagesHtml + '</div>' +
      '<div class="tm-attachment-pagination">' +
        '<button type="button" class="tm-attachment-page-btn" data-attachment-page-previous disabled onclick="TMTicketModal.stepAttachmentPage(\'' + pagerId + '\', -1)">Previous</button>' +
        '<span class="tm-attachment-page-counter" data-attachment-page-counter>1 of ' + String(pages.length) + '</span>' +
        '<button type="button" class="tm-attachment-page-btn primary" data-attachment-page-next' + (pages.length <= 1 ? ' disabled' : '') + ' onclick="TMTicketModal.stepAttachmentPage(\'' + pagerId + '\', 1)">Next</button>' +
      '</div>' +
      '</div>';
  }
  var pdfJsLoadPromise = null;
  function loadPdfJs() {
    if (window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
    if (pdfJsLoadPromise) return pdfJsLoadPromise;

    pdfJsLoadPromise = new Promise(function (resolve, reject) {
      var script = document.createElement('script');
      script.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
      script.async = true;
      script.onload = function () {
        if (!window.pdfjsLib) {
          reject(new Error('PDF renderer failed to load.'));
          return;
        }
        window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        resolve(window.pdfjsLib);
      };
      script.onerror = function () {
        reject(new Error('PDF renderer failed to load.'));
      };
      document.head.appendChild(script);
    });

    return pdfJsLoadPromise;
  }
  function renderPdfThumbnailNode(preview) {
    if (!preview || preview.dataset.rendered === '1') return;
    var src = preview.getAttribute('data-pdf-src') || '';
    var canvas = preview.querySelector('.tm-file-card-canvas');
    if (!src || !canvas) return;
    preview.dataset.rendered = '1';

    loadPdfJs()
      .then(function (pdfjsLib) {
        return pdfjsLib.getDocument(src).promise;
      })
      .then(function (pdf) {
        return pdf.getPage(1);
      })
      .then(function (page) {
        var cssSize = Math.max(120, Math.round(preview.getBoundingClientRect().width || 148));
        var dpr = Math.min(window.devicePixelRatio || 1, 2);
        var outputSize = Math.round(cssSize * dpr);
        var baseViewport = page.getViewport({ scale: 1 });
        var scale = Math.min(outputSize / baseViewport.width, outputSize / baseViewport.height);
        var viewport = page.getViewport({ scale: scale });
        var temp = document.createElement('canvas');
        var tempCtx = temp.getContext('2d');
        temp.width = Math.max(1, Math.ceil(viewport.width));
        temp.height = Math.max(1, Math.ceil(viewport.height));
        return page.render({ canvasContext: tempCtx, viewport: viewport }).promise.then(function () {
          var ctx = canvas.getContext('2d');
          canvas.width = outputSize;
          canvas.height = outputSize;
          ctx.fillStyle = '#ffffff';
          ctx.fillRect(0, 0, outputSize, outputSize);
          ctx.drawImage(temp, Math.round((outputSize - temp.width) / 2), Math.round((outputSize - temp.height) / 2));
          preview.classList.add('is-rendered');
        });
      })
      .catch(function () {
        preview.dataset.rendered = '0';
      });
  }
  function renderPdfCardThumbnails(root) {
    var scope = root && root.querySelectorAll ? root : document;
    var previews = scope.querySelectorAll('.tm-file-card-preview[data-pdf-src]');
    Array.prototype.forEach.call(previews, renderPdfThumbnailNode);
  }
  function getHrDisplay(data) {
    if (!data || !data.hr_display || typeof data.hr_display !== 'object') return null;
    return data.hr_display;
  }
  function isLapcHrDepartmentTicket(data) {
    var assignedCompany = String((data && data.assigned_company) || '').trim().toLowerCase();
    var assignedGroup = String((data && (data.assigned_group || data.assigned_department)) || '').trim().toUpperCase();
    return assignedCompany === '@leadsagri.com' && assignedGroup === 'HR';
  }
  function hasTicketAttachments(data) {
    if (data && Array.isArray(data.attachments) && data.attachments.length > 0) return true;
    return !!(data && data.attachment);
  }
  function normalizeDisplaySubject(subject) {
    var text = subject == null ? '' : String(subject).trim();
    if (!text) return 'Ticket';
    var previous = '';
    while (text !== previous) {
      previous = text;
      text = text.replace(/\b([A-Za-z]+)\s+\1\b$/i, '$1').trim();
      text = text.replace(/\b(Concerns?)\s+Concern\b$/i, '$1').trim();
    }
    return text || 'Ticket';
  }
  function getDisplaySubject(data) {
    if (!data || typeof data !== 'object') return 'Ticket';
    if (data.subject_display) return normalizeDisplaySubject(data.subject_display);
    if (data.category) return normalizeDisplaySubject(data.category);
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
        'name': String(report.name || '').trim(),
        'full name': String(report.name || '').trim(),
        'position': String(report.position || '').trim(),
        'address': String(report.address || '').trim(),
        'department': String(report.department || '').trim(),
        'tin': String(report.tin || '').trim(),
        'immediate supervisor': String(report.immediate_head || report.immediate_supervisor || '').trim(),
        'company': String(report.company || '').trim()
      };
      var hasValue = Object.keys(fields).some(function (key) { return fields[key] !== ''; });
      if (!hasValue) return null;
      return { index: String(index + 1), fields: fields };
    }).filter(function (report) { return !!report; });
  }
  function isEmailCreationTicket(data, descriptionText) {
    if (!data || String(data.category || '').trim().toLowerCase() !== 'email') return false;
    var assignedGroup = String((data.assigned_group || data.assigned_department) || '').trim().toUpperCase();
    if (assignedGroup !== 'IT') return false;
    var requestType = getEmailRequestTypeDisplay(data).toLowerCase();
    var text = String(descriptionText || data.description || '').trim().toLowerCase();
    return requestType === 'creation of email'
      || (text.indexOf('email request') === 0 && text.indexOf('email request type: creation of email') !== -1);
  }
  function parseEmailCreationsFromMeta(data) {
    var raw = data && data.request_meta ? data.request_meta.email_creations : '';
    var decoded = null;
    if (raw) {
      try {
        decoded = typeof raw === 'string' ? JSON.parse(raw) : raw;
      } catch (e) {
        decoded = null;
      }
    }
    if (!Array.isArray(decoded)) decoded = [];
    var entries = decoded.map(function (entry, index) {
      if (!entry || typeof entry !== 'object') return null;
      var fields = {
        name: String(entry.name || '').trim(),
        designation: String(entry.designation || '').trim(),
        company: String(entry.company || entry.subsidiary || '').trim(),
        department: String(entry.department || entry.target_department || '').trim()
      };
      var hasValue = Object.keys(fields).some(function (key) { return fields[key] !== ''; });
      return hasValue ? { index: String(index + 1), fields: fields } : null;
    }).filter(function (entry) { return !!entry; });
    if (!entries.length && data && data.request_meta) {
      var legacyFields = {
        name: String(data.request_meta.email_creation_name || '').trim(),
        designation: String(data.request_meta.email_creation_designation || '').trim(),
        company: String(data.request_meta.email_creation_company || data.request_meta.email_creation_subsidiary || '').trim(),
        department: String(data.request_meta.email_creation_department || data.request_meta.email_creation_target_department || '').trim()
      };
      var hasLegacyValue = Object.keys(legacyFields).some(function (key) { return legacyFields[key] !== ''; });
      if (hasLegacyValue) entries.push({ index: '1', fields: legacyFields });
    }
    return entries;
  }
  function parseEmailCreationDescription(descriptionText) {
    var lines = String(descriptionText || '').split(/\r?\n/).map(function (line) {
      return String(line || '').trim();
    }).filter(function (line) {
      return line !== '';
    });
    var entries = [];
    var current = null;
    lines.forEach(function (line) {
      if (/^email request$/i.test(line) || /^email request type:/i.test(line)) return;
      var emailMatch = line.match(/^Email(?: Details)?(?:\s+(\d+))?$/i);
      if (emailMatch) {
        current = { index: emailMatch[1] || String(entries.length + 1), fields: {} };
        entries.push(current);
        return;
      }
      var colonIndex = line.indexOf(':');
      if (colonIndex > 0) {
        if (!current) {
          current = { index: String(entries.length + 1), fields: {} };
          entries.push(current);
        }
        var label = line.slice(0, colonIndex).trim().toLowerCase();
        var value = line.slice(colonIndex + 1).trim();
        if (label === 'name' || label === 'designation' || label === 'company' || label === 'department') {
          current.fields[label] = value;
        } else if (label === 'subsidiaries' || label === 'subsidiary') {
          current.fields.company = value;
        } else if ((label === 'selected department' || label === 'assigned department') && !current.fields.department) {
          current.fields.department = value;
        }
      }
    });
    return entries.filter(function (entry) {
      var fields = entry && entry.fields ? entry.fields : {};
      return String(fields.name || fields.designation || fields.company || fields.department || '').trim() !== '';
    });
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
      '@leads-farmex.com': 'FARMEX / LAV',
      '@farmasee.ph': 'FARMASEE',
      '@gpsci.net': 'GPCI',
      '@leadsagri.com': 'LAPC',
      '@leadsav.com': 'FARMEX / LAV',
      '@leadstech-corp.com': 'LTC',
      '@lingapleads.org': 'LINGAP',
      '@malvedaholdings.com': 'MHC',
      '@malvedaproperties.com': 'MPDC',
      '@primestocks.ph': 'PCC'
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
      { key: 'name', aliases: ['full name'], label: 'Name' },
      { key: 'position', label: 'Position' },
      { key: 'address', label: 'Address' },
      { key: 'department', label: 'Department' },
      { key: 'tin', label: 'TIN' }
    ];
    return '<div class="tm-sap-display">' +
      '<div class="tm-sap-carousel" id="' + carouselId + '" data-index="0">' +
      reports.map(function (report, reportIndex) {
        return '<div class="tm-sap-card' + (reportIndex === 0 ? ' is-active' : '') + '" data-index="' + String(reportIndex) + '" aria-hidden="' + (reportIndex === 0 ? 'false' : 'true') + '">' +
          '<div class="tm-sap-card-title">Employee Details</div>' +
          '<div class="tm-sap-field-grid">' +
          fieldConfig.map(function (field) {
            var lookupKeys = [field.key].concat(field.aliases || []);
            var value = getSapFieldValue(report, lookupKeys) || '-';
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
  function renderEmailCreationDescriptionHtml(data, descriptionText) {
    if (!isEmailCreationTicket(data, descriptionText)) return '';
    var entries = parseEmailCreationsFromMeta(data);
    if (!entries.length) entries = parseEmailCreationDescription(descriptionText);
    if (!entries.length) return '';
    var carouselId = 'tmEmailDisplay-' + String(++sapDisplaySeq);
    var fieldConfig = [
      { key: 'name', label: 'Name' },
      { key: 'designation', label: 'Designation' },
      { key: 'company', label: 'Company' },
      { key: 'department', label: 'Department' }
    ];
    return '<div class="tm-sap-display tm-email-display">' +
      '<div class="tm-sap-carousel" id="' + carouselId + '" data-index="0">' +
      entries.map(function (entry, entryIndex) {
        return '<div class="tm-sap-card' + (entryIndex === 0 ? ' is-active' : '') + '" data-index="' + String(entryIndex) + '" aria-hidden="' + (entryIndex === 0 ? 'false' : 'true') + '">' +
          '<div class="tm-sap-card-title">Email Details ' + escapeHtml(entry.index) + '</div>' +
          '<div class="tm-sap-field-grid">' +
          fieldConfig.map(function (field) {
            var value = entry && entry.fields ? String(entry.fields[field.key] || '').trim() : '';
            return '<div class="tm-sap-field">' +
              '<div class="tm-sap-label">' + escapeHtml(field.label) + '</div>' +
              '<div class="tm-sap-value">' + escapeHtml(value || '-') + '</div>' +
              '</div>';
          }).join('') +
          '</div>' +
          '</div>';
      }).join('') +
      (entries.length > 1
        ? '<div class="tm-sap-actions">' +
          '<button type="button" class="tm-sap-nav-btn" onclick="TMTicketModal.stepSapDisplay(\'' + carouselId + '\', -1)">Previous</button>' +
          '<span class="tm-sap-counter" data-sap-counter>1 of ' + String(entries.length) + '</span>' +
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
  function safeAttachmentPathSegment(value) {
    var normalized = attachmentStoredName(value);
    return encodeURIComponent(String(normalized || ''));
  }
  function chatAttachmentIsImage(attachment) {
    if (!attachment) return false;
    if (attachment.is_image === true) return true;
    var storedName = String(attachment.stored_name || '');
    var originalName = String(attachment.original_name || '');
    var probe = storedName || originalName;
    if (!probe) return false;
    probe = attachmentStoredName(probe).toLowerCase();
    return /\.(jpe?g|png|gif|webp|bmp)$/i.test(probe);
  }
  function getChatAttachmentUrl(storedName) {
    var safeName = safeAttachmentPathSegment(storedName);
    return safeName ? ('../uploads/' + safeName) : '';
  }
  function formatAttachmentSize(bytes) {
    var size = Number(bytes || 0);
    if (!isFinite(size) || size <= 0) return '';
    if (size < 1024) return String(Math.max(1, Math.round(size))) + ' B';
    if (size < (1024 * 1024)) return String(Math.round(size / 102.4) / 10) + ' KB';
    return String(Math.round(size / (1024 * 102.4)) / 10) + ' MB';
  }
  function chatAttachmentTooLarge(file) {
    return !!(file && Number(file.size || 0) > CHAT_ATTACHMENT_MAX_BYTES);
  }
  function chatAttachmentSizeMessage(file) {
    var name = file && file.name ? String(file.name) : 'Selected file';
    return name + ' is too large. Chat attachments must be ' + CHAT_ATTACHMENT_MAX_LABEL + ' or smaller.';
  }
  function showChatAttachmentError(message) {
    if (typeof showMessengerConfirm === 'function' && document.getElementById('tmMessengerStyles')) {
      showMessengerConfirm({
        title: 'Attachment Too Large',
        message: message,
        confirmText: 'OK',
        hideCancel: true
      });
      return;
    }
    if (typeof window !== 'undefined' && window.alert) {
      window.alert(message);
    }
  }
  function filterChatAttachmentFiles(files) {
    var accepted = [];
    var rejected = [];
    (Array.isArray(files) ? files : []).forEach(function (file) {
      if (chatAttachmentTooLarge(file)) {
        rejected.push(file);
      } else if (file) {
        accepted.push(file);
      }
    });
    if (rejected.length) {
      showChatAttachmentError(rejected.length === 1
        ? chatAttachmentSizeMessage(rejected[0])
        : 'Some selected files are too large. Chat attachments must be ' + CHAT_ATTACHMENT_MAX_LABEL + ' or smaller.');
    }
    return accepted;
  }
  function isImageAttachmentFile(file) {
    if (!file) return false;
    var mime = String(file.type || '').toLowerCase();
    if (mime.indexOf('image/') === 0) return true;
    var name = String(file.name || '').toLowerCase();
    return /\.(jpe?g|png|gif|webp|bmp)$/i.test(name);
  }
  function getAttachmentFileIcon(file) {
    var name = String((file && file.name) || '').toLowerCase();
    if (/\.pdf$/i.test(name)) return 'fa-file-pdf';
    if (/\.(doc|docx)$/i.test(name)) return 'fa-file-word';
    if (/\.(xls|xlsx|csv)$/i.test(name)) return 'fa-file-excel';
    if (/\.(ppt|pptx)$/i.test(name)) return 'fa-file-powerpoint';
    if (/\.(zip|rar|7z)$/i.test(name)) return 'fa-file-archive';
    if (/\.(txt|rtf)$/i.test(name)) return 'fa-file-lines';
    return 'fa-file';
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
      icon.innerHTML = '<i class="fas ' + getAttachmentFileIcon(file) + '"></i>';
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
  function renderSelectedComposerAttachments(label, files, onRemoveAt) {
    if (!label) return;
    if (typeof URL !== 'undefined' && URL.revokeObjectURL) {
      label.querySelectorAll('[data-preview-url]').forEach(function (node) {
        var oldUrl = node.getAttribute('data-preview-url');
        if (oldUrl) {
          try { URL.revokeObjectURL(oldUrl); } catch (e) { }
        }
      });
    }
    label.removeAttribute('data-preview-url');
    label.innerHTML = '';
    label.classList.remove('has-file', 'has-many');
    files = Array.isArray(files) ? files.filter(Boolean) : [];
    if (!files.length) return;
    if (files.length > 1) label.classList.add('has-many');

    files.forEach(function (file, index) {
      var card = document.createElement('div');
      var isImage = isImageAttachmentFile(file);
      card.className = 'tm-selected-attachment is-compact' + (isImage ? ' is-image' : ' is-file');
      card.title = String(file.name || 'Attachment');
      var previewUrl = '';
      if (typeof URL !== 'undefined' && URL.createObjectURL) {
        previewUrl = URL.createObjectURL(file);
        card.setAttribute('data-preview-url', previewUrl);
      }
      if (isImage) {
        var thumb = document.createElement('img');
        thumb.className = 'tm-selected-attachment-thumb';
        thumb.alt = String(file.name || 'Attachment');
        if (previewUrl) thumb.src = previewUrl;
        card.appendChild(thumb);
      } else {
        var icon = document.createElement('span');
        icon.className = 'tm-selected-attachment-file-icon';
        icon.innerHTML = '<i class="fas ' + getAttachmentFileIcon(file) + '"></i>';
        card.appendChild(icon);
      }

      if (!isImage) {
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
      }

      card.addEventListener('click', function (e) {
        if (!previewUrl || e.target.closest('.tm-selected-attachment-remove')) return;
        e.preventDefault();
        e.stopPropagation();
        if (isImage) {
          viewImage(previewUrl);
        } else {
          window.open(previewUrl, '_blank', 'noopener,noreferrer');
        }
      });

      var removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'tm-selected-attachment-remove';
      removeBtn.setAttribute('aria-label', 'Remove attachment');
      removeBtn.innerHTML = '<i class="fas fa-times"></i>';
      removeBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (typeof onRemoveAt === 'function') onRemoveAt(index);
      });
      card.appendChild(removeBtn);
      label.appendChild(card);
    });

    label.classList.add('has-file');
  }
  function createMessageAttachmentNode(attachment) {
    if (!attachment || !attachment.stored_name) return null;
    var wrap = document.createElement('div');
    wrap.className = 'tm-chat-attachment';
    var attachmentUrl = getChatAttachmentUrl(attachment.stored_name);
    if (!attachmentUrl) return null;
    if (chatAttachmentIsImage(attachment)) {
      var link = document.createElement('button');
      link.type = 'button';
      link.className = 'tm-chat-attachment-link tm-chat-attachment-button';
      link.setAttribute('role', 'button');
      link.setAttribute('aria-label', 'Preview image attachment');
      link.setAttribute('data-src', attachmentUrl);
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
  function groupMessengerMessages(messages) {
    var grouped = [];
    var byGroup = {};
    (Array.isArray(messages) ? messages : []).forEach(function (msg) {
      var groupId = msg && msg.message_group_id ? String(msg.message_group_id) : '';
      var key = groupId || ('single-' + String(msg && msg.id != null ? msg.id : grouped.length));
      var existing = groupId ? byGroup[key] : null;
      if (!existing) {
        existing = Object.assign({}, msg || {});
        existing.id = msg && msg.id != null ? msg.id : key;
        existing.message_ids = [];
        existing.attachments = [];
        grouped.push(existing);
        if (groupId) byGroup[key] = existing;
      }
      if (msg && msg.id != null) existing.message_ids.push(msg.id);
      if (!existing.message && msg && msg.message) existing.message = msg.message;
      if (msg && msg.attachment) existing.attachments.push(msg.attachment);
      if (msg && msg.created_at) existing.created_at = msg.created_at;
      if (msg && msg.created_at_full) existing.created_at_full = msg.created_at_full;
      existing.is_edited = !!(existing.is_edited || (msg && msg.is_edited));
      if (msg && msg.edited_at) existing.edited_at = msg.edited_at;
      if (!Array.isArray(existing.edit_history)) existing.edit_history = [];
      if (msg && Array.isArray(msg.edit_history) && msg.edit_history.length) {
        existing.edit_history = existing.edit_history.concat(msg.edit_history);
      }
      existing.is_read = existing.is_read === true && (!msg || msg.is_read === true);
      existing.can_edit = existing.can_edit && (!msg || msg.can_edit !== false);
      existing.can_delete = existing.can_delete && (!msg || msg.can_delete !== false);
    });
    return grouped;
  }
  function createMessageAttachmentsNode(attachments) {
    attachments = Array.isArray(attachments) ? attachments.filter(Boolean) : [];
    if (!attachments.length) return null;
    if (attachments.length === 1) return createMessageAttachmentNode(attachments[0]);

    var group = document.createElement('div');
    group.className = 'tm-chat-attachment-group';
    var visibleAttachments = attachments.slice(0, 3);
    var hiddenCount = Math.max(0, attachments.length - visibleAttachments.length);
    visibleAttachments.forEach(function (attachment, index) {
      var item = createMessageAttachmentNode(attachment);
      if (item) {
        if (hiddenCount > 0 && index === visibleAttachments.length - 1) {
          item.classList.add('has-more');
          var more = document.createElement('button');
          more.type = 'button';
          more.className = 'tm-chat-attachment-more';
          more.textContent = 'View +' + String(hiddenCount);
          more.addEventListener('click', function (event) {
            var previewTarget = item.querySelector('.tm-chat-attachment-button[data-src], .tm-chat-attachment-link[href]');
            if (previewTarget) previewTarget.click();
            event.preventDefault();
            event.stopPropagation();
          });
          item.appendChild(more);
        }
        group.appendChild(item);
      }
    });
    if (hiddenCount > 0) {
      attachments.slice(3).forEach(function (attachment) {
        if (!(attachment && attachment.is_image && attachment.stored_name)) return;
        var hidden = createMessageAttachmentNode(attachment);
        if (!hidden) return;
        hidden.classList.add('tm-chat-attachment-hidden');
        group.appendChild(hidden);
      });
      group.setAttribute('data-hidden-count', String(hiddenCount));
      group.classList.add('has-more');
    }
    var allFiles = visibleAttachments.every(function (attachment) {
      return !(attachment && attachment.is_image);
    });
    group.classList.toggle('files-only', allFiles && hiddenCount === 0);
    return group;
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
  function setMessengerAttachments(files) {
    messengerAttachmentFiles = Array.isArray(files) ? files.filter(Boolean) : [];
    var label = qs('tmMessengerAttachmentName');
    var compose = label ? label.closest('.tm-messenger-compose') : null;
    if (compose) compose.classList.toggle('has-attachment', messengerAttachmentFiles.length > 0);
    renderSelectedComposerAttachments(label, messengerAttachmentFiles, function (index) {
      var input = qs('tmMessengerAttachmentInput');
      if (input) input.value = '';
      var next = messengerAttachmentFiles.filter(function (_, i) { return i !== index; });
      setMessengerAttachments(next);
    });
  }
  function resizeMessengerInput() {
    var input = qs('tmMessengerInput');
    if (!input) return;
    input.style.height = 'auto';
    var maxHeight = 150;
    var nextHeight = Math.min(Math.max(input.scrollHeight, 42), maxHeight);
    input.style.height = nextHeight + 'px';
    input.style.overflowY = input.scrollHeight > maxHeight ? 'auto' : 'hidden';
    var compose = input.closest ? input.closest('.tm-messenger-compose') : null;
    if (compose) compose.classList.toggle('is-expanded', nextHeight > 52);
  }
  function renderHrRequestDetailsCard(data, footerHtml, includeWithAttachments) {
    var hr = getHrDisplay(data);
    if (!hr || !hr.is_hr_special) return '';
    if (!includeWithAttachments && hasTicketAttachments(data)) return '';
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
      ? '<div class="tm-hr-section"><div class="tm-info-value tm-ticket-description-surface">' + escapeHtml(descriptionText).replace(/\n/g, '<br>') + '</div></div>'
      : '';
    if (!items.length && !descriptionHtml) return '';
    return '<div class="tm-card tm-card-request-details"><div class="tm-card-header"><span class="tm-card-title">' + escapeHtml(String(hr.request_section_title || 'Request Details')) + '</span></div><div class="tm-card-body">' +
      '<div class="tm-ticket-content-scroll tm-ticket-description-scroll">' +
        (items.length ? '<div class="tm-info-grid tm-info-grid-compact">' + items.join('') + '</div>' : '') +
        descriptionHtml +
      '</div>' + String(footerHtml || '') +
      '</div></div>';
  }
  function parseSalesTicketDescriptionMeta(data) {
    var result = { position: '', region: '', cleanedLines: [] };
    var descriptionText = String((data && data.description) || '');
    if (!descriptionText) return result;
    var lines = descriptionText.split(/\r?\n/).map(function (line) {
      return String(line || '').trim();
    }).filter(function (line) {
      return line !== '';
    });
    lines.forEach(function (line) {
      var match = line.match(/^\s*(Position|Region)\s*:\s*(.*)$/i);
      if (match) {
        var label = String(match[1] || '').toLowerCase();
        var value = String(match[2] || '').trim();
        if (label === 'position') result.position = value;
        if (label === 'region') result.region = value;
        return;
      }
      result.cleanedLines.push(line);
    });
    return result;
  }
  function renderSalesTicketInfoHtml(data) {
    var meta = parseSalesTicketDescriptionMeta(data);
    var html = '';
    if (meta.position) {
      html += '<div class="tm-info-label">POSITION</div><div class="tm-info-value">' + escapeHtml(meta.position) + '</div>';
    }
    if (meta.region) {
      html += '<div class="tm-info-label">REGION</div><div class="tm-info-value">' + escapeHtml(meta.region) + '</div>';
    }
    return html;
  }
  function isLapcHrIncidentReportTicket(data) {
    var category = String((data && data.category) || '').trim();
    return isLapcHrDepartmentTicket(data) && category === 'Incident Report';
  }
  function parseIncidentReportDisplay(data) {
    var descriptionText = String((data && data.description) || '');
    var meta = data && data.request_meta && typeof data.request_meta === 'object' ? data.request_meta : {};
    var summary = '';
    var summaryMatch = descriptionText.match(/Short Summary of IR\s*:\s*([\s\S]*?)(?:\r?\n\s*Gdrive Link \(Video\)\s*:|$)/i);
    if (summaryMatch) {
      summary = String(summaryMatch[1] || '').trim();
    }
    var gdriveLink = String(meta.incident_gdrive_link || '').trim();
    if (!gdriveLink) {
      var gdriveMatch = descriptionText.match(/Gdrive Link \(Video\)\s*:\s*(.+)$/im);
      if (gdriveMatch) gdriveLink = String(gdriveMatch[1] || '').trim();
    }
    return { summary: summary, gdriveLink: gdriveLink };
  }
  function renderIncidentReportAttachmentRows(data) {
    var list = data && Array.isArray(data.attachments) ? data.attachments : [];
    if (!list.length) return '<div class="tm-incident-empty">No attachment available.</div>';
    return '<div class="tm-incident-file-list">' + list.map(function (att) {
      var n = normalizeAttachment(att);
      if (!n.filename) return '';
      var src = '../uploads/' + encodeURIComponent(n.filename);
      var iconClass = n.isPdf ? 'fa-file-pdf' : (n.isWord ? 'fa-file-word' : (isImageFile(n.filename) ? 'fa-image' : 'fa-file'));
      var labelHtml = '<span class="tm-incident-file-icon"><i class="fas ' + iconClass + '"></i></span>' +
        '<span class="tm-incident-file-name">' + escapeHtml(String(n.displayName || n.filename)) + '</span>';
      if (isImageFile(n.filename)) {
        return '<button type="button" class="tm-incident-file-row" data-src="' + escapeHtml(src) + '" onclick="TMTicketModal.viewImage(this.dataset.src)">' +
          labelHtml +
          '</button>';
      }
      return '<a class="tm-incident-file-row" href="' + escapeHtml(src) + '" download>' +
        labelHtml +
        '</a>';
    }).join('') + '</div>';
  }
  function renderIncidentReportDetailsCard(data, footerHtml, includeWithAttachments) {
    if (!isLapcHrIncidentReportTicket(data)) return '';
    if (!includeWithAttachments && hasTicketAttachments(data)) return '';
    var incident = parseIncidentReportDisplay(data);
    var carouselId = 'tmIncidentDisplay-' + String(++sapDisplaySeq);
    var summaryText = incident.summary || '-';
    var driveHtml = incident.gdriveLink
      ? '<div class="tm-incident-section-title tm-incident-drive-title">GDrive Link (Video)</div>' +
        '<a class="tm-incident-drive-row" href="' + escapeHtml(incident.gdriveLink) + '" target="_blank" rel="noopener noreferrer">' +
          '<span class="tm-incident-drive-icon"><i class="fab fa-google-drive"></i></span>' +
          '<span class="tm-incident-drive-url">' + escapeHtml(incident.gdriveLink) + '</span>' +
        '</a>'
      : '';
    if (footerHtml) {
      var embeddedIncidentHtml = '<div class="tm-incident-field">' +
          '<div class="tm-incident-section-title">Short Summary of IR</div>' +
          '<div class="tm-incident-summary-box tm-ticket-description-surface" style="font-size:16px;">' + escapeHtml(summaryText).replace(/\n/g, '<br>') + '</div>' +
        '</div>' +
        (driveHtml ? '<div style="margin-top:18px;">' + driveHtml + '</div>' : '');
      return '<div class="tm-card tm-card-description tm-card-incident-report"><div class="tm-card-header"><span class="tm-card-title">Request Details</span></div><div class="tm-card-body">' +
        '<div class="tm-ticket-content-scroll tm-ticket-description-scroll">' + embeddedIncidentHtml + '</div>' + String(footerHtml) +
        '</div></div>';
    }
    var slides = [
      '<div class="tm-sap-card tm-incident-card is-active" data-index="0" aria-hidden="false">' +
        '<div class="tm-incident-field">' +
          '<div class="tm-incident-section-title">Short Summary of IR</div>' +
          '<div class="tm-incident-summary-box tm-ticket-description-surface" style="font-size:16px;">' + escapeHtml(summaryText).replace(/\n/g, '<br>') + '</div>' +
        '</div>' +
      '</div>'
    ];
    if (driveHtml) {
      slides.push('<div class="tm-sap-card tm-incident-card is-attachments" data-index="1" aria-hidden="true">' + driveHtml + '</div>');
    }
    var incidentHtml = '<div class="tm-sap-display tm-incident-display">' +
      '<div class="tm-sap-carousel" id="' + carouselId + '" data-index="0">' +
        '<div class="tm-incident-card-header"><span class="tm-incident-card-icon"><i class="fas fa-file-alt"></i></span><span>Request Details</span></div>' +
        slides.join('') +
        (slides.length > 1
          ? '<div class="tm-sap-actions">' +
              '<button type="button" class="tm-sap-nav-btn" onclick="TMTicketModal.stepSapDisplay(\'' + carouselId + '\', -1)">Previous</button>' +
              '<span class="tm-sap-counter" data-sap-counter>1 of ' + String(slides.length) + '</span>' +
              '<button type="button" class="tm-sap-nav-btn primary" onclick="TMTicketModal.stepSapDisplay(\'' + carouselId + '\', 1)">Next</button>' +
            '</div>'
          : '') +
      '</div>' +
    '</div>';
    return incidentHtml;
  }
  function renderDescriptionCard(data, footerHtml) {
    var hr = getHrDisplay(data);
    if (isLapcHrIncidentReportTicket(data)) return '';
    if (hr && hr.is_hr_special) return '';
    var title = 'Description';
    var descriptionText = String((data && data.description) || '');
    var descriptionHtml = '';
    if (descriptionText) {
      var emailCreationHtml = renderEmailCreationDescriptionHtml(data, descriptionText);
      var sapDescriptionHtml = emailCreationHtml ? '' : renderSapDescriptionHtml(data, descriptionText);
      if (emailCreationHtml) {
        title = 'Creation of Email';
        descriptionHtml = emailCreationHtml;
      } else if (sapDescriptionHtml) {
        title = 'SAP Form';
        descriptionHtml = sapDescriptionHtml;
      } else {
      var salesMeta = parseSalesTicketDescriptionMeta(data);
      var lines = salesMeta.cleanedLines.length ? salesMeta.cleanedLines : descriptionText.split(/\r?\n/).map(function (line) { return String(line || '').trim(); }).filter(function (line) { return line !== ''; });
      if ((data && data.is_sales_ticket === true) || (salesMeta.position || salesMeta.region)) {
        lines = salesMeta.cleanedLines;
      }
      var assignedCompany = String((data && data.assigned_company) || '').trim().toLowerCase();
      var assignedGroup = String((data && (data.assigned_group || data.assigned_department)) || '').trim();
      var isLapcHrTicket = assignedCompany === '@leadsagri.com' && assignedGroup === 'HR';
      if (isLapcHrTicket && lines.length > 1 && lines[0].indexOf(':') === -1) {
        title = lines[0];
        lines = lines.slice(1);
      }
      if (lines.length > 0) {
        descriptionHtml = '<div class="tm-desc-text tm-ticket-description-surface">';
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
    var emptyHtml = !descriptionHtml ? '<div class="tm-info-value">-</div>' : '';
    return '<div class="tm-card tm-card-description"><div class="tm-card-header"><span class="tm-card-title">' + escapeHtml(title) + '</span></div><div class="tm-card-body"><div class="tm-ticket-content-scroll tm-ticket-description-scroll">' + descriptionHtml + emptyHtml + '</div>' + String(footerHtml || '') + '</div></div>';
  }
  function renderAttachmentCard(data, footerHtml, preparedAttachmentsHtml) {
    var attachmentsHtml = preparedAttachmentsHtml || renderAttachmentsBlock(data, { showAll: true });
    if (!attachmentsHtml) return '';
    return '<div class="tm-card tm-card-attachment"><div class="tm-card-header"><span class="tm-card-title">Attachment</span></div><div class="tm-card-body"><div class="tm-ticket-content-scroll">' + attachmentsHtml + '</div>' + String(footerHtml || '') + '</div></div>';
  }
  function renderDescriptionAttachmentCards(data) {
    var attachmentsHtml = renderAttachmentsBlock(data, { showAll: true });
    if (!attachmentsHtml) return renderDescriptionCard(data);
    var pagerId = 'tmTicketContentPager-' + String(++attachmentCategorySeq);
    var descriptionNavigation = '<div class="tm-ticket-content-navigation">' +
      '<button type="button" class="tm-attachment-page-btn" onclick="TMTicketModal.showTicketContentPage(\'' + pagerId + '\', 1)">Previous</button>' +
      '<span class="tm-attachment-page-counter">1 of 2</span>' +
      '<button type="button" class="tm-attachment-page-btn primary" onclick="TMTicketModal.showTicketContentPage(\'' + pagerId + '\', 1)">Next</button>' +
      '</div>';
    var attachmentNavigation = '<div class="tm-ticket-content-navigation">' +
      '<button type="button" class="tm-attachment-page-btn" onclick="TMTicketModal.showTicketContentPage(\'' + pagerId + '\', 0)">Previous</button>' +
      '<span class="tm-attachment-page-counter">2 of 2</span>' +
      '<button type="button" class="tm-attachment-page-btn primary" onclick="TMTicketModal.showTicketContentPage(\'' + pagerId + '\', 0)">Next</button>' +
      '</div>';
    var descriptionCard = '';
    if (isLapcHrDepartmentTicket(data)) {
      if (isLapcHrIncidentReportTicket(data)) {
        descriptionCard = renderIncidentReportDetailsCard(data, descriptionNavigation, true);
      } else {
        var hr = getHrDisplay(data);
        descriptionCard = hr && hr.is_hr_special
          ? renderHrRequestDetailsCard(data, descriptionNavigation, true)
          : renderDescriptionCard(data, descriptionNavigation);
      }
    } else {
      descriptionCard = renderDescriptionCard(data, descriptionNavigation);
    }
    if (!descriptionCard) return renderAttachmentCard(data, '', attachmentsHtml);
    return '<div class="tm-ticket-content-pager" id="' + pagerId + '" data-index="0">' +
      '<section class="tm-ticket-content-page is-active" data-index="0" aria-hidden="false">' + descriptionCard + '</section>' +
      '<section class="tm-ticket-content-page" data-index="1" aria-hidden="true">' + renderAttachmentCard(data, attachmentNavigation, attachmentsHtml) + '</section>' +
      '</div>';
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
  function updateStatusColor(select, explicitValue) {
    if (!select) return;
    var status = typeof explicitValue === 'string' ? explicitValue : select.value;
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
    var userEl = form.querySelector('select[name="assigned_user_id"]');
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
    var initialUser = userEl && !userEl.disabled ? String(userEl.value || '') : '';
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

    [statusEl, deptEl, companyEl, userEl, noteEl].forEach(function (el) {
      if (!el) return;
      el.addEventListener('change', hideNotice);
      el.addEventListener('input', hideNotice);
    });

    form.addEventListener('submit', function (e) {
      var currentStatus = statusEl ? String(statusEl.value || '') : initialStatus;
      var currentCompany = normalizeCompanyValueForCompare(companyEl ? String(companyEl.value || '') : initialCompany);
      var currentDeptRaw = getDeptRawValue();
      var currentDept = normalizeDepartmentValueForCompare(currentDeptRaw, currentCompany);
      var currentUser = userEl && !userEl.disabled ? String(userEl.value || '') : '';
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
      if (currentStatus === initialStatus && currentDept === initialDept && currentCompany === initialCompany && currentUser === initialUser && currentNote === initialNote) {
        e.preventDefault();
        showNotice('No changes were made.');
        return;
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
  function bindUpdateActionLayout(container) {
    if (!container) return;
    var form = container.querySelector('#ticketUpdateForm');
    if (!form || form.dataset.updateActionBound === '1') return;
    form.dataset.updateActionBound = '1';
    var modeInput = form.querySelector('input[name="update_action_mode"]');
    var cards = Array.prototype.slice.call(form.querySelectorAll('[data-update-action-card]'));
    var sections = Array.prototype.slice.call(form.querySelectorAll('[data-update-action-section]'));
    if (!modeInput || cards.length === 0 || sections.length === 0) return;

    function applyMode(mode) {
      var nextMode = mode === 'reassign' ? 'reassign' : 'status';
      modeInput.value = nextMode;
      cards.forEach(function (card) {
        var isActive = card.getAttribute('data-update-action-card') === nextMode;
        card.classList.toggle('is-active', isActive);
        card.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        var iconEl = card.querySelector('.tm-update-mode-icon');
        if (iconEl) {
          iconEl.classList.toggle('is-neutral', !isActive);
          iconEl.classList.toggle('is-lit', isActive);
        }
      });
      sections.forEach(function (section) {
        section.style.display = section.getAttribute('data-update-action-section') === nextMode ? '' : 'none';
      });
    }

    cards.forEach(function (card) {
      card.addEventListener('click', function () {
        applyMode(card.getAttribute('data-update-action-card') || 'status');
      });
    });

    applyMode(modeInput.value || 'status');
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
    { value: 'Institutional Sales (Bidding)', label: 'Institutional Sales (Bidding)' },
    { value: 'Machineries', label: 'Machineries' },
    { value: 'Management', label: 'Management' },
    { value: 'Marketing', label: 'Marketing' },
    { value: 'New Business Segment', label: 'New Business Segment' },
    { value: 'Seed Production', label: 'Seed Production' },
    { value: 'Supply Chain', label: 'Supply Chain' },
    { value: 'Supply Chain Innovation', label: 'Supply Chain Innovation' },
    { value: 'Technical', label: 'Technical' }
  ];
  var mhcDeptOptions = [
    { value: 'Marketing Creatives', label: 'Marketing Creatives' }
  ];
  function getSharedCompanyDepartmentOptions() {
    var fallbackOptions = {
      '@leadsagri.com': lapcDeptOptions,
      '@malvedaholdings.com': mhcDeptOptions
    };
    if (typeof window === 'undefined' || !window.TM_COMPANY_DEPARTMENT_OPTIONS || typeof window.TM_COMPANY_DEPARTMENT_OPTIONS !== 'object') {
      return fallbackOptions;
    }
    var configured = window.TM_COMPANY_DEPARTMENT_OPTIONS;
    var normalizedMap = {};
    Object.keys(configured).forEach(function (key) {
      var normalizedKey = normalizeCompanyValue(key);
      var optionList = Array.isArray(configured[key]) ? configured[key] : [];
      if (!normalizedKey || optionList.length === 0) return;
      normalizedMap[normalizedKey] = optionList.map(function (option) {
        var value = option && option.value != null ? String(option.value) : '';
        var label = option && option.label != null ? String(option.label) : value;
        return { value: value, label: label };
      }).filter(function (option) {
        return option.value !== '';
      });
    });
    if (!normalizedMap['@leadsagri.com']) {
      normalizedMap['@leadsagri.com'] = lapcDeptOptions;
    }
    if (!normalizedMap['@malvedaholdings.com']) {
      normalizedMap['@malvedaholdings.com'] = mhcDeptOptions;
    }
    return normalizedMap;
  }
  function resolveCompanyIdentity(value, label) {
    var normalizedValue = normalizeCompanyValue(value);
    if (normalizedValue) return normalizedValue;
    var normalizedLabel = normalizeCompanyValue(label);
    if (normalizedLabel) return normalizedLabel;
    var rawValue = value == null ? '' : String(value).trim();
    if (rawValue !== '') return rawValue;
    return label == null ? '' : String(label).trim();
  }
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
      'farmex / lav': '@leads-farmex.com',
      'farmex/lav': '@leads-farmex.com',
      'farmex / lav (@leads-farmex.com)': '@leads-farmex.com',
      'farmex / lav (@leadsav.com)': '@leads-farmex.com',
      'farmex corp': '@leads-farmex.com',
      'leads-farmex.com': '@leads-farmex.com',
      '@leads-farmex.com': '@leads-farmex.com',
      'mhc': '@malvedaholdings.com',
      'mhc (@malvedaholdings.com)': '@malvedaholdings.com',
      'malveda holdings corporation - mhc': '@malvedaholdings.com',
      'malveda holdings - mhc': '@malvedaholdings.com',
      'malveda holdings corporation': '@malvedaholdings.com',
      'malveda holdings': '@malvedaholdings.com',
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
      'farmex / lav (@leadsav.com) - lav': '@leadsav.com',
      'leadsav.com': '@leadsav.com',
      '@leadsav.com': '@leadsav.com',
      'pcc': '@primestocks.ph',
      'pcc (@primestocks.ph)': '@primestocks.ph',
      'primestocks.ph': '@primestocks.ph',
      '@primestocks.ph': '@primestocks.ph'
    };
    return companyAliases[normalized] || normalized;
  }
  function normalizeDepartmentKey(value) {
    var raw = value == null ? '' : String(value).trim();
    if (!raw) return '';
    var normalized = raw.toUpperCase().replace(/\s+/g, ' ');
    var map = {
      'ACCOUNTING': ['ACCOUNTING', 'FINANCE AND ACCOUNTING', 'FINANCE & ACCOUNTING'],
      'ADMIN': ['ADMIN', 'ADMINISTRATION', 'ADMIN & LEGAL', 'FINANCE AND ADMIN', 'FINANCE & ADMIN'],
      'BIDDING': ['BIDDING', 'INSTITUTIONAL SALES (BIDDING)', 'INSTITUTIONAL SALES'],
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
      var key = keys[i];
      if (normalized === key) return key;
      var aliases = map[key] || [];
      for (var j = 0; j < aliases.length; j++) {
        if (normalized === String(aliases[j]).toUpperCase()) return key;
      }
    }
    return normalized;
  }
  function getDeptOptionsForCompany(companyValue) {
    var normalizedCompany = normalizeCompanyValue(companyValue);
    if (normalizedCompany === '@leadsagri.com') {
      return lapcDeptOptions;
    }
    if (normalizedCompany === '@malvedaholdings.com') {
      return mhcDeptOptions;
    }
    var sharedOptions = getSharedCompanyDepartmentOptions();
    var configuredOptions = sharedOptions[normalizedCompany];
    if (Array.isArray(configuredOptions) && configuredOptions.length > 0) {
      return configuredOptions;
    }
    if (typeof window !== 'undefined' && window.TM_FORCE_LAPC_DEPARTMENTS === true) return lapcDeptOptions;
    return [];
  }
  function preferredDeptValueForCompany(selectedValue, companyValue) {
    var raw = selectedValue == null ? '' : String(selectedValue).trim();
    var normalizedCompany = normalizeCompanyValue(companyValue);
    var options = getDeptOptionsForCompany(companyValue);
    var hasDepartmentOptions = options.length > 0;
    var isLapcCompany = normalizedCompany === '@leadsagri.com' || (typeof window !== 'undefined' && window.TM_FORCE_LAPC_DEPARTMENTS === true);
    var isMhcCompany = normalizedCompany === '@malvedaholdings.com';
    if (raw.toLowerCase() === 'no departments available') {
      raw = '';
    }
    if (!raw) return hasDepartmentOptions ? '' : '';
    for (var i = 0; i < options.length; i++) {
      if (String(options[i].value).toLowerCase() === raw.toLowerCase()) return String(options[i].value);
    }
    var deptKey = deptKeyFromValue(raw);
    if (isMhcCompany) {
      if (deptKey === 'MARKETING' || raw.toLowerCase() === 'marketing creatives') {
        return 'Marketing Creatives';
      }
      return '';
    }
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
    return hasDepartmentOptions ? raw : '';
  }
  function getCanonicalCompanySelectValue(selectEl) {
    if (!selectEl) return '';
    var rawValue = String(selectEl.value || '');
    var selectedOption = selectEl.options && selectEl.selectedIndex >= 0 ? selectEl.options[selectEl.selectedIndex] : null;
    var selectedLabel = selectedOption && selectedOption.text ? String(selectedOption.text) : '';
    var normalizedValue = resolveCompanyIdentity(rawValue, selectedLabel);
    if (!normalizedValue) return rawValue;
    var hasNormalizedOption = Array.prototype.slice.call(selectEl.options || []).some(function (option) {
      return String(option.value || '') === normalizedValue;
    });
    if (hasNormalizedOption && String(selectEl.value || '') !== normalizedValue) {
      selectEl.value = normalizedValue;
    }
    return hasNormalizedOption ? normalizedValue : (normalizedValue || rawValue);
  }
  function buildDeptOptionsHtml(companyValue, selectedValue) {
    var options = getDeptOptionsForCompany(companyValue);
    if (options.length === 0) {
      return '                  <option value="" selected>No departments available</option>';
    }
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
  function buildDepartmentUserOptionsHtml(users, selectedUserId) {
    var normalizedSelected = selectedUserId == null ? '' : String(selectedUserId);
    var currentUser = (typeof window !== 'undefined' && window.TM_CURRENT_USER) ? window.TM_CURRENT_USER : null;
    var currentUserId = currentUser && currentUser.id != null ? String(currentUser.id) : '';
    var list = (Array.isArray(users) ? users : []).filter(function (user) {
      var userId = user && user.id != null ? String(user.id) : '';
      return currentUserId === '' || userId === '' || userId !== currentUserId;
    });
    if (list.length === 0) {
      return '                  <option value="">All</option>';
    }
    var html = '                  <option value="">All</option>';
    html += list.map(function (user) {
      var userId = user && user.id != null ? String(user.id) : '';
      var name = user && user.name ? String(user.name) : 'Unnamed User';
      return '                  <option value="' + escapeHtml(userId) + '" ' + (userId === normalizedSelected ? 'selected' : '') + '>' + escapeHtml(name) + '</option>';
    }).join('');
    return html;
  }
  function bindDepartmentUserOptions(container, data) {
    if (!container) return;
    var form = container.querySelector('#ticketUpdateForm');
    if (!form || form.dataset.deptUsersBound === '1') return;
    form.dataset.deptUsersBound = '1';
    var companyEl = form.querySelector('select[name="assigned_company"]');
    var deptEl = form.querySelector('select[name="assigned_department"]');
    var userEl = form.querySelector('select[name="assigned_user_id"]');
    if (!companyEl || !deptEl || !userEl) return;
    var userField = userEl.closest ? userEl.closest('.tm-field') : null;

    var endpoint = (typeof window !== 'undefined' && window.TM_DEPARTMENT_USERS_ENDPOINT)
      ? String(window.TM_DEPARTMENT_USERS_ENDPOINT)
      : 'ajax_department_users.php';

    function departmentUserAllowed() {
      var selectedDeptKey = normalizeDepartmentKey(deptEl.value || '');
      return assignedCompanyUsesDepartment(companyEl.value || '') && selectedDeptKey !== '';
    }

    function setDepartmentUserVisibility(visible) {
      if (userField) {
        userField.style.display = visible ? '' : 'none';
      }
      userEl.disabled = !visible;
      if (!visible) {
        userEl.innerHTML = '                  <option value="">All</option>';
        userEl.value = '';
      }
      if (typeof userEl._tmRenderCustomDropdown === 'function') {
        userEl._tmRenderCustomDropdown();
      }
    }

    function setLoadingState(message) {
      userEl.innerHTML = '                  <option value="">' + escapeHtml(message || 'Loading department users...') + '</option>';
      userEl.disabled = true;
      if (typeof userEl._tmRenderCustomDropdown === 'function') {
        userEl._tmRenderCustomDropdown();
      }
    }

    function syncUsers(preferredUserId) {
      var companyValue = String(getCanonicalCompanySelectValue(companyEl) || '').trim();
      var deptValue = String(deptEl.value || '').trim();
      if (!departmentUserAllowed()) {
        setDepartmentUserVisibility(false);
        return;
      }
      setDepartmentUserVisibility(true);
      if (!companyValue || !deptValue) {
        userEl.innerHTML = '                  <option value="">All</option>';
        userEl.disabled = true;
        if (typeof userEl._tmRenderCustomDropdown === 'function') {
          userEl._tmRenderCustomDropdown();
        }
        return;
      }

      setLoadingState('Loading department users...');
      var params = new URLSearchParams();
      params.set('company', companyValue);
      params.set('department', deptValue);
      fetch(endpoint + '?' + params.toString(), { method: 'GET', credentials: 'same-origin' })
        .then(function (response) { return response.json(); })
        .then(function (payload) {
          var users = payload && payload.ok && Array.isArray(payload.users) ? payload.users : [];
          userEl.innerHTML = buildDepartmentUserOptionsHtml(users, preferredUserId);
          userEl.disabled = users.length === 0;
          if (users.length === 0) {
            userEl.innerHTML = '                  <option value="">All</option>';
          }
          if (typeof userEl._tmRenderCustomDropdown === 'function') {
            userEl._tmRenderCustomDropdown();
          }
        })
        .catch(function () {
          userEl.innerHTML = '                  <option value="">Unable to load department users</option>';
          userEl.disabled = true;
          if (typeof userEl._tmRenderCustomDropdown === 'function') {
            userEl._tmRenderCustomDropdown();
          }
        });
    }

    syncUsers(data && data.assigned_user_id != null ? String(data.assigned_user_id) : '');
    companyEl.addEventListener('change', function () {
      syncUsers('');
    });
    deptEl.addEventListener('change', function () {
      syncUsers('');
    });
  }
  function bindCustomSelectDropdowns(container) {
    if (!container) return;
    var form = container.querySelector('#ticketUpdateForm');
    if (!form || form.dataset.customSelectsBound === '1') return;
    form.dataset.customSelectsBound = '1';
    var wrappers = Array.prototype.slice.call(form.querySelectorAll('[data-custom-select]'));
    if (wrappers.length === 0) return;

    wrappers.forEach(function (wrapper) {
      var selectName = String(wrapper.getAttribute('data-custom-select') || '').trim();
      var selectEl = selectName ? form.querySelector('select[name="' + selectName + '"]') : null;
      var trigger = wrapper ? wrapper.querySelector('[data-custom-select-trigger]') : null;
      var triggerText = wrapper ? wrapper.querySelector('[data-custom-select-text]') : null;
      var menu = wrapper ? wrapper.querySelector('[data-custom-select-menu]') : null;
      var placeholder = String(wrapper.getAttribute('data-custom-select-placeholder') || 'Select option');
      if (!wrapper || !selectEl || !trigger || !triggerText || !menu) return;

      function closeMenu() {
        menu.classList.remove('show');
        menu.classList.remove('is-above');
        menu.style.maxHeight = '';
        wrapper.classList.remove('is-open');
        trigger.setAttribute('aria-expanded', 'false');
      }

      function positionMenu() {
        var gap = 8;
        var viewportPadding = 16;
        var rect = trigger.getBoundingClientRect();
        var clipTop = viewportPadding;
        var clipBottom = window.innerHeight - viewportPadding;
        var modalScrollArea = wrapper.closest('.tm-body') || wrapper.closest('.modal-content');
        if (modalScrollArea) {
          var modalRect = modalScrollArea.getBoundingClientRect();
          clipTop = Math.max(clipTop, modalRect.top + gap);
          clipBottom = Math.min(clipBottom, modalRect.bottom - gap);
        }
        var spaceBelow = clipBottom - rect.bottom - gap;
        var spaceAbove = rect.top - clipTop - gap;
        var shouldOpenAbove = false;
        var availableSpace = spaceBelow;
        var maxHeight = Math.max(140, Math.min(320, Math.floor(availableSpace)));
        menu.classList.toggle('is-above', shouldOpenAbove);
        menu.style.maxHeight = maxHeight + 'px';
      }

      function renderOptions() {
        var currentValue = String(selectEl.value || '');
        var options = Array.prototype.slice.call(selectEl.options || []).filter(function (option) {
          return !(option.disabled && option.hidden);
        });
        var selectedOption = selectEl.options[selectEl.selectedIndex] || null;
        triggerText.textContent = selectedOption && selectedOption.text ? String(selectedOption.text) : placeholder;
        trigger.disabled = !!selectEl.disabled;
        menu.innerHTML = options.map(function (option) {
          var value = String(option.value || '');
          var label = String(option.text || '');
          var selectedClass = value === currentValue ? ' is-selected' : '';
          return '' +
            '<button type="button" class="tm-select-menu-option' + selectedClass + '" data-custom-option-value="' + escapeHtml(value) + '">' +
            escapeHtml(label) +
            '</button>';
        }).join('');

        Array.prototype.forEach.call(menu.querySelectorAll('[data-custom-option-value]'), function (btn) {
          btn.addEventListener('click', function () {
            var nextValue = btn.getAttribute('data-custom-option-value') || '';
            selectEl.value = nextValue;
            if (selectName === 'assigned_company') {
              var departmentSelect = form.querySelector('select[name="assigned_department"]');
              if (departmentSelect) {
                var normalizedCompany = normalizeCompanyValue(nextValue);
                var companyHasDepartments = normalizedCompany === '@leadsagri.com' || normalizedCompany === '@malvedaholdings.com';
                if (companyHasDepartments) {
                  departmentSelect.innerHTML = buildDeptOptionsHtml(normalizedCompany, '');
                  departmentSelect.disabled = false;
                  var mirrorEl = form.querySelector('input[type="hidden"][data-dept-mirror="1"]');
                  if (mirrorEl && mirrorEl.parentNode) {
                    mirrorEl.parentNode.removeChild(mirrorEl);
                  }
                  if (typeof departmentSelect._tmRenderCustomDropdown === 'function') {
                    departmentSelect._tmRenderCustomDropdown();
                  }
                }
              }
            }
            closeMenu();
            renderOptions();
            var changeEvent;
            try {
              changeEvent = new Event('change', { bubbles: true });
            } catch (e) {
              changeEvent = document.createEvent('Event');
              changeEvent.initEvent('change', true, true);
            }
            selectEl.dispatchEvent(changeEvent);
          });
        });
      }

      trigger.addEventListener('click', function () {
        if (trigger.disabled) return;
        var willOpen = !menu.classList.contains('show');
        wrappers.forEach(function (otherWrapper) {
          var otherMenu = otherWrapper.querySelector('[data-custom-select-menu]');
          var otherTrigger = otherWrapper.querySelector('[data-custom-select-trigger]');
          if (!otherMenu || !otherTrigger) return;
          otherMenu.classList.remove('show');
          otherMenu.classList.remove('is-above');
          otherMenu.style.maxHeight = '';
          otherWrapper.classList.remove('is-open');
          otherTrigger.setAttribute('aria-expanded', 'false');
        });
        if (willOpen) {
          renderOptions();
          positionMenu();
          menu.classList.add('show');
          wrapper.classList.add('is-open');
          trigger.setAttribute('aria-expanded', 'true');
          var selected = menu.querySelector('.tm-select-menu-option.is-selected');
          if (selected && typeof selected.scrollIntoView === 'function') {
            selected.scrollIntoView({ block: 'nearest' });
          }
        }
      });

      document.addEventListener('click', function (event) {
        if (wrapper.contains(event.target)) return;
        closeMenu();
      });

      selectEl.addEventListener('change', renderOptions);
      selectEl._tmRenderCustomDropdown = renderOptions;
      renderOptions();
    });
  }
  function bindCustomStatusDropdown(container) {
    if (!container) return;
    var form = container.querySelector('#ticketUpdateForm');
    if (!form || form.dataset.customStatusBound === '1') return;
    form.dataset.customStatusBound = '1';
    var wrapper = form.querySelector('[data-status-segmented]');
    var selectEl = form.querySelector('select[name="status"]');
    var buttons = wrapper ? Array.prototype.slice.call(wrapper.querySelectorAll('[data-status-option]')) : [];
    if (!wrapper || !selectEl || buttons.length === 0) return;

    function renderOptions() {
      var currentValue = String(selectEl.value || '');
      buttons.forEach(function (btn) {
        var value = String(btn.getAttribute('data-status-option') || '');
        var isSelected = value === currentValue;
        btn.classList.toggle('is-selected', isSelected);
        btn.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
        updateStatusColor(btn, value);
      });
    }

    buttons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (selectEl.disabled) return;
        var nextValue = String(btn.getAttribute('data-status-option') || '');
        selectEl.value = nextValue;
        renderOptions();
        var changeEvent;
        try {
          changeEvent = new Event('change', { bubbles: true });
        } catch (e) {
          changeEvent = document.createEvent('Event');
          changeEvent.initEvent('change', true, true);
        }
        selectEl.dispatchEvent(changeEvent);
      });
    });

    selectEl.addEventListener('change', renderOptions);
    selectEl._tmRenderCustomStatus = renderOptions;
    renderOptions();
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
      var companyValue = getCanonicalCompanySelectValue(companyEl);
      deptEl.innerHTML = buildDeptOptionsHtml(companyValue, preferredValue);
      if (typeof deptEl._tmRenderCustomDropdown === 'function') {
        deptEl._tmRenderCustomDropdown();
      }
    }
    function syncDeptAvailability(preferredValue) {
      var companyValue = getCanonicalCompanySelectValue(companyEl);
      var deptOptions = getDeptOptionsForCompany(companyValue);
      var hasDepartmentOptions = deptOptions.length > 0;
      var hiddenMirror = form.querySelector('input[type="hidden"][data-dept-mirror="1"]');
      var selectedValue = preferredDeptValueForCompany(preferredValue, companyValue);
      var hiddenValue = hasDepartmentOptions ? selectedValue : '';

      deptEl.value = selectedValue;
      deptEl.disabled = !hasDepartmentOptions;
      if (typeof deptEl._tmRenderCustomDropdown === 'function') {
        deptEl._tmRenderCustomDropdown();
      }

      if (!hasDepartmentOptions) {
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
      var normalizedCompany = getCanonicalCompanySelectValue(companyEl);
        var nextPreferred = (normalizedCompany === '@leadsagri.com' || normalizedCompany === '@malvedaholdings.com') ? '' : '';
        syncDeptOptions(nextPreferred);
        syncDeptAvailability(nextPreferred);
      });
    deptEl.addEventListener('change', function () {
      syncDeptAvailability(deptEl.value || '');
    });
  }
  function buildHtml(data) {
    var hideUpdateTab = typeof window !== 'undefined' && window.TM_HIDE_UPDATE_TAB === true;
    var hideActionHistoryTab = typeof window !== 'undefined' && window.TM_HIDE_ACTION_HISTORY_TAB === true;
    if (data && data.can_update_tab === false) hideUpdateTab = true;
    var isClosedTicket = !!(data && String(data.status || '').trim().toLowerCase() === 'closed');
    var hideAdminChat = typeof window !== 'undefined' && window.TM_HIDE_ADMIN_CHAT === true;
    var hideRequesterAdminChatButton = typeof window !== 'undefined' && window.TM_HIDE_REQUESTOR_ADMIN_CHAT_BUTTON === true;
    var isSalesTicket = !!(data && data.is_sales_ticket);
    var isSalesManagerRegionalAccess = !!(data && data.sales_manager_regional_access === true);
    var isSalesAssigneeChatAccess = !!(data && data.sales_assignee_chat_access === true);
    if (isClosedTicket) hideUpdateTab = true;
    var hideConversationTab = false;
    if (data && data.hide_conversation_tab === true) hideConversationTab = true;
    if (isSalesTicket && !isSalesManagerRegionalAccess && !isSalesAssigneeChatAccess) hideConversationTab = true;
    var canViewChatHistory = !!(data && data.can_view_chat_history === true);
    var isReassignedViewOnly = !!(data && data.reassigned_view_only === true);
    if (isReassignedViewOnly) {
      hideUpdateTab = true;
      hideConversationTab = !canViewChatHistory;
    }
    var showClaimButton = !!(data && data.can_claim_ticket === true);
    var hideAdminConversationButton = hideRequesterAdminChatButton || (isSalesTicket && !isSalesManagerRegionalAccess && !isSalesAssigneeChatAccess);
    var hideQuickTags = typeof window !== 'undefined' && window.TM_HIDE_QUICK_TAGS === true;
    var showDepartmentUserSelect = typeof window !== 'undefined' && window.TM_SHOW_DEPARTMENT_USER_SELECT === true;
    var deptLabelText = (typeof window !== 'undefined' && window.TM_DEPARTMENT_LABEL_TEXT) ? String(window.TM_DEPARTMENT_LABEL_TEXT) : 'Assigned Department';
    var deptRequired = typeof window !== 'undefined' && window.TM_DEPARTMENT_REQUIRED === true;
    var deptLabelHtml = escapeHtml(deptLabelText) + (deptRequired ? ' <span class="tm-required-star">*</span>' : '');
    var statusSlug = data.status ? data.status.toLowerCase().replace(/\s+/g, '') : 'default';
    var urgencyLabel = data && data.priority ? String(data.priority) : (data && data.urgency ? String(data.urgency) : '-');
    var urgencySlugSource = urgencyLabel.toLowerCase();
    var prioritySlug = urgencySlugSource.indexOf('high') === 0 ? 'high' : (urgencySlugSource.indexOf('medium') === 0 ? 'medium' : (urgencySlugSource.indexOf('low') === 0 ? 'low' : 'default'));
    var resolutionStart = (data && (data.started_at || data.created_at)) ? (data.started_at || data.created_at) : null;
    var resolutionEnd = (data && data.status && (/^(Resolved|Closed)$/i).test(String(data.status)))
      ? (data.resolved_at || data.closed_at || data.updated_at || null)
      : null;
    var resSecondsAll = data && data.duration_seconds != null && data.duration_seconds !== ''
      ? Math.max(0, Number(data.duration_seconds))
      : null;
    if (resSecondsAll != null && !isFinite(resSecondsAll)) resSecondsAll = null;
    var resMinutesAll = resSecondsAll == null ? null : Math.round(resSecondsAll / 60);
    var backendStr = data && data.duration ? String(data.duration) : null;
    var displayStr = resolutionEnd
      ? (formatResolutionStringWithSeconds(resSecondsAll) || backendStr || formatResolutionString(resMinutesAll))
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
    if (isRequesterPOV) hideActionHistoryTab = true;
    var isAssigneeOrHandlerPOV = false;
    if (current && current.id != null && data) {
      var currentUserId = String(current.id);
      isAssigneeOrHandlerPOV =
        (data.assigned_to != null && String(data.assigned_to) === currentUserId)
        || (data.assigned_user_id != null && String(data.assigned_user_id) === currentUserId);
    }
    if (!isAssigneeOrHandlerPOV && current && data) {
      var currentEmail = current.email != null ? String(current.email).toLowerCase() : '';
      var currentName = current.name != null ? String(current.name).trim().toLowerCase() : '';
      var assignedToEmail = data.assigned_to_email != null ? String(data.assigned_to_email).toLowerCase() : '';
      var assigneeEmail = data.assignee_email != null ? String(data.assignee_email).toLowerCase() : '';
      var assignedToName = data.assigned_to_name != null ? String(data.assigned_to_name).trim().toLowerCase() : '';
      var assigneeName = data.assignee_name != null ? String(data.assignee_name).trim().toLowerCase() : '';
      isAssigneeOrHandlerPOV =
        (currentEmail !== '' && (currentEmail === assignedToEmail || currentEmail === assigneeEmail))
        || (currentName !== '' && (currentName === assignedToName || currentName === assigneeName));
    }
    if (isReassignedViewOnly) {
      hideConversationTab = !canViewChatHistory;
    }
    var statusControlHtml = '';
    if (isRequesterPOV) {
      statusControlHtml =
        '          <div class="tm-info-value">' +
        '            <span class="tm-chip tm-chip-' + statusSlug + '">' + escapeHtml(data.status) + '</span>' +
        '          </div>';
    } else {
      statusControlHtml =
        '          <div class="tm-status-picker" data-status-segmented>' +
        '            <select class="tm-select tm-status-select tm-native-select" name="status">' +
        '                  <option value="Open" ' + (data.status === 'Open' ? 'selected' : '') + '>Open</option>' +
        '                  <option value="In Progress" ' + (data.status === 'In Progress' ? 'selected' : '') + '>In Progress</option>' +
        '                  <option value="Resolved" ' + (data.status === 'Resolved' ? 'selected' : '') + '>Resolved</option>' +
        '            </select>' +
        '            <div class="tm-status-trigger-label">Status</div>' +
        '            <div class="tm-status-button-row">' +
        '              <button type="button" class="tm-status-trigger" data-status-option="Open" aria-pressed="false">Open</button>' +
        '              <button type="button" class="tm-status-trigger" data-status-option="In Progress" aria-pressed="false">In Progress</button>' +
        '              <button type="button" class="tm-status-trigger" data-status-option="Resolved" aria-pressed="false">Resolved</button>' +
        '            </div>' +
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
    var assignedCompanyValue = resolveCompanyIdentity(data.assigned_company || data.company || '', data.assigned_company_name || data.company_name || '');
    var assignedDeptValue = data.assigned_department || data.assigned_group || data.department || '';
    var emailRequestTypeDisplay = getEmailRequestTypeDisplay(data);
    var emailRequestTypeInfoHtml = emailRequestTypeDisplay
      ? ('        <div class="tm-info-label">EMAIL REQUEST TYPE</div><div class="tm-info-value">' + escapeHtml(emailRequestTypeDisplay) + '</div>')
      : '';
    var salesTicketInfoHtml = renderSalesTicketInfoHtml(data);
    var deptOptionsHtml = buildDeptOptionsHtml(assignedCompanyValue, assignedDeptValue);
    var assignedUserIdValue = '';
    var selectedDeptKey = normalizeDepartmentKey(assignedDeptValue);
    var canShowDepartmentUserSelect = showDepartmentUserSelect && assignedCompanyUsesDepartment(assignedCompanyValue) && selectedDeptKey !== '';
    var noteValue = '';
    var requesterActionItems = getActionHistoryItems(data);
    if (!requesterActionItems.length && data && data.admin_note != null && String(data.admin_note).trim() !== '') {
      requesterActionItems = [{
        note: String(data.admin_note).trim(),
        created_at: String((data && (data.updated_at || data.created_at)) || '')
      }];
    }
    var requesterNotesHtmlArray = requesterActionItems.map(function (item, index) {
      var noteText = String((item && item.note) || '').trim();
      if (!noteText) return '';
      var actionNumber = index + 1;
      var actionLabels = ['First', 'Second', 'Third', 'Fourth', 'Fifth', 'Sixth', 'Seventh', 'Eighth'];
      var actionLabel = (actionLabels[index] || ('Action #' + actionNumber)) + ' Action';
      var timeStr = escapeHtml(formatTimelineTime(item.created_at));
      return '<div style="padding:14px 0;">' +
        '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">' +
        '<span class="tm-action-badge" style="background:#d1f2d1;color:#1f7a3d;padding:4px 12px;border-radius:12px;font-size:12px;font-weight:700;text-transform:capitalize;">' + escapeHtml(actionLabel) + '</span>' +
        '<div class="tm-requestor-note-time" style="color:#64748b;font-size:12px;font-weight:600;letter-spacing:.06em;">' + timeStr + '</div>' +
        '</div>' +
        '<div class="tm-requestor-note-text" style="color:#0f172a;font-size:14px;font-weight:500;line-height:1.6;white-space:pre-wrap;overflow-wrap:anywhere;">' + renderLinkedText(noteText) + '</div>' +
        '</div>';
    }).filter(function (html) { return html !== ''; });
    var requesterNotesHtml = requesterNotesHtmlArray.join('<div style="border-top:1px solid #e2e8f0;"></div>');
    var requesterAdminNoteHtml = (isRequesterPOV && requesterNotesHtml !== '')
      ? (
        '      <div class="tm-card tm-card-admin-notes" style="height:420px;max-height:420px;display:flex;flex-direction:column;overflow:hidden;align-self:stretch;"><div class="tm-card-header" style="flex:0 0 auto;"><div class="tm-card-header-actions"><span class="tm-card-title">Action Taken/Comments</span></div></div><div class="tm-card-body" style="flex:1 1 auto;min-height:0;overflow-y:auto;">' +
        '        <div class="tm-requestor-note-list" style="display:flex;flex-direction:column;gap:12px;">' + requesterNotesHtml + '</div>' +
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
    var claimButtonHtml = showClaimButton
      ? ('  <div class="tm-tabs-actions"><button type="button" class="tm-claim-ticket-btn" onclick="TMTicketModal.claimTicket(' + String(data.id) + ', this)"><i class="fas fa-user-check"></i><span>Claim Ticket</span></button></div>')
      : '';
    var mobileClaimButtonHtml = showClaimButton
      ? ('  <div class="tm-mobile-claim"><button type="button" class="tm-claim-ticket-btn tm-mobile-claim-btn" onclick="TMTicketModal.claimTicket(' + String(data.id) + ', this)"><i class="fas fa-user-check"></i><span>Claim Ticket</span></button></div>')
      : '';
    var reassignedBannerTone = String((data && data.reassigned_banner_tone) || 'reassigned').toLowerCase();
    var reassignedBannerIsAssigned = reassignedBannerTone === 'assigned';
    var reassignedBannerBorder = reassignedBannerIsAssigned ? '#b7dfc2' : '#f3d273';
    var reassignedBannerBackground = reassignedBannerIsAssigned
      ? 'linear-gradient(180deg,#f4fcf6 0%,#fbfefb 100%)'
      : 'linear-gradient(180deg,#fffaf0 0%,#fffdf7 100%)';
    var reassignedBannerIconBackground = reassignedBannerIsAssigned ? '#e8f7ec' : '#fff3cd';
    var reassignedBannerIconColor = reassignedBannerIsAssigned ? '#1f7a3d' : '#7c5a00';
    var reassignedBannerHtml = isReassignedViewOnly
      ? (
        '    <div class="tm-reassigned-banner" style="margin:8px 14px 18px;border:1px solid ' + reassignedBannerBorder + ';background:' + reassignedBannerBackground + ';border-radius:18px;padding:14px 18px;display:flex;gap:14px;align-items:flex-start;box-shadow:0 10px 24px rgba(15,23,42,.05);">' +
        '      <div style="width:42px;height:42px;border-radius:999px;background:' + reassignedBannerIconBackground + ';color:' + reassignedBannerIconColor + ';display:flex;align-items:center;justify-content:center;flex:0 0 auto;font-size:20px;"><i class="fas fa-lock"></i></div>' +
        '      <div style="min-width:0;font-family:Georgia, \"Times New Roman\", serif;">' +
        '        <div style="font-size:15px;font-weight:700;color:#0f172a;margin-bottom:6px;">' + escapeHtml(String(data.reassigned_banner_heading || 'Ticket Reassigned')) + '</div>' +
        '        <div style="font-size:14px;line-height:1.55;color:#334155;font-weight:400;">' + escapeHtml(String(data.reassigned_title || 'This ticket has been reassigned.')) + '</div>' +
        '        <div style="font-size:14px;line-height:1.55;color:#334155;font-weight:400;">' + escapeHtml(String(data.reassigned_message || 'You can still view the ticket details, but you can no longer respond or access the chat.')) + '</div>' +
        '      </div>' +
        '    </div>'
      )
      : '';
    return '' +
      '<div class="tm-header' + sapHeaderClass + '">' +
      '  <div class="tm-header-left">' +
      '    <div class="tm-title">' + escapeHtml(getDisplaySubject(data)) + '</div>' +
      '    <div class="tm-chips">' +
      '      <span class="tm-chip tm-chip-' + statusSlug + '">' + escapeHtml(data.status) + '</span>' +
      '      <span class="tm-chip tm-chip-' + prioritySlug + '">' + escapeHtml(urgencyLabel) + '</span>' +
      '      <span class="tm-id">#' + String(data.id).padStart(6, '0') + '</span>' +
      '    </div>' +
      '  </div>' +
      '  <button type="button" class="tm-close-btn" onclick="TMTicketModal.close()" aria-label="Close ticket details"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>' +
      '</div>' +
      '<div class="tm-tabs">' +
      '  <div class="tm-tab active" data-tab="info" onclick="TMTicketModal.switchTab(\'info\')">Information</div>' +
      (hideUpdateTab ? '' : '  <div class="tm-tab" data-tab="actions" onclick="TMTicketModal.switchTab(\'actions\')">Update</div>') +
      (hideConversationTab ? '' : '  <div class="tm-tab" data-tab="conversation" onclick="TMTicketModal.openConversation(' + String(data.id) + ')">Go to Chat</div>') +
      (hideActionHistoryTab ? '' : '  <div class="tm-tab" data-tab="action-history" onclick="TMTicketModal.switchTab(\'action-history\')">Action History</div>') +
      claimButtonHtml +
      '</div>' +
      '<div class="tm-body">' +
      (isReassignedViewOnly ? reassignedBannerHtml : '') +
      '  <div id="tab-info" class="tm-tab-content active">' +
      '    <div class="tm-mobile-paged-content">' +
      '    <div class="tm-info-col">' +
      '      <div class="tm-card tm-card-ticket-info"><div class="tm-card-header"><span class="tm-card-title">Ticket Information</span></div><div class="tm-card-body"><div class="tm-info-grid">' +
      '        <div class="tm-info-label">CREATED BY</div><div class="tm-info-value">' + (data.created_by_name ? escapeHtml(String(data.created_by_name)) : '-') + '</div>' +
      '        <div class="tm-info-label">EMAIL</div><div class="tm-info-value">' + (data.created_by_email ? escapeHtml(String(data.created_by_email)) : '-') + '</div>' +
      '        <div class="tm-info-label">DEPARTMENT</div><div class="tm-info-value">' + escapeHtml(dashIfUnknown(data.department)) + '</div>' +
      salesTicketInfoHtml +
      '        <div class="tm-info-label">CATEGORY</div><div class="tm-info-value">' + (data.category ? escapeHtml(String(data.category)) : '-') + '</div>' +
      '        <div class="tm-info-label">URGENCY</div><div class="tm-info-value">' + (data.urgency ? escapeHtml(String(data.urgency)) : '-') + '</div>' +
      emailRequestTypeInfoHtml +
      '        <div class="tm-info-label">CREATED AT</div><div class="tm-info-value">' + (data.created_at ? formatTimelineTime(data.created_at) : '-') + '</div>' +
      '        <div class="tm-info-label">LAST UPDATED</div><div class="tm-info-value">' + (data.updated_at ? formatTimelineTime(data.updated_at) : '-') + '</div>' +
      '        <div class="tm-info-label">ASSIGNED TO</div><div class="tm-info-value">' + buildAssignedTargetHtml(data) + '</div>' +
      '      </div></div></div>' +
      '      <div class="tm-card tm-card-ticket-activity"><div class="tm-card-header"><span class="tm-card-title">Ticket Activity</span></div><div class="tm-card-body">' + renderTimeline(data) + '</div></div>' +
      '    </div>' +
      '    <div class="tm-desc-col">' +
      requesterAdminNoteHtml +
      '      ' + renderIncidentReportDetailsCard(data) +
      '      ' + renderHrRequestDetailsCard(data) +
      '      ' + renderDescriptionAttachmentCards(data) +
      '      ' + renderHrAttachmentCards(data) +
      resolutionCardHtml +
      '      ' + ((data.impact && data.impact !== '-') ? '<div class="tm-card tm-card-impact"><div class="tm-card-header"><span class="tm-card-title">Impact</span></div><div class="tm-card-body"><div class="tm-info-value">' + escapeHtml(String(data.impact)) + '</div></div></div>' : '') +
      '    </div>' +
      '    <nav class="tm-mobile-section-nav" aria-label="Ticket information sections">' +
      '      <button type="button" class="tm-mobile-section-btn" data-mobile-section-previous disabled onclick="TMTicketModal.stepMobileInfoSection(-1)"><i class="fas fa-chevron-left" aria-hidden="true"></i><span>Previous</span></button>' +
      '      <div class="tm-mobile-section-status" aria-live="polite"><strong data-mobile-section-title>Ticket Information</strong><span data-mobile-section-counter>1 of 3</span></div>' +
      '      <button type="button" class="tm-mobile-section-btn is-primary" data-mobile-section-next onclick="TMTicketModal.stepMobileInfoSection(1)"><span>Next</span><i class="fas fa-chevron-right" aria-hidden="true"></i></button>' +
      '    </nav>' +
      '    </div>' +
      '  </div>' +
      (hideActionHistoryTab ? '' : '  <div id="tab-action-history" class="tm-tab-content">' +
      '    <div class="tm-card tm-card-action-history"><div class="tm-card-header"><span class="tm-card-title">Action History</span></div><div class="tm-card-body">' +
      renderActionHistoryHtml(data) +
      '    </div></div>' +
      '  </div>') +
      (hideUpdateTab ? '' : '  <div id="tab-actions" class="tm-tab-content">' +
      '    <div class="tm-card tm-card-ticket-update"><div class="tm-card-header"><span class="tm-card-title">Ticket Update</span></div><div class="tm-card-body">' +
      '    <form id="ticketUpdateForm" method="POST" action="update_ticket.php" class="tm-actions-form">' +
      '      <input type="hidden" name="id" value="' + data.id + '">' +
      '      <input type="hidden" name="csrf_token" value="' + escapeHtml(getCsrfToken()) + '">' +
      '      <input type="hidden" name="update_action_mode" value="status">' +
      '      <div class="tm-nochange" id="tmNoChangeNotice"></div>' +
      '      <div class="tm-update-layout">' +
      '        <div class="tm-update-mode-grid">' +
      '          <button type="button" class="tm-update-mode-card is-active" data-update-action-card="status" aria-pressed="true">' +
      '            <span class="tm-update-mode-icon"><i class="fas fa-check-circle"></i></span>' +
      '            <span class="tm-update-mode-copy">' +
      '              <span class="tm-update-mode-title">Change Status</span>' +
      '              <span class="tm-update-mode-text">Update the current status of this ticket.</span>' +
      '            </span>' +
      '            <span class="tm-update-mode-check"><i class="fas fa-check-circle"></i></span>' +
      '          </button>' +
      '          <button type="button" class="tm-update-mode-card" data-update-action-card="reassign" aria-pressed="false">' +
      '            <span class="tm-update-mode-icon is-neutral"><i class="fas fa-users"></i></span>' +
      '            <span class="tm-update-mode-copy">' +
      '              <span class="tm-update-mode-title">Reassign Ticket</span>' +
      '              <span class="tm-update-mode-text">Transfer this ticket to another department or user.</span>' +
      '            </span>' +
      '            <span class="tm-update-mode-check"><i class="fas fa-check-circle"></i></span>' +
      '          </button>' +
      '        </div>' +
      '        <div class="tm-update-divider"></div>' +
      '      </div>' +
      '      <div class="tm-actions-fields" data-update-action-section="status">' +
      '        <div class="tm-field tm-field-status-only">' +
      statusControlHtml +
      '        </div>' +
      '      </div>' +
      '      <div class="tm-actions-fields" data-update-action-section="reassign" style="display:none;">' +
      '        <div class="tm-field">' +
      '          <label class="tm-control-label">Subsidiaries</label>' +
      '          <div class="tm-select-wrapper tm-custom-select" data-custom-select="assigned_company" data-custom-select-placeholder="Select subsidiary">' +
      '            <select class="tm-select tm-dept-select tm-native-select" name="assigned_company">' +
      ( !assignedCompanyValue ? '                  <option value="" disabled selected hidden>Select Recipient</option>' : '' ) +
      ( assignedCompanyValue && ['@gpsci.net','@farmasee.ph','@leads-farmex.com','@leadsagri.com','@leadsav.com','@malvedaholdings.com','@malvedaproperties.com','@leadstech-corp.com','@lingapleads.org','@primestocks.ph'].indexOf(String(assignedCompanyValue).toLowerCase()) === -1
          ? ('                  <option value="' + escapeHtml(assignedCompanyValue) + '" selected>' + escapeHtml(assignedCompanyValue) + '</option>')
          : '' ) +
      '                  <option value="@gpsci.net" ' + (String(assignedCompanyValue || '').toLowerCase() === '@gpsci.net' ? 'selected' : '') + '>GPCI</option>' +
      '                  <option value="@farmasee.ph" ' + (String(assignedCompanyValue || '').toLowerCase() === '@farmasee.ph' ? 'selected' : '') + '>FARMASEE</option>' +
      '                  <option value="@leads-farmex.com" ' + ((String(assignedCompanyValue || '').toLowerCase() === '@leads-farmex.com' || String(assignedCompanyValue || '').toLowerCase() === '@leadsav.com') ? 'selected' : '') + '>FARMEX / LAV</option>' +
      '                  <option value="@leadsagri.com" ' + (String(assignedCompanyValue || '').toLowerCase() === '@leadsagri.com' ? 'selected' : '') + '>LAPC</option>' +
      '                  <option value="@malvedaholdings.com" ' + (String(assignedCompanyValue || '').toLowerCase() === '@malvedaholdings.com' ? 'selected' : '') + '>MHC</option>' +
      '                  <option value="@malvedaproperties.com" ' + (String(assignedCompanyValue || '').toLowerCase() === '@malvedaproperties.com' ? 'selected' : '') + '>MPDC</option>' +
      '                  <option value="@leadstech-corp.com" ' + (String(assignedCompanyValue || '').toLowerCase() === '@leadstech-corp.com' ? 'selected' : '') + '>LTC</option>' +
      '                  <option value="@lingapleads.org" ' + (String(assignedCompanyValue || '').toLowerCase() === '@lingapleads.org' ? 'selected' : '') + '>LINGAP</option>' +
      '                  <option value="@primestocks.ph" ' + (String(assignedCompanyValue || '').toLowerCase() === '@primestocks.ph' ? 'selected' : '') + '>PCC</option>' +
      '            </select>' +
      '            <button type="button" class="tm-select tm-select-trigger" data-custom-select-trigger aria-haspopup="listbox" aria-expanded="false">' +
      '              <span class="tm-select-trigger-text" data-custom-select-text>Select subsidiary</span>' +
      '              <span class="tm-select-trigger-icon"><i class="fas fa-chevron-down"></i></span>' +
      '            </button>' +
      '            <div class="tm-select-menu" data-custom-select-menu role="listbox"></div>' +
      '          </div>' +
      '        </div>' +
      '        <div class="tm-field">' +
      '          <label class="tm-control-label">' + deptLabelHtml + '</label>' +
      '          <div class="tm-select-wrapper tm-custom-select" data-custom-select="assigned_department" data-custom-select-placeholder="Choose department">' +
      '            <select class="tm-select tm-dept-select tm-native-select" name="assigned_department">' +
      deptOptionsHtml +
      '            </select>' +
      '            <button type="button" class="tm-select tm-select-trigger" data-custom-select-trigger aria-haspopup="listbox" aria-expanded="false">' +
      '              <span class="tm-select-trigger-text" data-custom-select-text>Choose department</span>' +
      '              <span class="tm-select-trigger-icon"><i class="fas fa-chevron-down"></i></span>' +
      '            </button>' +
      '            <div class="tm-select-menu" data-custom-select-menu role="listbox"></div>' +
      '          </div>' +
      '        </div>' +
      (showDepartmentUserSelect ? (
      '        <div class="tm-field tm-dept-user-field" ' + (canShowDepartmentUserSelect ? '' : 'style="display:none;"') + '>' +
      '          <label class="tm-control-label">Department User</label>' +
      '          <div class="tm-select-wrapper tm-custom-select" data-custom-select="assigned_user_id" data-custom-select-placeholder="All">' +
      '            <select class="tm-select tm-dept-user-select tm-native-select" name="assigned_user_id" ' + (canShowDepartmentUserSelect ? '' : 'disabled') + '>' +
      '              <option value="">All</option>' +
      '            </select>' +
      '            <button type="button" class="tm-select tm-select-trigger" data-custom-select-trigger aria-haspopup="listbox" aria-expanded="false">' +
      '              <span class="tm-select-trigger-text" data-custom-select-text>All</span>' +
      '              <span class="tm-select-trigger-icon"><i class="fas fa-chevron-down"></i></span>' +
      '            </button>' +
      '            <div class="tm-select-menu" data-custom-select-menu role="listbox"></div>' +
      '          </div>' +
      '        </div>'
      ) : '') +
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
      '            <button type="submit" class="tm-btn tm-btn-primary">Save Ticket</button>' +
      '          </div>' +
      '        </div>' +
      '      </div>' +
      '    </form>' +
      '    </div></div>' +
      '  </div>') +
      mobileClaimButtonHtml +
      '</div>';
  }
  function buildFallbackHtml(data) {
    var safe = data && typeof data === 'object' ? data : {};
    var ticketId = safe && safe.id != null ? String(safe.id) : '-';
    var ticketIdLabel = /^\d+$/.test(ticketId) ? String(ticketId).padStart(6, '0') : ticketId;
    var subject = getDisplaySubject(safe);
    var status = safe && safe.status ? String(safe.status) : '-';
    var priority = safe && safe.priority ? String(safe.priority) : (safe && safe.urgency ? String(safe.urgency) : '-');
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
    var emailRequestTypeDisplay = getEmailRequestTypeDisplay(safe);
    var emailRequestTypeInfoHtml = emailRequestTypeDisplay
      ? ('        <div class="tm-info-label">EMAIL REQUEST TYPE</div><div class="tm-info-value">' + escapeHtml(emailRequestTypeDisplay) + '</div>')
      : '';
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
      '        <div class="tm-info-label">CATEGORY</div><div class="tm-info-value">' + (safe.category ? escapeHtml(String(safe.category)) : '-') + '</div>' +
      '        <div class="tm-info-label">URGENCY</div><div class="tm-info-value">' + (safe.urgency ? escapeHtml(String(safe.urgency)) : '-') + '</div>' +
      emailRequestTypeInfoHtml +
      '        <div class="tm-info-label">ASSIGNED TO</div><div class="tm-info-value">' + escapeHtml(department) + '</div>' +
      '        <div class="tm-info-label">RECIPIENT</div><div class="tm-info-value">' + escapeHtml(company) + '</div>' +
      '        <div class="tm-info-label">CREATED AT</div><div class="tm-info-value">' + escapeHtml(createdAt) + '</div>' +
      '      </div></div></div>' +
      '    </div>' +
      '    <div class="tm-desc-col">' +
      '      ' + renderIncidentReportDetailsCard(safe) +
      '      ' + renderHrRequestDetailsCard(safe) +
      '      ' + renderDescriptionAttachmentCards(safe) +
      '      ' + renderHrAttachmentCards(safe) +
      '    </div>' +
      '  </div>' +
      '</div>';
  }
  function startChat(ticketId) {
    stopChat();
    bindTypingInput(qs('chatInput'), 'chat');
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
        refreshTypingIndicator('chat', 'chatMessages');
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
    var lastDateKey = '';
    messages.forEach(function (msg) {
      var messageDateValue = msg && (msg.created_at_full || msg.created_at) ? (msg.created_at_full || msg.created_at) : '';
      var nextDateKey = chatDateKey(messageDateValue);
      if (nextDateKey && nextDateKey !== lastDateKey) {
        var separator = createChatDateSeparator(messageDateValue);
        if (separator) container.appendChild(separator);
        lastDateKey = nextDateKey;
      }
      var bubble = document.createElement('div');
      bubble.classList.add('chat-bubble', (msg.is_me ? 'me' : 'other'));
      if (msg && msg.is_edited) bubble.classList.add('has-edited-meta');
      var replyNode = createMessageReplyNode(msg);
      if (replyNode) bubble.appendChild(replyNode);
      var contentDiv = document.createElement('div');
      contentDiv.classList.add('chat-message-text');
      contentDiv.textContent = msg.message;
      bubble.appendChild(contentDiv);
      bubble.appendChild(createMessageMetaNode(msg, formatChatTimeDisplay(messageDateValue || (msg && msg.created_at))));
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
    if (chatReplyContext) {
      formData.append('reply_to_message_id', String(chatReplyContext.messageId || ''));
      formData.append('reply_to_sender_name', String(chatReplyContext.senderName || ''));
      formData.append('reply_to_text', String(chatReplyContext.text || ''));
      formData.append('reply_to_attachment', chatReplyContext.hasImageAttachment ? 'image' : '');
      formData.append('reply_to_attachment_stored_name', String(chatReplyContext.attachmentStoredName || ''));
    }
    var t = getCsrfToken();
    if (t) formData.append('csrf_token', t);
    postJson('chat_send.php', formData)
      .then(function (data) {
        if (btn) btn.disabled = false;
        if (data && data.success) {
          input.value = '';
          clearOwnTyping('chat');
          clearReplyContext('chat');
          if (typeof window !== 'undefined' && typeof window.TMRefreshGlobalChatBadge === 'function') {
            window.TMRefreshGlobalChatBadge();
          }
          loadMessages(ticketIdEl.value, true);
        }
      })
      .catch(function () {
        if (btn) btn.disabled = false;
      });
  }
  var mobileInfoSectionLabels = ['Ticket Information', 'Description', 'Ticket Activity'];
  function setMobileInfoSection(index, shouldScroll) {
    var panel = qs('tab-info');
    if (!panel) return;
    var nextIndex = Math.max(0, Math.min(mobileInfoSectionLabels.length - 1, Number(index) || 0));
    panel.setAttribute('data-mobile-section', String(nextIndex));

    var title = panel.querySelector('[data-mobile-section-title]');
    var counter = panel.querySelector('[data-mobile-section-counter]');
    var previous = panel.querySelector('[data-mobile-section-previous]');
    var next = panel.querySelector('[data-mobile-section-next]');
    if (title) title.textContent = mobileInfoSectionLabels[nextIndex];
    if (counter) counter.textContent = String(nextIndex + 1) + ' of ' + String(mobileInfoSectionLabels.length);
    if (previous) previous.disabled = nextIndex === 0;
    if (next) next.disabled = nextIndex === mobileInfoSectionLabels.length - 1;

    if (shouldScroll && window.matchMedia && window.matchMedia('(max-width: 768px)').matches) {
      var activeSection = nextIndex === 1
        ? panel.querySelector('.tm-desc-col')
        : panel.querySelector('.tm-info-col');
      var scrollTarget = activeSection || panel;
      if (typeof scrollTarget.scrollTo === 'function') {
        scrollTarget.scrollTo({ top: 0, behavior: 'smooth' });
      } else {
        scrollTarget.scrollTop = 0;
      }
    }
  }
  function stepMobileInfoSection(delta) {
    var panel = qs('tab-info');
    if (!panel) return;
    var currentIndex = parseInt(panel.getAttribute('data-mobile-section') || '0', 10);
    setMobileInfoSection(currentIndex + Number(delta || 0), true);
  }
  function switchTab(tabName) {
    document.querySelectorAll('.tm-tab-content').forEach(function (c) { c.classList.remove('active'); });
    document.querySelectorAll('.tm-tab').forEach(function (t) { t.classList.remove('active'); });
    var content = qs('tab-' + tabName);
    var tab = document.querySelector('.tm-tab[data-tab="' + tabName + '"]');
    if (content) content.classList.add('active');
    if (tab) tab.classList.add('active');
    if (tabName === 'actions') {
      var noteEl = qs('tmAdminNote');
      if (noteEl) noteEl.value = '';
    }
    if (tabName === 'info') setMobileInfoSection(0, false);
    if (tabName === 'chat') { /* no-op: chat now opens in separate modal */ }
  }
  function claimTicket(ticketId, buttonEl) {
    if (!ticketId) return;
    if (buttonEl) {
      buttonEl.disabled = true;
      buttonEl.classList.add('is-loading');
    }
    var formData = new FormData();
    formData.append('ticket_id', String(ticketId));
    var token = getCsrfToken();
    if (token) formData.append('csrf_token', token);
    postJson('claim_ticket.php', formData)
      .then(function (data) {
        if (!data || data.ok !== true) {
          throw new Error(String((data && (data.error || data.message)) || 'Unable to claim ticket.'));
        }
        open(ticketId);
      })
      .catch(function (err) {
        if (buttonEl) {
          buttonEl.disabled = false;
          buttonEl.classList.remove('is-loading');
        }
        window.alert(err && err.message ? err.message : 'Unable to claim ticket.');
      });
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
      '    <button class="modal-close" onclick="TMTicketModal.closeChatModal()">&times;</button>' +
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
      '      <input type="file" id="chatModalAttachmentInput" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx" style="display:none;">' +
      '      <div id="chatModalReplyPreview" class="tm-reply-preview" style="display:none;">' +
      '        <div class="tm-reply-preview-body">' +
      '          <div class="tm-reply-preview-label">Reply</div>' +
      '          <div id="chatModalReplyPreviewText" class="tm-reply-preview-text"></div>' +
      '        </div>' +
      '        <button type="button" class="tm-reply-preview-close" id="chatModalReplyPreviewClose" aria-label="Cancel reply">&times;</button>' +
      '      </div>' +
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
        if (chatAttachmentTooLarge(file)) {
          attachInput.value = '';
          setChatModalAttachment(null);
          showChatAttachmentError(chatAttachmentSizeMessage(file));
          return;
        }
        setChatModalAttachment(file);
      });
    }
    var replyCloseBtn = qs('chatModalReplyPreviewClose');
    if (replyCloseBtn) {
      replyCloseBtn.addEventListener('click', function () {
        clearReplyContext('chat');
      });
    }
    bindTypingInput(qs('chatModalInput'), 'modal');
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
    return parts.filter(function (x) { return x && String(x).trim() !== ''; }).join(' \u2022 ');
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
    var lockedText = String(message || chatPermissionState.lockedMessage || '').trim();
    var handlerName = extractHandlerName(lockedText);
    var subtitle = handlerName
      ? ('This ticket is already assigned to <strong>' + escapeHtml(handlerName || 'another IT staff') + '</strong>.')
      : escapeHtml(lockedText || 'You cannot send messages for this ticket.');
    container.innerHTML =
      '<div class="chat-locked-state">' +
      '  <div class="chat-lock-title-row"><span class="chat-locked-icon"><i class="fas fa-lock"></i></span><div class="chat-lock-title">You can\'t message.</div></div>' +
      '  <div class="chat-lock-subtitle">' + subtitle + '</div>' +
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
      input.placeholder = allowed ? 'Type a message...' : (String(lockedMessage || '').trim() || 'You can\'t message.');
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
      clearReplyContext('chat');
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
      setChatModalMetaHtml('<span class="chat-meta-loading">Loading details&hellip;</span>');
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
            (assigneeHtml ? ('<span class="chat-meta-dot">&bull;</span>' + assigneeHtml) : '') +
            (handlerParts.length ? ('<span class="chat-meta-dot">&bull;</span><span class="chat-meta-details">' + escapeHtml(handlerParts.join(' \u2022 ')) + '</span>') : '')
          );
          return;
        }
        var adminParts = handlerParts.slice();
        var isLockedForViewer = data && data.can_chat !== true && !!data.assigned_to_name;
        if (!adminParts.length && data.status === 'Open') adminParts.push('Waiting for IT');
        setChatModalMetaHtml(
          headerHtml +
          (assigneeHtml ? ('<span class="chat-meta-dot">&bull;</span>' + assigneeHtml) : '') +
          (isLockedForViewer ? '' : (adminParts.length ? ('<span class="chat-meta-dot">&bull;</span><span class="chat-meta-details">' + escapeHtml(adminParts.join(' \u2022 ')) + '</span>') : '')) +
          (isLockedForViewer ? '' : (requesterMeta.length ? ('<span class="chat-meta-dot">&bull;</span><span class="chat-meta-details">' + escapeHtml(requesterName + ' \u2022 ' + requesterMeta.join(' \u2022 ')) + '</span>') : ''))
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
              (rest.length ? ('<span class="chat-meta-dot">&bull;</span><span class="chat-meta-details">' + escapeHtml(rest.join(' \u2022 ')) + '</span>') : '')
            );
          } else {
            setChatModalMetaHtml('<span class="chat-meta-with">Chat with <span class="chat-meta-name">Support</span></span>');
          }
        } else {
          // Admin/Assigned POV: show requester and their details, keep assigned context compact
          setChatModalMetaHtml(
            '<span class="chat-meta-with">Chat with <span class="chat-meta-name">' + escapeHtml(requesterName) + '</span></span>' +
            (requesterMeta.length ? ('<span class="chat-meta-dot">&bull;</span><span class="chat-meta-details">' + escapeHtml(requesterMeta.join(' \u2022 ')) + '</span>') : '') +
            (assignedParts.length ? ('<span class="chat-meta-dot">&bull;</span><span class="chat-meta-details">Assigned: ' + escapeHtml(assignedParts.join(' \u2022 ')) + '</span>') : '')
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
    clearReplyContext('chat');
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
    clearReplyContext('chat');
    clearOwnTyping('modal');
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
        renderChatModalMessages(msgs, scrollBottom);
        refreshTypingIndicator('modal', 'chatModalMessages');
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
      if (msg && msg.is_edited) bubble.classList.add('has-edited-meta');
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
      var bubbleAvatarName = senderLabel || (msg && msg.is_me ? 'You' : 'User');
      bubble.classList.add('tm-has-letter-avatar');
      bubble.setAttribute('data-avatar', tmAvatarInitials(bubbleAvatarName));
      bubble.style.setProperty('--tm-avatar-bg', tmAvatarColor(bubbleAvatarName));
      if (senderLabel) {
        var sDiv = document.createElement('div');
        sDiv.classList.add('chat-sender');
        sDiv.textContent = senderLabel;
        bubble.appendChild(sDiv);
      }
      var replyNode = createMessageReplyNode(msg);
      if (replyNode) bubble.appendChild(replyNode);
      if (msg && msg.message) {
        var contentDiv = document.createElement('div');
        contentDiv.textContent = msg.message;
        bubble.appendChild(contentDiv);
      }
      var attachmentNode = createMessageAttachmentNode(msg && msg.attachment ? msg.attachment : null);
      if (attachmentNode) bubble.appendChild(attachmentNode);
      bubble.appendChild(createMessageMetaNode(msg, msg.created_at));
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
    if (chatAttachmentTooLarge(chatModalAttachmentFile)) {
      showChatAttachmentError(chatAttachmentSizeMessage(chatModalAttachmentFile));
      return;
    }
    if (btn && btn.disabled) return;
    var ticketId = String(ticketIdEl.value || '');
    if (btn) btn.disabled = true;
    var formData = new FormData();
    formData.append('ticket_id', ticketId);
    formData.append('message', message);
    if (chatReplyContext) {
      formData.append('reply_to_message_id', String(chatReplyContext.messageId || ''));
      formData.append('reply_to_sender_name', String(chatReplyContext.senderName || ''));
      formData.append('reply_to_text', String(chatReplyContext.text || ''));
      formData.append('reply_to_attachment', chatReplyContext.hasImageAttachment ? 'image' : '');
      formData.append('reply_to_attachment_stored_name', String(chatReplyContext.attachmentStoredName || ''));
    }
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
          clearOwnTyping('modal');
          if (attachInput) attachInput.value = '';
          setChatModalAttachment(null);
          clearReplyContext('chat');
          if (typeof window !== 'undefined' && typeof window.TMRefreshGlobalChatBadge === 'function') {
            window.TMRefreshGlobalChatBadge();
          }
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
  function setMessengerMobileView(view) {
    var modal = qs('tmMessengerModal');
    if (!modal) return;
    var nextView = view === 'chat' ? 'chat' : 'list';
    modal.classList.toggle('tm-mobile-view-chat', nextView === 'chat');
    modal.classList.toggle('tm-mobile-view-list', nextView !== 'chat');
    var tabs = modal.querySelectorAll('.tm-messenger-mobile-tab');
    tabs.forEach(function (tab) {
      var isActive = tab.getAttribute('data-mobile-view') === nextView;
      tab.classList.toggle('active', isActive);
      tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });
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
        '.tm-messenger-edit-input{width:100%;min-height:128px;resize:none;border:1.5px solid #dbe3ec;border-radius:14px;padding:14px 16px;font-size:14px;color:#0f172a;outline:none;background:#ffffff;line-height:1.55;font-family:inherit;box-shadow:inset 0 1px 2px rgba(15,23,42,.04);appearance:none;-webkit-appearance:none;}' +
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
        '.tm-messenger-item.unread-chat .tm-messenger-item-subject{font-weight:600;}' +
        '.tm-messenger-item.tm-has-letter-avatar{position:relative;padding-left:62px!important;}' +
        '.tm-messenger-item.tm-has-letter-avatar::before{content:attr(data-avatar);position:absolute;left:14px;top:50%;transform:translateY(-50%);width:38px;height:38px;border-radius:999px;background:var(--tm-avatar-bg,#1877f2);color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:900;line-height:1;text-transform:uppercase;box-shadow:0 3px 8px rgba(15,23,42,.16),inset 0 0 0 1px rgba(255,255,255,.4);}' +
        '.tm-messenger-overlay .chat-bubble.tm-has-letter-avatar{position:relative;overflow:visible;}' +
        '.tm-messenger-overlay .chat-bubble.other.tm-has-letter-avatar{margin-left:58px;}' +
        '.tm-messenger-overlay .chat-bubble.me.tm-has-letter-avatar{margin-right:58px;}' +
        '.tm-messenger-overlay .chat-bubble.other.tm-has-letter-avatar::before,.tm-messenger-overlay .chat-bubble.me.tm-has-letter-avatar::before{content:attr(data-avatar);position:absolute;bottom:0;width:32px;height:32px;border-radius:999px;background:var(--tm-avatar-bg,#1877f2);color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:900;line-height:1;text-transform:uppercase;box-shadow:0 3px 8px rgba(15,23,42,.14),inset 0 0 0 1px rgba(255,255,255,.4);}' +
        '.tm-messenger-overlay .chat-bubble.other.tm-has-letter-avatar::before{left:-52px;}' +
        '.tm-messenger-overlay .chat-bubble.me.tm-has-letter-avatar::before{right:-52px;left:auto;}' +
        '.tm-messenger-item-top{display:flex;align-items:center;justify-content:space-between;gap:10px;}' +
        '.tm-messenger-item-subject{font-size:13px;font-weight:600;color:#0f172a;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}' +
        '.tm-messenger-item-right{display:flex;align-items:center;gap:8px;flex:0 0 auto;}' +
        '.tm-messenger-item-time{font-size:11px;font-weight:500;color:#64748b;flex:0 0 auto;}' +
        '.unread-badge{background:#22c55e;color:#ffffff;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:900;line-height:1;display:inline-flex;align-items:center;justify-content:center;min-width:20px;}' +
        '.tm-messenger-item-preview{font-size:12px;color:#475569;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}' +
        '.tm-messenger-item-preview.locked{display:flex;align-items:flex-start;gap:6px;color:#64748b;font-weight:500;white-space:normal;overflow:visible;text-overflow:clip;line-height:1.4;}' +
        '.tm-messenger-item-preview .lock-icon{display:inline-flex;align-items:center;justify-content:center;font-size:12px;opacity:.9;}' +
        '.tm-messenger-right{flex:1;min-width:0;display:flex;flex-direction:column;background:#fff;}' +
        '.tm-messenger-right-header{padding:14px 16px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between;gap:12px;}' +
        '.tm-messenger-right-title{display:flex;flex-direction:column;gap:3px;min-width:0;}' +
        '.tm-messenger-title-main{font-size:14px;font-weight:600;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}' +
        '.tm-messenger-title-sub{font-size:12px;font-weight:400;color:#64748b;font-variant-numeric:tabular-nums;-webkit-font-smoothing:antialiased;text-rendering:geometricPrecision;}' +
        '.tm-messenger-meta-text,.tm-messenger-assignee,.tm-messenger-assignee strong,.tm-messenger-sub-sep{font-weight:400;font-variant-numeric:tabular-nums;line-height:1.2;}' +
        '.tm-messenger-meta-text{display:inline-block;}' +
        '.tm-messenger-header-actions{display:flex;align-items:center;gap:8px;flex:0 0 auto;}' +
        '.tm-messenger-menu-wrap{position:relative;display:flex;align-items:center;}' +
        '.tm-messenger-menu-btn{border:1px solid #e2e8f0;background:#ffffff;color:#334155;border-radius:12px;width:42px;height:42px;cursor:pointer;font-weight:900;display:inline-flex;align-items:center;justify-content:center;font-size:22px;line-height:1;}' +
        '.tm-messenger-menu-btn:hover{background:#f8fafc;border-color:#cbd5e1;}' +
        '.tm-messenger-menu-btn:disabled{opacity:.55;cursor:not-allowed;}' +
        '.tm-messenger-menu{position:absolute;top:calc(100% + 8px);right:0;min-width:170px;background:#fff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 18px 40px rgba(2,6,23,.16);padding:8px;display:none;flex-direction:column;gap:6px;z-index:2;}' +
        '.tm-messenger-menu.show{display:flex;}' +
        '.tm-messenger-menu-item{width:100%;border:none;background:#fff;color:#334155;border-radius:10px;padding:10px 12px;cursor:pointer;font-size:13px;font-weight:400;display:flex;align-items:center;justify-content:flex-start;text-align:left;}' +
        '.tm-messenger-menu-item:hover{background:#f8fafc;}' +
        '.tm-messenger-menu-item.danger{color:#dc2626;}' +
        '.tm-messenger-menu-item.danger:hover{background:#fef2f2;}' +
        '.tm-messenger-close{border:none;background:#f1f5f9;color:#0f172a;border-radius:10px;padding:8px 10px;cursor:pointer;font-weight:900;display:inline-flex;align-items:center;justify-content:center;}' +
        '.tm-messenger-close:hover{background:#e2e8f0;}' +
        '.tm-messenger-messages{flex:1;overflow:auto;padding:16px;background:#f9fafb;display:flex;flex-direction:column;gap:6px;}' +
        '.chat-typing-indicator{align-self:flex-start;display:inline-flex;align-items:flex-end;gap:12px;min-height:38px;margin:4px 0 12px 8px;box-sizing:border-box;}' +
        '.chat-typing-avatar{width:32px;height:32px;min-width:32px;border-radius:999px;background:var(--tm-typing-avatar-bg,#008a63);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:900;line-height:1;text-transform:uppercase;box-shadow:0 3px 8px rgba(15,23,42,.14),inset 0 0 0 1px rgba(255,255,255,.4);}' +
        '.chat-typing-bubble{height:34px;min-width:62px;padding:0 15px;border-radius:17px;background:#eaf6ee;display:inline-flex;align-items:center;justify-content:center;gap:7px;box-shadow:0 6px 14px rgba(15,23,42,.06);box-sizing:border-box;}' +
        '.chat-typing-bubble span{width:7px;height:7px;border-radius:999px;background:#16a34a;display:block;animation:tmTypingDot 1.15s infinite ease-in-out;}' +
        '.chat-typing-bubble span:nth-child(2){animation-delay:.16s;}' +
        '.chat-typing-bubble span:nth-child(3){animation-delay:.32s;}' +
        '@keyframes tmTypingDot{0%,80%,100%{opacity:.45;transform:translateY(0);}40%{opacity:1;transform:translateY(-2px);}}' +
        '.tm-messenger-empty{color:#94a3b8;font-weight:700;text-align:center;margin-top:26px;}' +
        '.tm-messenger-compose{border-top:1px solid #e5e7eb;padding:12px;background:#fff;display:flex;gap:8px;align-items:center;flex-wrap:nowrap;position:relative;padding-bottom:64px;}' +
        '.ticket-chat-input-wrapper.has-reply,.tm-messenger-compose.has-reply{margin-top:56px;}' +
        '.tm-reply-preview{position:absolute;left:0;right:0;top:-52px;min-height:44px;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 12px;border:1px solid #dbe3ec;border-radius:14px;background:#f8fafc;box-shadow:0 8px 18px rgba(15,23,42,.06);z-index:3;}' +
        '.tm-reply-preview-body{min-width:0;display:flex;flex-direction:column;gap:2px;}' +
        '.tm-reply-preview-label{font-size:11px;font-weight:800;line-height:1;color:#166534;text-transform:uppercase;letter-spacing:.04em;}' +
        '.tm-reply-preview-text{min-width:0;font-size:12px;line-height:1.35;color:#334155;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}' +
        '.tm-reply-preview-close{flex:0 0 auto;width:26px;height:26px;border:none;border-radius:999px;background:#e2e8f0;color:#334155;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;font-size:18px;line-height:1;padding:0;}' +
        '.tm-reply-preview-close:hover{background:#fecaca;color:#b91c1c;}' +
        '#chatModalComposer.has-reply #chatModalAttachmentName.tm-chat-attachment-selected{top:-102px;}' +
        '.tm-messenger-compose.has-reply #tmMessengerAttachmentName.tm-chat-attachment-selected{top:-100px;}' +
        '.tm-messenger-compose input{flex:1 1 auto;min-width:0;border:1px solid #e5e7eb;border-radius:12px;padding:12px 14px;font-size:14px;outline:none;background:#fff;}' +
        '.tm-messenger-compose input:focus{border-color:#22c55e;box-shadow:0 0 0 4px rgba(34,197,94,.12);}' +
        '.tm-messenger-compose input:disabled{background:#f8fafc;border-color:#dbe3ec;color:#64748b;box-shadow:none;cursor:not-allowed;opacity:.8;}' +
        '.tm-messenger-compose textarea{flex:1 1 auto;min-width:0;min-height:42px;max-height:150px;border:1px solid #e5e7eb;border-radius:12px;padding:11px 14px;font-size:14px;line-height:20px;outline:none;background:#fff;resize:none;overflow:hidden;box-sizing:border-box;font-family:inherit;}' +
        '.tm-messenger-compose textarea:focus{border-color:#22c55e;box-shadow:0 0 0 4px rgba(34,197,94,.12);}' +
        '.tm-messenger-compose textarea:disabled{background:#f8fafc;border-color:#dbe3ec;color:#64748b;box-shadow:none;cursor:not-allowed;opacity:.8;}' +
        '.tm-messenger-compose.is-expanded{align-items:flex-end;}' +
        '.tm-messenger-attach{flex:0 0 auto;width:46px;height:46px;border:none;border-radius:14px;background:#f1f5f9;color:#475569;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:inset 0 0 0 1px #e2e8f0;}' +
        '.tm-messenger-attach:hover{background:#e2e8f0;color:#0f172a;}' +
        '.tm-messenger-attach:disabled{background:#e2e8f0;color:#94a3b8;cursor:not-allowed;box-shadow:none;}' +
        '.tm-chat-attachment-selected{position:absolute;left:68px;right:98px;bottom:8px;min-height:46px;display:none;}' +
        '.tm-chat-attachment-selected.has-file{display:block;}' +
        '.tm-chat-reply{display:flex;flex-direction:column;gap:4px;margin:0 0 8px;padding:8px 10px;border-left:3px solid #86efac;border-radius:10px;background:rgba(241,245,249,.92);max-width:min(280px,100%);}' +
        '.chat-bubble.me .tm-chat-reply{background:rgba(220,252,231,.9);border-left-color:#16a34a;}' +
        '.tm-chat-reply-label{font-size:11px;font-weight:800;line-height:1.2;color:#166534;}' +
        '.chat-bubble.other .tm-chat-reply-label{color:#0f766e;}' +
        '.tm-chat-reply-text{font-size:12px;line-height:1.35;color:#334155;word-break:break-word;}' +
        '.tm-chat-reply-image{display:block;width:52px;height:52px;border-radius:10px;border:1px solid rgba(148,163,184,.25);object-fit:cover;background:#fff;}' +
        '.tm-selected-attachment{display:flex;align-items:center;gap:10px;width:100%;min-height:46px;border:1px solid #dbe3ec;border-radius:14px;background:#f8fafc;box-shadow:0 6px 14px rgba(15,23,42,.06);padding:5px 8px 5px 6px;}' +
        '.tm-selected-attachment-thumb{width:34px;height:34px;border-radius:10px;border:1px solid #e2e8f0;object-fit:cover;display:block;flex:0 0 auto;}' +
        '.tm-selected-attachment-file-icon{width:34px;height:34px;border-radius:10px;background:#e2e8f0;color:#334155;display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;}' +
        '.tm-selected-attachment-meta{min-width:0;flex:1 1 auto;display:flex;flex-direction:column;gap:1px;}' +
        '.tm-selected-attachment-name{color:#0f172a;font-size:12px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}' +
        '.tm-selected-attachment-size{color:#64748b;font-size:11px;font-weight:700;}' +
        '.tm-selected-attachment-remove{width:28px;height:28px;border:none;border-radius:999px;background:#e2e8f0;color:#334155;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;flex:0 0 auto;}' +
        '.tm-selected-attachment-remove:hover{background:#fecaca;color:#b91c1c;}' +
        '.tm-chat-attachment{display:flex;flex-direction:column;gap:8px;margin-top:2px;}' +
        '.tm-chat-attachment-group{display:flex;flex-direction:row;align-items:stretch;gap:8px;margin-top:4px;max-width:min(430px,100%);overflow:hidden;padding:2px 0 4px;}' +
        '.tm-chat-attachment-group::-webkit-scrollbar{display:none;}' +
        '.tm-chat-attachment-group.files-only{flex-direction:column;align-items:stretch;gap:7px;max-width:min(330px,100%);overflow:visible;padding:2px 0;}' +
        '.tm-chat-attachment-group .tm-chat-attachment{margin-top:0;min-width:112px;flex:0 0 112px;}' +
        '.tm-chat-attachment-group .tm-chat-attachment-hidden{position:absolute;width:0;height:0;overflow:hidden;opacity:0;pointer-events:none;}' +
        '.tm-chat-attachment-group .tm-chat-attachment.has-more{position:relative;}' +
        '.tm-chat-attachment-more{position:absolute;inset:0;border:none;border-radius:10px;background:rgba(15,23,42,.58);color:#ffffff;font-size:14px;font-weight:900;display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:3;backdrop-filter:blur(1px);}' +
        '.tm-chat-attachment-more:hover{background:rgba(15,23,42,.68);}' +
        '.tm-chat-attachment-group.files-only .tm-chat-attachment{min-width:0;flex:0 0 auto;width:100%;line-height:1;}' +
        '.tm-chat-attachment-group .tm-chat-attachment:has(.tm-chat-attachment-link:not(.tm-chat-attachment-button)){min-width:185px;flex-basis:185px;}' +
        '.tm-chat-attachment-group.files-only .tm-chat-attachment:has(.tm-chat-attachment-link:not(.tm-chat-attachment-button)){min-width:0;flex-basis:auto;}' +
        '.tm-chat-attachment-link{display:inline-flex;align-items:center;gap:10px;color:inherit;text-decoration:none;max-width:100%;}' +
        '.tm-chat-attachment-button{appearance:none;-webkit-appearance:none;padding:0;border:none;background:transparent;cursor:zoom-in;position:relative;z-index:1;}' +
        '.tm-chat-attachment-icon{width:34px;height:34px;border-radius:12px;background:rgba(255,255,255,.18);display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;}' +
        '.tm-messenger-overlay .chat-bubble.other .tm-chat-attachment-icon{background:#e2e8f0;color:#334155;}' +
        '.tm-chat-attachment-name{font-size:13px;font-weight:700;line-height:1.35;word-break:break-word;}' +
        '.tm-chat-attachment-image{display:block;max-width:min(260px,100%);max-height:220px;border-radius:14px;border:1px solid rgba(148,163,184,.28);object-fit:cover;background:#fff;cursor:zoom-in;pointer-events:auto;position:relative;z-index:1;}' +
        '.tm-chat-attachment-group .tm-chat-attachment-image{width:112px;height:86px;max-width:none;max-height:none;object-fit:cover;border-radius:10px;}' +
        '.tm-chat-attachment-group .tm-chat-attachment-link:not(.tm-chat-attachment-button){width:185px;min-height:54px;border-radius:12px;padding:8px 10px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.18);box-sizing:border-box;}' +
        '.tm-chat-attachment-group.files-only .tm-chat-attachment-link:not(.tm-chat-attachment-button){width:100%;}' +
        '.tm-chat-attachment-group .tm-chat-attachment-link:not(.tm-chat-attachment-button) .tm-chat-attachment-icon{width:34px;height:34px;border-radius:10px;}' +
        '.tm-chat-attachment-group .tm-chat-attachment-link:not(.tm-chat-attachment-button) .tm-chat-attachment-name{display:block;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:12px;line-height:1.25;}' +
        '.tm-messenger-send{border:none;background:#1B5E20;color:#fff;border-radius:12px;padding:12px 14px;font-weight:900;cursor:pointer;min-width:86px;}' +
        '.tm-messenger-send:disabled{opacity:.6;cursor:not-allowed;}' +
        '.tm-messenger-overlay .chat-bubble{max-width:80%;padding:10px 14px;border-radius:16px;font-size:14px;line-height:1.5;word-wrap:break-word;display:flex;flex-direction:column;gap:2px;box-shadow:0 1px 2px rgba(0,0,0,.04);position:relative;overflow:visible;margin-bottom:12px;}' +
        '.tm-messenger-overlay .chat-bubble.has-edited-meta{margin-bottom:44px;}' +
        '.tm-messenger-overlay .chat-bubble.me{align-self:flex-end;background:#dff4e9;color:#0f172a;border:1px solid #bfe7d0;border-bottom-right-radius:4px;}' +
        '.tm-messenger-overlay .chat-bubble.other{align-self:flex-start;background:#eef9f2;color:#0f172a;border:1px solid #cfeede;border-bottom-left-radius:4px;}' +
        '.tm-messenger-overlay .chat-sender{font-size:12px;font-weight:800;opacity:.9;}' +
        '.tm-messenger-overlay .chat-bubble.me .chat-sender{color:#166534;}' +
        '.tm-messenger-overlay .chat-bubble.other .chat-sender{color:#475569;}' +
        '.tm-messenger-overlay .chat-time{position:static;z-index:2;font-size:10px;font-weight:400;color:#111827;opacity:1;margin-top:0;align-self:flex-start;text-shadow:none;display:flex;align-items:center;justify-content:flex-start;gap:4px;white-space:nowrap;flex-wrap:nowrap;}' +
        '.tm-messenger-overlay .chat-bubble.other .chat-time{color:#111827;}' +
        '.tm-messenger-overlay .chat-edited{position:absolute;right:10px;bottom:-35px;z-index:2;font-size:11px;font-weight:800;color:#2563eb;line-height:1.2;align-self:auto;text-shadow:none;}' +
        '.tm-messenger-overlay .chat-bubble.has-edited-meta .chat-time,.tm-messenger-overlay .chat-bubble.has-edited-meta .chat-edited{right:auto;left:10px;width:auto;text-align:left;}' +
        '.tm-messenger-overlay .chat-bubble.has-edited-meta .chat-time{justify-content:flex-start;}' +
        '.tm-messenger-overlay .chat-bubble.has-edited-meta .chat-time{bottom:auto;}' +
        '.tm-messenger-overlay .chat-bubble.has-edited-meta .chat-edited{bottom:-35px;}' +
        '.tm-messenger-overlay .chat-bubble.has-edited-meta.other .chat-time,.tm-messenger-overlay .chat-bubble.has-edited-meta.other .chat-edited{right:auto;left:10px;}' +
        '.tm-messenger-overlay .chat-bubble.other .chat-edited{right:auto;left:10px;}' +
        '.tm-messenger-overlay .chat-bubble.me .chat-edited{color:#60a5fa;}' +
        '.tm-messenger-overlay .chat-read-status{display:inline-flex;align-items:center;font-size:11px;font-weight:900;letter-spacing:-1px;line-height:1;color:#4b5563;}' +
        '.tm-messenger-overlay .chat-read-status svg{width:15px;height:15px;display:block;}' +
        '.tm-messenger-overlay .chat-read-status.seen{color:#2563eb;}' +
        '.tm-messenger-overlay .chat-time{flex-wrap:nowrap;}' +
        '.tm-messenger-overlay .chat-delivery-label{position:absolute;margin:0;display:block;align-items:center;gap:4px;font-size:11px;font-weight:500;letter-spacing:0;color:#64748b;text-align:right;line-height:1;white-space:nowrap;top:calc(100% + 8px);right:0;}' +
        '.tm-messenger-overlay .chat-delivery-label.seen{color:#64748b;}' +
        '.tm-messenger-overlay .chat-date-separator{width:100%;display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:14px;margin:8px 0 2px;color:#94a3b8;font-size:12px;font-weight:500;line-height:1;}' +
        '.tm-messenger-overlay .chat-date-separator span{height:1px;background:#e5e7eb;}' +
        '.tm-messenger-overlay .chat-date-separator strong{font:inherit;color:inherit;}' +
        '.tm-messenger-overlay .chat-status-legend{display:flex;align-items:center;justify-content:center;gap:22px;width:100%;padding:8px 12px 10px;border-top:1px dashed #e5e7eb;color:#64748b;font-size:12px;font-weight:700;line-height:1;}' +
        '.tm-messenger-overlay .chat-legend-item{display:inline-flex;align-items:center;gap:7px;white-space:nowrap;}' +
        '.tm-messenger-overlay .chat-legend-dot{width:8px;height:8px;border-radius:999px;background:#1877f2;box-shadow:0 0 0 3px rgba(24,119,242,.12);}' +
        '.tm-messenger-overlay .chat-legend-read{display:inline-flex;align-items:center;font-size:12px;font-weight:900;letter-spacing:-1px;line-height:1;}' +
        '.tm-messenger-overlay .chat-legend-read.seen{color:#2563eb;}' +
        '.tm-messenger-overlay .chat-legend-read.delivered{color:#6b7280;}' +
        '.tm-messenger-overlay .chat-bubble .chat-meta{display:flex;flex-direction:column;align-items:flex-start;justify-content:flex-start;gap:6px;margin-top:6px;align-self:flex-start;max-width:100%;white-space:normal;}' +
        '.tm-messenger-overlay .chat-bubble.other .chat-meta{align-self:flex-start;justify-content:flex-start;}' +
        '.tm-messenger-overlay .chat-bubble .chat-meta .chat-time,.tm-messenger-overlay .chat-bubble.has-edited-meta .chat-meta .chat-time{position:static;inset:auto;width:auto;text-align:inherit;margin:0;padding:0;}' +
        '.tm-messenger-overlay .chat-bubble.me .chat-meta .chat-time{width:100%;justify-content:flex-end;}' +
        '.tm-messenger-overlay .chat-bubble.other .chat-meta .chat-time{text-align:left;}' +
        '.tm-messenger-overlay .chat-bubble .chat-meta .chat-edited,.tm-messenger-overlay .chat-bubble.has-edited-meta .chat-meta .chat-edited,.tm-messenger-overlay .chat-bubble.other .chat-meta .chat-edited{position:static;inset:auto;width:auto;text-align:inherit;margin:0;}' +
        '.tm-messenger-overlay .chat-bubble{margin-bottom:12px;}' +
        '.tm-messenger-overlay .chat-bubble.has-edited-meta{margin-bottom:42px;}' +
        '.tm-messenger-overlay .chat-bubble .chat-meta .chat-edited{appearance:none;-webkit-appearance:none;border:none;background:transparent;padding:0;cursor:pointer;font:inherit;font-weight:500;color:#2563eb;text-decoration:underline;text-underline-offset:2px;}' +
        '.tm-messenger-overlay .chat-bubble.me .chat-meta .chat-edited{color:#60a5fa;}' +
        '.tm-message-history-overlay{position:fixed;inset:0;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(15,23,42,.52);z-index:2147483647;}' +
        '.tm-message-history-box{width:min(470px,88vw);max-height:min(430px,78vh);overflow:hidden;background:#fff;border:1px solid #d1d5db;border-radius:18px;box-shadow:0 16px 38px rgba(15,23,42,.14);display:flex;flex-direction:column;}' +
        '.tm-message-history-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:18px 24px;border-bottom:1px solid #e5e7eb;}' +
        '.tm-message-history-title{font-size:20px;font-weight:400;color:#0f172a;letter-spacing:0;line-height:1.15;}' +
        '.tm-message-history-close{width:38px;height:38px;border:none;border-radius:13px;background:#f3f4f6;color:#0f172a;cursor:pointer;font-size:28px;font-weight:300;line-height:1;display:inline-flex;align-items:center;justify-content:center;}' +
        '.tm-message-history-list{overflow:auto;padding:18px 24px 24px;display:flex;flex-direction:column;gap:10px;}' +
        '.tm-message-history-item{border:1px solid #d1d5db;border-radius:14px;background:#fff;padding:14px 18px;}' +
        '.tm-message-history-meta{color:#64748b;font-size:13px;font-weight:600;margin-bottom:12px;line-height:1.25;}' +
        '.tm-message-history-text{color:#0f172a;font-size:15px;line-height:1.4;white-space:pre-wrap;word-break:break-word;}' +
        '.tm-message-history-empty{color:#64748b;font-weight:700;text-align:center;padding:24px 8px;}' +
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
          '.tm-messenger-compose{display:flex;gap:8px;padding:12px;border-top:1px solid #eee;background:#fff;position:sticky;bottom:0;flex-wrap:nowrap;padding-bottom:62px;}' +
          '.tm-messenger-compose input{flex:1 1 auto;min-width:0;border-radius:12px;padding:10px 12px;border:1px solid #ddd;min-height:44px;}' +
          '.tm-messenger-compose textarea{flex:1 1 auto;min-width:0;border-radius:12px;padding:10px 12px;border:1px solid #ddd;min-height:44px;max-height:150px;line-height:20px;resize:none;overflow:hidden;box-sizing:border-box;font-family:inherit;}' +
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
        '.tm-messenger-overlay.employee-style .tm-messenger-panel{position:relative;width:min(96vw,1220px);height:min(92vh,760px);max-height:92vh;border-radius:22px;overflow:hidden;background:#ffffff;border:1px solid rgba(220,235,226,.95);box-shadow:0 26px 60px rgba(15,23,42,.16),0 12px 26px rgba(15,23,42,.08);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-panel::before{content:none;display:none;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-left{width:360px;min-width:360px;max-width:360px;background:linear-gradient(180deg,#fcfcfd 0%,#f8fafc 100%);border-right:1px solid #e2e8f0;padding-top:0;}' +
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
        '.tm-messenger-overlay.employee-style .tm-messenger-item-preview.locked{display:flex;align-items:flex-start;gap:7px;color:#475569;font-weight:500;white-space:normal;overflow:visible;text-overflow:clip;line-height:1.42;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-item-time{font-size:12px;font-weight:800;color:#475569;}' +
        '.tm-messenger-overlay.employee-style .unread-badge{width:10px;height:10px;min-width:10px;border-radius:999px;background:#16a34a;color:transparent;font-size:0;display:inline-flex;align-items:center;justify-content:center;padding:0;box-shadow:0 0 0 3px rgba(22,163,74,.14);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-right{background:linear-gradient(180deg,#ffffff 0%,#fbfcfd 100%);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-right-header{padding:26px 22px 16px;border-bottom:1px solid #e5e7eb;background:#fff;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-title-main{font-size:20px;font-weight:600;color:#0f172a;letter-spacing:0;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-title-sub{margin-top:8px;font-size:13px;color:#475569;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-status-pill{display:inline-flex;align-items:center;gap:7px;padding:6px 12px;border-radius:999px;background:#f6fbf7;border:1px solid #d9efe0;color:#0f172a;font-size:13px;font-weight:800;line-height:1;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-status-pill::before{content:\"\";width:10px;height:10px;border-radius:999px;background:#22a55a;display:inline-block;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-status-pill.status-in-progress::before{background:#16a34a;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-status-pill.status-resolved{background:#dbeafe;border-color:#bfdbfe;color:#1d4ed8;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-status-pill.status-resolved::before{background:#60a5fa;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-status-pill.status-closed::before{background:#94a3b8;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-assignee{font-size:15px;color:#475569;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-assignee strong{color:#0f172a;font-weight:800;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-sub-sep{color:#cbd5e1;font-weight:800;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-menu-btn,.tm-messenger-overlay.employee-style .tm-messenger-close{width:48px;height:48px;border-radius:15px;border:1px solid #dbe3ec;background:#fff;box-shadow:0 8px 18px rgba(15,23,42,.08);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-close{display:inline-flex;font-size:28px;line-height:1;color:#dc2626;border-color:#fecaca;background:#fff5f5;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-close:hover{background:#fee2e2;border-color:#fca5a5;color:#b91c1c;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-menu{top:calc(100% + 10px);min-width:208px;border-radius:18px;padding:10px;box-shadow:0 20px 46px rgba(15,23,42,.16);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-menu-item{padding:12px 14px;font-size:14px;font-weight:400;border-radius:12px;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-messages{padding:20px 22px 16px;background:linear-gradient(180deg,#ffffff 0%,#fbfbfd 100%);gap:6px;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble{position:relative;overflow:visible;display:flex;flex-direction:column;max-width:min(68%,460px);margin-top:24px;margin-bottom:14px;padding:16px 20px 24px;border-radius:24px;border:1px solid #e6edf3;box-shadow:0 16px 34px rgba(15,23,42,.08);gap:2px;backdrop-filter:blur(2px);transition:transform .18s ease,box-shadow .18s ease;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.has-edited-meta{margin-bottom:50px;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble:hover{transform:translateY(-1px);}' +
        '.tm-messenger-overlay.employee-style .chat-bubble::after{content:"";position:absolute;bottom:10px;width:18px;height:18px;border-radius:0 0 16px 0;transform:rotate(45deg);z-index:0;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.other{background:linear-gradient(180deg,#f6fff9 0%,#eef9f2 100%);color:#0f172a;border-color:#cfeede;border-bottom-left-radius:12px;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.other::after{left:-7px;background:linear-gradient(180deg,#f6fff9 0%,#eef9f2 100%);border-left:1px solid #cfeede;border-bottom:1px solid #cfeede;box-shadow:-10px 10px 20px rgba(15,23,42,.03);}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.me{min-width:112px;background:linear-gradient(180deg,#eaf8f0 0%,#dff4e9 100%);color:#0f172a;border-color:#bfe7d0;border-bottom-right-radius:12px;box-shadow:0 18px 34px rgba(15,23,42,.10);}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.me::after{right:-7px;background:linear-gradient(180deg,#eaf8f0 0%,#dff4e9 100%);border-right:1px solid #bfe7d0;border-bottom:1px solid #bfe7d0;box-shadow:10px 10px 20px rgba(15,23,42,.04);}' +
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
        '.tm-messenger-overlay.employee-style .chat-sender{position:absolute;top:-30px;z-index:2;font-size:13px;font-weight:800;line-height:1.2;letter-spacing:.01em;white-space:nowrap;text-shadow:0 1px 0 rgba(255,255,255,.7);}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.me .chat-sender{right:10px;color:#245f2a;text-align:right;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.other .chat-sender{left:10px;color:#3a4b63;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble .chat-message-text,.tm-messenger-overlay.employee-style .chat-bubble > div:not(.chat-sender):not(.chat-time):not(.tm-msg-actions):not(.tm-chat-attachment){position:relative;z-index:1;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble .chat-message-text{white-space:pre-wrap;word-break:break-word;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble .tm-chat-attachment{position:relative;z-index:1;margin-top:4px;}' +
        '.tm-messenger-overlay.employee-style .chat-time{position:static;z-index:2;font-size:11px;font-weight:400;opacity:1;align-self:flex-start;margin-top:0;letter-spacing:.01em;padding:0;color:#111827;text-shadow:none;display:flex;align-items:center;justify-content:flex-start;gap:4px;white-space:nowrap;flex-wrap:nowrap;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.me .chat-time{color:#111827;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.other .chat-time{color:#111827;}' +
        '.tm-messenger-overlay.employee-style .chat-edited{position:absolute;right:12px;bottom:-42px;z-index:2;font-size:12px;font-weight:900;color:#2563eb;line-height:1.2;align-self:auto;text-shadow:none;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.has-edited-meta .chat-time,.tm-messenger-overlay.employee-style .chat-bubble.has-edited-meta .chat-edited{right:auto;left:12px;width:auto;text-align:left;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.has-edited-meta .chat-time{justify-content:flex-start;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.has-edited-meta .chat-time{bottom:auto;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.has-edited-meta .chat-edited{bottom:-42px;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.has-edited-meta.other .chat-time,.tm-messenger-overlay.employee-style .chat-bubble.has-edited-meta.other .chat-edited{right:auto;left:12px;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.other .chat-edited{right:auto;left:12px;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.me .chat-edited{color:#60a5fa;}' +
        '.tm-messenger-overlay.employee-style .chat-read-status{display:inline-flex;align-items:center;font-size:11px;font-weight:900;letter-spacing:-1px;line-height:1;color:#4b5563;}' +
        '.tm-messenger-overlay.employee-style .chat-read-status svg{width:16px;height:16px;display:block;}' +
        '.tm-messenger-overlay.employee-style .chat-read-status.seen{color:#2563eb;}' +
        '.tm-messenger-overlay.employee-style .chat-time{flex-wrap:nowrap;}' +
        '.tm-messenger-overlay.employee-style .chat-delivery-label{position:absolute;margin:0;display:block;align-items:center;gap:4px;font-size:11px;font-weight:500;letter-spacing:0;color:#64748b;text-align:right;line-height:1;white-space:nowrap;top:calc(100% + 8px);right:0;}' +
        '.tm-messenger-overlay.employee-style .chat-delivery-label.seen{color:#64748b;}' +
        '.tm-messenger-overlay.employee-style .chat-date-separator{width:100%;display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:14px;margin:8px 0 2px;color:#94a3b8;font-size:12px;font-weight:500;line-height:1;}' +
        '.tm-messenger-overlay.employee-style .chat-date-separator span{height:1px;background:#e5e7eb;}' +
        '.tm-messenger-overlay.employee-style .chat-date-separator strong{font:inherit;color:inherit;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble .chat-meta{display:flex;flex-direction:column;align-items:flex-start;justify-content:flex-start;gap:6px;margin-top:6px;align-self:flex-start;max-width:100%;white-space:normal;position:static;z-index:1;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.me .chat-meta{align-self:stretch;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.other .chat-meta{align-self:flex-start;justify-content:flex-start;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble .chat-meta .chat-time,.tm-messenger-overlay.employee-style .chat-bubble.has-edited-meta .chat-meta .chat-time{position:static;inset:auto;width:auto;text-align:inherit;margin:0;padding:0;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.me .chat-meta .chat-time{width:100%;justify-content:flex-end;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.other .chat-meta .chat-time{text-align:left;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble .chat-meta .chat-edited,.tm-messenger-overlay.employee-style .chat-bubble.has-edited-meta .chat-meta .chat-edited,.tm-messenger-overlay.employee-style .chat-bubble.other .chat-meta .chat-edited{position:static;inset:auto;width:auto;text-align:inherit;margin:0;padding:0;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble{margin-bottom:28px;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.has-edited-meta{margin-bottom:62px;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble .chat-meta .chat-time{color:rgba(17,24,39,.9);}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.me .chat-meta .chat-time{color:rgba(17,24,39,.9);}' +
        '.tm-messenger-overlay.employee-style .chat-bubble .chat-meta .chat-edited{appearance:none;-webkit-appearance:none;border:none;background:transparent;cursor:pointer;font:inherit;font-weight:500;color:#2563eb;text-decoration:underline;text-underline-offset:2px;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.me .chat-meta .chat-edited{color:#2563eb;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-locked-state{min-height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:16px;padding:32px 20px;color:#475569;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-lock-title-row{display:inline-flex;align-items:center;gap:12px;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-locked-icon{width:34px;height:34px;border-radius:10px;background:#f3f4f6;color:#4b5563;display:inline-flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-lock-title{font-size:16px;font-weight:500;color:#4b5563;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-lock-subtitle{font-size:14px;color:#475569;max-width:520px;line-height:1.55;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-lock-subtitle strong{color:#0f172a;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-compose{padding:14px 14px 16px;border-top:1px solid #e5e7eb;background:#fff;gap:8px;flex-wrap:nowrap;position:relative;padding-bottom:68px;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-compose input{flex:1 1 auto;min-width:0;border-radius:16px;border:1.5px solid #86efac;padding:13px 16px;font-size:14px;min-height:50px;box-shadow:0 0 0 4px rgba(34,197,94,.08);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-compose input:focus{border-color:#22c55e;box-shadow:0 0 0 5px rgba(34,197,94,.12);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-compose input:disabled{border-color:#dbe3ec;background:#f8fafc;color:#64748b;box-shadow:none;cursor:not-allowed;opacity:.8;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-compose textarea{flex:1 1 auto;min-width:0;border-radius:16px;border:1.5px solid #86efac;padding:13px 16px;font-size:14px;min-height:50px;max-height:150px;line-height:20px;box-shadow:0 0 0 4px rgba(34,197,94,.08);resize:none;overflow:hidden;box-sizing:border-box;font-family:inherit;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-compose textarea:focus{border-color:#22c55e;box-shadow:0 0 0 5px rgba(34,197,94,.12);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-compose textarea:disabled{border-color:#dbe3ec;background:#f8fafc;color:#64748b;box-shadow:none;cursor:not-allowed;opacity:.8;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-compose.is-expanded{align-items:flex-end;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-attach{width:50px;height:50px;border-radius:16px;background:#f8fafc;color:#475569;box-shadow:inset 0 0 0 1px #dbe3ec;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-attach:hover{background:#eef2f7;color:#0f172a;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-attach:disabled{background:#e2e8f0;color:#94a3b8;box-shadow:none;}' +
        '.tm-messenger-overlay.employee-style .tm-chat-attachment-selected{left:76px;right:110px;bottom:8px;}' +
        '.tm-messenger-overlay.employee-style .tm-chat-attachment-image{max-width:min(280px,100%);border-radius:16px;box-shadow:0 8px 18px rgba(15,23,42,.08);}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.me .tm-chat-attachment-icon{background:rgba(22,101,52,.14);color:#166534;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.me .tm-chat-attachment-name{color:#0f172a;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.other .tm-chat-attachment-name{color:#0f172a;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-send{min-width:96px;min-height:50px;padding:0 20px;border-radius:16px;background:linear-gradient(180deg,#1f5f23 0%,#174d1b 100%);font-size:14px;letter-spacing:.01em;box-shadow:0 14px 26px rgba(23,77,27,.22);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-send:hover{background:linear-gradient(180deg,#205d24 0%,#154819 100%);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-send:disabled{background:#cbd5e1;color:#fff;box-shadow:none;cursor:not-allowed;opacity:1;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-empty{font-size:15px;color:#94a3b8;}' +
        '@media (max-width: 980px){.tm-messenger-overlay.employee-style{padding:10px}.tm-messenger-overlay.employee-style .tm-messenger-panel{width:min(96vw,940px);height:min(84vh,720px);border-radius:20px}.tm-messenger-overlay.employee-style .tm-messenger-left{width:300px;min-width:300px;max-width:300px}.tm-messenger-overlay.employee-style .tm-messenger-title-main{font-size:18px;font-weight:700}.tm-messenger-overlay.employee-style .chat-bubble{max-width:80%;}}' +
        '@media (max-width: 768px){.tm-messenger-overlay.employee-style{padding:0;align-items:flex-end}.tm-messenger-overlay.employee-style .tm-messenger-panel{height:88vh;border-radius:22px 22px 0 0}.tm-messenger-overlay.employee-style .tm-messenger-panel::before{content:none;display:none}.tm-messenger-overlay.employee-style .tm-messenger-left{width:100%;min-width:0;max-width:none;height:40%}.tm-messenger-overlay.employee-style .tm-messenger-right{height:60%}.tm-messenger-overlay.employee-style .tm-messenger-left-header{padding:20px 16px 10px}.tm-messenger-overlay.employee-style .tm-messenger-right-header{padding:20px 16px 12px}.tm-messenger-overlay.employee-style .tm-messenger-title-main{font-size:17px}.tm-messenger-overlay.employee-style .tm-messenger-search{padding:0 12px 10px}.tm-messenger-overlay.employee-style .tm-messenger-search::before{left:25px}.tm-messenger-overlay.employee-style .tm-messenger-filters{padding:0 12px 12px;gap:6px;overflow-x:auto;}.tm-messenger-overlay.employee-style .tm-messenger-filter-btn{padding:9px 12px;font-size:12px;border-radius:12px}.tm-messenger-overlay.employee-style .tm-messenger-messages{padding:16px 14px}.tm-messenger-overlay.employee-style .tm-messenger-compose{padding:12px 12px 14px;padding-bottom:64px}.tm-messenger-overlay.employee-style .tm-chat-attachment-selected{left:70px;right:100px}.tm-messenger-overlay.employee-style .tm-messenger-send{min-width:86px;min-height:48px;border-radius:15px;}}';
      document.head.appendChild(employeeStyle);
    }

    if (!document.getElementById('tmMessengerUiPolishStyles')) {
      var polishStyle = document.createElement('style');
      polishStyle.id = 'tmMessengerUiPolishStyles';
      polishStyle.textContent =
        '.tm-messenger-overlay.employee-style{background:rgba(15,23,42,.54);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-panel{border-radius:20px;overflow:hidden;border:1px solid rgba(244,196,48,.74);border-top:3px solid #f4c430;box-shadow:0 32px 88px rgba(15,23,42,.34);background:#ffffff;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-panel::before{content:none;display:none;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-left,.tm-messenger-overlay.employee-style .tm-messenger-right{position:relative;z-index:1;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-left{background:#fbfcfd;border-right:1px solid rgba(148,163,184,.28);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-left-header{height:88px;padding:0 22px;border-bottom:1px solid rgba(255,255,255,.08);background:#0f4f25;align-items:center;gap:18px;}' +
        '.tm-messenger-overlay.employee-style .conversation-sidebar-header{background:#0b4f22!important;color:#ffffff!important;}' +
        '.tm-messenger-overlay.employee-style .chat-panel-header,.tm-messenger-overlay.employee-style .chat-panel-title-row,.tm-messenger-overlay.employee-style .chat-ticket-meta{background:#ffffff!important;color:#0f172a!important;background-image:none!important;}' +
        '.tm-messenger-overlay.employee-style .chat-panel-header::before,.tm-messenger-overlay.employee-style .chat-panel-header::after{content:none!important;display:none!important;}' +
        '.tm-messenger-overlay.employee-style .chat-panel-title-row{display:flex;align-items:flex-start;justify-content:space-between;flex-direction:column;gap:0;min-width:0;}' +
        '.tm-messenger-overlay.employee-style .chat-panel-header .tm-messenger-menu-btn,.tm-messenger-overlay.employee-style .chat-panel-header .tm-messenger-close{background:#ffffff!important;color:#334155!important;border:1px solid #e5e7eb!important;box-shadow:0 6px 18px rgba(15,23,42,.12)!important;}' +
        '.tm-messenger-overlay.employee-style .chat-panel-header .tm-messenger-close{color:#b94a54!important;background:#fff7f7!important;border-color:#fecaca!important;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-left-title{color:#ffffff;font-size:26px;font-family:Inter,Segoe UI,Arial,sans-serif;font-weight:800;letter-spacing:0;line-height:1;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-left-icon{width:50px;height:50px;min-width:50px;border-radius:16px;background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.10);color:#ffffff;display:inline-flex;align-items:center;justify-content:center;font-size:22px;box-shadow:inset 0 0 0 1px rgba(255,255,255,.04),0 8px 18px rgba(0,0,0,.10);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-search{padding:22px 20px 16px;background:#ffffff;border-bottom:1px solid #eef2f7;position:relative;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-search::before{content:"\\f002";font-family:"Font Awesome 6 Free","Font Awesome 5 Free";font-weight:900;position:absolute;left:36px;top:49px;width:auto;height:auto;transform:translateY(-50%);background:none;color:#94a3b8;font-size:17px;pointer-events:none;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-search::after{content:none;display:none;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-search input{height:54px;border-radius:14px;border:1px solid #e5e7eb;background:#ffffff;padding:0 18px 0 48px;color:#1f2937;font-size:16px;box-shadow:0 8px 18px rgba(15,23,42,.05);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-search input:focus{border-color:#86b996;box-shadow:0 0 0 4px rgba(22,101,52,.10);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-filters{padding:10px 16px 12px;background:#ffffff;border-bottom:1px solid #eef2f7;gap:8px;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-filter-btn{height:38px;border-radius:18px;background:#ffffff;border:1px solid #e2e8f0;color:#334155;font-size:13px;font-weight:600;box-shadow:0 4px 10px rgba(15,23,42,.035);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-filter-btn.active{background:#173f2a;border-color:#173f2a;color:#ffffff;box-shadow:0 10px 22px rgba(23,63,42,.18);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-list{background:#f8fafb;padding:12px 10px 16px;gap:10px;scrollbar-color:#7c858f transparent;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-item{border-radius:14px;border:1px solid #e2e8f0;background:#ffffff;padding:14px 16px;box-shadow:0 8px 18px rgba(15,23,42,.035);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-item:hover{border-color:#c8d8cf;background:#fbfefd;box-shadow:0 12px 24px rgba(15,23,42,.07);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-item.active{border-color:#dcebe2;border-left:5px solid #2d8f4d;background:linear-gradient(180deg,#f3fbf6 0%,#edf8f1 100%);box-shadow:0 14px 28px rgba(34,101,52,.12);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-item.active .tm-messenger-item-subject{color:#10271d;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-item.locked-ticket{background:#ffffff;border-color:#e2e8f0;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-item-top{align-items:flex-start;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-item-subject{font-size:15px;font-weight:600;color:#111827;letter-spacing:.01em;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-item-time{font-size:13px;font-weight:500;color:#334155;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-item-preview{font-size:14px;color:#475569;line-height:1.35;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-right{background:#ffffff;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-right-header,.tm-messenger-overlay.employee-style .chat-panel-header{min-height:122px;height:auto;padding:24px 28px 16px;background:#ffffff!important;background-image:none!important;border-bottom:1px solid #e5e7eb!important;align-items:stretch;color:#0f172a!important;box-shadow:none!important;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-title-main,.tm-messenger-overlay.employee-style .chat-ticket-title{font-size:24px;font-family:Inter,Segoe UI,Arial,sans-serif;font-weight:600;color:#0f172a!important;letter-spacing:0;line-height:1.2;padding-right:120px;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-title-sub,.tm-messenger-overlay.employee-style .chat-ticket-meta{position:static;height:auto;margin-top:18px;padding:0 0 0;background:#ffffff!important;border-bottom:none;color:#334155;font-size:15px;font-weight:400;font-variant-numeric:tabular-nums;z-index:3;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;display:flex;align-items:center;gap:12px;-webkit-font-smoothing:antialiased;text-rendering:geometricPrecision;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-status-pill{height:29px;padding:0 14px;border-radius:999px;background:#eef8f1;border-color:#d6eadc;color:#183f2a;font-size:14px;font-weight:600;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-meta-text,.tm-messenger-overlay.employee-style .tm-messenger-assignee,.tm-messenger-overlay.employee-style .tm-messenger-assignee strong{font-weight:400;color:#334155;font-variant-numeric:tabular-nums;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-header-actions,.tm-messenger-overlay.employee-style .chat-header-actions{position:absolute;right:20px;top:12px;z-index:5;display:flex;align-items:center;gap:12px;background:transparent!important;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-menu-btn,.tm-messenger-overlay.employee-style .tm-messenger-close{width:46px;height:46px;border-radius:14px;background:#ffffff;color:#25302d;border:1px solid rgba(226,232,240,.95);box-shadow:0 12px 26px rgba(15,23,42,.12);}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-close{color:#b94a54;background:#fff7f7;border-color:#fecaca;font-size:28px;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-menu-btn:hover{background:#f8fafc;color:#0f172a;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-close:hover{background:#fee2e2;color:#991b1b;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-messages{margin-top:0;padding:18px 22px 16px;background:#ffffff;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-empty{height:100%;min-height:280px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;color:#64748b;font-size:18px;font-weight:750;letter-spacing:.02em;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-empty.no-messages::before{content:"\\f27a";font-family:"Font Awesome 6 Free";font-weight:400;width:64px;height:50px;border:1px solid #8faf9e;border-radius:22px 22px 22px 4px;color:#6f9c84;display:inline-flex;align-items:center;justify-content:center;font-size:24px;margin-bottom:20px;background:#f4faf6;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-empty.no-messages::after{content:"Start the conversation...";display:block;margin-top:12px;color:#697586;font-size:16px;font-weight:400;letter-spacing:0;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-compose{background:#ffffff;border:1.5px solid #a7dcb5;border-radius:28px;margin:10px 18px 14px;padding:12px 14px;gap:8px;display:grid;grid-template-columns:42px minmax(0,1fr) 112px;grid-template-rows:42px;align-items:center;box-shadow:0 14px 28px rgba(15,23,42,.07);position:relative;overflow:visible;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-compose.has-reply{margin-top:68px;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-compose.has-attachment{margin-top:58px;}' +
        '.tm-messenger-overlay.employee-style .tm-reply-preview{left:0;right:0;top:-58px;border-color:#cfe8d6;background:#f6fbf7;box-shadow:0 10px 18px rgba(24,95,45,.08);}' +
        '.tm-messenger-overlay.employee-style .tm-reply-preview-label{color:#185f2d;}' +
        '.tm-messenger-overlay.employee-style .tm-reply-preview-text{color:#334155;}' +
        '.tm-messenger-overlay.employee-style .tm-reply-preview-close{background:#e4f4e9;color:#185f2d;border:1px solid #c9e7d2;}' +
        '.tm-messenger-overlay.employee-style .tm-reply-preview-close:hover{background:#fee2e2;color:#b91c1c;border-color:#fecaca;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-compose:not(.has-attachment){grid-template-rows:42px;padding:10px 14px;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-attach{grid-column:1;grid-row:1;width:42px;height:42px;border-radius:14px;background:#ffffff;color:#334155;border:1px solid #dbe4ee;box-shadow:0 5px 12px rgba(15,23,42,.055);font-size:18px;align-self:center;justify-self:start;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-compose input{grid-column:2;grid-row:1;min-height:42px;border-radius:21px;border:1px solid #dbe4ee;background:#ffffff;box-shadow:none;font-size:15px;color:#334155;line-height:20px;padding:11px 18px;box-sizing:border-box;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-compose.has-attachment input{padding-left:18px;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-compose textarea{grid-column:2;grid-row:1;min-height:42px;max-height:150px;border-radius:21px;border:1px solid #dbe4ee;background:#ffffff;box-shadow:none;font-size:15px;color:#334155;line-height:20px;padding:11px 18px;box-sizing:border-box;resize:none;overflow:hidden;font-family:inherit;align-self:center;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-compose textarea:focus{border-color:#22c55e;box-shadow:0 0 0 4px rgba(34,197,94,.12);outline:none;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-compose.has-attachment textarea{padding-left:18px;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-compose.is-expanded{grid-template-rows:auto!important;align-items:end!important;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-compose.is-expanded .tm-messenger-attach,.tm-messenger-overlay.employee-style .tm-messenger-compose.is-expanded .tm-messenger-send{align-self:end;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-compose.is-expanded textarea{align-self:end;}' +
        '.tm-messenger-overlay.employee-style #tmMessengerAttachmentName.tm-chat-attachment-selected{position:absolute;left:0;right:0;top:-50px;min-height:0;width:auto;height:42px;z-index:2;pointer-events:auto;display:none;align-items:center;gap:10px;overflow-x:auto;overflow-y:hidden;scrollbar-width:thin;padding:3px 8px 3px 0;}' +
        '.tm-messenger-overlay.employee-style #tmMessengerAttachmentName.tm-chat-attachment-selected.has-file{display:flex;}' +
        '.tm-messenger-overlay.employee-style #tmMessengerAttachmentName.tm-chat-attachment-selected.has-many{width:100%;}' +
        '.tm-messenger-overlay.employee-style #tmMessengerAttachmentName .tm-selected-attachment{position:relative;height:40px;min-height:40px;box-sizing:border-box;cursor:pointer;overflow:visible;}' +
        '.tm-messenger-overlay.employee-style #tmMessengerAttachmentName .tm-selected-attachment.is-image{width:48px;min-width:48px;max-width:48px;padding:0;border:1px solid #cfe8d6;border-radius:10px;background:#f6fbf7;box-shadow:0 5px 12px rgba(24,95,45,.10);}' +
        '.tm-messenger-overlay.employee-style #tmMessengerAttachmentName .tm-selected-attachment.is-file{width:164px;min-width:164px;max-width:164px;border:1px solid #cfe8d6;border-radius:12px;background:#f6fbf7;color:#153f26;box-shadow:0 5px 12px rgba(24,95,45,.10);padding:6px 28px 6px 10px;gap:8px;}' +
        '.tm-messenger-overlay.employee-style #tmMessengerAttachmentName.has-many .tm-selected-attachment.is-file{width:142px;min-width:142px;max-width:142px;}' +
        '.tm-messenger-overlay.employee-style #tmMessengerAttachmentName .tm-selected-attachment.is-image .tm-selected-attachment-thumb{width:46px;height:38px;border-radius:9px;border:none;object-fit:cover;}' +
        '.tm-messenger-overlay.employee-style #tmMessengerAttachmentName .tm-selected-attachment.is-file .tm-selected-attachment-file-icon{width:30px;height:30px;border-radius:999px;background:#e4f4e9;color:#185f2d;border:1px solid #c9e7d2;}' +
        '.tm-messenger-overlay.employee-style #tmMessengerAttachmentName .tm-selected-attachment.is-file .tm-selected-attachment-name{font-size:12px;line-height:1.15;color:#173f2a;font-weight:850;}' +
        '.tm-messenger-overlay.employee-style #tmMessengerAttachmentName .tm-selected-attachment.is-file .tm-selected-attachment-size{font-size:11px;line-height:1.1;color:#5d7164;font-weight:750;}' +
        '.tm-messenger-overlay.employee-style #tmMessengerAttachmentName .tm-selected-attachment-remove{position:absolute;right:-7px;top:-7px;width:22px;height:22px;background:#e4f4e9;color:#185f2d;border:1px solid #c9e7d2;box-shadow:0 3px 8px rgba(24,95,45,.16);z-index:3;}' +
        '.tm-messenger-overlay.employee-style #tmMessengerAttachmentName .tm-selected-attachment-remove:hover{background:#fee2e2;color:#b91c1c;border-color:#fecaca;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-send{grid-column:3;grid-row:1;min-width:112px;min-height:42px;border-radius:21px;background:#185f2d;box-shadow:0 12px 22px rgba(24,95,45,.20);font-size:16px;font-weight:850;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-send:hover{background:#144f26;}' +
        '.tm-messenger-overlay.employee-style .tm-messenger-mobile-tabs{display:none;}' +
        '@media (min-width:769px){.tm-messenger-overlay.employee-style{inset:0;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;z-index:20000;}.tm-messenger-overlay.employee-style .tm-messenger-panel{width:min(96vw,1220px);height:min(92vh,760px);max-height:92vh;}}' +
        '@media (max-width:768px){.tm-messenger-overlay.employee-style .tm-messenger-panel::before{content:none;display:none}.tm-messenger-overlay.employee-style .tm-messenger-left-header{height:58px}.tm-messenger-overlay.employee-style .tm-messenger-right-header,.tm-messenger-overlay.employee-style .chat-panel-header{min-height:106px;height:auto;padding:18px 16px 12px}.tm-messenger-overlay.employee-style .tm-messenger-title-sub,.tm-messenger-overlay.employee-style .chat-ticket-meta{margin-top:14px;white-space:normal}.tm-messenger-overlay.employee-style .tm-messenger-header-actions,.tm-messenger-overlay.employee-style .chat-header-actions{top:8px;right:12px;gap:8px}.tm-messenger-overlay.employee-style .tm-messenger-messages{margin-top:0;padding:16px 14px}.tm-messenger-overlay.employee-style .tm-messenger-left-title{font-size:17px}.tm-messenger-overlay.employee-style .tm-messenger-title-main,.tm-messenger-overlay.employee-style .chat-ticket-title{font-size:17px;padding-right:104px}.tm-messenger-overlay.employee-style .tm-messenger-menu-btn,.tm-messenger-overlay.employee-style .tm-messenger-close{width:42px;height:42px}.tm-messenger-overlay.employee-style .tm-messenger-empty{min-height:180px;font-size:16px}.tm-messenger-overlay.employee-style .tm-messenger-empty.no-messages::before{width:54px;height:44px;font-size:21px;margin-bottom:14px}}' +
        '@media (max-width:768px){.tm-messenger-overlay.employee-style{padding:10px 8px calc(env(safe-area-inset-bottom,0px) + 10px);align-items:center;justify-content:center;box-sizing:border-box;}.tm-messenger-overlay.employee-style .tm-messenger-panel{width:calc(100vw - 16px);height:calc(100dvh - 28px);max-height:calc(100dvh - 28px);border-radius:18px;display:flex;flex-direction:column;overflow:hidden;}.tm-messenger-overlay.employee-style .tm-messenger-mobile-tabs{display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:10px;background:#0f4f25;border-bottom:1px solid rgba(255,255,255,.12);}.tm-messenger-overlay.employee-style .tm-messenger-mobile-tab{appearance:none;-webkit-appearance:none;border:1px solid rgba(255,255,255,.24);border-radius:13px;background:rgba(255,255,255,.10);color:#d8f3df;height:38px;font-size:13px;font-weight:850;}.tm-messenger-overlay.employee-style .tm-messenger-mobile-tab.active{background:#ffffff;color:#14532d;border-color:#ffffff;box-shadow:0 8px 18px rgba(15,23,42,.16);}.tm-messenger-overlay.employee-style .tm-messenger-left{width:100%;min-width:0;max-width:none;height:auto;flex:1 1 auto;min-height:0;max-height:none;border-right:0;border-bottom:0;background:#ffffff;}.tm-messenger-overlay.employee-style .tm-messenger-right{height:auto;flex:1 1 auto;min-height:0;background:#ffffff;}.tm-messenger-overlay.employee-style.tm-mobile-view-list .tm-messenger-left{display:flex;}.tm-messenger-overlay.employee-style.tm-mobile-view-list .tm-messenger-right{display:none;}.tm-messenger-overlay.employee-style.tm-mobile-view-chat .tm-messenger-left{display:none;}.tm-messenger-overlay.employee-style.tm-mobile-view-chat .tm-messenger-right{display:flex;}.tm-messenger-overlay.employee-style .tm-messenger-left-header{height:56px;padding:0 16px;gap:12px;}.tm-messenger-overlay.employee-style .tm-messenger-left-icon{width:40px;height:40px;min-width:40px;border-radius:13px;font-size:17px;}.tm-messenger-overlay.employee-style .tm-messenger-left-title{font-size:19px;line-height:1.1;}.tm-messenger-overlay.employee-style .tm-messenger-search{padding:10px 12px;background:#ffffff;border-bottom:0;}.tm-messenger-overlay.employee-style .tm-messenger-search::before{left:26px;top:50%;font-size:14px;}.tm-messenger-overlay.employee-style .tm-messenger-search::after{right:26px;top:50%;font-size:14px;}.tm-messenger-overlay.employee-style .tm-messenger-search input{height:44px;border-radius:12px;padding:0 40px;font-size:14px;box-shadow:0 4px 12px rgba(15,23,42,.045);}.tm-messenger-overlay.employee-style .tm-messenger-filters{padding:0 12px 10px;gap:6px;overflow-x:auto;scrollbar-width:none;}.tm-messenger-overlay.employee-style .tm-messenger-filters::-webkit-scrollbar{display:none;}.tm-messenger-overlay.employee-style .tm-messenger-filter-btn{height:32px;padding:0 11px;border-radius:14px;font-size:11px;font-weight:800;}.tm-messenger-overlay.employee-style .tm-messenger-list{padding:10px 12px 16px;gap:13px;background:#f8fafb;}.tm-messenger-overlay.employee-style .tm-messenger-item{position:relative;min-height:70px;padding:14px 12px 14px 68px;border-radius:15px;gap:8px;box-shadow:0 5px 12px rgba(15,23,42,.045);}.tm-messenger-overlay.employee-style .tm-messenger-item.tm-has-letter-avatar{padding-left:68px!important;}.tm-messenger-overlay.employee-style .tm-messenger-item.tm-has-letter-avatar::before{left:14px;width:42px;height:42px;font-size:14px;}.tm-messenger-overlay.employee-style .tm-messenger-item-top{gap:10px;align-items:flex-start;}.tm-messenger-overlay.employee-style .tm-messenger-item-subject{font-size:14px;line-height:1.35;white-space:normal;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}.tm-messenger-overlay.employee-style .tm-messenger-item-time{font-size:12px;line-height:1.2;padding-top:1px;}.tm-messenger-overlay.employee-style .tm-messenger-item-preview{margin-top:8px;font-size:13px;line-height:1.42;white-space:normal;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;text-overflow:clip;}.tm-messenger-overlay.employee-style .tm-messenger-item-preview.locked{display:flex;align-items:flex-start;gap:8px;max-width:100%;overflow:hidden;white-space:normal;line-height:1.42;}.tm-messenger-overlay.employee-style .tm-messenger-item-preview.locked .lock-icon{flex:0 0 auto;margin-top:1px;}.tm-messenger-overlay.employee-style .tm-messenger-preview-text{min-width:0;overflow:hidden;text-overflow:ellipsis;}.tm-messenger-overlay.employee-style .tm-messenger-item-preview.locked .tm-messenger-preview-text{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;white-space:normal;}.tm-messenger-overlay.employee-style .tm-messenger-right-header,.tm-messenger-overlay.employee-style .chat-panel-header{min-height:78px;padding:12px 12px 10px;border-bottom:1px solid #e5e7eb!important;}.tm-messenger-overlay.employee-style .chat-panel-title-row{gap:4px;}.tm-messenger-overlay.employee-style .tm-messenger-title-main,.tm-messenger-overlay.employee-style .chat-ticket-title{font-size:14px;line-height:1.22;padding-right:90px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}.tm-messenger-overlay.employee-style .tm-messenger-title-sub,.tm-messenger-overlay.employee-style .chat-ticket-meta{margin-top:7px;font-size:11px;gap:6px;line-height:1.2;white-space:normal;}.tm-messenger-overlay.employee-style .tm-messenger-status-pill{height:24px;padding:0 10px;font-size:11px;}.tm-messenger-overlay.employee-style .tm-messenger-header-actions,.tm-messenger-overlay.employee-style .chat-header-actions{top:8px;right:10px;gap:7px;}.tm-messenger-overlay.employee-style .tm-messenger-menu-btn,.tm-messenger-overlay.employee-style .tm-messenger-close{width:36px;height:36px;border-radius:12px;font-size:20px;}.tm-messenger-overlay.employee-style .tm-messenger-close{font-size:24px;}.tm-messenger-overlay.employee-style .tm-messenger-messages{flex:1 1 auto;min-height:0;overflow-y:auto;padding:14px 12px 10px;background:#ffffff;gap:6px;}.tm-messenger-overlay.employee-style .chat-bubble{max-width:calc(100% - 46px);padding:10px 12px;border-radius:17px;font-size:13px;line-height:1.42;margin-top:20px!important;margin-bottom:14px!important;gap:2px;box-shadow:0 8px 18px rgba(15,23,42,.06);}.tm-messenger-overlay.employee-style .chat-bubble.other.tm-has-letter-avatar{margin-left:40px;}.tm-messenger-overlay.employee-style .chat-bubble.me.tm-has-letter-avatar{margin-right:40px;}.tm-messenger-overlay.employee-style .chat-bubble.other.tm-has-letter-avatar::before,.tm-messenger-overlay.employee-style .chat-bubble.me.tm-has-letter-avatar::before{width:28px;height:28px;font-size:10px;}.tm-messenger-overlay.employee-style .chat-bubble.other.tm-has-letter-avatar::before{left:-40px;}.tm-messenger-overlay.employee-style .chat-bubble.me.tm-has-letter-avatar::before{right:-40px;}.tm-messenger-overlay.employee-style .chat-bubble::after{width:12px;height:12px;bottom:8px;}.tm-messenger-overlay.employee-style .chat-sender{top:-24px;font-size:11px;max-width:190px;overflow:hidden;text-overflow:ellipsis;}.tm-messenger-overlay.employee-style .chat-time{font-size:10px;bottom:-18px;}.tm-messenger-overlay.employee-style .chat-bubble .chat-meta{gap:6px;margin-top:2px;}.tm-messenger-overlay.employee-style .tm-messenger-compose{margin:7px 10px calc(env(safe-area-inset-bottom,0px) + 8px);padding:8px;grid-template-columns:38px minmax(0,1fr) 74px;grid-template-rows:38px;border-radius:18px;gap:8px;border-width:1.5px;box-shadow:0 8px 18px rgba(15,23,42,.07);}.tm-messenger-overlay.employee-style .tm-messenger-compose:not(.has-attachment){padding:8px;grid-template-rows:38px;}.tm-messenger-overlay.employee-style .tm-messenger-compose.has-attachment{margin-top:48px;}.tm-messenger-overlay.employee-style .tm-messenger-attach{width:38px;height:38px;border-radius:12px;font-size:15px;}.tm-messenger-overlay.employee-style .tm-messenger-compose input{min-height:38px;height:38px;border-radius:18px;padding:8px 12px;font-size:13px;line-height:18px;}.tm-messenger-overlay.employee-style .tm-messenger-send{min-width:74px;min-height:38px;height:38px;border-radius:18px;font-size:13px;padding:0 10px;}.tm-messenger-overlay.employee-style #tmMessengerAttachmentName.tm-chat-attachment-selected{top:-43px;height:38px;gap:8px;padding-right:2px;}.tm-messenger-overlay.employee-style #tmMessengerAttachmentName .tm-selected-attachment{height:36px;min-height:36px;}.tm-messenger-overlay.employee-style #tmMessengerAttachmentName .tm-selected-attachment.is-image{width:44px;min-width:44px;max-width:44px;}.tm-messenger-overlay.employee-style #tmMessengerAttachmentName .tm-selected-attachment.is-image .tm-selected-attachment-thumb{width:42px;height:34px;}.tm-messenger-overlay.employee-style #tmMessengerAttachmentName .tm-selected-attachment.is-file{width:136px;min-width:136px;max-width:136px;}.tm-messenger-overlay.employee-style .tm-messenger-empty{min-height:120px;font-size:13px;padding:16px 10px;}}' +
        '@media (max-width:768px){.tm-messenger-overlay.employee-style{padding:calc(env(safe-area-inset-top,0px) + 18px) 18px calc(env(safe-area-inset-bottom,0px) + 58px)!important;}.tm-messenger-overlay.employee-style .tm-messenger-panel{width:min(92vw,390px)!important;height:min(82dvh,690px)!important;max-height:min(82dvh,690px)!important;border-radius:18px!important;box-shadow:0 18px 42px rgba(15,23,42,.30)!important;}.tm-messenger-overlay.employee-style .tm-messenger-mobile-tabs{padding:8px!important;gap:7px!important;}.tm-messenger-overlay.employee-style .tm-messenger-mobile-tab{height:34px!important;border-radius:12px!important;font-size:12px!important;}.tm-messenger-overlay.employee-style .tm-messenger-left-header{height:48px!important;padding:0 13px!important;gap:10px!important;}.tm-messenger-overlay.employee-style .tm-messenger-left-icon{width:34px!important;height:34px!important;min-width:34px!important;border-radius:11px!important;font-size:15px!important;}.tm-messenger-overlay.employee-style .tm-messenger-left-title{font-size:16px!important;}.tm-messenger-overlay.employee-style .tm-messenger-search{padding:8px 10px!important;}.tm-messenger-overlay.employee-style .tm-messenger-search input{height:38px!important;font-size:13px!important;border-radius:11px!important;}.tm-messenger-overlay.employee-style .tm-messenger-list{padding:8px 10px 12px!important;gap:9px!important;}.tm-messenger-overlay.employee-style .tm-messenger-item{min-height:58px!important;padding:11px 10px 11px 58px!important;border-radius:13px!important;}.tm-messenger-overlay.employee-style .tm-messenger-item.tm-has-letter-avatar{padding-left:58px!important;}.tm-messenger-overlay.employee-style .tm-messenger-item.tm-has-letter-avatar::before{left:12px!important;width:36px!important;height:36px!important;font-size:12px!important;}.tm-messenger-overlay.employee-style .tm-messenger-item-subject{font-size:13px!important;line-height:1.28!important;}.tm-messenger-overlay.employee-style .tm-messenger-item-time{font-size:11px!important;}.tm-messenger-overlay.employee-style .tm-messenger-item-preview{font-size:12px!important;margin-top:5px!important;}.tm-messenger-overlay.employee-style .tm-messenger-right-header,.tm-messenger-overlay.employee-style .chat-panel-header{min-height:66px!important;padding:10px 10px 8px!important;}.tm-messenger-overlay.employee-style .tm-messenger-title-main,.tm-messenger-overlay.employee-style .chat-ticket-title{font-size:13px!important;padding-right:82px!important;}.tm-messenger-overlay.employee-style .tm-messenger-title-sub,.tm-messenger-overlay.employee-style .chat-ticket-meta{font-size:10px!important;margin-top:5px!important;gap:5px!important;}.tm-messenger-overlay.employee-style .tm-messenger-status-pill{height:21px!important;padding:0 8px!important;font-size:10px!important;}.tm-messenger-overlay.employee-style .tm-messenger-menu-btn,.tm-messenger-overlay.employee-style .tm-messenger-close{width:32px!important;height:32px!important;border-radius:10px!important;}.tm-messenger-overlay.employee-style .tm-messenger-messages{padding:12px 10px 8px!important;gap:8px!important;}.tm-messenger-overlay.employee-style .chat-bubble{max-width:calc(100% - 38px)!important;padding:8px 10px!important;border-radius:15px!important;font-size:12px!important;line-height:1.35!important;margin-top:17px!important;margin-bottom:17px!important;}.tm-messenger-overlay.employee-style .chat-bubble.other.tm-has-letter-avatar{margin-left:34px!important;}.tm-messenger-overlay.employee-style .chat-bubble.me.tm-has-letter-avatar{margin-right:34px!important;}.tm-messenger-overlay.employee-style .chat-bubble.other.tm-has-letter-avatar::before,.tm-messenger-overlay.employee-style .chat-bubble.me.tm-has-letter-avatar::before{width:24px!important;height:24px!important;font-size:9px!important;}.tm-messenger-overlay.employee-style .chat-bubble.other.tm-has-letter-avatar::before{left:-34px!important;}.tm-messenger-overlay.employee-style .chat-bubble.me.tm-has-letter-avatar::before{right:-34px!important;}.tm-messenger-overlay.employee-style .tm-messenger-compose{margin:6px 8px calc(env(safe-area-inset-bottom,0px) + 7px)!important;padding:7px!important;grid-template-columns:34px minmax(0,1fr) 64px!important;grid-template-rows:34px!important;border-radius:16px!important;gap:7px!important;}.tm-messenger-overlay.employee-style .tm-messenger-attach{width:34px!important;height:34px!important;border-radius:11px!important;font-size:14px!important;}.tm-messenger-overlay.employee-style .tm-messenger-compose input{min-height:34px!important;height:34px!important;border-radius:17px!important;font-size:12px!important;padding:7px 10px!important;}.tm-messenger-overlay.employee-style .tm-messenger-send{min-width:64px!important;min-height:34px!important;height:34px!important;border-radius:17px!important;font-size:12px!important;padding:0 8px!important;}}' +
        '@media (max-width:768px){.tm-messenger-overlay.employee-style .tm-messenger-mobile-tabs{grid-template-columns:minmax(0,1fr) minmax(0,1fr) 34px 34px!important;align-items:center!important;}.tm-messenger-overlay.employee-style .tm-messenger-mobile-tabs>.tm-messenger-menu-wrap{width:34px;height:34px;z-index:20;}.tm-messenger-overlay.employee-style .tm-messenger-mobile-tabs>.tm-messenger-menu-wrap .tm-messenger-menu-btn{width:34px!important;height:34px!important;border-radius:12px!important;font-size:20px!important;}.tm-messenger-overlay.employee-style .tm-messenger-header-actions .tm-messenger-close{display:none!important;}.tm-messenger-overlay.employee-style .tm-messenger-mobile-close{appearance:none;-webkit-appearance:none;width:34px;height:34px;border-radius:12px;border:1px solid rgba(254,202,202,.95);background:#fff7f7;color:#b91c1c;font-size:24px;line-height:1;font-weight:700;display:inline-flex;align-items:center;justify-content:center;box-shadow:0 8px 18px rgba(15,23,42,.12);}.tm-messenger-overlay.employee-style .tm-messenger-item{height:auto!important;min-height:66px!important;overflow:hidden!important;align-items:center!important;}.tm-messenger-overlay.employee-style .tm-messenger-item-top{display:grid!important;grid-template-columns:minmax(0,1fr) auto!important;gap:8px!important;width:100%!important;align-items:start!important;}.tm-messenger-overlay.employee-style .tm-messenger-item-subject{display:block!important;-webkit-line-clamp:unset!important;-webkit-box-orient:unset!important;white-space:normal!important;overflow:visible!important;text-overflow:clip!important;word-break:break-word!important;padding-right:0!important;}.tm-messenger-overlay.employee-style .tm-messenger-item-right{display:flex!important;align-items:flex-start!important;justify-content:flex-end!important;min-width:48px!important;}.tm-messenger-overlay.employee-style .tm-messenger-item-preview{display:none!important;}.tm-messenger-overlay.employee-style .tm-messenger-preview-text{display:none!important;}.tm-messenger-overlay.employee-style.tm-mobile-view-list .tm-messenger-left{gap:0!important;}.tm-messenger-overlay.employee-style .tm-messenger-left-header{margin:0!important;border-bottom:0!important;}.tm-messenger-overlay.employee-style .tm-messenger-search{position:relative!important;top:auto!important;transform:none!important;margin:0!important;padding:8px 10px!important;min-height:0!important;border-top:0!important;}.tm-messenger-overlay.employee-style .tm-messenger-search::before,.tm-messenger-overlay.employee-style .tm-messenger-search::after{top:50%!important;}.tm-messenger-overlay.employee-style .tm-messenger-filters{display:none!important;}.tm-messenger-overlay.employee-style .tm-messenger-list{padding-top:8px!important;}}';
      polishStyle.textContent += '@media (max-width:768px){.tm-messenger-overlay.employee-style .tm-messenger-compose input{font-size:16px!important;-webkit-text-size-adjust:100%;}.tm-messenger-overlay.employee-style .tm-reply-preview-close{display:none!important;}.tm-messenger-overlay.employee-style .tm-reply-preview-body{width:100%!important;max-width:100%!important;}}';
      document.head.appendChild(polishStyle);
    }

    if (!document.getElementById('tmMessengerChatAlignmentFix')) {
      var alignmentStyle = document.createElement('style');
      alignmentStyle.id = 'tmMessengerChatAlignmentFix';
      alignmentStyle.textContent =
        '.tm-messenger-overlay.employee-style .chat-bubble.me{min-width:148px!important;padding-bottom:12px!important;margin-bottom:28px!important;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble .chat-message-text{display:block!important;min-height:18px!important;line-height:1.45!important;word-break:break-word!important;padding:0 0 6px!important;margin:0!important;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble .chat-meta{height:auto!important;margin-top:6px!important;padding:0!important;gap:6px!important;position:static!important;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.me .chat-meta{align-self:stretch!important;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble .chat-meta .chat-time{position:static!important;inset:auto!important;display:flex!important;flex-wrap:nowrap!important;align-items:center!important;justify-content:flex-start!important;gap:4px!important;line-height:1!important;white-space:nowrap!important;text-align:left!important;min-width:0!important;margin:0!important;padding:0!important;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble .chat-meta .chat-time .chat-edited{position:static!important;inset:auto!important;width:auto!important;min-width:max-content!important;margin:0!important;padding:0!important;line-height:1!important;text-align:inherit!important;white-space:nowrap!important;}' +
        '.tm-messenger-overlay.employee-style .chat-time-text{flex:0 0 auto!important;white-space:nowrap!important;}' +
        '.tm-messenger-overlay.employee-style .chat-read-status{display:inline-flex!important;align-items:center!important;white-space:nowrap!important;}' +
        '.tm-messenger-overlay.employee-style .chat-edited-separator{color:#94a3b8!important;font-weight:600!important;line-height:1!important;}' +
        '.tm-messenger-overlay.employee-style .chat-delivery-label{position:absolute!important;margin:0!important;display:block!important;align-items:center!important;gap:4px!important;line-height:1!important;text-align:right!important;white-space:nowrap!important;top:calc(100% + 10px)!important;right:0!important;}' +
        '.tm-messenger-overlay.employee-style .chat-bubble.me .chat-meta .chat-time{width:100%!important;justify-content:flex-end!important;}' +
        '@media (max-width:768px){.tm-messenger-overlay.employee-style .chat-bubble.other .tm-msg-actions{right:-60px!important;left:auto!important;}.tm-messenger-overlay.employee-style .chat-bubble.me .tm-msg-actions{left:-46px!important;right:auto!important;}}';
      document.head.appendChild(alignmentStyle);
    }

    var overlay = document.createElement('div');
    overlay.id = 'tmMessengerModal';
    overlay.className = 'tm-messenger-overlay' + ((typeof window !== 'undefined' && window.TM_MESSENGER_STYLE === 'employee') ? ' employee-style' : '');
    overlay.innerHTML =
      '<div class="tm-messenger-panel" role="dialog" aria-modal="true" aria-label="Ticket Conversations">' +
      ((typeof window !== 'undefined' && window.TM_MESSENGER_STYLE === 'employee')
        ? ('  <div class="tm-messenger-mobile-tabs" role="tablist" aria-label="Chat views">' +
           '    <button type="button" class="tm-messenger-mobile-tab active" data-mobile-view="list" role="tab" aria-selected="true">Tickets</button>' +
           '    <button type="button" class="tm-messenger-mobile-tab" data-mobile-view="chat" role="tab" aria-selected="false">Chat</button>' +
           '    <button type="button" class="tm-messenger-mobile-close" id="tmMessengerMobileCloseBtn" aria-label="Close">&times;</button>' +
           '  </div>')
        : '') +
      '  <div class="tm-messenger-left">' +
      '    <div class="tm-messenger-left-header conversation-sidebar-header">' +
      '      <span class="tm-messenger-left-icon" aria-hidden="true"><i class="far fa-comment-dots"></i></span>' +
      '      <div class="tm-messenger-left-title">Conversations</div>' +
      '    </div>' +
      '    <div class="tm-messenger-search"><input type="text" id="tmMessengerSearch" placeholder="Search tickets..."></div>' +
      ((typeof window !== 'undefined' && window.TM_MESSENGER_STYLE === 'employee')
        ? ('    <div class="tm-messenger-filters" id="tmMessengerFilters">' +
           '      <button type="button" class="tm-messenger-filter-btn active" data-filter="all" id="tmMessengerFilterAll">All (0)</button>' +
           '      <button type="button" class="tm-messenger-filter-btn" data-filter="in_progress" id="tmMessengerFilterInProgress">In Progress (0)</button>' +
           '      <button type="button" class="tm-messenger-filter-btn" data-filter="resolved" id="tmMessengerFilterClosed">Resolved (0)</button>' +
           '    </div>')
        : '') +
      '    <div class="tm-messenger-list" id="tmMessengerList"><div class="tm-messenger-empty">Loading...</div></div>' +
      '  </div>' +
      '  <div class="tm-messenger-right">' +
      '    <div class="tm-messenger-right-header chat-panel-header">' +
      '      <div class="tm-messenger-right-title chat-panel-title-row">' +
      '        <div class="tm-messenger-title-main chat-ticket-title" id="tmMessengerHeaderTitle">Select a conversation</div>' +
      '        <div class="tm-messenger-title-sub chat-ticket-meta" id="tmMessengerHeaderSub"> </div>' +
      '      </div>' +
      '      <div class="tm-messenger-header-actions chat-header-actions">' +
      '        <div class="tm-messenger-menu-wrap">' +
      '          <button type="button" class="tm-messenger-menu-btn" id="tmMessengerMenuBtn" aria-label="Chat options" aria-expanded="false" disabled>&#8942;</button>' +
      '          <div class="tm-messenger-menu" id="tmMessengerMenu">' +
      '            <button type="button" class="tm-messenger-menu-item" id="tmMessengerViewTicketBtn">View Ticket</button>' +
      '          </div>' +
      '        </div>' +
      '        <button type="button" class="tm-messenger-close" id="tmMessengerCloseBtn" aria-label="Close">&times;</button>' +
      '      </div>' +
      '    </div>' +
      '    <div class="tm-messenger-messages" id="tmMessengerMessages"><div class="tm-messenger-empty">Select a ticket on the left.</div></div>' +
      '    <div class="tm-messenger-compose" id="tmMessengerCompose">' +
      '      <input type="hidden" id="tmMessengerTicketId" value="">' +
      '      <input type="file" id="tmMessengerAttachmentInput" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx" multiple style="display:none;">' +
      '      <div id="tmMessengerReplyPreview" class="tm-reply-preview" style="display:none;">' +
      '        <div class="tm-reply-preview-body">' +
      '          <div class="tm-reply-preview-label">Reply</div>' +
      '          <div id="tmMessengerReplyPreviewText" class="tm-reply-preview-text"></div>' +
      '        </div>' +
      '        <button type="button" class="tm-reply-preview-close" id="tmMessengerReplyPreviewClose" aria-label="Cancel reply">&times;</button>' +
      '      </div>' +
      '      <button type="button" class="tm-messenger-attach" id="tmMessengerAttachBtn" aria-label="Attach file"><i class="fas fa-paperclip"></i></button>' +
      '      <textarea id="tmMessengerInput" rows="1" placeholder="Type a message..." autocomplete="off" disabled></textarea>' +
      '      <span id="tmMessengerAttachmentName" class="tm-chat-attachment-selected"></span>' +
      '      <button type="button" class="tm-messenger-send" id="tmMessengerSendBtn" disabled>Send</button>' +
      '    </div>' +
      '  </div>' +
      '</div>';
    document.body.appendChild(overlay);
    var messengerReplyCloseBtn = qs('tmMessengerReplyPreviewClose');
    if (messengerReplyCloseBtn) {
      messengerReplyCloseBtn.addEventListener('click', function () {
        clearReplyContext('messenger');
      });
    }
    setMessengerMobileView('list');
    ensureMessengerConfirmExists();

    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) closeMessengerChat();
    });
    var closeBtn = qs('tmMessengerCloseBtn');
    if (closeBtn) closeBtn.addEventListener('click', closeMessengerChat);
    var mobileCloseBtn = qs('tmMessengerMobileCloseBtn');
    if (mobileCloseBtn) mobileCloseBtn.addEventListener('click', closeMessengerChat);
    var mobileTabsBar = overlay.querySelector('.tm-messenger-mobile-tabs');
    var headerActions = overlay.querySelector('.tm-messenger-header-actions');
    var messengerMenuWrap = overlay.querySelector('.tm-messenger-menu-wrap');

    function syncMessengerHeaderActions() {
      if (!mobileTabsBar || !headerActions || !messengerMenuWrap || !mobileCloseBtn) return;
      if (window.innerWidth <= 768) {
        mobileTabsBar.insertBefore(messengerMenuWrap, mobileCloseBtn);
      } else {
        headerActions.insertBefore(messengerMenuWrap, closeBtn || null);
      }
    }

    syncMessengerHeaderActions();
    window.addEventListener('resize', syncMessengerHeaderActions);
    var mobileTabs = overlay.querySelectorAll('.tm-messenger-mobile-tab');
    mobileTabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        setMessengerMobileView(tab.getAttribute('data-mobile-view'));
      });
    });
    var attachBtn = qs('tmMessengerAttachBtn');
    var attachInput = qs('tmMessengerAttachmentInput');
    if (attachBtn && attachInput) {
      attachBtn.addEventListener('click', function () {
        if (!attachBtn.disabled) attachInput.click();
      });
      attachInput.addEventListener('change', function () {
        var selected = filterChatAttachmentFiles(attachInput.files ? Array.prototype.slice.call(attachInput.files) : []);
        attachInput.value = '';
        setMessengerAttachments(messengerAttachmentFiles.concat(selected));
      });
    }
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
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          sendMessengerMessage();
        }
      });
      input.addEventListener('input', resizeMessengerInput);
      bindTypingInput(input, 'messenger');
      resizeMessengerInput();
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
    if (s === 'resolved') return 'resolved';
    if (s === 'closed') return 'closed';
    return 'other';
  }
  function updateMessengerFilterButtons() {
    var activeFilter = (typeof window !== 'undefined' && window.__tmMessengerFilter) ? String(window.__tmMessengerFilter) : 'all';
    var convs = Array.isArray(window.__tmConversations) ? window.__tmConversations : [];
    if (activeFilter === 'closed') activeFilter = 'resolved';
    var counts = { all: convs.length, resolved: 0, in_progress: 0 };
    convs.forEach(function (c) {
      var normalized = normalizeMessengerStatus(c && c.status ? c.status : '');
      if (normalized === 'resolved') counts.resolved += 1;
      if (normalized === 'in_progress') counts.in_progress += 1;
    });
    var defs = [
      { id: 'tmMessengerFilterAll', key: 'all', label: 'All' },
      { id: 'tmMessengerFilterInProgress', key: 'in_progress', label: 'In Progress' },
      { id: 'tmMessengerFilterClosed', key: 'resolved', label: 'Resolved' }
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
  function parseChatMessageDate(value) {
    if (!value) return null;
    var parsed = value instanceof Date ? value : new Date(String(value).replace(' ', 'T'));
    return isNaN(parsed.getTime()) ? null : parsed;
  }
  function chatDateKey(value) {
    var parsed = parseChatMessageDate(value);
    if (!parsed) return '';
    return [
      parsed.getFullYear(),
      String(parsed.getMonth() + 1).padStart(2, '0'),
      String(parsed.getDate()).padStart(2, '0')
    ].join('-');
  }
  function formatChatDateSeparator(value) {
    var parsed = parseChatMessageDate(value);
    if (!parsed) return '';
    return parsed.toLocaleDateString([], { month: 'long', day: 'numeric', year: 'numeric' });
  }
  function createChatDateSeparator(value) {
    var label = formatChatDateSeparator(value);
    if (!label) return null;
    var separator = document.createElement('div');
    separator.className = 'chat-date-separator';
    separator.innerHTML = '<span></span><strong></strong><span></span>';
    var strong = separator.querySelector('strong');
    if (strong) strong.textContent = label;
    return separator;
  }
  function messengerStatusClass(status) {
    var s = normalizeMessengerStatus(status);
    if (s === 'in_progress') return 'status-in-progress';
    if (s === 'resolved') return 'status-resolved';
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
        if (typeof window !== 'undefined' && typeof window.TMRefreshGlobalChatBadge === 'function') {
          window.TMRefreshGlobalChatBadge();
        }

        updateMessengerFilterButtons();
        renderConversations(searchEl ? searchEl.value : '');
        if (!messengerTicketId && window.__tmConversations.length) {
          selectConversation(window.__tmConversations[0]);
        } else if (messengerTicketId) {
          var found = window.__tmConversations.find(function (c) { return String(c.id) === String(messengerTicketId); });
          if (found) {
            setMessengerHeader(found);
            updateMessengerFilterButtons();
            renderConversations(searchEl ? searchEl.value : '');
          }
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
    if (activeFilter === 'closed') activeFilter = 'resolved';
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
        ? ((c && c.chat_locked_message && String(c.chat_locked_message).trim() !== '')
            ? String(c.chat_locked_message)
            : "You can't message. This ticket is already assigned.")
        : (c.last_message ? ((c.last_sender_name ? (String(c.last_sender_name) + ': ') : '') + String(c.last_message)) : 'No messages yet.');
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'tm-messenger-item' +
        (isLocked ? ' locked-ticket' : '') +
        ((!isLocked && unread > 0) ? ' unread-chat' : '') +
        (isCurrent ? (isLocked ? ' active-locked' : ' active') : '');
      btn.dataset.ticketId = String(c.id);
      var avatarName = c.last_sender_name || c.requester_name || c.requester_email || c.assigned_to_name || c.subject || ('Ticket ' + c.id);
      btn.classList.add('tm-has-letter-avatar');
      btn.setAttribute('data-avatar', tmAvatarInitials(avatarName));
      btn.style.setProperty('--tm-avatar-bg', tmAvatarColor(avatarName));
      btn.innerHTML =
        '<div class="tm-messenger-item-top">' +
        '  <div class="tm-messenger-item-subject" title="' + escapeHtml(c.subject) + '">#' + String(c.id).padStart(6, '0') + ' &bull; ' + escapeHtml(c.subject) + '</div>' +
        '  <div class="tm-messenger-item-right">' +
        '    <div class="tm-messenger-item-time">' + escapeHtml(toRelative(c.last_message_time || c.ticket_created_at)) + '</div>' +
        (unread > 0 ? ('<span class="unread-badge">' + escapeHtml(String(unread)) + '</span>') : '') +
        '  </div>' +
        '</div>' +
        '<div class="tm-messenger-item-preview' + (isLocked ? ' locked' : '') + '">' +
          (isLocked
            ? '<span class="lock-icon"><i class="fas fa-lock"></i></span>'
            : '') +
          '<span class="tm-messenger-preview-text">' + escapeHtml(previewText) + '</span>' +
        '</div>';
      btn.addEventListener('click', function () {
        selectConversation(c);
        setMessengerMobileView('chat');
      });
      list.appendChild(btn);
    });
  }
  function setMessengerHeader(conv) {
    var title = qs('tmMessengerHeaderTitle');
    var sub = qs('tmMessengerHeaderSub');
    var menuBtn = qs('tmMessengerMenuBtn');
    if (title) {
      var nextTitle = conv ? ('#' + String(conv.id).padStart(6, '0') + ' \u2022 ' + String(conv.subject || '')) : 'Select a conversation';
      if (title.textContent !== nextTitle) title.textContent = nextTitle;
    }
    if (sub) {
      if (conv) {
        if (typeof window !== 'undefined' && window.TM_MESSENGER_STYLE === 'employee') {
          var rel = toRelative(conv.chat_partner_last_seen_at || conv.last_message_time || conv.ticket_created_at || '');
          var statusLabel = String(conv.status || 'Open').trim() || 'Open';
          var requesterName = conv && conv.requester_name ? String(conv.requester_name) : '';
          var requesterEmail = conv && conv.requester_email ? String(conv.requester_email) : '';
          var requesterLabel = requesterName || requesterEmail;
          var assignedName = conv && conv.assigned_to_name ? String(conv.assigned_to_name) : '';
          var lastSenderName = conv && conv.last_sender_name ? String(conv.last_sender_name) : '';
          var currentUser = (typeof window !== 'undefined' && window.TM_CURRENT_USER) ? window.TM_CURRENT_USER : null;
          var currentEmail = currentUser && currentUser.email ? String(currentUser.email).trim().toLowerCase() : '';
          var currentName = currentUser && currentUser.name ? String(currentUser.name).trim().toLowerCase() : '';
          var requesterEmailKey = requesterEmail.trim().toLowerCase();
          var requesterNameKey = requesterName.trim().toLowerCase();
          var assignedNameKey = assignedName.trim().toLowerCase();
          var lastSenderNameKey = lastSenderName.trim().toLowerCase();
          var isCurrentRequester = (currentEmail && requesterEmailKey && currentEmail === requesterEmailKey)
            || (currentName && requesterNameKey && currentName === requesterNameKey);
          var isCurrentAssignee = currentName && assignedNameKey && currentName === assignedNameKey;
          var lastSenderLabel = lastSenderName && (!currentName || lastSenderNameKey !== currentName) ? lastSenderName : '';
          var partnerLabel = isCurrentRequester && assignedName
            ? assignedName
            : (isCurrentRequester && lastSenderLabel
              ? lastSenderLabel
              : (isCurrentAssignee && requesterLabel ? requesterLabel : (requesterLabel || assignedName || lastSenderLabel)));
          var nextSubHtml =
            '<span class="tm-messenger-status-pill ' + messengerStatusClass(statusLabel) + '">' + escapeHtml(statusLabel) + '</span>' +
            (rel ? ('<span class="tm-messenger-sub-sep">&bull;</span><span class="tm-messenger-meta-text">' + escapeHtml(rel) + '</span>') : '') +
            (partnerLabel ? ('<span class="tm-messenger-sub-sep">&bull;</span><span class="tm-messenger-meta-text">' + escapeHtml(partnerLabel) + '</span>') : '');
          if (sub.innerHTML !== nextSubHtml) sub.innerHTML = nextSubHtml;
        } else {
          var nextSubText = conv.last_message_time ? ('Last message: ' + String(conv.last_message_time)) : '';
          if (sub.textContent !== nextSubText) sub.textContent = nextSubText;
        }
      } else {
        if (sub.textContent !== '') sub.textContent = '';
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
    messengerComposerSignature = '';
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
    setMessengerAttachments([]);
    clearReplyContext('messenger');
    setMessengerHeader(null);
    var container = qs('tmMessengerMessages');
    if (container) container.innerHTML = '<div class="tm-messenger-empty">Select a ticket on the left.</div>';
    messengerMessagesSignature = '';
    clearOwnTyping('messenger');
    hideMessengerMenu();
    renderConversations(qs('tmMessengerSearch') ? qs('tmMessengerSearch').value : '');
  }
  function selectConversation(conv, noReloadConversations) {
    if (!conv || conv.id == null) return;
    clearReplyContext('messenger');
    messengerTicketId = String(conv.id);
    setCurrentTicketId(messengerTicketId);
    var idEl = qs('tmMessengerTicketId');
    if (idEl) idEl.value = messengerTicketId;
    setMessengerHeader(conv);
    messengerComposerSignature = '';
    setMessengerComposerState(false, 'Checking ticket handler...');
    messengerMessagesSignature = '';

    renderConversations(qs('tmMessengerSearch') ? qs('tmMessengerSearch').value : '');
    stopMessenger();
    loadMessengerMessages(messengerTicketId, true);
    messengerInterval = setInterval(function () { loadMessengerMessages(messengerTicketId, false, true); }, 3000);
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
        renderMessengerMessages(data || [], scrollBottom);
        refreshTypingIndicator('messenger', 'tmMessengerMessages');
      })
      .catch(function () { });
  }
  function renderMessengerMessages(messages, scrollBottom) {
    var container = qs('tmMessengerMessages');
    if (!container) return;
    var groupedMessages = groupMessengerMessages(messages || []);
    var nextSignature = JSON.stringify(groupedMessages.map(function (msg) {
      return {
        id: msg && msg.id != null ? String(msg.id) : '',
        ids: Array.isArray(msg && msg.message_ids) ? msg.message_ids.map(String) : [],
        group: msg && msg.message_group_id != null ? String(msg.message_group_id) : '',
        sender: msg && msg.sender_id != null ? String(msg.sender_id) : '',
        senderName: msg && msg.sender_name != null ? String(msg.sender_name) : '',
        message: msg && msg.message != null ? String(msg.message) : '',
        createdAt: msg && msg.created_at != null ? String(msg.created_at) : '',
        createdAtFull: msg && msg.created_at_full != null ? String(msg.created_at_full) : '',
        isEdited: !!(msg && msg.is_edited),
        editedAt: msg && msg.edited_at != null ? String(msg.edited_at) : '',
        editHistory: Array.isArray(msg && msg.edit_history) ? msg.edit_history.map(function (item) {
          return {
            message: String(item && item.message != null ? item.message : ''),
            editedAt: String(item && item.edited_at != null ? item.edited_at : '')
          };
        }) : [],
        isRead: !!(msg && msg.is_read),
        isMe: !!(msg && msg.is_me),
        attachments: Array.isArray(msg && msg.attachments) ? msg.attachments.map(function (att) {
          return {
            stored: String(att && att.stored_name || ''),
            original: String(att && att.original_name || ''),
            isImage: !!(att && att.is_image)
          };
        }) : []
      };
    }));
    if (nextSignature === messengerMessagesSignature) {
      if (scrollBottom) container.scrollTop = container.scrollHeight;
      return;
    }
    messengerMessagesSignature = nextSignature;
    var isNearBottom = container.scrollHeight - container.scrollTop - container.clientHeight < 120;
    container.innerHTML = '';
    if (!groupedMessages.length) {
      container.innerHTML = '<div class="tm-messenger-empty no-messages">No messages yet</div>';
      return;
    }
    var lastDateKey = '';
    groupedMessages.forEach(function (msg) {
      var messageDateValue = msg && (msg.created_at_full || msg.created_at) ? (msg.created_at_full || msg.created_at) : '';
      var nextDateKey = chatDateKey(messageDateValue);
      if (nextDateKey && nextDateKey !== lastDateKey) {
        var separator = createChatDateSeparator(messageDateValue);
        if (separator) container.appendChild(separator);
        lastDateKey = nextDateKey;
      }
      var bubble = document.createElement('div');
      bubble.classList.add('chat-bubble', (msg.is_me ? 'me' : 'other'));
      if (msg && msg.is_edited) bubble.classList.add('has-edited-meta');
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
      var bubbleAvatarName = senderLabel || (msg && msg.is_me ? 'You' : 'User');
      bubble.classList.add('tm-has-letter-avatar');
      bubble.setAttribute('data-avatar', tmAvatarInitials(bubbleAvatarName));
      bubble.style.setProperty('--tm-avatar-bg', tmAvatarColor(bubbleAvatarName));
      if (senderLabel) {
        var sDiv = document.createElement('div');
        sDiv.classList.add('chat-sender');
        sDiv.textContent = senderLabel;
        bubble.appendChild(sDiv);
      }
      var replyNode = createMessageReplyNode(msg);
      if (replyNode) bubble.appendChild(replyNode);
      if (msg && msg.message) {
        var contentDiv = document.createElement('div');
        contentDiv.classList.add('chat-message-text');
        contentDiv.textContent = msg.message;
        bubble.appendChild(contentDiv);
      }
      var attachmentNode = createMessageAttachmentsNode(msg && msg.attachments ? msg.attachments : (msg && msg.attachment ? [msg.attachment] : []));
      if (attachmentNode) bubble.appendChild(attachmentNode);
      bubble.appendChild(createMessageMetaNode(msg, formatChatTimeDisplay(messageDateValue || (msg && msg.created_at))));
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
    var optimisticAvatarName = senderName || (isMe ? 'You' : 'User');
    bubble.classList.add('tm-has-letter-avatar');
    bubble.setAttribute('data-avatar', tmAvatarInitials(optimisticAvatarName));
    bubble.style.setProperty('--tm-avatar-bg', tmAvatarColor(optimisticAvatarName));
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
    setMessageTimeWithStatus(timeDiv, { is_me: isMe, is_read: false }, timeText || formatHHMM(new Date()));
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
    var nextSignature = [allowed ? '1' : '0', waiting ? '1' : '0', String(lockedMessage || ''), handlerName].join('|');
    if (nextSignature === messengerComposerSignature) {
      messengerPermissionState.canChat = allowed;
      messengerPermissionState.lockedMessage = String(lockedMessage || '');
      messengerPermissionState.handlerName = handlerName;
      messengerPermissionState.isChecking = waiting;
      return;
    }
    messengerComposerSignature = nextSignature;
    messengerPermissionState.canChat = allowed;
    messengerPermissionState.lockedMessage = String(lockedMessage || '');
    messengerPermissionState.handlerName = handlerName;
    messengerPermissionState.isChecking = waiting;
    if (input) {
      input.disabled = !allowed;
      input.readOnly = !allowed;
      input.tabIndex = allowed ? 0 : -1;
      if (!allowed) input.value = '';
      input.placeholder = allowed ? 'Type a message...' : (waiting ? 'Checking ticket handler...' : (String(lockedMessage || '').trim() || 'You can\'t message.'));
      input.style.cursor = allowed ? 'text' : 'not-allowed';
      input.style.opacity = allowed ? '1' : '0.75';
      input.style.backgroundColor = allowed ? '' : '#f8fafc';
      input.style.pointerEvents = allowed ? 'auto' : 'none';
      resizeMessengerInput();
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
      setMessengerAttachments([]);
      clearReplyContext('messenger');
    }
  }
  function renderMessengerLockedState(message) {
    var container = qs('tmMessengerMessages');
    if (!container) return;
    messengerMessagesSignature = '';
    var lockedText = String(message || messengerPermissionState.lockedMessage || '').trim();
    var handlerName = extractHandlerName(lockedText);
    var subtitle = handlerName
      ? ('This ticket is already assigned to <strong>' + escapeHtml(handlerName || 'another IT staff') + '</strong>.')
      : escapeHtml(lockedText || 'You cannot send messages for this ticket.');
    container.innerHTML =
      '<div class="tm-messenger-locked-state">' +
      '  <div class="tm-messenger-lock-title-row"><span class="tm-messenger-locked-icon"><i class="fas fa-lock"></i></span><div class="tm-messenger-lock-title">You can\'t message.</div></div>' +
      '  <div class="tm-messenger-lock-subtitle">' + subtitle + '</div>' +
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
            requester_name: data && data.created_by_name ? String(data.created_by_name) : '',
            requester_email: data && data.created_by_email ? String(data.created_by_email) : '',
            assigned_to_name: data && data.assigned_to_name ? String(data.assigned_to_name) : ''
          };
        }
        if (conv) {
          conv.subject = getDisplaySubject(data);
          if (data && data.status) conv.status = String(data.status);
          if (data && data.created_by_name) conv.requester_name = String(data.created_by_name);
          if (data && data.created_by_email) conv.requester_email = String(data.created_by_email);
          if (data && data.assigned_to_name) conv.assigned_to_name = String(data.assigned_to_name);
          conv.can_chat = data && data.can_chat === true;
          conv.chat_locked_message = data && data.chat_locked_message ? String(data.chat_locked_message) : '';
        } else if (headerConv) {
          headerConv.requester_name = data && data.created_by_name ? String(data.created_by_name) : '';
          if (data && data.assigned_to_name) headerConv.assigned_to_name = String(data.assigned_to_name);
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
          loadMessengerMessages(ticketId, scrollBottom === true, true);
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
    var files = Array.isArray(messengerAttachmentFiles) ? messengerAttachmentFiles.slice() : [];
    if (!ticketId || (!message && files.length === 0)) return;
    var oversizedFile = files.find(chatAttachmentTooLarge);
    if (oversizedFile) {
      showChatAttachmentError(chatAttachmentSizeMessage(oversizedFile));
      return;
    }
    if (input.disabled || input.readOnly || messengerPermissionState.canChat !== true) return;
    if (btn && btn.disabled) return;
    if (btn) btn.disabled = true;
    var formData = new FormData();
    formData.append('ticket_id', ticketId);
    formData.append('message', message);
    if (messengerReplyContext) {
      formData.append('reply_to_message_id', String(messengerReplyContext.messageId || ''));
      formData.append('reply_to_sender_name', String(messengerReplyContext.senderName || ''));
      formData.append('reply_to_text', String(messengerReplyContext.text || ''));
      formData.append('reply_to_attachment', messengerReplyContext.hasImageAttachment ? 'image' : '');
      formData.append('reply_to_attachment_stored_name', String(messengerReplyContext.attachmentStoredName || ''));
    }
    files.forEach(function (file) {
      formData.append('attachments[]', file);
    });
    var t = getCsrfToken();
    if (t) formData.append('csrf_token', t);
    postJson('chat_send.php', formData)
      .then(function (data) {
        if (btn) btn.disabled = false;
        if (data && data.success) {
          input.value = '';
          clearOwnTyping('messenger');
          resizeMessengerInput();
          if (attachInput) attachInput.value = '';
          setMessengerAttachments([]);
          clearReplyContext('messenger');
          messengerMessagesSignature = '';
          if (typeof window !== 'undefined' && typeof window.TMRefreshGlobalChatBadge === 'function') {
            window.TMRefreshGlobalChatBadge();
          }
          setTimeout(function () { loadMessengerMessages(ticketId, true, true); }, 0);
          return;
        }
        loadMessengerMessages(ticketId, false, true);
      })
      .catch(function () {
        if (btn) btn.disabled = false;
        loadMessengerMessages(ticketId, false, true);
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
    setMessengerMobileView('list');
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
    setMessengerMobileView('chat');
    setTimeout(function () { loadConversationsAndMaybeSelect(); }, 0);
  }
  function closeMessengerChat() {
    var modal = qs('tmMessengerModal');
    if (modal) modal.style.display = 'none';
    hideMessengerMessageEditor();
    hideMessengerConfirm();
    messengerOpen = false;
    messengerReturnContext = null;
    clearOwnTyping('messenger');
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
    setMessengerMobileView('chat');
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
        lastTicketMeta = {
          id: data && data.id != null ? data.id : id,
          subject: getDisplaySubject(data),
          status: data && data.status ? String(data.status) : '',
          feedback_status: data && data.feedback_status ? String(data.feedback_status) : ''
        };
        try {
          modalContent.innerHTML = buildHtml(data);
        } catch (renderError) {
          console.error('Ticket modal render failed:', renderError, data);
          modalContent.innerHTML = buildFallbackHtml(data);
        }
        setMobileInfoSection(0, false);
        try {
          if (data && data.id != null && data.hide_conversation_tab !== true && (data.is_sales_ticket !== true || data.sales_manager_regional_access === true || data.sales_assignee_chat_access === true) && (data.reassigned_view_only !== true || data.can_view_chat_history === true)) {
            var tabsEl = modalContent.querySelector('.tm-tabs');
            var existingConversationTab = tabsEl ? tabsEl.querySelector('.tm-tab[data-tab="conversation"]') : null;
            if (tabsEl && !existingConversationTab) {
              var conversationTab = document.createElement('div');
              conversationTab.className = 'tm-tab';
              conversationTab.setAttribute('data-tab', 'conversation');
              conversationTab.textContent = 'Go to Chat';
              conversationTab.addEventListener('click', function () {
                openConversation(String(data.id));
              });
              tabsEl.appendChild(conversationTab);
            }
          }
        } catch (conversationTabInjectError) {
          console.error('Ticket modal conversation-tab injection failed:', conversationTabInjectError, data);
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
          bindUpdateActionLayout(modalContent);
        } catch (updateActionLayoutError) {
          console.error('Ticket modal update action layout binding failed:', updateActionLayoutError, data);
        }
        try {
          bindDepartmentOptions(modalContent, data);
        } catch (deptBindError) {
          console.error('Ticket modal department binding failed:', deptBindError, data);
        }
        try {
          bindCustomStatusDropdown(modalContent);
        } catch (customStatusBindError) {
          console.error('Ticket modal status dropdown binding failed:', customStatusBindError, data);
        }
        try {
          bindCustomSelectDropdowns(modalContent);
        } catch (customDeptBindError) {
          console.error('Ticket modal custom department dropdown binding failed:', customDeptBindError, data);
        }
        if (typeof window !== 'undefined' && window.TM_SHOW_DEPARTMENT_USER_SELECT === true) {
          try {
            bindDepartmentUserOptions(modalContent, data);
          } catch (deptUserBindError) {
            console.error('Ticket modal department-user binding failed:', deptUserBindError, data);
          }
        }
        try {
          renderPdfCardThumbnails(modalContent);
        } catch (pdfThumbError) {
          console.error('PDF thumbnail rendering failed:', pdfThumbError);
        }
        setTimeout(function () {
          syncTicketContentCardHeight(modalContent);
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
  function isPreviewableImageSrc(src) {
    var clean = String(src || '').split('?')[0].split('#')[0].toLowerCase();
    return /\.(jpe?g|png|gif|webp|bmp)$/i.test(clean);
  }
  function ensureImagePreviewControls() {
    var modal = qs('imagePreviewModal');
    if (!modal) return;
    var content = modal.querySelector('.preview-content, .image-preview-content');
    if (!content) return;
    var closeBtn = content.querySelector('.preview-close');
    if (closeBtn) {
      closeBtn.setAttribute('type', 'button');
      closeBtn.setAttribute('aria-label', 'Close preview');
      closeBtn.textContent = 'X';
    }
    if (!content.querySelector('.preview-prev')) {
      var prev = document.createElement('button');
      prev.type = 'button';
      prev.className = 'preview-nav preview-prev';
      prev.setAttribute('aria-label', 'Previous attachment');
      prev.innerHTML = '<i class="fas fa-chevron-left"></i>';
      prev.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();
        stepImagePreview(-1);
      });
      content.insertBefore(prev, content.querySelector('img') || content.firstChild);
    }
    if (!content.querySelector('.preview-next')) {
      var next = document.createElement('button');
      next.type = 'button';
      next.className = 'preview-nav preview-next';
      next.setAttribute('aria-label', 'Next attachment');
      next.innerHTML = '<i class="fas fa-chevron-right"></i>';
      next.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();
        stepImagePreview(1);
      });
      content.appendChild(next);
    }
  }
  function collectImagePreviewSources(activeSrc) {
    var seen = {};
    var sources = [];
    var nodes = document.querySelectorAll('.tm-attachment-thumb[data-src], .tm-hr-category-media.is-image[data-src], .tm-view-btn[data-src], .tm-chat-attachment-button[data-src]');
    Array.prototype.forEach.call(nodes, function (node) {
      var src = node.getAttribute('data-src') || '';
      if (!src || !isPreviewableImageSrc(src) || seen[src]) return;
      seen[src] = true;
      sources.push(src);
    });
    if (activeSrc && !seen[activeSrc]) {
      sources.push(activeSrc);
    }
    return sources;
  }
  function setImagePreviewSource(src) {
    var img = qs('previewImage');
    if (img) img.src = src;
    var modal = qs('imagePreviewModal');
    if (!modal) return;
    var hasMultiple = imagePreviewSources.length > 1;
    var prev = modal.querySelector('.preview-prev');
    var next = modal.querySelector('.preview-next');
    if (prev) prev.hidden = !hasMultiple;
    if (next) next.hidden = !hasMultiple;
  }
  function viewImage(src) {
    var modal = qs('imagePreviewModal');
    var img = qs('previewImage');
    if (!modal || !img) return;
    ensureImagePreviewControls();
    imagePreviewSources = collectImagePreviewSources(src);
    imagePreviewIndex = imagePreviewSources.indexOf(src);
    if (imagePreviewIndex < 0) imagePreviewIndex = imagePreviewSources.length - 1;
    setImagePreviewSource(src);
    modal.classList.add('show');
  }
  function stepImagePreview(delta) {
    if (!imagePreviewSources.length) return;
    var total = imagePreviewSources.length;
    imagePreviewIndex = ((imagePreviewIndex + Number(delta || 0)) % total + total) % total;
    setImagePreviewSource(imagePreviewSources[imagePreviewIndex]);
  }
  function closeImagePreview(e) {
    var modal = qs('imagePreviewModal');
    if (!modal) return;
    if (!e || e.target.id === 'imagePreviewModal' || (e.target && e.target.closest && e.target.closest('.preview-close'))) {
      modal.classList.remove('show');
      setTimeout(function () {
        var img = qs('previewImage');
        if (img) img.src = '';
        imagePreviewSources = [];
        imagePreviewIndex = -1;
      }, 300);
    }
  }
  function openFilePreview(src, name) {
    ensureTicketModalExists();
    var modal = qs('filePreviewModal');
    var frame = qs('filePreviewFrame');
    var title = qs('filePreviewTitle');
    var download = qs('filePreviewDownload');
    if (!modal || !frame) {
      window.location.href = src;
      return;
    }
    var fileName = String(name || '').trim() || String(src || '').split('/').pop() || 'Attachment';
    if (title) title.textContent = fileName;
    if (download) {
      download.href = src;
      download.setAttribute('download', fileName);
    }
    frame.src = src;
    modal.classList.add('show');
  }
  function openFilePreviewFromCard(card) {
    if (!card) return;
    openFilePreview(card.getAttribute('data-src') || '', card.getAttribute('data-name') || '');
  }
  function closeFilePreview() {
    var modal = qs('filePreviewModal');
    var frame = qs('filePreviewFrame');
    if (!modal) return;
    modal.classList.remove('show');
    setTimeout(function () {
      if (frame) frame.src = '';
    }, 250);
  }
  return {
    open: open,
    close: close,
    switchTab: switchTab,
    claimTicket: claimTicket,
    sendMessage: sendMessage,
    openChatModal: openChatModal,
    closeChatModal: closeChatModal,
    sendChatModalMessage: sendChatModalMessage,
    openConversation: openConversation,
    openMessengerChat: openMessengerChat,
    closeMessengerChat: closeMessengerChat,
    updateStatusColor: updateStatusColor,
    stepHrAttachmentCategory: stepHrAttachmentCategory,
    stepAttachmentPage: stepAttachmentPage,
    showTicketContentPage: showTicketContentPage,
    stepSapDisplay: stepSapDisplay,
    viewImage: viewImage,
    stepImagePreview: stepImagePreview,
    closeImagePreview: closeImagePreview,
    openFilePreview: openFilePreview,
    openFilePreviewFromCard: openFilePreviewFromCard,
    closeFilePreview: closeFilePreview,
    toggleTimeline: toggleTimeline,
    stepMobileInfoSection: stepMobileInfoSection,
    getCurrentTicketId: getCurrentTicketId
  };
})(); 




