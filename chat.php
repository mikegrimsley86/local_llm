<?php
// Streaming proxy endpoint
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['stream'])) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) { ob_end_flush(); }

    $input = json_decode(file_get_contents('php://input'), true);
    $messages = $input['messages'] ?? [];

    $payload = json_encode([
        'model'    => 'local-model',
        'messages' => $messages,
        'stream'   => true,
        'stream_options' => ['include_usage' => true],
    ]);

    $ch = curl_init('http://localhost:1234/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_WRITEFUNCTION  => function ($ch, $data) {
            echo $data;
            flush();
            return strlen($data);
        },
    ]);
    curl_exec($ch);
    if (curl_errno($ch)) {
        echo "data: " . json_encode(['error' => curl_error($ch)]) . "\n\n";
        flush();
    }
    curl_close($ch);
    exit;
}

// --- Chat persistence API (SQLite) ---
if (isset($_GET['api'])) {
    header('Content-Type: application/json');

    $action = $_GET['api'];
    $safeId = function ($id) {
        return preg_match('/^chat_[0-9]+_[a-z0-9]+$/i', (string)$id) ? $id : null;
    };

    // Open / initialize the database
    try {
        $db = new PDO('sqlite:' . __DIR__ . '/chats.db');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('PRAGMA busy_timeout = 5000');
        $db->exec('CREATE TABLE IF NOT EXISTS chats (
            id       TEXT PRIMARY KEY,
            title    TEXT NOT NULL DEFAULT \'Untitled\',
            messages TEXT NOT NULL,
            updated  INTEGER NOT NULL
        )');
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'db init failed: ' . $e->getMessage()]);
        exit;
    }

    if ($action === 'list') {
        $q = trim($_GET['q'] ?? '');
        if ($q !== '') {
            // Search both title and message content
            $stmt = $db->prepare(
                'SELECT id, title, updated FROM chats
                 WHERE title LIKE :kw OR messages LIKE :kw
                 ORDER BY updated DESC'
            );
            $stmt->execute([':kw' => '%' . $q . '%']);
        } else {
            $stmt = $db->query('SELECT id, title, updated FROM chats ORDER BY updated DESC');
        }
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($action === 'load') {
        $id = $safeId($_GET['id'] ?? '');
        if (!$id) { http_response_code(404); echo json_encode(['error' => 'not found']); exit; }
        $stmt = $db->prepare('SELECT id, title, messages, updated FROM chats WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { http_response_code(404); echo json_encode(['error' => 'not found']); exit; }
        echo json_encode([
            'id'       => $row['id'],
            'title'    => $row['title'],
            'messages' => json_decode($row['messages'], true),
            'updated'  => (int)$row['updated'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        $id = $safeId($body['id'] ?? '');
        if (!$id || !isset($body['messages'])) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid payload']);
            exit;
        }
        $title = mb_substr(trim($body['title'] ?? 'Untitled'), 0, 80) ?: 'Untitled';
        $messages = json_encode($body['messages'], JSON_UNESCAPED_UNICODE);
        $updated = time();
        $stmt = $db->prepare(
            'INSERT INTO chats (id, title, messages, updated)
             VALUES (:id, :title, :messages, :updated)
             ON CONFLICT(id) DO UPDATE SET
               title = excluded.title,
               messages = excluded.messages,
               updated = excluded.updated'
        );
        $stmt->execute([
            ':id' => $id, ':title' => $title,
            ':messages' => $messages, ':updated' => $updated,
        ]);
        echo json_encode(['ok' => true, 'title' => $title, 'updated' => $updated]);
        exit;
    }

    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        $id = $safeId($body['id'] ?? '');
        if ($id) {
            $stmt = $db->prepare('DELETE FROM chats WHERE id = ?');
            $stmt->execute([$id]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'unknown action']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Local LLM Chat</title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,600&family=Newsreader:ital,wght@0,400;0,500;1,400&display=swap" rel="stylesheet">
<style>
  :root {
    --bg: #1a1714;
    --panel: #221e1a;
    --ink: #ede6db;
    --muted: #9a9081;
    --accent: #d98a4f;
    --accent-soft: #3a2f24;
    --user-bubble: #2e2820;
    --border: #38312a;
  }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  html, body { height: 100%; }
  body {
    background:
      radial-gradient(1200px 600px at 80% -10%, rgba(217,138,79,0.08), transparent 60%),
      var(--bg);
    color: var(--ink);
    font-family: 'Newsreader', Georgia, serif;
    height: 100vh;
  }
  #shell {
    display: flex;
    height: 100vh;
  }
  #sidebar {
    width: 260px;
    flex-shrink: 0;
    background: var(--panel);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }
  .sidebar-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 18px 16px 14px;
    border-bottom: 1px solid var(--border);
  }
  .sidebar-head h2 {
    font-family: 'Fraunces', serif;
    font-weight: 600;
    font-size: 18px;
  }
  #new-chat {
    background: var(--accent);
    color: #1a1714;
    border: none;
    border-radius: 8px;
    padding: 7px 12px;
    font-family: 'Fraunces', serif;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    transition: transform 0.1s;
  }
  #new-chat:hover { transform: translateY(-1px); }
  .sidebar-search {
    padding: 10px 12px;
    border-bottom: 1px solid var(--border);
  }
  .sidebar-search input {
    width: 100%;
    background: var(--bg);
    border: 1px solid var(--border);
    color: var(--ink);
    border-radius: 8px;
    padding: 8px 12px;
    font-family: 'Newsreader', serif;
    font-size: 14px;
    outline: none;
    transition: border-color 0.15s;
  }
  .sidebar-search input:focus { border-color: var(--accent); }
  #chat-list { flex: 1; overflow-y: auto; padding: 8px; }
  .chat-item {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 10px 10px;
    border-radius: 8px;
    cursor: pointer;
    color: var(--ink);
    transition: background 0.12s;
  }
  .chat-item:hover { background: var(--user-bubble); }
  .chat-item.active { background: var(--accent-soft); }
  .chat-item .ci-title {
    flex: 1;
    font-size: 14.5px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .chat-item .ci-del {
    opacity: 0;
    background: none;
    border: none;
    color: var(--muted);
    cursor: pointer;
    font-size: 15px;
    padding: 2px 5px;
    border-radius: 5px;
    transition: opacity 0.12s, color 0.12s, background 0.12s;
  }
  .chat-item:hover .ci-del { opacity: 1; }
  .chat-item .ci-del:hover { color: #e06a5a; background: rgba(224,106,90,0.12); }
  #chat-list .empty {
    color: var(--muted);
    font-style: italic;
    font-size: 13px;
    padding: 14px 10px;
  }
  #main {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-width: 0;
    height: 100vh;
  }
  header {
    padding: 22px 28px 16px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: baseline;
    gap: 14px;
  }
  header h1 {
    font-family: 'Fraunces', serif;
    font-weight: 600;
    font-size: 26px;
    letter-spacing: -0.5px;
  }
  header span {
    color: var(--muted);
    font-size: 13px;
    font-style: italic;
  }
  #chat {
    flex: 1;
    overflow-y: auto;
    padding: 28px;
    display: flex;
    flex-direction: column;
    gap: 22px;
  }
  .msg { max-width: 760px; width: 100%; margin: 0 auto; line-height: 1.6; }
  .msg .role {
    font-family: 'Fraunces', serif;
    font-size: 12px;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: 6px;
  }
  .msg.user .role { color: var(--accent); }
  .msg .body {
    white-space: pre-wrap;
    font-size: 17px;
  }
  .msg.user .body {
    background: var(--user-bubble);
    border: 1px solid var(--border);
    padding: 14px 18px;
    border-radius: 4px 14px 14px 14px;
  }
  .blink::after {
    content: '▍';
    color: var(--accent);
    animation: blink 1s steps(2) infinite;
  }
  @keyframes blink { 0%,50% { opacity: 1; } 50.01%,100% { opacity: 0; } }
  footer {
    border-top: 1px solid var(--border);
    background: var(--panel);
    padding: 18px 28px 24px;
  }
  form {
    max-width: 760px;
    margin: 0 auto;
    display: flex;
    gap: 12px;
    align-items: flex-end;
  }
  textarea {
    flex: 1;
    resize: none;
    background: var(--bg);
    border: 1px solid var(--border);
    color: var(--ink);
    border-radius: 12px;
    padding: 14px 16px;
    font-family: 'Newsreader', serif;
    font-size: 17px;
    line-height: 1.5;
    max-height: 180px;
    outline: none;
    transition: border-color 0.2s;
  }
  textarea:focus { border-color: var(--accent); }
  button {
    background: var(--accent);
    color: #1a1714;
    border: none;
    border-radius: 12px;
    padding: 14px 22px;
    font-family: 'Fraunces', serif;
    font-weight: 600;
    font-size: 15px;
    cursor: pointer;
    transition: transform 0.1s, opacity 0.2s;
  }
  button:hover { transform: translateY(-1px); }
  button:disabled { opacity: 0.45; cursor: not-allowed; transform: none; }
  #stats {
    max-width: 760px;
    margin: 12px auto 0;
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    font-family: ui-monospace, 'SF Mono', Menlo, monospace;
    font-size: 12px;
    color: var(--muted);
  }
  #stats .stat {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 4px 12px;
    letter-spacing: 0.3px;
  }
  #stats .stat.live { border-color: var(--accent); color: var(--accent); }
  #chat::-webkit-scrollbar { width: 9px; }
  #chat::-webkit-scrollbar-thumb { background: var(--accent-soft); border-radius: 6px; }

  .msg .body table {
    border-collapse: collapse;
    width: 100%;
    margin: 14px 0;
    font-size: 15px;
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 8px;
    overflow: hidden;
  }
  .msg .body th, .msg .body td {
    border: 1px solid var(--border);
    padding: 10px 14px;
    text-align: left;
    vertical-align: top;
    line-height: 1.5;
  }
  .msg .body thead th {
    background: var(--accent-soft);
    font-family: 'Fraunces', serif;
    font-weight: 600;
    color: var(--ink);
    letter-spacing: 0.3px;
  }
  .msg .body tbody tr:nth-child(even) { background: rgba(255,255,255,0.02); }
  .msg .body td strong, .msg .body th strong { color: var(--accent); font-weight: 600; }
  .msg .body code {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 4px;
    padding: 1px 6px;
    font-size: 13px;
    font-family: ui-monospace, 'SF Mono', Menlo, monospace;
  }
  .msg .body .table-scroll { overflow-x: auto; }
  .msg .body p { margin: 0 0 4px; }
  .msg .body .md-h {
    font-family: 'Fraunces', serif;
    font-weight: 600;
    color: var(--ink);
    line-height: 1.3;
    margin: 16px 0 6px;
  }
  .msg .body .md-h1 { font-size: 24px; }
  .msg .body .md-h2 { font-size: 21px; }
  .msg .body .md-h3 { font-size: 18px; color: var(--accent); }
  .msg .body .md-h4, .msg .body .md-h5, .msg .body .md-h6 {
    font-size: 16px;
    color: var(--accent);
    letter-spacing: 0.3px;
  }
  .msg .body .code-block {
    margin: 14px 0;
    border: 1px solid var(--border);
    border-radius: 8px;
    overflow: hidden;
    background: #15120f;
  }
  .msg .body .code-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: var(--accent-soft);
    border-bottom: 1px solid var(--border);
    padding: 0 6px 0 14px;
  }
  .msg .body .code-lang {
    font-family: ui-monospace, 'SF Mono', Menlo, monospace;
    font-size: 11px;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: var(--muted);
    padding: 6px 0;
  }
  .msg .body .code-copy {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: none;
    border: 1px solid transparent;
    color: var(--muted);
    font-family: ui-monospace, 'SF Mono', Menlo, monospace;
    font-size: 11px;
    font-weight: 400;
    letter-spacing: 0.5px;
    padding: 4px 9px;
    margin: 4px 0;
    border-radius: 6px;
    cursor: pointer;
    transition: color 0.15s, border-color 0.15s, background 0.15s;
    transform: none;
  }
  .msg .body .code-copy:hover {
    color: var(--ink);
    border-color: var(--border);
    background: var(--bg);
    transform: none;
  }
  .msg .body .code-copy.copied {
    color: var(--accent);
    border-color: var(--accent);
  }
  .msg .body .code-copy svg { flex-shrink: 0; }
  .msg .body .code-block pre {
    margin: 0;
    padding: 14px 16px;
    overflow-x: auto;
  }
  .msg .body .code-block code {
    font-family: ui-monospace, 'SF Mono', Menlo, monospace;
    font-size: 13.5px;
    line-height: 1.55;
    color: #e8d9c4;
    background: none;
    border: none;
    padding: 0;
    white-space: pre;
  }
  .msg .body .md-hr {
    border: none;
    border-top: 1px solid var(--border);
    margin: 18px 0;
  }
  .msg .body .md-quote {
    margin: 12px 0;
    padding: 8px 16px;
    border-left: 3px solid var(--accent);
    background: rgba(217,138,79,0.06);
    border-radius: 0 6px 6px 0;
    color: var(--ink);
  }
