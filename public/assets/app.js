/**
 * The assistant panel (BRIEF.md §6), in plain browser JavaScript.
 *
 * No framework and no build step: there is no Node on the target host, and a
 * bundle nobody can rebuild for three weeks is a liability rather than a
 * convenience.
 *
 * The conversation lives here and nowhere else. Attachments are held in memory
 * for as long as the conversation is open and are gone when she starts a new
 * question — they are never written to disk on the server (§6).
 */
(function () {
  'use strict';

  var root = document.getElementById('assistant');
  if (!root) return;

  var CONTEXTS = JSON.parse(root.getAttribute('data-contexts') || '[]');

  /* Must match src/Conversation.php. */
  var MAX_ATTACHMENT_BASE64 = 11000000;
  var MAX_CONVERSATION_BASE64 = 18000000;
  var IMAGE_MAX_EDGE = 1568;
  var ATTACHMENT_ONLY_QUESTION = 'Does this sit inside the pack?';

  /*
   * crypto.randomUUID only exists in a secure context, and this app runs over
   * plain HTTP until the certificate is issued. The fallback is not
   * cryptographic and does not need to be: the id only separates one
   * conversation from another for the per-conversation message cap.
   */
  function uuid() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      return window.crypto.randomUUID();
    }
    return 'c-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
  }

  var state = {
    context: null,
    conversationId: uuid(),
    turns: [],
    attachments: [],
    busy: false,
    controller: null
  };

  /* ---------- small DOM helpers ---------- */

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = text;
    return node;
  }

  function clear(node) {
    while (node.firstChild) node.removeChild(node.firstChild);
  }

  function describe(base64Length) {
    var bytes = Math.floor((base64Length * 3) / 4);
    var mb = bytes / (1024 * 1024);
    return mb >= 1 ? mb.toFixed(1) + ' MB' : Math.round(bytes / 1024) + ' KB';
  }

  /* ---------- attachments ---------- */

  function blobToBase64(blob) {
    return new Promise(function (resolve, reject) {
      var reader = new FileReader();
      reader.onerror = function () { reject(new Error('That file could not be read. Try attaching it again.')); };
      reader.onload = function () {
        var result = String(reader.result);
        var comma = result.indexOf(',');
        if (comma < 0) reject(new Error('That file could not be read. Try attaching it again.'));
        else resolve(result.slice(comma + 1));
      };
      reader.readAsDataURL(blob);
    });
  }

  /*
   * Photographs off a phone are several megabytes and several times larger than
   * the API can use. Resizing here is the difference between an attachment
   * working on the factory floor and a request that takes a minute to upload.
   */
  function prepareImage(file) {
    return createImageBitmap(file).then(function (bitmap) {
      var longest = Math.max(bitmap.width, bitmap.height);
      var scale = longest > IMAGE_MAX_EDGE ? IMAGE_MAX_EDGE / longest : 1;
      var allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

      if (scale === 1 && allowed.indexOf(file.type) !== -1 && file.size * 1.37 < MAX_ATTACHMENT_BASE64) {
        bitmap.close();
        return { blob: file, mediaType: file.type };
      }

      var canvas = document.createElement('canvas');
      canvas.width = Math.max(1, Math.round(bitmap.width * scale));
      canvas.height = Math.max(1, Math.round(bitmap.height * scale));
      var context = canvas.getContext('2d');
      if (!context) throw new Error('That image could not be prepared. Try attaching it again.');
      context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
      bitmap.close();

      return new Promise(function (resolve, reject) {
        canvas.toBlob(function (blob) {
          if (blob) resolve({ blob: blob, mediaType: 'image/jpeg' });
          else reject(new Error('That image could not be prepared. Try attaching it again.'));
        }, 'image/jpeg', 0.9);
      });
    }, function () {
      throw new Error(
        'That image is in a format this browser cannot open' + (file.type ? ' (' + file.type + ')' : '') +
        '. Save it as a JPEG or PNG and attach it again.'
      );
    });
  }

  function prepareFile(file) {
    var name = file.name || 'attachment';

    if (file.type === 'application/pdf') {
      return blobToBase64(file).then(function (data) {
        if (data.length > MAX_ATTACHMENT_BASE64) {
          throw new Error(
            name + ' is ' + describe(data.length) + ', over the ' + describe(MAX_ATTACHMENT_BASE64) +
            ' limit for one attachment. Export it at a lower resolution, or send just the page you want checked.'
          );
        }
        return { id: uuid(), kind: 'document', mediaType: 'application/pdf', data: data, name: name };
      });
    }

    if (file.type.indexOf('image/') !== 0) {
      return Promise.reject(new Error(name + ' is not an image or a PDF. Those are the two things that can be attached.'));
    }

    return prepareImage(file).then(function (prepared) {
      return blobToBase64(prepared.blob).then(function (data) {
        if (data.length > MAX_ATTACHMENT_BASE64) {
          throw new Error(
            name + ' is still ' + describe(data.length) + ' after resizing, over the ' +
            describe(MAX_ATTACHMENT_BASE64) + ' limit. Crop it to the part you want checked.'
          );
        }
        return {
          id: uuid(),
          kind: 'image',
          mediaType: prepared.mediaType,
          data: data,
          name: name,
          preview: 'data:' + prepared.mediaType + ';base64,' + data
        };
      });
    });
  }

  function attachedBytes(extra) {
    var total = 0;
    state.turns.forEach(function (turn) {
      if (turn.role === 'user') {
        turn.attachments.forEach(function (a) { total += a.data.length; });
      }
    });
    (extra || []).forEach(function (a) { total += a.data.length; });
    return total;
  }

  /* ---------- the shell ---------- */

  var ui = {};

  function buildShell() {
    clear(root);

    ui.contextBar = el('div', 'context-bar');
    ui.contextName = el('span', 'context-bar-name');
    var actions = el('span', 'context-bar-actions');
    ui.newQuestion = el('button', 'linkbutton', 'New question');
    ui.newQuestion.type = 'button';
    ui.changeBrand = el('button', 'linkbutton', 'Change brand');
    ui.changeBrand.type = 'button';
    actions.appendChild(ui.newQuestion);
    actions.appendChild(document.createTextNode(' · '));
    actions.appendChild(ui.changeBrand);
    ui.contextBar.appendChild(ui.contextName);
    ui.contextBar.appendChild(actions);

    ui.empty = el('p', 'instruction',
      'Paste a draft, drop a proof, or ask what you can decide on your own. Nothing you attach is stored.');

    ui.transcript = el('div');

    ui.working = el('section', 'working');
    ui.working.hidden = true;
    ui.working.appendChild(el('p', 'turn-label', 'Assistant'));
    ui.workingLine = el('p', 'working-line');
    ui.working.appendChild(ui.workingLine);
    var bar = el('div', 'working-bar');
    bar.appendChild(el('span'));
    ui.working.appendChild(bar);
    ui.workingElapsed = el('p', 'working-elapsed');
    ui.working.appendChild(ui.workingElapsed);

    ui.error = el('p', 'notice');
    ui.error.hidden = true;

    ui.composer = buildComposer();

    root.appendChild(ui.contextBar);
    root.appendChild(ui.empty);
    root.appendChild(ui.transcript);
    root.appendChild(ui.working);
    root.appendChild(ui.error);
    root.appendChild(ui.composer);

    ui.newQuestion.addEventListener('click', function () { startNewQuestion(state.context); });
    ui.changeBrand.addEventListener('click', function () { startNewQuestion(null); });

    root.addEventListener('dragover', function (e) { e.preventDefault(); });
    root.addEventListener('drop', function (e) {
      e.preventDefault();
      if (e.dataTransfer && e.dataTransfer.files.length > 0) addFiles(e.dataTransfer.files);
    });
  }

  function buildComposer() {
    var composer = el('div', 'composer');

    ui.attachments = el('div', 'attachments');
    ui.attachments.hidden = true;

    ui.textarea = el('textarea');
    ui.textarea.rows = 3;

    var row = el('div', 'composer-row');
    var tools = el('div', 'composer-tools');

    ui.attachLabel = buildFileButton('Attach', 'image/*,application/pdf', true, false);
    ui.photoLabel = buildFileButton('Photo', 'image/*', false, true);
    ui.photoLabel.className = 'button touch-only';

    tools.appendChild(ui.attachLabel);
    tools.appendChild(ui.photoLabel);

    ui.stop = el('button', 'button', 'Stop');
    ui.stop.type = 'button';
    ui.stop.hidden = true;
    ui.stop.addEventListener('click', function () {
      if (state.controller) state.controller.abort();
    });

    ui.send = el('button', 'button button-primary', 'Send');
    ui.send.type = 'button';
    ui.send.style.marginLeft = 'auto';
    ui.send.disabled = true;
    ui.send.addEventListener('click', send);

    row.appendChild(tools);
    row.appendChild(ui.stop);
    row.appendChild(ui.send);

    composer.appendChild(ui.attachments);
    composer.appendChild(ui.textarea);
    composer.appendChild(row);

    ui.textarea.addEventListener('input', refreshSendState);
    ui.textarea.addEventListener('paste', function (e) {
      if (e.clipboardData && e.clipboardData.files.length > 0) {
        e.preventDefault();
        addFiles(e.clipboardData.files);
      }
    });
    ui.textarea.addEventListener('keydown', function (e) {
      if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
        e.preventDefault();
        send();
      }
    });

    return composer;
  }

  function buildFileButton(label, accept, multiple, capture) {
    var wrapper = el('label', 'button', label);
    var input = document.createElement('input');
    input.type = 'file';
    input.className = 'visually-hidden';
    input.accept = accept;
    if (multiple) input.multiple = true;
    if (capture) input.setAttribute('capture', 'environment');
    input.addEventListener('change', function () {
      if (input.files) addFiles(input.files);
      input.value = '';
    });
    wrapper.appendChild(input);
    return wrapper;
  }

  /* ---------- rendering ---------- */

  function showContextPicker() {
    clear(root);

    root.appendChild(el('h1', 'page-title', 'Which brand is this about?'));
    root.appendChild(el('p', 'lede', 'The assistant reads that brand’s pack and the authority envelope before it answers.'));

    var list = el('div', 'context-list');
    CONTEXTS.forEach(function (context) {
      var button = el('button', 'context-choice');
      button.type = 'button';
      button.style.setProperty('--context-colour', context.colour);
      var swatch = el('span', 'swatch');
      swatch.setAttribute('aria-hidden', 'true');
      button.appendChild(swatch);
      button.appendChild(el('span', 'context-choice-name', context.name));
      if (context.internal) button.appendChild(el('span', 'context-choice-note', 'Internal'));
      button.addEventListener('click', function () { startNewQuestion(context); });
      list.appendChild(button);
    });
    root.appendChild(list);
  }

  function renderAttachments() {
    clear(ui.attachments);
    ui.attachments.hidden = state.attachments.length === 0;

    state.attachments.forEach(function (attachment) {
      var chip = el('span', 'attachment');
      if (attachment.preview) {
        var img = document.createElement('img');
        img.src = attachment.preview;
        img.alt = '';
        chip.appendChild(img);
      } else {
        var badge = el('span', 'attachment-pdf', 'PDF');
        badge.setAttribute('aria-hidden', 'true');
        chip.appendChild(badge);
      }
      var name = el('span', 'attachment-name', attachment.name);
      name.title = attachment.name;
      chip.appendChild(name);

      var remove = el('button', 'linkbutton', '×');
      remove.type = 'button';
      remove.setAttribute('aria-label', 'Remove ' + attachment.name);
      remove.addEventListener('click', function () {
        state.attachments = state.attachments.filter(function (a) { return a.id !== attachment.id; });
        renderAttachments();
        refreshSendState();
      });
      chip.appendChild(remove);
      ui.attachments.appendChild(chip);
    });
  }

  function appendUserTurn(turn) {
    var section = el('section', 'turn');
    section.appendChild(el('p', 'turn-label', 'You'));
    var body = el('div', 'turn-you');
    turn.text.split('\n\n').forEach(function (paragraph) {
      body.appendChild(el('p', null, paragraph));
    });
    section.appendChild(body);

    if (turn.attachments.length > 0) {
      var names = turn.attachments.map(function (a) { return a.name; }).join(', ');
      var note = el('p', 'meta', names + ' — sent, not stored');
      note.style.marginTop = '0.5rem';
      section.appendChild(note);
    }

    ui.transcript.appendChild(section);
    turn.node = section;
  }

  function appendAssistantTurn(reply) {
    var section = el('section', 'turn');
    section.appendChild(el('p', 'turn-label', 'Assistant'));

    var prose = el('div', 'prose');
    /*
     * This HTML came from src/Markdown.php on our own server, which escapes
     * every piece of model text before adding a tag. It is the same renderer
     * the log screen uses, so there is exactly one implementation to trust.
     */
    prose.innerHTML = reply.html;
    section.appendChild(prose);

    var outcome = el('div', 'outcome');
    outcome.appendChild(el('p', 'outcome-label', reply.outcomeLabel));
    if (reply.logError) outcome.appendChild(el('p', 'outcome-warning', reply.logError));
    if (reply.logId) outcome.appendChild(buildNoteForm(reply));
    section.appendChild(outcome);

    ui.transcript.appendChild(section);
  }

  /*
   * "What she decided" (BRIEF.md §9). The entry is already in the log — the
   * server wrote it the moment the assistant classified the reply — so this
   * fills in the one field that is hers, once. It cannot be edited afterwards.
   */
  function buildNoteForm(reply) {
    var logId = reply.logId;
    var form = el('div', 'note-form');

    // The wording differs by kind and comes from the server, so there is one
    // place that decides what each kind of entry calls her own words.
    var label = el('label', 'note-label', reply.noteLabel);
    label.htmlFor = 'note-' + logId;

    var textarea = el('textarea');
    textarea.id = 'note-' + logId;
    textarea.rows = 2;
    textarea.placeholder = reply.notePlaceholder;

    var row = el('div', 'composer-row');
    var button = el('button', 'button', 'Add to log');
    button.type = 'button';
    button.disabled = true;
    row.appendChild(button);

    var error = el('p', 'outcome-warning');
    error.hidden = true;

    textarea.addEventListener('input', function () {
      button.disabled = textarea.value.trim() === '';
    });

    button.addEventListener('click', function () {
      var note = textarea.value.trim();
      if (note === '') return;
      button.disabled = true;
      button.textContent = 'Saving…';
      error.hidden = true;

      fetch('/api/note', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: logId, note: note })
      }).then(function (response) {
        return response.json().catch(function () { return null; }).then(function (payload) {
          if (!response.ok) throw new Error((payload && payload.error) || 'That could not be saved. Try again.');
          var saved = el('p', 'note-saved');
          saved.appendChild(el('span', 'meta', reply.noteHeading + ': '));
          saved.appendChild(document.createTextNode(note));
          form.parentNode.replaceChild(saved, form);
        });
      }).catch(function (failure) {
        error.textContent = failure.message || 'That could not be saved — you may be offline. Try again.';
        error.hidden = false;
        button.disabled = false;
        button.textContent = 'Add to log';
      });
    });

    form.appendChild(label);
    form.appendChild(textarea);
    form.appendChild(row);
    form.appendChild(error);
    return form;
  }

  function showError(message) {
    ui.error.textContent = message;
    ui.error.hidden = false;
  }

  function hideError() {
    ui.error.hidden = true;
  }

  function refreshSendState() {
    var hasSomething = ui.textarea.value.trim() !== '' || state.attachments.length > 0;
    ui.send.disabled = state.busy || !hasSomething;
  }

  function scrollToBottom() {
    var nearBottom = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 200;
    if (nearBottom) window.scrollTo(0, document.documentElement.scrollHeight);
  }

  /* ---------- actions ---------- */

  function addFiles(files) {
    hideError();
    Array.prototype.slice.call(files).forEach(function (file) {
      prepareFile(file).then(function (attachment) {
        state.attachments.push(attachment);
        renderAttachments();
        refreshSendState();
      }).catch(function (failure) {
        showError(failure.message || 'That file could not be attached. Try a JPEG, PNG or PDF.');
      });
    });
  }

  function startNewQuestion(context) {
    if (state.controller) state.controller.abort();
    state.context = context || null;
    state.conversationId = uuid();
    state.turns = [];
    state.attachments = [];
    state.busy = false;
    state.controller = null;

    if (!state.context) {
      showContextPicker();
      return;
    }

    buildShell();
    ui.contextBar.style.setProperty('--context-colour', state.context.colour);
    ui.contextName.textContent = state.context.name;
    ui.textarea.placeholder = 'Ask about ' + state.context.name + ', or paste the draft.';
    renderAttachments();
    refreshSendState();
  }

  function setBusy(busy) {
    state.busy = busy;
    ui.working.hidden = !busy;
    ui.stop.hidden = !busy;
    ui.textarea.disabled = busy;
    refreshSendState();
  }

  var elapsedTimer = null;

  function startElapsed() {
    var started = Date.now();
    ui.workingLine.textContent = 'Checking against the ' + state.context.name + ' pack…';
    ui.workingElapsed.textContent = '';
    elapsedTimer = window.setInterval(function () {
      var seconds = Math.round((Date.now() - started) / 1000);
      if (seconds >= 8) {
        ui.workingElapsed.textContent = seconds + 's. A considered answer usually takes twenty to forty.';
      }
    }, 1000);
  }

  function stopElapsed() {
    if (elapsedTimer !== null) {
      window.clearInterval(elapsedTimer);
      elapsedTimer = null;
    }
  }

  function toApiMessages() {
    return state.turns.map(function (turn) {
      if (turn.role === 'user') {
        var content = turn.attachments.map(function (a) {
          return a.kind === 'image'
            ? { type: 'image', media_type: a.mediaType, data: a.data }
            : { type: 'document', media_type: a.mediaType, data: a.data };
        });
        content.push({ type: 'text', text: turn.text });
        return { role: 'user', content: content };
      }
      return { role: 'assistant', content: [{ type: 'text', text: turn.text }] };
    });
  }

  function send() {
    if (!state.context || state.busy) return;

    var typed = ui.textarea.value.trim();
    var text = typed !== '' ? typed : (state.attachments.length > 0 ? ATTACHMENT_ONLY_QUESTION : '');
    if (text === '') return;

    if (attachedBytes(state.attachments) > MAX_CONVERSATION_BASE64) {
      showError(
        'There are too many attachments in this conversation to send — the limit across all of ' +
        'them is about ' + describe(MAX_CONVERSATION_BASE64) + '. Start a new question with just the ' +
        'file you want checked.'
      );
      return;
    }

    var sentText = ui.textarea.value;
    var sentAttachments = state.attachments;
    var turn = { role: 'user', text: text, attachments: sentAttachments, node: null };

    state.turns.push(turn);
    state.attachments = [];
    ui.textarea.value = '';
    ui.empty.hidden = true;
    renderAttachments();
    hideError();
    appendUserTurn(turn);
    setBusy(true);
    startElapsed();
    scrollToBottom();

    /*
     * On failure the turn is taken back out of the transcript and what she
     * typed is put back in the box, so pressing Send again just retries. Leaving
     * a question with no answer in the history would also send two user messages
     * in a row on the next attempt.
     */
    function restore(message) {
      state.turns.pop();
      if (turn.node && turn.node.parentNode) turn.node.parentNode.removeChild(turn.node);
      ui.textarea.value = sentText;
      state.attachments = sentAttachments;
      renderAttachments();
      ui.empty.hidden = state.turns.length > 0;
      showError(message);
    }

    var controller = new AbortController();
    state.controller = controller;

    fetch('/api/assistant', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      signal: controller.signal,
      body: JSON.stringify({
        conversationId: state.conversationId,
        contextKey: state.context.key,
        messages: toApiMessages()
      })
    }).then(function (response) {
      return response.json().catch(function () { return null; }).then(function (payload) {
        if (!response.ok || !payload) {
          throw new Error((payload && payload.error) || 'The assistant could not be reached. Try again.');
        }
        state.turns.push({ role: 'assistant', text: payload.text });
        appendAssistantTurn(payload);
        scrollToBottom();
      });
    }).catch(function (failure) {
      if (failure && failure.name === 'AbortError') {
        state.turns.pop();
        if (turn.node && turn.node.parentNode) turn.node.parentNode.removeChild(turn.node);
        ui.textarea.value = sentText;
        state.attachments = sentAttachments;
        renderAttachments();
        ui.empty.hidden = state.turns.length > 0;
        return;
      }
      restore((failure && failure.message) || 'The assistant could not be reached — you may be offline. Try again.');
    }).then(function () {
      state.controller = null;
      stopElapsed();
      setBusy(false);
    });
  }

  showContextPicker();
})();
