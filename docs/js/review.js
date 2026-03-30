// ── State ──
let session = null;
let attempts = [];
let currentIndex = 0;
let history = []; // for undo

// ── DOM refs ──
const $loading    = document.getElementById('loading');
const $error      = document.getElementById('error');
const $review     = document.getElementById('review');
const $completion = document.getElementById('completion');
const $cardStack  = document.getElementById('card-stack');
const $progress   = document.getElementById('progress-fill');
const $progText   = document.getElementById('progress-text');
const $modal      = document.getElementById('modal');
const $btnApprove = document.getElementById('btn-approve');
const $btnReject  = document.getElementById('btn-reject');
const $btnUndo    = document.getElementById('btn-undo');

// ── Supabase REST helpers ──
function supabaseGet(table, query) {
    return fetch(`${SUPABASE_URL}/rest/v1/${table}?${query}`, {
        headers: {
            'apikey': SUPABASE_ANON_KEY,
            'Authorization': `Bearer ${SUPABASE_ANON_KEY}`
        }
    }).then(r => r.json());
}

function supabasePatch(table, query, body) {
    return fetch(`${SUPABASE_URL}/rest/v1/${table}?${query}`, {
        method: 'PATCH',
        headers: {
            'apikey': SUPABASE_ANON_KEY,
            'Authorization': `Bearer ${SUPABASE_ANON_KEY}`,
            'Content-Type': 'application/json',
            'Prefer': 'return=minimal'
        },
        body: JSON.stringify(body)
    });
}

// ── Init ──
async function init() {
    const token = new URLSearchParams(window.location.search).get('token');
    if (!token) return showError('No Token', 'Please use the link from your email.');

    try {
        // Load session
        const sessions = await supabaseGet('review_sessions', `token=eq.${token}&select=*`);
        if (!sessions.length) return showError('Invalid Link', 'This review link is not valid or has expired.');
        session = sessions[0];

        // Load attempts ordered by startdatetime
        attempts = await supabaseGet('attempts', `session_id=eq.${session.id}&select=*&order=startdatetime.asc`);
        if (!attempts.length) return showError('No Data', 'No attempts found for this session.');

        // Find first unreviewed
        currentIndex = attempts.findIndex(a => a.is_genuine === null);
        if (currentIndex === -1) currentIndex = attempts.length;

        // Show review UI
        document.getElementById('report-date').textContent = formatDate(session.report_date);
        showState('review');
        updateProgress();
        renderCards();
    } catch (e) {
        console.error(e);
        showError('Connection Error', 'Could not connect to the database. Please try again.');
    }
}

function showState(state) {
    $loading.style.display = state === 'loading' ? '' : 'none';
    $error.style.display = state === 'error' ? '' : 'none';
    $review.style.display = state === 'review' ? '' : 'none';
    $completion.style.display = state === 'complete' ? '' : 'none';
}

function showError(title, message) {
    document.getElementById('error-title').textContent = title;
    document.getElementById('error-message').textContent = message;
    showState('error');
}

function formatDate(dateStr) {
    const d = new Date(dateStr + 'T00:00:00');
    return d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'long', year: 'numeric' });
}

// ── Progress ──
function updateProgress() {
    const reviewed = attempts.filter(a => a.is_genuine !== null).length;
    const total = attempts.length;
    const pct = total ? (reviewed / total * 100) : 0;
    $progress.style.width = pct + '%';
    $progText.textContent = `${reviewed} of ${total} reviewed`;
    $btnUndo.style.visibility = history.length ? 'visible' : 'hidden';
}

// ── Card Rendering ──
function renderCards() {
    $cardStack.innerHTML = '';

    if (currentIndex >= attempts.length) {
        showCompletion();
        return;
    }

    // Render up to 2 cards (current + next for stack effect)
    for (let i = Math.min(currentIndex + 1, attempts.length - 1); i >= currentIndex; i--) {
        const card = createCard(attempts[i], i === currentIndex);
        $cardStack.appendChild(card);
    }

    if (currentIndex < attempts.length) {
        setupSwipe($cardStack.lastElementChild);
    }
}