</style>
</head>
<body>
  <div id="shell">
    <aside id="sidebar">
      <div class="sidebar-head">
        <h2>Chats</h2>
        <button id="new-chat" title="New chat">+ New</button>
      </div>
      <div class="sidebar-search">
        <input id="search" type="text" placeholder="Search chats…" autocomplete="off">
      </div>
      <div id="chat-list"></div>
    </aside>

    <main id="main">
      <header>
        <h1>Local LLM</h1>
        <span>streaming via localhost:1234</span>
      </header>

      <div id="chat"></div>

      <footer>
        <form id="form">
          <textarea id="input" rows="1" placeholder="Ask something…" autofocus></textarea>
          <button type="submit" id="send">Send</button>
        </form>
        <div id="stats">
          <span class="stat" id="stat-model">—</span>
          <span class="stat" id="stat-tokens">0 tokens</span>
          <span class="stat" id="stat-tps">0.0 tok/s</span>
          <span class="stat" id="stat-time">0.0s</span>
        </div>
      </footer>
    </main>
  </div>

<script>
const chat   = document.getElementById('chat');
const form   = document.getElementById('form');
const input  = document.getElementById('input');
const sendBtn= document.getElementById('send');
const statModel  = document.getElementById('stat-model');
const statTokens = document.getElementById('stat-tokens');
const statTps    = document.getElementById('stat-tps');
const statTime   = document.getElementById('stat-time');
let history = [];

