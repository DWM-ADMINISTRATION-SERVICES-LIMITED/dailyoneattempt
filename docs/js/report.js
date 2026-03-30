// ── State ──
let currentYear, currentMonth;

const $loading = document.getElementById('loading');
const $report  = document.getElementById('report');
const $error   = document.getElementById('error');

// ── Supabase REST helper ──
function supabaseGet(table, query) {
    return fetch(`${SUPABASE_URL}/rest/v1/${table}?${query}`, {
        headers: {
            'apikey': SUPABASE_ANON_KEY,
            'Authorization': `Bearer ${SUPABASE_ANON_KEY}`
        }
    }).then(r => r.json());
}

// ── Init ──
function init() {
    const params = new URLSearchParams(window.location.search);
    const month = params.get('month');

    if (month && /^\d{4}-\d{2}$/.test(month)) {
        currentYear = parseInt(month.split('-')[0]);
        currentMonth = parseInt(month.split('-')[1]);
    } else {
        const now = new Date();
        currentYear = now.getFullYear();
        currentMonth = now.getMonth() + 1;
    }

    document.getElementById('prev-month').addEventListener('click', () => navigateMonth(-1));
    document.getElementById('next-month').addEventListener('click', () => navigateMonth(1));

    loadMonth();
}

function navigateMonth(delta) {
    currentMonth += delta;
    if (currentMonth > 12) { currentMonth = 1; currentYear++; }
    if (currentMonth < 1)  { currentMonth = 12; currentYear--; }

    const param = `${currentYear}-${String(currentMonth).padStart(2, '0')}`;
    history.replaceState(null, '', `?month=${param}`);
    loadMonth();
}

async function loadMonth() {
    showState('loading');

    const monthStr = `${currentYear}-${String(currentMonth).padStart(2, '0')}`;
    const monthName = new Date(currentYear, currentMonth - 1).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
    document.getElementById('current-month').textContent = monthName;

    // Calculate date range for the month
    const startDate = `${monthStr}-01`;
    const endYear = currentMonth === 12 ? currentYear + 1 : currentYear;
    const endMonth = currentMonth === 12 ? 1 : currentMonth + 1;
    const endDate = `${endYear}-${String(endMonth).padStart(2, '0')}-01`;

    try {
        // Fetch sessions for this month
        const sessions = await supabaseGet('review_sessions',
            `report_date=gte.${startDate}&report_date=lt.${endDate}&select=*&order=report_date.asc`
        );

        if (!sessions.length) {
            showState('error');
            return;
        }

        // Fetch all attempts for these sessions
        const sessionIds = sessions.map(s => s.id);
        const attempts = await supabaseGet('attempts',
            `session_id=in.(${sessionIds.join(',')})&select=*`
        );

        renderReport(sessions, attempts, monthStr);
        showState('report');
    } catch (e) {
        console.error(e);
        document.getElementById('error-title').textContent = 'Connection Error';
        document.getElementById('error-message').textContent = 'Could not load report data.';
        showState('error');
    }
}

function showState(state) {
    $loading.style.display = state === 'loading' ? '' : 'none';
    $report.style.display  = state === 'report'  ? '' : 'none';
    $error.style.display   = state === 'error'   ? '' : 'none';
}

function renderReport(sessions, attempts, monthStr) {
    renderSummary(sessions, attempts);
    renderDayGrid(sessions, monthStr);
    renderAgentTable(attempts);
    renderReasonTable(attempts);
}

