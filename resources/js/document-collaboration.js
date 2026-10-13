const catalog = JSON.parse(document.querySelector("[data-ui-translations]")?.value || "{}");
const t = (value) => catalog[value] || value;
const statuses = { todo: "To do", in_progress: "In progress", blocked: "Blocked", done: "Done", cancelled: "Cancelled" };
const uuid = () => typeof crypto.randomUUID === 'function' ? crypto.randomUUID()
  : '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, character =>
    (Number(character) ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> Number(character) / 4).toString(16));
const el = (tag, text, className) => {
  const item = document.createElement(tag);
  if (text !== void 0) item.textContent = text;
  if (className) item.className = className;
  return item;
};
const btn = (text, action) => {
  const item = el("button", t(text), "button button--ghost");
  item.type = "button";
  item.addEventListener("click", action);
  return item;
};
const normalize = (value) => value.replace(/[\s\u00a0]+/gu, " ").trim();
function documentMap(container) {
  const raw = [], blocks = /* @__PURE__ */ new Set(["P", "DIV", "H1", "H2", "H3", "H4", "H5", "H6", "LI", "TR", "PRE", "SUMMARY", "BLOCKQUOTE", "BR"]);
  const walk = (current) => {
    if (current.nodeType === Node.TEXT_NODE) {
      let offset = 0;
      for (const char of current.data) {
        raw.push({ char, node: current, start: offset, end: offset + char.length });
        offset += char.length;
      }
      return;
    }
    if (current.nodeType !== Node.ELEMENT_NODE) return;
    if (current.matches('script,style,button,input,select,textarea,[aria-hidden="true"],[data-comment-decoration],.diagram-status,iframe')) return;
    for (const child of current.childNodes) walk(child);
    if (blocks.has(current.tagName) && !current.matches(".table-scroll")) raw.push({ char: "\n", node: null });
  };
  for (const child of container.childNodes) walk(child);
  const entries = [];
  let whitespace = null;
  for (const item of raw) {
    if (/[\s\u00a0]/u.test(item.char)) {
      if (entries.length) whitespace ||= item;
      continue;
    }
    if (whitespace) {
      entries.push({ ...whitespace, char: " " });
      whitespace = null;
    }
    entries.push(item);
  }
  return { text: entries.map((item) => item.char).join(""), entries };
}
document.querySelectorAll("[data-collaboration]").forEach((root) => {
  const config = JSON.parse(root.dataset.config), article = document.querySelector(config.selector), panel = root.querySelector("[data-discussions-panel]"), dialog = root.querySelector("[data-task-dialog]");
  const commentForm = root.querySelector("[data-comment-form]"), taskForm = root.querySelector("[data-task-form]");
  const discussionFeedback = root.querySelector("[data-discussions-feedback]"), taskFeedback = root.querySelector("[data-tasks-feedback]");
  let selection, commentContext, currentTask, taskSource = {}, discussionPage = 1, taskPage = 1, panelFocus, taskFocus;
  let commentBusy = false, taskBusy = false, assigneeLoading = false, assigneeSequence = 0, assigneeTimer, threadSequence = 0, taskSequence = 0;
  let fragmentLinks = [];
  let threadTarget = location.hash.match(/^#discussion-(\d+)$/)?.[1] || null;
  const date = (value) => value ? new Intl.DateTimeFormat(config.locale, { dateStyle: "medium", timeStyle: "short", timeZone: config.timezone }).format(/* @__PURE__ */ new Date(value.replace(" ", "T") + "Z")) : "";
  const request = async (action, input = {}, write = false) => {
    const response = await fetch("/collaboration/pages/" + config.page + "/" + action + (write ? "" : "?" + new URLSearchParams(input)), {
      method: write ? "POST" : "GET",
      headers: { Accept: "application/json", ...write ? { "Content-Type": "application/json", "X-CSRF-Token": config.token } : {} },
      ...write ? { body: JSON.stringify(input) } : {}
    });
    const body = await response.json();
    if (!response.ok) {
      const error = new Error(body.error?.message || t("Unable to save. Try again."));
      error.status = response.status;
      throw error;
    }
    return body;
  };
  const operation = (form, input) => {
    const hash = JSON.stringify(input);
    if (form.dataset.operationInput !== hash) {
      form.dataset.operationInput = hash;
      form.dataset.operationId = uuid();
    }
    return { ...input, operation_id: form.dataset.operationId };
  };
  const clearOperation = (form) => {
    delete form.dataset.operationInput;
    delete form.dataset.operationId;
  };
  const pages = (container, meta, load) => {
    container.replaceChildren();
    if (meta.page > 1) container.append(btn("Previous", () => load(meta.page - 1)));
    container.append(el("span", meta.page + " · " + meta.total, "muted"));
    if (meta.page * meta.per_page < meta.total) container.append(btn("Next", () => load(meta.page + 1)));
  };
  const showPanel = () => {
    if (panel.open) return;
    panelFocus = document.activeElement;
    if (matchMedia("(max-width: 767px)").matches) panel.showModal();
    else panel.show();
    panel.querySelector("[data-panel-close]").focus();
  };
  const closePanel = () => {
    panel.close();
    CSS.highlights?.delete("openwiki-discussion");
    panelFocus?.focus({preventScroll: true});
  };
  panel.querySelector("[data-panel-close]").addEventListener("click", closePanel);
  panel.addEventListener("cancel", (event) => {
    event.preventDefault();
    closePanel();
  });
  panel.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      event.preventDefault();
      closePanel();
    }
  });
  const highlight = (thread, fromSource = false) => {
    showPanel();
    const map = documentMap(article), location2 = thread.location;
    if (!location2 || map.text !== config.text) {
      discussionFeedback.textContent = t("Fragment changed or removed");
      return;
    }
    let start = location2.start, end = location2.end - 1;
    while (start <= end && !map.entries[start]?.node) start++;
    while (end >= start && !map.entries[end]?.node) end--;
    const first = map.entries[start], last = map.entries[end];
    if (!first?.node || !last?.node) return;
    const range = document.createRange();
    range.setStart(first.node, first.start);
    range.setEnd(last.node, last.end);
    if (CSS.highlights && window.Highlight) CSS.highlights.set("openwiki-discussion", new Highlight(range));
    else {
      const selected = window.getSelection();
      selected.removeAllRanges();
      selected.addRange(range);
    }
    first.node.parentElement.scrollIntoView({ block: "center", behavior: "smooth" });
    root.querySelector("#discussion-" + thread.id)?.focus({ preventScroll: true });
    if (!fromSource && matchMedia('(max-width: 767px)').matches) {
      closePanel();
      first.node.parentElement.scrollIntoView({block: 'center'});
      article.focus({preventScroll: true});
    }
  };
  const startComment = (context, body = "") => {
    showPanel();
    commentContext = context;
    clearOperation(commentForm);
    commentForm.hidden = false;
    commentForm.elements.body.value = body;
    root.querySelector("[data-comment-quote]").textContent = context.quote || "";
    root.querySelector('[data-comment-source-revision]').textContent = context.sourceRevision || context.revision_number || config.revision;
    root.querySelector("[data-comment-form-title]").textContent = t(context.id ? "Edit comment" : context.parent_id ? "Reply" : "New discussion");
    root.querySelector("[data-comment-feedback]").textContent = "";
    commentForm.scrollIntoView({ block: "nearest" });
    commentForm.elements.body.focus();
  };
  const plain = (html) => {
    const div = el("div");
    div.innerHTML = html;
    div.querySelectorAll("br").forEach((br) => br.replaceWith("\n"));
    return div.textContent;
  };
  const renderComment = (comment, thread) => {
    const item = el("article", void 0, "collaboration-message"), edited = comment.created_at !== comment.updated_at;
    item.append(el("small", comment.username + " · " + date(comment.created_at) + (edited ? " · " + t("Edited") : "")), el("p", plain(comment.body_html), "collaboration-message-body"));
    const actions = el("div", void 0, "collaboration-actions");
    if (config.can_comment) actions.append(btn("Reply", () => startComment({ parent_id: comment.id, quote: thread.anchor.quote, sourceRevision: thread.revision_number })));
    if (config.can_comment && Number(comment.author_id) === config.user) actions.append(btn("Edit", () => startComment({ id: comment.id, base_version: Number(comment.version), quote: thread.anchor.quote, sourceRevision: thread.revision_number }, plain(comment.body_html))));
    if (config.can_moderate || config.can_comment && Number(comment.author_id) === config.user) actions.append(btn("Delete", async (event) => {
      if (!window.confirm(t("Delete comment?"))) return;
      const target = event.currentTarget;
      target.disabled = true;
      try {
        await request("comment-delete", { id: comment.id }, true);
        await loadThreads(discussionPage);
      } catch (error) {
        discussionFeedback.textContent = error.message;
      } finally {
        target.disabled = false;
      }
    }));
    item.append(actions);
    return item;
  };
  async function loadThreads(page = 1) {
    const sequence = ++threadSequence;
    discussionFeedback.textContent = t("Loading…");
    try {
      const result = await request("discussions", { revision_number: config.revision, status: root.querySelector("[data-discussion-filter]").value, page, ...(threadTarget ? {thread_id: threadTarget} : {}) });
      if (sequence !== threadSequence) return;
      discussionPage = result.meta.page;
      const list = root.querySelector("[data-discussions-list]");
      list.replaceChildren();
      for (const thread of result.data) {
        const item = el("section", void 0, "collaboration-thread");
        item.id = "discussion-" + thread.id;
        item.tabIndex = -1;
        item.append(el("strong", t(thread.thread_status === "open" ? "Open thread" : "Resolved")));
        const quote = btn(thread.anchor.quote, () => highlight(thread));
        quote.className = "collaboration-quote";
        quote.textContent = thread.anchor.quote;
        item.append(quote);
        if (!thread.location) item.append(el("p", t("Fragment changed or removed"), "muted"));
        const source = el("a", t("Source revision") + " " + thread.revision_number);
        source.href = thread.source_url;
        item.append(source, renderComment(thread, thread));
        const replies = el('div'), pagination = el('nav');
        const renderReplies = result => {
          replies.replaceChildren(...result.data.map(reply => renderComment(reply, thread)));
          pages(pagination, result.meta, async page => {
            try { renderReplies(await request('discussion-replies', {id: thread.id, page})); }
            catch(error) { discussionFeedback.textContent = error.message; }
          });
        };
        renderReplies({data: thread.replies, meta: thread.replies_meta});
        if (thread.replies_meta.total) item.append(replies, pagination);
        const actions = el("div", void 0, "collaboration-actions");
        if (thread.can_resolve) actions.append(btn(thread.thread_status === "open" ? "Resolve" : "Reopen", async (event) => {
          const target = event.currentTarget;
          target.disabled = true;
          try {
            await request("discussion-status", { id: thread.id, base_version: Number(thread.version), status: thread.thread_status === "open" ? "resolved" : "open", operation_id: uuid() }, true);
            await loadThreads(discussionPage);
          } catch (error) {
            discussionFeedback.textContent = error.message;
          } finally {
            target.disabled = false;
          }
        }));
        if (config.can_create_task) actions.append(btn("Create task", () => openTask(null, { thread_id: Number(thread.id), revision_number: Number(thread.revision_number), quote: thread.anchor.quote })));
        item.append(actions);
        list.append(item);
      }
      discussionFeedback.textContent = result.data.length ? "" : t("No discussions.");
      fragmentLinks = [];
      const map = documentMap(article);
      if (map.text === config.text) for (const thread of result.data) {
        if (!thread.location) continue;
        let start = thread.location.start, end = thread.location.end - 1;
        while (start <= end && !map.entries[start]?.node) start++;
        while (end >= start && !map.entries[end]?.node) end--;
        const first = map.entries[start], last = map.entries[end];
        if (!first?.node || !last?.node) continue;
        const range = document.createRange();
        range.setStart(first.node, first.start); range.setEnd(last.node, last.end);
        fragmentLinks.push({thread, range});
      }
      if (CSS.highlights && window.Highlight) CSS.highlights.set('openwiki-threads', new Highlight(...fragmentLinks.map(item => item.range)));
      pages(root.querySelector("[data-discussion-pagination]"), result.meta, loadThreads);
      const hash = location.hash.match(/^#discussion-(\d+)$/);
      if (hash) root.querySelector("#discussion-" + hash[1])?.scrollIntoView({ block: "nearest" });
    } catch (error) {
      if (sequence === threadSequence) discussionFeedback.textContent = error.message;
    }
  }
  root.querySelector("[data-discussions-open]").addEventListener("click", () => {
    showPanel();
    threadTarget = null;
    loadThreads();
  });
  root.querySelector("[data-discussion-filter]").addEventListener("change", () => {threadTarget = null; loadThreads();});
  root.querySelector('[data-discussions-all]').addEventListener('click', () => {threadTarget = null; loadThreads();});
  const selectionButton = root.querySelector("[data-comment-selection]");
  if (selectionButton) {
    document.addEventListener("selectionchange", () => {
      const selected = window.getSelection();
      if (!selected.rangeCount || selected.isCollapsed) {
        selectionButton.disabled = !selection;
        return;
      }
      const range = selected.getRangeAt(0);
      if (!article.contains(range.startContainer) || !article.contains(range.endContainer)) {
        selection = null;
        selectionButton.disabled = true;
        return;
      }
      const quote = normalize(selected.toString());
      if (!quote || Array.from(quote).length > 2e3) {
        selection = null;
        selectionButton.disabled = true;
        return;
      }
      const map = documentMap(article);
      let start = -1;
      if (map.text === config.text) {
        start = map.entries.findIndex((entry) => entry.node && range.comparePoint(entry.node, entry.start) === 0 && !/\s/u.test(entry.char));
        if (Array.from(config.text).slice(start, start + Array.from(quote).length).join("") !== quote) start = -1;
      } else {
        const index = config.text.indexOf(quote);
        if (index >= 0 && config.text.indexOf(quote, index + quote.length) < 0) start = Array.from(config.text.slice(0, index)).length;
      }
      selection = start < 0 ? null : { quote, start, hash: config.hash };
      selectionButton.disabled = !selection;
    });
    selectionButton.addEventListener("pointerdown", (event) => event.preventDefault());
    selectionButton.addEventListener("click", () => {
      if (selection) {
        startComment({ anchor: selection, quote: selection.quote, revision_number: config.revision });
        panelFocus = selectionButton;
        loadThreads();
      }
    });
  }
  root.querySelector("[data-comment-cancel]").addEventListener("click", () => {
    commentForm.hidden = true;
    commentContext = null;
    panel.querySelector("[data-panel-close]").focus();
  });
  commentForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (commentBusy) return;
    commentBusy = true;
    const feedback = root.querySelector("[data-comment-feedback]"), save = commentForm.querySelector('[type="submit"]');
    save.disabled = true;
    feedback.textContent = t("Saving…");
    const { quote: _quote, sourceRevision: _sourceRevision, ...context } = commentContext;
    try {
      const result = await request(context.id ? "comment-update" : "comment-create", operation(commentForm, { ...context, body: commentForm.elements.body.value }), true);
      threadTarget = result.data.thread_id || result.data.id;
      root.querySelector('[data-discussion-filter]').value = 'all';
      commentForm.hidden = true;
      commentForm.reset();
      clearOperation(commentForm);
      await loadThreads(1);
      discussionFeedback.textContent = t("Comment saved.");
    } catch (error) {
      feedback.textContent = error.message;
    } finally {
      commentBusy = false;
      save.disabled = false;
    }
  });
  async function loadTasks(page = 1) {
    const sequence = ++taskSequence;
    taskFeedback.textContent = t("Loading…");
    try {
      const result = await request("tasks", { page });
      if (sequence !== taskSequence) return;
      taskPage = result.meta.page;
      root.querySelector("[data-tasks-count]").textContent = result.meta.open;
      const list = root.querySelector("[data-tasks-list]");
      list.replaceChildren();
      for (const task of result.data) {
        const item = el("article", void 0, "collaboration-task");
        item.id = "task-" + task.id;
        const title = btn(task.title, () => openTask(task));
        title.className = "collaboration-task-title";
        title.textContent = task.title;
        item.append(title, el("span", t(statuses[task.status]), "status-pill"), el("small", (task.assignee || t("Unassigned")) + " · " + (task.due_date || t("No due date"))));
        if (task.read_only) item.append(el("small", t("Archive is read only.")));
        list.append(item);
      }
      taskFeedback.textContent = result.data.length ? "" : t("No tasks.");
      pages(root.querySelector("[data-tasks-pagination]"), result.meta, loadTasks);
      const hash = location.hash.match(/^#task-(\d+)$/);
      if (hash && !dialog.open) {
        const task = result.data.find((item) => String(item.id) === hash[1]);
        openTask(task || (await request("task", { id: hash[1] })).data);
      }
    } catch (error) {
      if (sequence === taskSequence) taskFeedback.textContent = error.message;
    }
  }
  const taskButtons = () => {
    root.querySelector("[data-task-save]").disabled = taskBusy || assigneeLoading || Boolean(currentTask && !currentTask.can_status);
  };
  async function loadAssignees(query = "") {
    const sequence = ++assigneeSequence;
    assigneeLoading = true;
    taskButtons();
    try {
      const result = await request("assignees", { q: query, ...taskSource.thread_id ? { thread_id: taskSource.thread_id } : taskSource.revision_number ? { revision_number: taskSource.revision_number } : {} });
      if (sequence !== assigneeSequence) return;
      const select = taskForm.elements.assignee_id, previous = select.selectedOptions[0], value = select.value, options = result.data.map((user) => new Option(user.username, user.id));
      if (value && !options.some((option) => option.value === value)) options.unshift(new Option(previous?.text || currentTask?.assignee || value, value));
      select.replaceChildren(new Option(t("Unassigned"), ""), ...options);
      select.value = value;
    } catch (error) {
      if (sequence === assigneeSequence) root.querySelector("[data-task-feedback]").textContent = error.message;
    } finally {
      if (sequence === assigneeSequence) {
        assigneeLoading = false;
        taskButtons();
      }
    }
  }
  async function loadHistory(page = 1) {
    const target = root.querySelector("[data-task-history]");
    target.textContent = t("Loading…");
    try {
      const result = await request("task-history", { id: currentTask.id, page });
      target.replaceChildren();
      for (const event of result.data) {
        const item = el("article");
        item.append(el("small", t("Version") + " " + event.version + " · " + event.actor + " · " + date(event.created_at)));
        const changes = event.changes.created || event.changes, list = el("dl"), labels = { title: "Title", description: "Description", status: "Status", due_date: "Due date", assignee_id: "Assignee" };
        for (const [key, value] of Object.entries(changes)) {
          const display = (entry) => key === "status" ? t(statuses[entry] || entry) : String(entry ?? "—");
          list.append(el("dt", t(labels[key] || key)), el("dd", typeof value === "object" && value !== null ? display(value.from) + " → " + display(value.to) : display(value)));
        }
        item.append(list);
        target.append(item);
      }
      pages(root.querySelector("[data-history-pagination]"), result.meta, loadHistory);
    } catch (error) {
      target.textContent = error.message;
    }
  }
  function openTask(task = null, source = {}) {
    assigneeSequence++;
    clearTimeout(assigneeTimer);
    assigneeLoading = false;
    currentTask = task;
    taskSource = task ? { thread_id: task.thread_id, revision_number: task.revision_number } : source;
    taskFocus = document.activeElement;
    taskForm.reset();
    clearOperation(taskForm);
    const editable = !task || task.can_edit;
    for (const name of ["title", "description", "due_date", "assignee_id"]) taskForm.elements[name].disabled = !editable;
    taskForm.elements.status.disabled = Boolean(task && !task.can_status);
    taskForm.querySelector("[data-assignee-query]").disabled = !editable;
    for (const name of ["title", "description", "status", "due_date"]) taskForm.elements[name].value = task?.[name] || (name === "status" ? "todo" : "");
    taskForm.elements.assignee_id.replaceChildren(new Option(task?.assignee || t("Unassigned"), task?.assignee_id || ""));
    root.querySelector("[data-task-feedback]").textContent = task?.read_only ? t("Archive is read only.") : "";
    root.querySelector("[data-task-reload]").hidden = true;
    const sourceLabel = root.querySelector("[data-task-source]");
    sourceLabel.replaceChildren();
    if (task) {
      const sourceLink = el("a", task.revision_number ? t("Source revision") + " " + task.revision_number : t("Open document"));
      sourceLink.href = task.source_url;
      sourceLabel.append(sourceLink);
      if (task.thread_id) {
        if (task.discussion_url) {
          const discussionLink = el("a", t("Open discussion"));
          discussionLink.href = task.discussion_url;
          sourceLabel.append(" · ", discussionLink);
        } else sourceLabel.append(" · ", t("Discussion is unavailable."));
      }
    } else sourceLabel.textContent = taskSource.quote || (taskSource.revision_number ? t("Source revision") + " " + taskSource.revision_number : "");
    root.querySelector('[data-task-details]').open = Boolean(task);
    root.querySelector("[data-task-history-section]").hidden = !task;
    if (!dialog.open) dialog.showModal();
    taskButtons();
    (editable ? taskForm.elements.title : taskForm.elements.status).focus();
    if (editable) loadAssignees();
    if (task) loadHistory();
  }
  const closeTask = () => {
    if (taskBusy) return;
    assigneeSequence++;
    clearTimeout(assigneeTimer);
    dialog.close();
    taskFocus?.focus();
  };
  root.querySelector("[data-task-close]").addEventListener("click", closeTask);
  dialog.addEventListener("cancel", (event) => {
    event.preventDefault();
    closeTask();
  });
  root.querySelector("[data-task-create]")?.addEventListener("click", () => openTask(null, {revision_number: config.task_revision}));
  root.querySelector("[data-assignee-query]").addEventListener("input", (event) => {
    clearTimeout(assigneeTimer);
    assigneeTimer = setTimeout(() => loadAssignees(event.target.value), 250);
  });
  root.querySelector("[data-task-reload]").addEventListener("click", async () => {
    if (taskBusy) return;
    taskBusy = true;
    taskButtons();
    try {
      const previous = currentTask;
      const fresh = (await request("task", { id: currentTask.id })).data;
      for (const name of ["title", "description", "status", "due_date", "assignee_id"]) {
        const field = taskForm.elements[name];
        if (field.value !== String(previous[name] ?? "")) continue;
        if (name === "assignee_id" && fresh.assignee_id && ![...field.options].some(option => option.value === String(fresh.assignee_id))) field.add(new Option(fresh.assignee, fresh.assignee_id));
        field.value = fresh[name] ?? "";
      }
      currentTask = fresh;
      for (const name of ["title", "description", "due_date", "assignee_id"]) taskForm.elements[name].disabled = !fresh.can_edit;
      taskForm.elements.status.disabled = !fresh.can_status;
      root.querySelector("[data-task-feedback]").textContent = t("Current version loaded. Your form values are retained; save to apply them.");
      clearOperation(taskForm);
      taskButtons();
      loadHistory();
    } catch (error) {
      root.querySelector("[data-task-feedback]").textContent = error.message;
    } finally {
      taskBusy = false;
      taskButtons();
    }
  });
  taskForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (taskBusy || assigneeLoading) return;
    taskBusy = true;
    taskButtons();
    const feedback = root.querySelector("[data-task-feedback]");
    feedback.textContent = t("Saving…");
    const input = Object.fromEntries(new FormData(taskForm));
    if (currentTask) {
      input.id = Number(currentTask.id);
      input.base_version = Number(currentTask.version);
    } else {
      if (taskSource.thread_id) input.thread_id = taskSource.thread_id;
      if (taskSource.revision_number) input.revision_number = taskSource.revision_number;
    }
    try {
      await request(currentTask ? "task-update" : "task-create", operation(taskForm, input), true);
      taskBusy = false;
      closeTask();
      clearOperation(taskForm);
      await loadTasks(taskPage);
      taskFeedback.textContent = t("Task saved.");
    } catch (error) {
      feedback.textContent = error.message;
      root.querySelector("[data-task-reload]").hidden = !currentTask || error.status !== 409;
    } finally {
      taskBusy = false;
      taskButtons();
    }
  });
  article.tabIndex = 0;
  article.addEventListener('click', event => {
    if (!window.getSelection().isCollapsed) return;
    const caret = document.caretRangeFromPoint?.(event.clientX, event.clientY);
    const point = document.caretPositionFromPoint?.(event.clientX, event.clientY);
    const target = caret?.startContainer || point?.offsetNode, offset = caret?.startOffset ?? point?.offset;
    if (!target) return;
    const link = fragmentLinks.find(item => item.range.comparePoint(target, offset) === 0);
    if (link) { highlight(link.thread, true); panelFocus = article; }
  });
  article.addEventListener('keydown', event => {
    if (event.key !== 'Enter') return;
    const selection = window.getSelection();
    if (!selection.focusNode || !article.contains(selection.focusNode)) return;
    const link = fragmentLinks.find(item => item.range.comparePoint(selection.focusNode, selection.focusOffset) === 0);
    if (link) { event.preventDefault(); highlight(link.thread, true); panelFocus = article; }
  });
  loadTasks();
  loadThreads();
  if (location.hash.startsWith("#discussion-")) {
    showPanel();
  }
});