// --- Chat management ---
const chatList = document.getElementById('chat-list');
const newChatBtn = document.getElementById('new-chat');
const searchBox = document.getElementById('search');
let currentId = null;

function newId() {
  return 'chat_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);
}

function deriveTitle() {
  const firstUser = history.find(m => m.role === 'user');
  if (!firstUser) return 'New chat';
  return firstUser.content.replace(/\s+/g, ' ').trim().slice(0, 60) || 'New chat';
}

async function refreshList() {
  try {
    const q = searchBox.value.trim();
    const url = '?api=list' + (q ? '&q=' + encodeURIComponent(q) : '');
    const res = await fetch(url);
    const items = await res.json();
    chatList.innerHTML = '';
    if (!items.length) {
      chatList.innerHTML = '<div class="empty">' +
        (q ? 'No chats match.' : 'No saved chats yet.') + '</div>';
      return;
    }
    for (const it of items) {
      const row = document.createElement('div');
      row.className = 'chat-item' + (it.id === currentId ? ' active' : '');
      row.dataset.id = it.id;
      row.innerHTML = '<span class="ci-title"></span>' +
                      '<button class="ci-del" title="Delete">×</button>';
      row.querySelector('.ci-title').textContent = it.title || 'Untitled';
      chatList.appendChild(row);
    }
  } catch (_) {}
}

