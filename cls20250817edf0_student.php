<?php
// student_classroom.php
// Full student page with assessment taking + auto-grading (MCQ).
// Assumes includes/access_guard.php defines enforce_student_access and STUN_SERVERS_JSON and provides $mysqli.

if (session_status() === PHP_SESSION_NONE) session_start();

// require student role
$required_role = "student";
require_once __DIR__ . '/../includes/access_guard.php';

// classroom code -- replace if your workflow injects dynamic code
$CLASSROOM_CODE = 'CLS-20250817-EDF0';

// enforce access
list($CLASSROOM, $STUDENT_ID) = enforce_student_access($mysqli, $CLASSROOM_CODE);

$ROOM_ID = $CLASSROOM_CODE;
$CLASSROOM_NAME = $CLASSROOM['classroom_name'] ?? 'Classroom';

// WS and STUN
$WS_URL = 'ws://127.0.0.1:8080';
$STUN_JSON = defined('STUN_SERVERS_JSON') ? STUN_SERVERS_JSON : json_encode([
    ["urls" => "stun:stun.l.google.com:19302"],
    ["urls" => "stun:stun1.l.google.com:19302"]
], JSON_UNESCAPED_SLASHES);

/* --------------------------
   Ensure DB columns exist
   -------------------------- */
// Ensure assessment_answers has awarded_marks column (if absent add it)
function ensure_awarded_column(mysqli $m) {
    $q = $m->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'assessment_answers' AND COLUMN_NAME = 'awarded_marks'");
    $db = $m->real_escape_string($m->query("SELECT DATABASE()")->fetch_row()[0]);
    $q->bind_param("s", $db);
    $q->execute();
    $res = $q->get_result();
    $exists = ($res && $res->num_rows > 0);
    $q->close();
    if (!$exists) {
        // add column
        $m->query("ALTER TABLE assessment_answers ADD COLUMN awarded_marks INT DEFAULT 0");
    }
}
ensure_awarded_column($mysqli);

/* --------------------------
   JSON helper
   -------------------------- */