function createCard(attempt, isTop) {
    const card = document.createElement('div');
    card.className = 'swipe-card';
    if (!isTop) {
        card.style.transform = 'scale(0.95) translateY(8px)';
        card.style.opacity = '0.6';
        card.style.pointerEvents = 'none';
    }

    const resultClass = attempt.resultcode.includes('Hang Up') ? 'hang-up' : 'no-answer';

    card.innerHTML = `
        <div class="card-label genuine">Genuine</div>
        <div class="card-label not-genuine">Not Genuine</div>
        <div class="card-field">
            <div class="label">Name</div>
            <div class="value name">${escapeHtml(attempt.fullname)}</div>
        </div>
        <div class="card-field">
            <div class="label">Phone Number</div>
            <div class="value phone">${escapeHtml(formatPhone(attempt.phonenumber))}</div>
        </div>
        <div class="card-field">
            <div class="label">Result</div>
            <div class="value"><span class="result-badge ${resultClass}">${escapeHtml(attempt.resultcode)}</span></div>
        </div>
        <div class="card-field">
            <div class="label">Date &amp; Time</div>
            <div class="value">${escapeHtml(attempt.startdatetime)}</div>
        </div>
        <div class="card-field">
            <div class="label">Disconnected By</div>
            <div class="value">${escapeHtml(attempt.disconnector)}</div>
        </div>
    `;
    return card;
}

function formatPhone(phone) {
    if (phone.length === 11) {
        return phone.replace(/^(\d{5})(\d{3})(\d{3})$/, '$1 $2 $3');
    }
    return phone;
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// ── Swipe Mechanics ──
function setupSwipe(card) {
    let startX = 0, startY = 0, currentX = 0, isDragging = false;
    const threshold = window.innerWidth * 0.3;

    function onStart(e) {
        isDragging = true;
        const point = e.touches ? e.touches[0] : e;
        startX = point.clientX;
        startY = point.clientY;
        card.style.transition = 'none';
    }

    function onMove(e) {
        if (!isDragging) return;
        const point = e.touches ? e.touches[0] : e;
        currentX = point.clientX - startX;
        const rotation = currentX * 0.08;
        card.style.transform = `translateX(${currentX}px) rotate(${rotation}deg)`;

        // Show labels based on direction
        const genuineLabel = card.querySelector('.card-label.genuine');
        const notGenuineLabel = card.querySelector('.card-label.not-genuine');
        const opacity = Math.min(Math.abs(currentX) / threshold, 1);

        if (currentX > 0) {
            genuineLabel.style.opacity = opacity;
            notGenuineLabel.style.opacity = 0;
        } else {
            notGenuineLabel.style.opacity = opacity;
            genuineLabel.style.opacity = 0;
        }
    }

    function onEnd() {
        if (!isDragging) return;
        isDragging = false;

        if (currentX > threshold) {
            animateOut(card, 1);
            markGenuine();
        } else if (currentX < -threshold) {
            animateOut(card, -1);
            showRejectionModal();
        } else {
            card.style.transition = 'transform 0.3s ease';
            card.style.transform = '';
            card.querySelector('.card-label.genuine').style.opacity = 0;
            card.querySelector('.card-label.not-genuine').style.opacity = 0;
        }
        currentX = 0;
    }

    card.addEventListener('touchstart', onStart, { passive: true });
    card.addEventListener('touchmove', onMove, { passive: true });
    card.addEventListener('touchend', onEnd);
    card.addEventListener('mousedown', onStart);
    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onEnd);
}

function animateOut(card, direction) {
    card.style.transition = 'transform 0.4s ease, opacity 0.4s ease';
    card.style.transform = `translateX(${direction * window.innerWidth}px) rotate(${direction * 30}deg)`;
    card.style.opacity = '0';
}