let searchTimer = null;
searchBox.addEventListener('input', () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(refreshList, 200);
});

async function saveCurrent() {
  if (!currentId || history.length === 0) return;
  try {
    await fetch('?api=save', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: currentId, title: deriveTitle(), messages: history }),
    });
    await refreshList();
  } catch (_) {}
}

function startNewChat() {
  currentId = newId();
  history = [];
  chat.innerHTML = '';
  statModel.textContent = '—';
  statTokens.textContent = '0 tokens';
  statTps.textContent = '0.0 tok/s';
  statTime.textContent = '0.0s';
  document.querySelectorAll('.chat-item.active').forEach(e => e.classList.remove('active'));
  input.focus();
}

async function loadChat(id) {
  try {
    const res = await fetch('?api=load&id=' + encodeURIComponent(id));
    if (!res.ok) return;
    const data = await res.json();
    currentId = id;
    history = data.messages || [];
    chat.innerHTML = '';
    for (const m of history) {
      const el = addMessage(m.role, m.role === 'user' ? m.content : '');
      if (m.role !== 'user') el.innerHTML = renderContent(m.content);
    }
    chat.scrollTop = chat.scrollHeight;
    document.querySelectorAll('.chat-item').forEach(e =>
      e.classList.toggle('active', e.dataset.id === id));
  } catch (_) {}
}

async function deleteChat(id) {
  try {
    await fetch('?api=delete', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id }),
    });
  } catch (_) {}
  if (id === currentId) startNewChat();
  await refreshList();
}

newChatBtn.addEventListener('click', startNewChat);