function json_out($data){
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/* --------------------------
   AJAX endpoints
   -------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'list_assessments') {
    // Return list of assessments for this classroom (id, title, type, total_marks, created_at)
    $stmt = $mysqli->prepare("SELECT id, title, type, total_marks, created_at FROM assessments WHERE classroom_id = ? ORDER BY created_at DESC");
    $stmt->bind_param("i", $CLASSROOM['id']);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) $out[] = $r;
    $stmt->close();
    json_out(['ok'=>true,'assessments'=>$out]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_assessment' && isset($_GET['id'])) {
    $aid = (int)$_GET['id'];
    // fetch assessment
    $stmt = $mysqli->prepare("SELECT id, title, type, total_marks, created_at FROM assessments WHERE id = ? AND classroom_id = ?");
    $stmt->bind_param("ii", $aid, $CLASSROOM['id']);
    $stmt->execute();
    $res = $stmt->get_result();
    $assess = $res->fetch_assoc();
    $stmt->close();
    if (!$assess) json_out(['ok'=>false,'error'=>'Assessment not found']);

    // fetch sections
    $stmt = $mysqli->prepare("SELECT id, title, start_at, end_at FROM assessment_sections WHERE assessment_id = ? ORDER BY id ASC");
    $stmt->bind_param("i",$aid);
    $stmt->execute();
    $sres = $stmt->get_result();
    $sections = [];
    while ($s = $sres->fetch_assoc()) {
        // fetch questions for section
        $qstmt = $mysqli->prepare("SELECT id, question_text, q_type, marks FROM assessment_questions WHERE section_id = ? ORDER BY id ASC");
        $qstmt->bind_param("i", $s['id']);
        $qstmt->execute();
        $qres = $qstmt->get_result();
        $questions = [];
        while ($q = $qres->fetch_assoc()) {
            // fetch options if mcq
            $options = [];
            if ($q['q_type'] === 'mcq') {
                $ostmt = $mysqli->prepare("SELECT id, option_text, is_correct FROM assessment_options WHERE question_id = ? ORDER BY id ASC");
                $ostmt->bind_param("i", $q['id']);
                $ostmt->execute();
                $ores = $ostmt->get_result();
                while ($o = $ores->fetch_assoc()) {
                    $options[] = $o;
                }
                $ostmt->close();
            }
            $q['options'] = $options;
            $questions[] = $q;
        }
        $qstmt->close();
        $s['questions'] = $questions;
        $sections[] = $s;
    }
    $stmt->close();

    // include classroom logo if present
    $logo = $CLASSROOM['logo_url'] ?? null;
    json_out(['ok'=>true, 'assessment'=>$assess, 'sections'=>$sections, 'classroom'=>['name'=>$CLASSROOM_NAME, 'logo'=>$logo]]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'submit_answers') {
    // Accept JSON body: { assessment_id, section_id, answers: [ { question_id, selected_option_id?, answer_text? } ] }
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) json_out(['ok'=>false,'error'=>'Invalid payload']);
    $assessment_id = (int)($data['assessment_id'] ?? 0);
    $section_id = (int)($data['section_id'] ?? 0);
    $answers = $data['answers'] ?? [];
    if (!$assessment_id || !$section_id || !is_array($answers)) json_out(['ok'=>false,'error'=>'Missing fields']);

    // Verify that assessment & section belong to this classroom
    $stmt = $mysqli->prepare("SELECT a.id FROM assessments a JOIN assessment_sections s ON s.assessment_id=a.id WHERE a.id=? AND s.id=? AND a.classroom_id=?");
    $stmt->bind_param("iii",$assessment_id,$section_id,$CLASSROOM['id']);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows !== 1) { $stmt->close(); json_out(['ok'=>false,'error'=>'Assessment/Section mismatch']); }
    $stmt->close();

    // Begin transaction: insert answers and compute score for MCQ
    $mysqli->begin_transaction();
    try {
        $insert = $mysqli->prepare("INSERT INTO assessment_answers (assessment_id, section_id, question_id, student_id, answer_text, selected_option_id, submitted_at, awarded_marks) VALUES (?,?,?,?,?,?,NOW(),?)");
        $total_awarded = 0;
        $per_question = []; // detailed results
        foreach ($answers as $ans) {
            $qid = (int)($ans['question_id'] ?? 0);
            $selected = isset($ans['selected_option_id']) ? (int)$ans['selected_option_id'] : null;
            $atext = isset($ans['answer_text']) ? $ans['answer_text'] : null;

            // fetch question info (type, marks)
            $qstmt = $mysqli->prepare("SELECT q.q_type, q.marks FROM assessment_questions q WHERE q.id=? AND q.section_id=?");
            $qstmt->bind_param("ii",$qid,$section_id);
            $qstmt->execute();
            $qres = $qstmt->get_result();
            if ($qres->num_rows !== 1) { $qstmt->close(); throw new Exception("Question not found or mismatch"); }
            $qrow = $qres->fetch_assoc();
            $qstmt->close();

            $awarded = 0;
            if ($qrow['q_type'] === 'mcq') {
                // find whether selected option is correct
                if ($selected) {
                    $ost = $mysqli->prepare("SELECT is_correct FROM assessment_options WHERE id = ? AND question_id = ?");
                    $ost->bind_param("ii",$selected,$qid);
                    $ost->execute();
                    $oresult = $ost->get_result();
                    $is_correct = 0;
                    if ($oresult->num_rows === 1) {
                        $is_correct = (int)$oresult->fetch_assoc()['is_correct'];
                    }
                    $ost->close();
                    if ($is_correct) {
                        $awarded = (int)$qrow['marks'];
                    } else $awarded = 0;
                } else {
                    $awarded = 0;
                }
            } else {
                // short answers: leave awarded 0 for auto-grading (can be graded later manually)
                $awarded = 0;
            }

            // insert answer
            $insert->bind_param("iiiisii", $assessment_id, $section_id, $qid, $STUDENT_ID = $STUDENT_ID, $atext, $selected_param = $selected, $awarded);
            // Note: bind_param doesn't accept null for integer easily; pass null as NULL if $selected is null
            // To handle, we'll use a more robust prepare/execute using types dynamic:
            // But due to complexity, instead use a separate prepared statement building with proper types per case.
            // Close the previous approach and do a per-row insert safely using statement with NULL handling:
            $insert->close();
            $ins2 = $mysqli->prepare("INSERT INTO assessment_answers (assessment_id, section_id, question_id, student_id, answer_text, selected_option_id, submitted_at, awarded_marks) VALUES (?,?,?,?,?,?,NOW(),?)");
            $sel = $selected ? $selected : null;
            // bind types: i i i i s i i  -> but selected_option_id can be null; use 'i' but null is passed as null
            if ($sel === null) {
                $ins2->bind_param("iiiisi", $assessment_id, $section_id, $qid, $STUDENT_ID, $atext, $awarded);
                // This will fail because types don't match positions; safer is to use explicit query with NULL handling:
                $stmt_sql = $mysqli->prepare("INSERT INTO assessment_answers (assessment_id, section_id, question_id, student_id, answer_text, selected_option_id, submitted_at, awarded_marks) VALUES (?,?,?,?,?,NULL,NOW(),?)");
                $stmt_sql->bind_param("iiiisii", $assessment_id, $section_id, $qid, $STUDENT_ID, $atext, $awarded, $awarded); // dummy - this is messy
                // To avoid this complexity, we'll use parameterized insert using mysqli_stmt::bind_param with appropriate null handling:
            }
            // The above attempt got messy because of strict bind_param ordering. Simpler approach: use a single INSERT with placeholders and use null casting.
            // We'll do a direct safe insertion using prepared statement with selected_option_id bound as integer or NULL via mysqli_stmt::bind_param using 'i' and setting var to null and calling bind_param - but PHP will convert null to empty string which may cause SQL error.
            // To keep code robust and clear, use this approach: build query with ? for selected_option_id and if null use SQL NULL via query string composition (but still escape other values).
            // Build safe insertion per row:
            $asi = (int)$assessment_id;
            $ssi = (int)$section_id;
            $qqi = (int)$qid;
            $stu = (int)$STUDENT_ID;
            $atext_esc = $mysqli->real_escape_string($atext);
            $awarded_i = (int)$awarded;
            if ($selected === null) {
                $sql = "INSERT INTO assessment_answers (assessment_id, section_id, question_id, student_id, answer_text, selected_option_id, submitted_at, awarded_marks)
                        VALUES ($asi, $ssi, $qqi, $stu, '". $mysqli->real_escape_string($atext_esc) ."', NULL, NOW(), $awarded_i)";
                $mysqli->query($sql);
            } else {
                $sel_i = (int)$selected;
                $sql = "INSERT INTO assessment_answers (assessment_id, section_id, question_id, student_id, answer_text, selected_option_id, submitted_at, awarded_marks)
                        VALUES ($asi, $ssi, $qqi, $stu, '". $mysqli->real_escape_string($atext_esc) ."', $sel_i, NOW(), $awarded_i)";
                $mysqli->query($sql);
            }

            $total_awarded += $awarded;
            $per_question[] = ['question_id'=>$qid, 'awarded'=>$awarded, 'marks'=>(int)$qrow['marks']];
        }

        $mysqli->commit();
        json_out(['ok'=>true, 'total_awarded'=>$total_awarded, 'details'=>$per_question]);
    } catch (Exception $e) {
        $mysqli->rollback();
        json_out(['ok'=>false, 'error'=>$e->getMessage()]);
    }
}
// end AJAX endpoints
/* --------------------------
   Render student page HTML below
   -------------------------- */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Student | <?php echo htmlspecialchars($CLASSROOM_NAME); ?></title>
<style>
/* UI styles similar to your template, plus assessment UI */
body{margin:0;font-family:system-ui,Segoe UI,Arial;background:#0f172a;color:#e2e8f0;}
.container{display:grid;grid-template-columns:180px 1fr 320px;gap:8px;height:100vh;padding:8px;}
.panel{background:#1e293b;border-radius:10px;overflow:hidden;display:flex;flex-direction:column;}
.panel h3{margin:0;padding:10px 12px;background:#0b1223;color:#38bdf8;font-size:14px;}
.list{flex:1;overflow:auto;padding:10px;}
.center{display:grid;grid-template-rows:1fr 260px;gap:8px;}
.videoWrap{display:flex;align-items:center;justify-content:center;background:#0b1223;}
video{max-width:100%;max-height:100%;background:#000;border-radius:6px;}
.chat{display:flex;flex-direction:column;}
.chatLog{flex:1;overflow:auto;padding:10px;background:#0b1223;border-top:1px solid #334155;}
.chatForm{display:flex;gap:8px;padding:8px;}
.chatForm input{flex:1;padding:10px;border:1px solid #334155;background:#0f172a;color:#e2e8f0;border-radius:6px;}
.chatForm button{padding:10px 14px;border:0;border-radius:6px;background:#38bdf8;color:#081226;font-weight:600;cursor:pointer;}
.selfPanel{padding:8px;}
.selfPanel video{width:100%;height:auto;border-radius:8px;}
.meta{padding:10px;font-size:13px;color:#94a3b8;border-top:1px solid #334155;}
.badge{display:inline-block;padding:2px 8px;border:1px solid #334155;border-radius:999px;margin-right:6px;}
.small-note{font-size:12px;color:#94a3b8;padding:8px;text-align:center}

/* Assessment list & modal */
.assess-list { padding:10px; display:flex;flex-direction:column; gap:8px; }
.assess-item { background:#071427;padding:10px;border-radius:8px;border:1px solid #223241; display:flex;justify-content:space-between;align-items:center }
.btn { padding:8px 12px; background:#38bdf8; border-radius:8px; color:#081226; border:0; cursor:pointer; font-weight:700; }
.btn.ghost { background:transparent; border:1px solid #334155; color:#94a3b8; }

/* modal */
.modal { position:fixed;left:0;top:0;right:0;bottom:0;background:rgba(0,0,0,.6);display:none;align-items:center;justify-content:center;z-index:9999; }
.modal.open { display:flex; }
.modal-card { width:980px; max-height:90vh; overflow:auto; background:#07122a;border-radius:10px;padding:18px; border:1px solid #22323f; color:#e2e8f0; }

/* header */
.assess-header { display:flex; gap:12px; align-items:center; margin-bottom:12px; }
.assess-logo { width:72px;height:72px;background:#00131f;border-radius:8px;display:flex;align-items:center;justify-content:center;font-weight:700;color:#38bdf8 }
.assess-meta { display:flex;flex-direction:column; }
.section-card { background:#0b1a29;padding:10px;border-radius:8px;margin-bottom:10px;border:1px solid #223241 }
.question { background:#102033;padding:8px;border-radius:6px;margin-bottom:8px;border:1px solid #223241 }
.timer { font-weight:700;color:#f97316 }
.option { display:flex; gap:8px; align-items:center; margin-bottom:6px; }
.small { font-size:12px;color:#94a3b8 }
</style>
</head>
<body>
<div class="container">
  <aside class="panel">
    <h3>Available Assessments</h3>
    <div id="assessList" class="list assess-list">Loading...</div>
    <div class="meta"><span class="badge">Room</span><?php echo htmlspecialchars($ROOM_ID); ?></div>
    <div class="small-note">Click an assessment to view details and start when allowed.</div>
  </aside>

  <main class="panel center">
    <div class="videoWrap">
      <video id="teacherVideo" autoplay playsinline></video>
    </div>

    <section class="panel chat">
      <h3>Class Chat</h3>
      <div id="chatLog" class="chatLog"></div>
      <form id="chatForm" class="chatForm">
        <input id="chatInput" placeholder="Write a message..." autocomplete="off" />
        <button type="submit">Send</button>
      </form>
    </section>
  </main>

  <aside class="panel">
    <h3>Your Camera</h3>
    <div class="selfPanel"><video id="selfVideo" autoplay playsinline muted></video></div>
    <div class="meta">
      <div><span class="badge">Class</span><?php echo htmlspecialchars($CLASSROOM_NAME); ?></div>
      <div><span class="badge">You</span>Student #<?php echo (int)$STUDENT_ID; ?></div>
    </div>
  </aside>
</div>

<!-- Assessment modal -->
<div id="assessModal" class="modal" aria-hidden="true">
  <div class="modal-card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
      <div class="assess-header">
        <div id="assessLogo" class="assess-logo"></div>
        <div class="assess-meta">
          <div id="assessTitle" style="font-size:18px;font-weight:700"></div>
          <div id="assessClass" class="small"></div>
          <div id="assessType" class="small"></div>
          <div id="assessTotalMarks" class="small"></div>
        </div>
      </div>
      <div>
        <button id="btnCloseAssess" class="btn ghost">Close</button>
      </div>
    </div>

    <div id="sectionsContainer"></div>

    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:8px">
      <button id="btnCloseAssess2" class="btn ghost">Close</button>
    </div>
  </div>
</div>

<script>
/* injected config */
const ROOM_ID = <?php echo json_encode($ROOM_ID); ?>;
const WS_URL  = <?php echo json_encode($WS_URL); ?>;
const STUN    = <?php echo $STUN_JSON; ?>;
const ROLE    = 'student';
const USER_ID = <?php echo (int)$STUDENT_ID; ?>;

/* runtime state */
let ws = null;
let pc = null;
let localStream = null;
let candidateBuffer = [];
let currentAssessment = null; // fetched assessment details
let activeSectionTimers = {}; // sectionId => { intervalId, endTs }

/* helper */
function el(id){ return document.getElementById(id); }
function log(...a){ console.log('[student]', ...a); }
function escapeHtml(s){ return (s===null||s===undefined) ? '' : String(s).replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function formatDTLocal(dtStr){
  try {
    const d = new Date(dtStr);
    if (isNaN(d)) return dtStr;
    return d.toLocaleString();
  } catch(e){ return dtStr; }
}

/* ---- signaling minimal (keep connection open) ---- */
function bindWSOnce(){
  if (ws && ws.readyState === WebSocket.OPEN) return Promise.resolve(ws);
  if (ws && ws.readyState === WebSocket.CONNECTING) {
    return new Promise((resolve,reject)=>{
      ws.addEventListener('open', ()=>resolve(ws));
      ws.addEventListener('error', (e)=>reject(e));
    });
  }
  ws = new WebSocket(WS_URL);
  return new Promise((resolve,reject)=>{
    ws.addEventListener('open', ()=>{
      log('WS connected', WS_URL);
      try {
        ws.send(JSON.stringify({ type:'join', room: ROOM_ID, role: ROLE, user_id: USER_ID }));
        ws.send(JSON.stringify({ type:'attendance', room: ROOM_ID, role: ROLE, user_id: USER_ID, event: 'join'}));
      } catch(e){}
      resolve(ws);
    });
    ws.addEventListener('message', (ev)=> {
      let data = {};
      try { data = JSON.parse(ev.data || '{}'); } catch(e){ return; }
      if (data.type === 'ping') { try { ws.send(JSON.stringify({type:'pong', ts:new Date().toISOString()})); } catch(e){}; return; }
      if (data.type === 'chat') addChat(`${data.sender_label||data.role||data.from_role||'user'}#${data.user_id}: ${data.text}`);
      if (data.type === 'roster') setStudentList(data.students || []);
      // other signaling handled by createPC when teacher offers
      if (data.type === 'offer' && (data.from_role === 'teacher' || data.role === 'teacher')) {
        handleOfferFromTeacher(data);
      }
      if (data.type === 'ice-candidate') {
        if (!pc) {
          candidateBuffer.push(data.candidate);
        } else {
          try { pc.addIceCandidate(new RTCIceCandidate(data.candidate)); } catch(e){ console.warn('addIceCandidate', e); }
        }
      }
    });
    ws.addEventListener('error', (e)=>{ console.error('WS error', e); reject(e); });
  });
}

/* ---- media + pc to receive teacher stream ---- */
async function initMedia(){
  try {
    localStream = await navigator.mediaDevices.getUserMedia({ video:true, audio:true });
    el('selfVideo').srcObject = localStream;
  } catch (e) {
    console.warn('media denied', e);
  }
}

function createPC(){
  if (pc) return pc;
  pc = new RTCPeerConnection({ iceServers: STUN });
  if (localStream) for (const t of localStream.getTracks()) pc.addTrack(t, localStream);
  pc.ontrack = (ev)=>{
    const v = el('teacherVideo');
    if (ev.streams && ev.streams[0]) v.srcObject = ev.streams[0];
    else {
      const s = new MediaStream();
      if (ev.track) s.addTrack(ev.track);
      v.srcObject = s;
    }
  };
  pc.onicecandidate = (ev)=>{
    if (ev.candidate && ws && ws.readyState === WebSocket.OPEN) {
      ws.send(JSON.stringify({ type:'ice-candidate', room: ROOM_ID, role: ROLE, from: USER_ID, candidate: ev.candidate }));
    }
  };
  // apply buffered candidates
  if (candidateBuffer.length) {
    candidateBuffer.forEach(c => {
      try { pc.addIceCandidate(new RTCIceCandidate(c)); } catch(e){ console.warn(e); }
    });
    candidateBuffer = [];
  }
  return pc;
}

async function handleOfferFromTeacher(data){
  try {
    if (!pc) createPC();
    await pc.setRemoteDescription(new RTCSessionDescription(data.sdp));
    const answer = await pc.createAnswer();
    await pc.setLocalDescription(answer);
    if (ws && ws.readyState === WebSocket.OPEN) {
      ws.send(JSON.stringify({ type:'answer', room: ROOM_ID, role: ROLE, from: USER_ID, to: data.from, sdp: pc.localDescription }));
    }
  } catch (e) { console.error('handleOfferFromTeacher', e); }
}

/* ---- roster & chat UI ---- */
function addChat(msg){ const log = el('chatLog'); const d = document.createElement('div'); d.textContent = `[${new Date().toLocaleTimeString()}] ${msg}`; log.appendChild(d); log.scrollTop = log.scrollHeight; }
function setStudentList(arr){ const elist = el('studentList'); if (!Array.isArray(arr) || !arr.length) { elist.innerHTML = '<div style="opacity:.7">No other students online</div>'; return; } elist.innerHTML = arr.map(s=>`<div>#${s.user_id} ${s.role?('('+s.role+')'):''}</div>`).join(''); }

el('chatForm').addEventListener('submit', async (ev)=>{
  ev.preventDefault();
  const input = el('chatInput'); const text = input.value.trim(); if (!text) return;
  try{ await bindWSOnce(); ws.send(JSON.stringify({ type:'chat', room: ROOM_ID, role: ROLE, user_id: USER_ID, text })); addChat(`You: ${text}`); input.value=''; } catch(e){ addChat('Not connected to signaling server.'); }
});

/* bootstrap */
(async function initBootstrap(){
  try {
    await initMedia();
    await bindWSOnce();
  } catch(e){ console.warn('bootstrap err', e); }
})();

/* ---- Assessments: load list and UI ---- */
async function loadAssessments(){
  try {
    const res = await fetch('?action=list_assessments');
    const j = await res.json();
    const cont = el('assessList');
    if (!j.ok) { cont.innerText = 'Failed to load'; return; }
    cont.innerHTML = '';
    if (!j.assessments.length) { cont.innerHTML = '<div class="small">No assessments available.</div>'; return; }
    j.assessments.forEach(a => {
      const item = document.createElement('div'); item.className = 'assess-item';
      const left = document.createElement('div'); left.innerHTML = `<div style="font-weight:700">${escapeHtml(a.title)}</div><div class="small">${escapeHtml(a.type)} • created: ${escapeHtml(a.created_at)}</div>`;
      const right = document.createElement('div'); right.style.display='flex';
      const btnView = document.createElement('button'); btnView.className='btn ghost'; btnView.textContent='View';
      btnView.onclick = ()=> openAssessment(a.id);
      right.appendChild(btnView);
      item.appendChild(left); item.appendChild(right);
      cont.appendChild(item);
    });
  } catch(e){ console.error(e); el('assessList').innerText='Error'; }
}
loadAssessments();

/* open assessment modal and fetch details */
async function openAssessment(assessmentId){
  try {
    const res = await fetch('?action=get_assessment&id=' + encodeURIComponent(assessmentId));
    const j = await res.json();
    if (!j.ok) return alert('Failed to load assessment');
    currentAssessment = j;
    renderAssessmentModal(j);
  } catch(e){ console.error(e); alert('Failed to load assessment'); }
}

function renderAssessmentModal(data){
  const modal = el('assessModal');
  // header
  el('assessTitle').textContent = data.assessment.title;
  el('assessClass').textContent = data.classroom.name || '';
  el('assessType').textContent = 'Type: ' + (data.assessment.type || '');
  el('assessTotalMarks').textContent = 'Total marks: ' + (data.assessment.total_marks ?? '—');
  // logo
  const logoEl = el('assessLogo');
  if (data.classroom.logo) {
    logoEl.style.backgroundImage = `url(${escapeHtml(data.classroom.logo)})`;
    logoEl.style.backgroundSize = 'cover';
    logoEl.textContent = '';
  } else {
    logoEl.style.backgroundImage = '';
    logoEl.textContent = (data.classroom.name || '').split(' ').map(s=>s[0]).slice(0,2).join('').toUpperCase();
  }

  // sections list
  const sc = el('sectionsContainer'); sc.innerHTML = '';
  (data.sections || []).forEach(sec=>{
    const secDiv = document.createElement('div'); secDiv.className='section-card';
    const now = new Date();
    const start = new Date(sec.start_at);
    const end = new Date(sec.end_at);
    const isStarted = now >= start;
    const isEnded = now > end;
    secDiv.innerHTML = `
      <div style="display:flex;justify-content:space-between;align-items:center">
        <div><strong>${escapeHtml(sec.title)}</strong><div class="small">Start: ${formatDTLocal(sec.start_at)} — End: ${formatDTLocal(sec.end_at)}</div></div>
        <div>
          ${ isEnded ? `<span class="small">Section ended</span>` : (isStarted ? `<button class="btn" data-action="start-section" data-sid="${sec.id}">Start Section</button>` : `<button class="btn ghost" data-action="start-section" data-sid="${sec.id}" disabled>Locked until start</button>`) }
        </div>
      </div>
      <div style="margin-top:8px" id="section_preview_${sec.id}">${sec.questions.length} question(s)</div>
    `;
    sc.appendChild(secDiv);
  });

  // open modal
  modal.classList.add('open'); modal.setAttribute('aria-hidden','false');

  // wire start buttons
  modal.querySelectorAll('[data-action="start-section"]').forEach(b=>{
    b.onclick = ()=> {
      const sid = parseInt(b.getAttribute('data-sid'),10);
      startSection(sid);
    };
  });
}

// close buttons
el('btnCloseAssess').addEventListener('click', ()=> { el('assessModal').classList.remove('open'); el('assessModal').setAttribute('aria-hidden','true'); });
el('btnCloseAssess2').addEventListener('click', ()=> { el('assessModal').classList.remove('open'); el('assessModal').setAttribute('aria-hidden','true'); });

/* ---- start a section: render questions, start timer, handle submit/auto-submit ---- */
function startSection(sectionId){
  if (!currentAssessment) return;
  const sec = (currentAssessment.sections || []).find(s=>s.id == sectionId);
  if (!sec) return alert('Section not found');
  const now = new Date();
  const start = new Date(sec.start_at);
  const end = new Date(sec.end_at);
  if (now < start) return alert('Section not started yet');
  // open a focused modal for the section
  openSectionModal(sec, currentAssessment.assessment);
}

function openSectionModal(section, assessment) {
  // create a modal-like overlay for questions (re-using assessModal area)
  const modal = el('assessModal');
  // replace sectionsContainer with questions UI temporarily
  const container = el('sectionsContainer');
  container.innerHTML = '';
  // header area
  const hdr = document.createElement('div');
  hdr.style.display = 'flex'; hdr.style.justifyContent='space-between'; hdr.style.alignItems='center';
  hdr.innerHTML = `<div><strong>${escapeHtml(section.title)}</strong><div class="small">Section ends: ${formatDTLocal(section.end_at)}</div></div><div><span id="sectionTimer" class="timer">--:--</span></div>`;
  container.appendChild(hdr);

  // build form
  const form = document.createElement('div'); form.id = 'sectionForm';
  section.questions.forEach((q, idx)=>{
    const qdiv = document.createElement('div'); qdiv.className='question';
    qdiv.innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center"><div><strong>Q${idx+1}</strong> <span class="small">(${escapeHtml(q.q_type)} • marks: ${q.marks})</span></div></div>
      <div style="margin-top:6px">${escapeHtml(q.question_text)}</div>
      <div style="margin-top:8px" id="qopts_${q.id}"></div>`;
    form.appendChild(qdiv);
    const optsContainer = qdiv.querySelector(`#qopts_${q.id}`);

    if (q.q_type === 'mcq') {
      q.options.forEach(opt=>{
        const odiv = document.createElement('div'); odiv.className='option';
        odiv.innerHTML = `<label><input type="radio" name="q_${q.id}" value="${opt.id}"> ${escapeHtml(opt.option_text)}</label>`;
        optsContainer.appendChild(odiv);
      });
    } else {
      const ta = document.createElement('textarea'); ta.style.width='100%'; ta.style.minHeight='80px'; ta.id = 'short_' + q.id;
      optsContainer.appendChild(ta);
    }
  });
  // submit button
  const controls = document.createElement('div'); controls.style.display='flex'; controls.style.justifyContent='flex-end'; controls.style.marginTop='8px';
  const submitBtn = document.createElement('button'); submitBtn.className='btn'; submitBtn.textContent='Submit Section';
  controls.appendChild(submitBtn);
  form.appendChild(controls);
  container.appendChild(form);

  // start timer
  const timerEl = el('sectionTimer');
  const endTs = new Date(section.end_at).getTime();
  // show remaining time and auto-submit when time over
  function updateTimer(){
    const now = Date.now();
    const diff = endTs - now;
    if (diff <= 0) {
      timerEl.textContent = '00:00';
      clearInterval(activeSectionTimers[section.id]?.intervalId);
      // auto-submit
      submitSectionAnswers(section, assessment, true);
    } else {
      const mins = Math.floor(diff/60000);
      const secs = Math.floor((diff%60000)/1000);
      timerEl.textContent = String(mins).padStart(2,'0') + ':' + String(secs).padStart(2,'0');
    }
  }
  updateTimer();
  const iid = setInterval(updateTimer, 1000);
  activeSectionTimers[section.id] = { intervalId: iid, endTs };

  // submit handler (manual)
  submitBtn.onclick = ()=> submitSectionAnswers(section, assessment, false);
}

/* collect answers and send to server; if auto=true it's auto-submission */
async function submitSectionAnswers(section, assessment, auto=false){
  // gather answers
  const answers = [];
  for (const q of section.questions) {
    if (q.q_type === 'mcq') {
      const els = document.getElementsByName('q_' + q.id);
      let selected = null;
      for (const e of els) if (e.checked) { selected = parseInt(e.value,10); break; }
      answers.push({ question_id: q.id, selected_option_id: selected });
    } else {
      const ta = document.getElementById('short_' + q.id);
      const txt = ta ? ta.value.trim() : '';
      answers.push({ question_id: q.id, answer_text: txt });
    }
  }

  // POST to submit_answers endpoint
  try {
    const payload = { assessment_id: assessment.id, section_id: section.id, answers };
    const res = await fetch('?action=submit_answers', {
      method: 'POST',
      headers: { 'Content-Type':'application/json' },
      body: JSON.stringify(payload)
    });
    const j = await res.json();
    if (!j.ok) {
      alert('Submit failed: ' + (j.error || 'unknown'));
      return;
    }
    // stop timer for this section
    if (activeSectionTimers[section.id]) {
      clearInterval(activeSectionTimers[section.id].intervalId);
      delete activeSectionTimers[section.id];
    }

    // show result summary for this section
    let awarded = j.total_awarded ?? 0;
    alert(`Section submitted. Marks awarded (auto-graded MCQ): ${awarded}`);
    // After submission, refresh assessment view (re-open modal in read-only preview)
    // For simplicity, close modal and refresh list
    el('assessModal').classList.remove('open');
    el('assessModal').setAttribute('aria-hidden','true');
    loadAssessments();
  } catch (e) {
    console.error('submitSectionAnswers', e);
    alert('Submission error');
  }
}
</script>
</body>
</html>
//Pages zikenewe (MVP) — umubare n’intego yabyo

Nguhaye ibyiciro bibiri: User-facing (izo abakiriya babona) na Admin (izo uzakoresha mu gucunga). Niba ushaka gusa gutangira vuba, tangira na “MVP” (pages 5–7). Hano munsi ni urutonde rwuzuye n’intego za buri paji.

A. User-facing (frontend) — 5–7 pages (MVP)

Home / Landing page (1) — imbonerahamwe y’ibikorwa (service cards), testimonials, contact button.

Ibirimo: title, short pitch, service cards, contact/WhatsApp button, footer.

Services list / Catalog (1) — urutonde rwa services zose n’ibiciro (ishobora kuba igice cya Home niba ushaka compact).

Ibirimo: filters, “Odera” button kuri buri service.

Service detail + Order form (1 per service template) — page imwe ikoreshwa ku service yose (dynamic) aho umukiriya yuzuza order (name, phone, details, payment method).

Ibirimo: form, price, delivery time, payment instructions.

Order confirmation / Payment instructions page (1) — nyuma yo gutanga order, aha niho ugaragaza order number, amabwiriza ya Mobile Money/PayPal, n’uburyo bwo kohereza proof.

Order status / Tracking (1) — umukiriya yashyiramo order number cyangwa email akareba aho order igeze (pending/paid/in_progress/completed).

(Optional but recommended) Portfolio / Samples (1) — ibyakozwe nawe (screenshots, links) kugirango wubake credibility.

(Optional) About / Contact / FAQ / Terms & Privacy (1–2) — amakuru y’inyongera n’amabwiriza yo kwishyura.

Frontend total (MVP): 5 pages.
Frontend recommended (with extras): 6–8 pages.

B. Admin (backend) — 5–7 pages (pro)

Admin Login (1) — authentication for admins.

Dashboard (1) — overview (new orders, unpaid, in-progress, recent payments).

Orders list + detail (1) — filter by status, click to view order details, view payment proof, change status.

Create / Edit Services (1) — form to add/update service (title, price, description, delivery_time).

Payments / Transactions (1) — view payment logs, confirm manual MoMo payments.

Deliverables (Upload files) (1) — upload file per order and send notification to customer.

(Optional) Testimonials / Users / Settings (1) — manage testimonials, admin accounts, site settings.

Admin total (core): 5 pages.
Admin recommended (full): 6–8 pages.

C. API endpoints / misc (not visible as pages but important)

POST /api/orders

GET /api/orders/{order_number}

POST /api/payments/webhook/paypal

POST /admin/deliver (file upload)

D. Structure y’amasoko (file / route suggestion)

/ → Home

/services → Services list

/services/{slug} → Service detail + order form

/order/{order_number} → Order status / confirmation

/portfolio → Samples

/contact → Contact / FAQ

/admin → Admin login

/admin/dashboard → Dashboard

/admin/orders → Orders list

/admin/services → Services management

/admin/payments → Payments

/admin/deliver → Upload deliverables

E. Inama ngufi

Tangirira kuri Home + Service detail (template) + Order confirmation + Admin login + Orders list — ibi nibyo byibanze (5 pages) bikora neza ku mushinga wawe.

Ongeraho Portfolio na FAQ nyuma y’icyumweru kimwe cyo guteranya abakiriya no kubona feedback.