// ── Actions ──
async function markGenuine() {
    const attempt = attempts[currentIndex];
    history.push({ index: currentIndex, prev: { is_genuine: attempt.is_genuine, rejection_reason: attempt.rejection_reason } });

    attempt.is_genuine = true;
    attempt.rejection_reason = null;
    attempt.reviewed_at = new Date().toISOString();
    currentIndex++;
    updateProgress();

    // Save to Supabase
    await supabasePatch('attempts', `id=eq.${attempt.id}`, {
        is_genuine: true,
        rejection_reason: null,
        reviewed_at: attempt.reviewed_at
    });
    await syncSessionProgress();

    setTimeout(() => renderCards(), 350);
}

async function markNotGenuine(reason) {
    const attempt = attempts[currentIndex];
    history.push({ index: currentIndex, prev: { is_genuine: attempt.is_genuine, rejection_reason: attempt.rejection_reason } });

    attempt.is_genuine = false;
    attempt.rejection_reason = reason;
    attempt.reviewed_at = new Date().toISOString();
    currentIndex++;
    updateProgress();

    await supabasePatch('attempts', `id=eq.${attempt.id}`, {
        is_genuine: false,
        rejection_reason: reason,
        reviewed_at: attempt.reviewed_at
    });
    await syncSessionProgress();

    setTimeout(() => renderCards(), 350);
}

async function undo() {
    if (!history.length) return;
    const last = history.pop();
    const attempt = attempts[last.index];
    attempt.is_genuine = last.prev.is_genuine;
    attempt.rejection_reason = last.prev.rejection_reason;
    attempt.reviewed_at = null;
    currentIndex = last.index;
    updateProgress();

    await supabasePatch('attempts', `id=eq.${attempt.id}`, {
        is_genuine: last.prev.is_genuine,
        rejection_reason: last.prev.rejection_reason,
        reviewed_at: null
    });
    await syncSessionProgress();

    renderCards();
}

async function syncSessionProgress() {
    const reviewed = attempts.filter(a => a.is_genuine !== null).length;
    const body = { reviewed_count: reviewed };
    if (reviewed === attempts.length) {
        body.completed_at = new Date().toISOString();
    }
    await supabasePatch('review_sessions', `id=eq.${session.id}`, body);
}

// ── Completion ──
function showCompletion() {
    const genuine = attempts.filter(a => a.is_genuine === true).length;
    const notGenuine = attempts.filter(a => a.is_genuine === false).length;
    document.getElementById('stat-genuine').textContent = genuine;
    document.getElementById('stat-not-genuine').textContent = notGenuine;
    showState('complete');
}

// ── Rejection Modal ──
function showRejectionModal() {
    $modal.classList.add('active');
}

function hideRejectionModal() {
    $modal.classList.remove('active');
    document.getElementById('other-reason').value = '';
}

// Preset reason buttons
document.querySelectorAll('.reason-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        hideRejectionModal();
        markNotGenuine(btn.dataset.reason);
    });
});

// Other reason submit
document.getElementById('modal-submit-other').addEventListener('click', () => {
    const reason = document.getElementById('other-reason').value.trim();
    if (!reason) return;
    hideRejectionModal();
    markNotGenuine(reason);
});

// Cancel modal — put card back
document.getElementById('modal-cancel').addEventListener('click', () => {
    hideRejectionModal();
    renderCards(); // re-render to reset card position
});

// Close modal on overlay click
$modal.addEventListener('click', (e) => {
    if (e.target === $modal) {
        hideRejectionModal();
        renderCards();
    }
});

// ── Button handlers ──
$btnApprove.addEventListener('click', () => {
    if (currentIndex >= attempts.length) return;
    const card = $cardStack.lastElementChild;
    if (card) animateOut(card, 1);
    markGenuine();
});

$btnReject.addEventListener('click', () => {
    if (currentIndex >= attempts.length) return;
    const card = $cardStack.lastElementChild;
    if (card) animateOut(card, -1);
    showRejectionModal();
});

$btnUndo.addEventListener('click', undo);

// ── Keyboard shortcuts ──
document.addEventListener('keydown', (e) => {
    if ($modal.classList.contains('active')) return;
    if (e.key === 'ArrowRight') $btnApprove.click();
    if (e.key === 'ArrowLeft') $btnReject.click();
    if (e.key === 'z' && (e.ctrlKey || e.metaKey)) undo();
});

// ── Start ──
init();