chatList.addEventListener('click', e => {
  const del = e.target.closest('.ci-del');
  const item = e.target.closest('.chat-item');
  if (!item) return;
  const id = item.dataset.id;
  if (del) {
    e.stopPropagation();
    if (confirm('Delete this chat?')) deleteChat(id);
  } else {
    loadChat(id);
  }
});

// Copy-to-clipboard for code blocks (delegated, survives re-renders)
chat.addEventListener('click', async e => {
  const btn = e.target.closest('.code-copy');
  if (!btn) return;
  const block = btn.closest('.code-block');
  if (!block) return;
  const code = decodeURIComponent(block.dataset.code || '');
  const label = btn.querySelector('.copy-label');
  try {
    await navigator.clipboard.writeText(code);
  } catch (_) {
    const ta = document.createElement('textarea');
    ta.value = code;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch (_) {}
    document.body.removeChild(ta);
  }
  btn.classList.add('copied');
  if (label) label.textContent = 'Copied!';
  setTimeout(() => {
    btn.classList.remove('copied');
    if (label) label.textContent = 'Copy';
  }, 1500);
});

// --- Rendering helpers ---
function escapeHtml(s) {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

// Inline formatting: run AFTER escaping. Restores allowed <br>, **bold**, `code`.
function inline(s) {
  return escapeHtml(s)
    .replace(/&lt;br\s*\/?&gt;/gi, '<br>')
    .replace(/`([^`]+)`/g, (_, c) => '<code>' + c + '</code>')
    .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    .replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>');
}

function isTableSep(line) {
  return /^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)+\|?\s*$/.test(line);
}
function isTableRow(line) {
  return /^\s*\|.*\|\s*$/.test(line.trim()) || (line.includes('|') && line.trim().split('|').length > 2);
}
function splitCells(line) {
  let t = line.trim();
  if (t.startsWith('|')) t = t.slice(1);
  if (t.endsWith('|')) t = t.slice(0, -1);
  return t.split('|').map(c => c.trim());
}

function renderTable(headerLine, rows) {
  const heads = splitCells(headerLine);
  let html = '<div class="table-scroll"><table><thead><tr>';
  html += heads.map(h => '<th>' + inline(h) + '</th>').join('');
  html += '</tr></thead><tbody>';
  for (const r of rows) {
    const cells = splitCells(r);
    html += '<tr>' + cells.map(c => '<td>' + inline(c) + '</td>').join('') + '</tr>';
  }
  html += '</tbody></table></div>';
  return html;
}

// Convert raw assistant text (markdown tables + text) into safe HTML.
function renderContent(text) {
  const lines = text.split('\n');
  let out = '';
  let textBuf = [];
  const flushText = () => {
    if (textBuf.length) {
      out += '<span>' + inline(textBuf.join('\n')) + '</span>';
      textBuf = [];
    }
  };

  for (let i = 0; i < lines.length; i++) {
    const fenceMatch = lines[i].match(/^\s*```+\s*([\w+-]*)\s*$/);
    const headMatch = lines[i].match(/^\s*(#{1,6})[ \t\u00a0]+(.*\S.*)$/);
    // Fenced code block: ```lang ... ```
    if (fenceMatch) {
      flushText();
      const lang = fenceMatch[1];
      i++;
      const code = [];
      while (i < lines.length && !/^\s*```+\s*$/.test(lines[i])) {
        code.push(lines[i]);
        i++;
      }
      // if closing fence not yet streamed, i lands at end — fine, renders partial
      const label = '<div class="code-bar">' +
                    '<span class="code-lang">' + escapeHtml(lang || 'code') + '</span>' +
                    '<button class="code-copy" type="button" title="Copy">' +
                    '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>' +
                    '<span class="copy-label">Copy</span></button></div>';
      const raw = code.join('\n');
      out += '<div class="code-block" data-code="' + encodeURIComponent(raw) + '">' + label +
             '<pre><code>' + escapeHtml(raw) + '</code></pre></div>';
    } else if (isTableRow(lines[i]) && i + 1 < lines.length && isTableSep(lines[i + 1])) {
      flushText();
      const header = lines[i];
      i += 2;
      const rows = [];
      while (i < lines.length && isTableRow(lines[i]) && !isTableSep(lines[i])) {
        rows.push(lines[i]);
        i++;
      }
      i--; // step back; loop will increment
      out += renderTable(header, rows);
    } else if (headMatch) {
      flushText();
      const level = headMatch[1].length;
      out += '<div class="md-h md-h' + level + '">' + inline(headMatch[2]) + '</div>';
    } else if (/^\s*(---+|\*\*\*+|___+)\s*$/.test(lines[i])) {
      flushText();
      out += '<hr class="md-hr">';
    } else if (/^\s*>\s?/.test(lines[i])) {
      flushText();
      const quote = [];
      while (i < lines.length && /^\s*>\s?/.test(lines[i])) {
        quote.push(lines[i].replace(/^\s*>\s?/, ''));
        i++;
      }
      i--; // step back; loop will increment
      out += '<blockquote class="md-quote">' + inline(quote.join('\n')) + '</blockquote>';
    } else {
      textBuf.push(lines[i]);
    }
  }
  flushText();
  return out;
}

input.addEventListener('input', () => {
  input.style.height = 'auto';
  input.style.height = Math.min(input.scrollHeight, 180) + 'px';
});
input.addEventListener('keydown', e => {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); }
});

function addMessage(role, text) {
  const wrap = document.createElement('div');
  wrap.className = 'msg ' + role;
  wrap.innerHTML = `<div class="role">${role}</div><div class="body"></div>`;
  wrap.querySelector('.body').textContent = text;
  chat.appendChild(wrap);
  chat.scrollTop = chat.scrollHeight;
  return wrap.querySelector('.body');
}

form.addEventListener('submit', async e => {
  e.preventDefault();
  const text = input.value.trim();
  if (!text) return;

  addMessage('user', text);
  history.push({ role: 'user', content: text });
  input.value = '';
  input.style.height = 'auto';
  sendBtn.disabled = true;

  const body = addMessage('assistant', '');
  body.classList.add('blink');
  let answer = '';

  // --- stats tracking ---
  const startTime = performance.now();
  let firstTokenTime = null;
  let usage = null;
  let modelName = '';
  let chunkCount = 0;
  statTps.classList.add('live');
  statTokens.classList.add('live');

  const fmtTime = ms => (ms / 1000).toFixed(1) + 's';
  const updateLive = () => {
    const elapsed = performance.now() - startTime;
    statTime.textContent = fmtTime(elapsed);
    // estimate tokens during stream from chunk count; refined by usage at end
    const est = chunkCount;
    statTokens.textContent = est + ' tokens';
    const genMs = firstTokenTime ? (performance.now() - firstTokenTime) : elapsed;
    const tps = genMs > 0 ? (est / (genMs / 1000)) : 0;
    statTps.textContent = tps.toFixed(1) + ' tok/s';
  };

  try {
    const res = await fetch('?stream=1', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ messages: history }),
    });

    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true });
      const lines = buffer.split('\n');
      buffer = lines.pop();

      for (const line of lines) {
        const trimmed = line.trim();
        if (!trimmed.startsWith('data:')) continue;
        const data = trimmed.slice(5).trim();
        if (data === '[DONE]') continue;
        try {
          const json = JSON.parse(data);
          if (json.model) modelName = json.model;
          if (json.usage) usage = json.usage;
          const delta = json.choices?.[0]?.delta?.content;
          if (delta) {
            if (firstTokenTime === null) firstTokenTime = performance.now();
            chunkCount++;
            answer += delta;
            body.innerHTML = renderContent(answer);
            chat.scrollTop = chat.scrollHeight;
            updateLive();
          }
        } catch (_) {}
      }
    }
  } catch (err) {
    body.textContent = '[Error: ' + err.message + ']';
  }

  body.classList.remove('blink');
  statTps.classList.remove('live');
  statTokens.classList.remove('live');

  // --- finalize stats ---
  const totalMs = performance.now() - startTime;
  const genMs = firstTokenTime ? (performance.now() - firstTokenTime) : totalMs;
  statTime.textContent = fmtTime(totalMs);
  if (modelName) statModel.textContent = modelName;

  if (usage) {
    const completion = usage.completion_tokens ?? chunkCount;
    const total = usage.total_tokens ?? completion;
    statTokens.textContent = completion + ' out / ' + total + ' total';
    const tps = genMs > 0 ? (completion / (genMs / 1000)) : 0;
    statTps.textContent = tps.toFixed(1) + ' tok/s';
  } else {
    statTokens.textContent = chunkCount + ' tokens (est)';
  }

  if (answer) history.push({ role: 'assistant', content: answer });
  sendBtn.disabled = false;
  input.focus();
  saveCurrent();
});

// --- init ---
startNewChat();
refreshList();
</script>
</body>
</html>