// ── Summary Cards ──
function renderSummary(sessions, attempts) {
    const total = attempts.length;
    const reviewed = attempts.filter(a => a.is_genuine !== null).length;
    const genuine = attempts.filter(a => a.is_genuine === true).length;
    const notGenuine = attempts.filter(a => a.is_genuine === false).length;
    const pct = reviewed ? Math.round(genuine / reviewed * 100) : 0;

    document.getElementById('summary-cards').innerHTML = `
        <div class="summary-card">
            <div class="number">${total}</div>
            <div class="label">Total Attempts</div>
        </div>
        <div class="summary-card">
            <div class="number">${reviewed}</div>
            <div class="label">Reviewed</div>
        </div>
        <div class="summary-card">
            <div class="number" style="color:var(--genuine)">${genuine}</div>
            <div class="label">Genuine</div>
        </div>
        <div class="summary-card">
            <div class="number" style="color:var(--not-genuine)">${notGenuine}</div>
            <div class="label">Not Genuine</div>
        </div>
        <div class="summary-card">
            <div class="number" style="color:var(--primary)">${pct}%</div>
            <div class="label">Genuine Rate</div>
        </div>
    `;
}

// ── Day Completion Grid ──
function renderDayGrid(sessions, monthStr) {
    const daysInMonth = new Date(currentYear, currentMonth, 0).getDate();
    const grid = document.getElementById('day-grid');
    grid.innerHTML = '';

    const sessionByDay = {};
    sessions.forEach(s => {
        const day = parseInt(s.report_date.split('-')[2]);
        sessionByDay[day] = s;
    });

    for (let d = 1; d <= daysInMonth; d++) {
        const cell = document.createElement('div');
        cell.className = 'day-cell';
        cell.textContent = d;

        const s = sessionByDay[d];
        if (!s) {
            cell.classList.add('empty');
        } else if (s.completed_at) {
            cell.classList.add('complete');
        } else if (s.reviewed_count > 0) {
            cell.classList.add('partial');
        } else {
            cell.classList.add('pending');
        }

        grid.appendChild(cell);
    }
}

// ── Agent Breakdown ──
function renderAgentTable(attempts) {
    const agents = {};
    attempts.forEach(a => {
        if (a.is_genuine === null) return; // skip unreviewed
        if (!agents[a.fullname]) agents[a.fullname] = { total: 0, genuine: 0, notGenuine: 0 };
        agents[a.fullname].total++;
        if (a.is_genuine) agents[a.fullname].genuine++;
        else agents[a.fullname].notGenuine++;
    });

    const sorted = Object.entries(agents).sort((a, b) => b[1].total - a[1].total);
    const tbody = document.getElementById('agent-tbody');
    tbody.innerHTML = '';

    sorted.forEach(([name, data]) => {
        const pct = data.total ? Math.round(data.genuine / data.total * 100) : 0;
        const row = document.createElement('tr');
        row.innerHTML = `
            <td><strong>${escapeHtml(name)}</strong></td>
            <td>${data.total}</td>
            <td>${data.genuine}</td>
            <td>${data.notGenuine}</td>
            <td>
                <span class="pct-bar genuine" style="width:${pct}px"></span>
                <span class="pct-bar not-genuine" style="width:${100 - pct}px"></span>
                ${pct}%
            </td>
        `;
        tbody.appendChild(row);
    });

    if (!sorted.length) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#999">No reviewed data yet</td></tr>';
    }
}

// ── Rejection Reasons ──
function renderReasonTable(attempts) {
    const reasons = {};
    const rejected = attempts.filter(a => a.is_genuine === false);

    rejected.forEach(a => {
        const r = a.rejection_reason || 'Unknown';
        reasons[r] = (reasons[r] || 0) + 1;
    });

    const sorted = Object.entries(reasons).sort((a, b) => b[1] - a[1]);
    const tbody = document.getElementById('reason-tbody');
    tbody.innerHTML = '';
    const total = rejected.length;

    sorted.forEach(([reason, count]) => {
        const pct = total ? Math.round(count / total * 100) : 0;
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${escapeHtml(reason)}</td>
            <td>${count}</td>
            <td>${pct}%</td>
        `;
        tbody.appendChild(row);
    });

    if (!sorted.length) {
        tbody.innerHTML = '<tr><td colspan="3" style="text-align:center;color:#999">No rejections recorded</td></tr>';
    }
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

init();
