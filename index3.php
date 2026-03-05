<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

require __DIR__ . '/vendor/autoload.php';

$app = AppFactory::create();

// ── DATABASE ───────────────────────────────────────────────────────────────
$host    = 'localhost';
$db      = 'verificaasorpresa';
$user    = 'mecja_kevin';
$pass    = 'mEcjA69@104';
$charset = 'utf8mb4';
$dsn     = "mysql:host=$host;dbname=$db;charset=$charset";
define('JWT_SECRET', 'catalogo_secret_key_2024_slim_app');

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(['errore' => 'Connessione fallita: ' . $e->getMessage()]));
}


function queryDB(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function queryOne(PDO $pdo, string $sql, array $params = []): ?array {
    $rows = queryDB($pdo, $sql, $params);
    return $rows[0] ?? null;
}

function jsonRes(Response $res, mixed $data, int $status = 200): Response {
    $res->getBody()->write(json_encode($data));
    return $res->withHeader('Content-Type', 'application/json')->withStatus($status);
}

function paginate(PDO $pdo, string $baseSql, array $params, int $page, int $perPage = 10): array { //funzione di paginazione dei riusltati delle query
    $total  = (int)queryOne($pdo, "SELECT COUNT(*) as t FROM ($baseSql) _cnt", $params)['t'];
    $offset = ($page - 1) * $perPage;
    $rows   = queryDB($pdo, $baseSql . " LIMIT $perPage OFFSET $offset", $params);
    return [
        'data'       => $rows,
        'pagina'     => $page,
        'per_pagina' => $perPage,
        'totale'     => $total,
        'pagine'     => (int)ceil($total / $perPage),
    ];
}


function getAuthUser(Request $req): ?array {
    $auth = $req->getHeaderLine('Authorization');
    if (!$auth || !preg_match('/Bearer\s(\S+)/', $auth, $m)) return null;
    try {
        $decoded = JWT::decode($m[1], new Key(JWT_SECRET, 'HS256'));
        return (array)$decoded;
    } catch (Exception $e) { return null; }
}

/**
 * Controlla autenticazione e ruolo.
 * Se $ruolo è fornito, l'utente deve avere quel ruolo (oppure essere admin).
 * Ritorna Response con errore oppure null se ok.
 */
function requireAuth(Request $req, Response $res, ?string $ruolo = null): ?Response {
    $user = getAuthUser($req);
    if (!$user) return jsonRes($res, ['errore' => 'Non autenticato'], 401);
    if ($ruolo && $user['ruolo'] !== $ruolo && $user['ruolo'] !== 'admin') {
        return jsonRes($res, ['errore' => 'Non autorizzato'], 403);
    }
    return null;
}

$queries = [
    1  => "SELECT DISTINCT p.pnome FROM Pezzi p JOIN Catalogo c ON p.pid = c.pid",
    2  => "SELECT f.fnome FROM Fornitori f WHERE NOT EXISTS (SELECT * FROM Pezzi p WHERE NOT EXISTS (SELECT * FROM Catalogo c WHERE c.fid = f.fid AND c.pid = p.pid))",
    3  => "SELECT f.fnome FROM Fornitori f WHERE NOT EXISTS (SELECT * FROM Pezzi p WHERE p.colore = 'rosso' AND NOT EXISTS (SELECT * FROM Catalogo c WHERE c.fid = f.fid AND c.pid = p.pid))",
    4  => "SELECT p.pnome FROM Pezzi p JOIN Catalogo c ON p.pid = c.pid JOIN Fornitori f ON f.fid = c.fid WHERE f.fnome = 'Acme' AND NOT EXISTS (SELECT * FROM Catalogo c2 WHERE c2.pid = p.pid AND c2.fid <> f.fid)",
    5  => "SELECT DISTINCT c.fid FROM Catalogo c WHERE c.costo > (SELECT AVG(c2.costo) FROM Catalogo c2 WHERE c2.pid = c.pid)",
    6  => "SELECT p.pid, f.fnome FROM Pezzi p JOIN Catalogo c ON p.pid = c.pid JOIN Fornitori f ON f.fid = c.fid WHERE c.costo = (SELECT MAX(c2.costo) FROM Catalogo c2 WHERE c2.pid = p.pid)",
    7  => "SELECT f.fid FROM Fornitori f WHERE EXISTS (SELECT * FROM Catalogo c JOIN Pezzi p ON p.pid = c.pid WHERE c.fid = f.fid) AND NOT EXISTS (SELECT * FROM Catalogo c JOIN Pezzi p ON p.pid = c.pid WHERE c.fid = f.fid AND p.colore <> 'rosso')",
    8  => "SELECT f.fid FROM Fornitori f WHERE EXISTS (SELECT * FROM Catalogo c JOIN Pezzi p ON p.pid = c.pid WHERE c.fid = f.fid AND p.colore = 'rosso') AND EXISTS (SELECT * FROM Catalogo c JOIN Pezzi p ON p.pid = c.pid WHERE c.fid = f.fid AND p.colore = 'verde')",
    9  => "SELECT DISTINCT c.fid FROM Catalogo c JOIN Pezzi p ON p.pid = c.pid WHERE p.colore = 'rosso' OR p.colore = 'verde'",
    10 => "SELECT pid FROM Catalogo GROUP BY pid HAVING COUNT(DISTINCT fid) >= 2",
];


$app->add(function (Request $request, $handler) {
    if ($request->getMethod() === 'OPTIONS') {
        $response = new \Slim\Psr7\Response();
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization')
            ->withStatus(200);
    }
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization');
});


$app->addBodyParsingMiddleware();

$app->addRoutingMiddleware();


$app->addErrorMiddleware(true, true, true);


function configBar(): string {
    return '<hr class="section-divider"/>';
}

function renderPage(Response $res, string $activeQuery, string $pageContent): Response {
    $navItems = [
        'q1'  => ['label' => 'Pezzi con fornitori',         'num' => '01'],
        'q2'  => ['label' => 'Fornitori con tutti i pezzi', 'num' => '02'],
        'q3'  => ['label' => 'Fornitori pezzi rossi',       'num' => '03'],
        'q4'  => ['label' => 'Pezzi solo Acme',             'num' => '04'],
        'q5'  => ['label' => 'Fornitori sopra media',       'num' => '05'],
        'q6'  => ['label' => 'Fornitori max ricarico',      'num' => '06'],
        'q7'  => ['label' => 'Fornitori solo rossi',        'num' => '07'],
        'q8'  => ['label' => 'Fornitori rosso e verde',     'num' => '08'],
        'q9'  => ['label' => 'Fornitori rosso o verde',     'num' => '09'],
        'q10' => ['label' => 'Pezzi almeno 2 fornitori',    'num' => '10'],
    ];

    $navHtml = '';
    foreach ($navItems as $qid => $item) {
        $activeClass = ($qid === $activeQuery) ? 'active' : '';
        $navHtml .= '<li><a href="/frontend/' . $qid . '" class="' . $activeClass . '">';
        $navHtml .= '<span class="num">' . $item['num'] . '</span> ' . $item['label'];
        $navHtml .= '</a></li>' . "\n";
    }

    $css = '
    :root {
      --accent: #5ba08e; --accent-light: rgba(91,160,142,0.15); --bg: #141718;
      --surface: #1e2122; --sidebar-bg: #111314; --sidebar-text: #8a9399;
      --sidebar-active: #5ba08e; --border: #2a2e30; --text: #dde3e6;
      --muted: #606870; --danger: #e05c5c; --danger-bg: rgba(224,92,92,0.1);
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: var(--bg); color: var(--text); font-family: "Nunito", sans-serif; min-height: 100vh; display: flex; }
    #sidebar { width: 270px; min-height: 100vh; background: var(--sidebar-bg); display: flex; flex-direction: column; position: fixed; top: 0; left: 0; z-index: 100; transition: transform 0.3s; }
    .sidebar-header { padding: 28px 24px 20px; border-bottom: 1px solid rgba(255,255,255,0.08); }
    .sidebar-header h1 { font-size: 1.1rem; font-weight: 700; color: #ffffff; }
    .sidebar-header p { font-size: 0.78rem; color: var(--sidebar-text); margin-top: 4px; }
    .nav-list { list-style: none; padding: 12px 0; flex: 1; overflow-y: auto; }
    .nav-list li a { display: flex; align-items: center; gap: 12px; padding: 10px 24px; color: var(--sidebar-text); text-decoration: none; font-size: 0.88rem; font-weight: 600; transition: all 0.2s; border-left: 3px solid transparent; }
    .nav-list li a:hover { color: #ffffff; background: rgba(255,255,255,0.06); }
    .nav-list li a.active { color: var(--sidebar-active); border-left-color: var(--sidebar-active); background: rgba(129,199,184,0.1); }
    .nav-list li a .num { font-family: "Fira Code", monospace; font-size: 0.7rem; background: rgba(255,255,255,0.1); border-radius: 4px; padding: 2px 6px; min-width: 30px; text-align: center; flex-shrink: 0; }
    .nav-list li a.active .num { background: var(--sidebar-active); color: var(--sidebar-bg); }
    #main { margin-left: 270px; flex: 1; padding: 40px 48px 80px; min-height: 100vh; animation: fadeIn 0.2s ease; }
    .section-header { margin-bottom: 24px; }
    .section-header .tag { font-family: "Fira Code", monospace; font-size: 0.72rem; color: var(--accent); text-transform: uppercase; letter-spacing: 0.1em; background: var(--accent-light); display: inline-block; padding: 3px 10px; border-radius: 20px; margin-bottom: 8px; }
    .section-header h2 { font-size: 1.7rem; font-weight: 700; margin-bottom: 6px; color: var(--text); line-height: 1.3; }
    .section-header p { color: var(--muted); font-size: 0.93rem; max-width: 600px; line-height: 1.6; }
    .sql-block { background: #0e1a24; border-radius: 8px; padding: 16px 20px; font-family: "Fira Code", monospace; font-size: 0.8rem; color: #a8d8c8; margin-bottom: 20px; overflow-x: auto; white-space: pre; line-height: 1.8; }
    .btn-fetch { font-family: "Nunito", sans-serif; font-size: 0.9rem; font-weight: 700; background: var(--accent); color: #ffffff; border: none; border-radius: 8px; padding: 10px 28px; cursor: pointer; transition: background 0.2s, transform 0.1s; }
    .btn-fetch:hover { background: #3a6459; }
    .btn-fetch:active { transform: scale(0.98); }
    .btn-fetch:disabled { opacity: 0.5; cursor: not-allowed; }
    .section-divider { border: none; border-top: 1px solid var(--border); margin: 28px 0; }
    .result-area { margin-top: 24px; }
    .result-meta { font-size: 0.8rem; color: var(--muted); margin-bottom: 10px; font-family: "Fira Code", monospace; }
    .result-meta span { color: var(--accent); font-weight: 600; }
    .table-wrapper { border: 1px solid var(--border); border-radius: 8px; overflow: hidden; background: var(--surface); }
    table { width: 100%; border-collapse: collapse; }
    thead tr { background: #252a2c; border-bottom: 1px solid var(--border); }
    thead th { padding: 12px 16px; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--muted); font-weight: 700; text-align: left; }
    tbody tr { border-bottom: 1px solid var(--border); transition: background 0.15s; }
    tbody tr:last-child { border-bottom: none; }
    tbody tr:hover { background: rgba(91,160,142,0.05); }
    tbody td { padding: 11px 16px; font-size: 0.9rem; color: var(--text); }
    .empty-state { padding: 48px; text-align: center; color: var(--muted); font-size: 0.9rem; background: var(--surface); border-radius: 8px; border: 1px solid var(--border); }
    .error-state { padding: 16px 20px; background: var(--danger-bg); border: 1px solid rgba(224,92,92,0.3); border-radius: 8px; color: var(--danger); font-size: 0.85rem; }
    .spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid rgba(255,255,255,0.3); border-top-color: #ffffff; border-radius: 50%; animation: spin 0.7s linear infinite; vertical-align: middle; margin-right: 6px; }
    @keyframes spin { to { transform: rotate(360deg); } }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }
    #menu-toggle { display: none; position: fixed; top: 16px; left: 16px; z-index: 200; background: var(--accent); color: #ffffff; border: none; border-radius: 6px; padding: 8px 12px; font-size: 1.1rem; cursor: pointer; }
    @media (max-width: 768px) { #sidebar { transform: translateX(-100%); } #sidebar.open { transform: translateX(0); } #main { margin-left: 0; padding: 24px 16px 60px; padding-top: 70px; } #menu-toggle { display: block; } }
    /* ── Dashboard styles ── */
    .card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 20px; margin-bottom: 20px; }
    .toolbar { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
    .search-input { background: #252a2c; border: 1px solid var(--border); border-radius: 8px; padding: 8px 14px; color: var(--text); font-size: 0.85rem; outline: none; font-family: "Nunito", sans-serif; min-width: 200px; }
    .search-input:focus { border-color: var(--accent); }
    .btn-sm { font-size: 0.8rem; font-weight: 700; border: none; border-radius: 6px; padding: 7px 16px; cursor: pointer; font-family: "Nunito", sans-serif; transition: all 0.2s; }
    .btn-accent { background: var(--accent); color: #fff; }
    .btn-accent:hover { background: #3a6459; }
    .btn-danger { background: var(--danger-bg); color: var(--danger); border: 1px solid rgba(224,92,92,0.3); }
    .btn-danger:hover { background: rgba(224,92,92,0.2); }
    .btn-edit { background: rgba(91,160,142,0.1); color: var(--accent); border: 1px solid rgba(91,160,142,0.2); }
    .btn-edit:hover { background: rgba(91,160,142,0.2); }
    .btn-detail { background: rgba(255,255,255,0.05); color: var(--text); border: 1px solid var(--border); }
    .btn-detail:hover { background: rgba(255,255,255,0.1); }
    .pag { display: flex; align-items: center; gap: 8px; margin-top: 16px; justify-content: flex-end; flex-wrap: wrap; }
    .pag button { background: var(--surface); border: 1px solid var(--border); border-radius: 6px; padding: 5px 12px; color: var(--text); font-size: 0.8rem; cursor: pointer; font-family: "Nunito", sans-serif; transition: all 0.2s; }
    .pag button:hover:not(:disabled) { border-color: var(--accent); color: var(--accent); }
    .pag button:disabled { opacity: 0.4; cursor: not-allowed; }
    .pag button.active { background: var(--accent); border-color: var(--accent); color: #fff; }
    .pag-info { font-size: 0.78rem; color: var(--muted); margin-right: auto; }
    .dlg-wrap { position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 1000; display: flex; align-items: center; justify-content: center; animation: fadeIn 0.15s; }
    .dlg { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 28px; width: 480px; max-width: 95vw; max-height: 85vh; overflow-y: auto; position: relative; }
    .dlg h3 { font-size: 1.1rem; font-weight: 700; margin-bottom: 20px; padding-right: 30px; }
    .dlg-close { position: absolute; top: 16px; right: 16px; background: transparent; border: none; color: var(--muted); font-size: 1.2rem; cursor: pointer; }
    .dlg-close:hover { color: var(--text); }
    .det-row { display: flex; gap: 12px; margin-bottom: 12px; }
    .det-lbl { font-size: 0.75rem; color: var(--muted); font-weight: 700; text-transform: uppercase; min-width: 90px; padding-top: 2px; }
    .det-val { font-size: 0.9rem; }
    .ff { margin-bottom: 14px; }
    .ff label { display: block; font-size: 0.75rem; font-weight: 700; color: var(--muted); text-transform: uppercase; margin-bottom: 5px; }
    .ff input, .ff select { width: 100%; background: #252a2c; border: 1px solid var(--border); border-radius: 7px; padding: 9px 12px; color: var(--text); font-size: 0.88rem; outline: none; font-family: "Nunito", sans-serif; }
    .ff input:focus, .ff select:focus { border-color: var(--accent); }
    .ff select option { background: #252a2c; }
    .dlg-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; }
    .alert { padding: 10px 16px; border-radius: 8px; font-size: 0.85rem; margin-bottom: 14px; }
    .alert-ok  { background: rgba(91,160,142,0.15); border: 1px solid rgba(91,160,142,0.3); color: var(--accent); }
    .alert-err { background: var(--danger-bg); border: 1px solid rgba(224,92,92,0.3); color: var(--danger); }
    .tag-pill { display: inline-block; font-size: 0.65rem; font-weight: 700; padding: 2px 8px; border-radius: 20px; text-transform: uppercase; }
    .tag-admin { background: rgba(224,92,92,0.15); color: #e05c5c; }
    .tag-forn  { background: var(--accent-light); color: var(--accent); }
    ';

    $js = '
    /* ── QUERY ORIGINALI ── */
    async function fetchData(endpoint, btn, resultId) {
        const resultDiv = document.getElementById(resultId);
        btn.disabled = true;
        btn.innerHTML = "<span class=\"spinner\"></span> Caricamento...";
        resultDiv.innerHTML = "";
        try {
            const res = await fetch(endpoint, { headers: { "Accept": "application/json" } });
            if (!res.ok) throw new Error("HTTP " + res.status + " - " + res.statusText);
            const data = await res.json();
            renderTable(resultDiv, data);
        } catch (err) {
            resultDiv.innerHTML = "<div class=\"error-state\">\u26a0 Errore: " + err.message + "</div>";
        } finally {
            btn.disabled = false;
            btn.innerHTML = "\u25b6 Esegui Query";
        }
    }
    function renderTable(container, data) {
        if (!Array.isArray(data) || data.length === 0) {
            container.innerHTML = "<div class=\"empty-state\">Nessun risultato trovato.</div>";
            return;
        }
        const cols = Object.keys(data[0]);
        let html = "<div class=\"result-meta\">Risultati: <span>" + data.length + "</span> righe</div>";
        html += "<div class=\"table-wrapper\"><table><thead><tr>";
        cols.forEach(c => { html += "<th>" + c + "</th>"; });
        html += "</tr></thead><tbody>";
        data.forEach(row => {
            html += "<tr>";
            cols.forEach(c => { html += "<td>" + (row[c] ?? "\u2014") + "</td>"; });
            html += "</tr>";
        });
        html += "</tbody></table></div>";
        container.innerHTML = html;
    }

    const TOKEN_KEY = "cat_jwt";
    function getToken() { return localStorage.getItem(TOKEN_KEY); }
    function setToken(t) { localStorage.setItem(TOKEN_KEY, t); }
    function clearToken() { localStorage.removeItem(TOKEN_KEY); }


    async function apiCall(url, method="GET", body=null) {
        const headers = { "Content-Type": "application/json" };
        const tok = getToken();
        if (tok) headers["Authorization"] = "Bearer " + tok;
        const opts = { method, headers };
        if (body !== null) opts.body = JSON.stringify(body);
        const r = await fetch(url, opts);
        const d = await r.json();
        if (!r.ok) throw new Error(d.errore || "Errore " + r.status);
        return d;
    }

    function showAlert(msg, type="ok") {
        const el = document.createElement("div");
        el.className = "alert alert-" + type;
        el.textContent = msg;
        const pc = document.getElementById("dash-content");
        if (pc) { pc.prepend(el); setTimeout(() => el.remove(), 3000); }
    }

    function openDlg(html) {
        let w = document.getElementById("dlg-root");
        if (!w) { w = document.createElement("div"); w.id = "dlg-root"; document.body.appendChild(w); }
        w.innerHTML = "<div class=\"dlg-wrap\" onclick=\"if(event.target===this)closeDlg()\"><div class=\"dlg\">" + html + "</div></div>";
    }
    function closeDlg() {
        const w = document.getElementById("dlg-root");
        if (w) w.innerHTML = "";
    }
    function detailDlg(title, fields) {
        const rows = fields.map(([k,v]) =>
            "<div class=\"det-row\"><span class=\"det-lbl\">" + k + "</span><span class=\"det-val\">" + (v ?? "\u2014") + "</span></div>"
        ).join("");
        openDlg("<button class=\"dlg-close\" onclick=\"closeDlg()\">\u2715</button><h3>" + title + "</h3>" + rows);
    }

    function renderPag(container, r, cbStr) {
        const { pagina: pg, pagine: tot, totale, per_pagina: pp } = r;
        const from = (pg-1)*pp+1, to = Math.min(pg*pp, totale);
        let h = "<div class=\"pag\"><span class=\"pag-info\">Mostrando " + from + "\u2013" + to + " di " + totale + "</span>";
        h += "<button onclick=\"" + cbStr + "(" + (pg-1) + ")\" " + (pg<=1?"disabled":"") + ">\u2190 Prec</button>";
        const s = Math.max(1,pg-2), e = Math.min(tot,s+4);
        for(let p=s;p<=e;p++) h += "<button class=\"" + (p===pg?"active":"") + "\" onclick=\"" + cbStr + "(" + p + ")\">" + p + "</button>";
        h += "<button onclick=\"" + cbStr + "(" + (pg+1) + ")\" " + (pg>=tot?"disabled":"") + ">Succ \u2192</button></div>";
        container.insertAdjacentHTML("beforeend", h);
    }

    window._store = {};
    function storeObj(obj) {
        const k = "k" + Date.now() + Math.random().toString(36).slice(2);
        window._store[k] = obj;
        return k;
    }

    let pzPg=1, pzQ="";
    async function loadPezzi() {
        try {
            const d = await apiCall("/api/pezzi?page=" + pzPg + "&q=" + encodeURIComponent(pzQ));
            const c = document.getElementById("pz-tbl");
            if (!d.data || !d.data.length) { c.innerHTML = "<div class=\"empty-state\">Nessun pezzo trovato</div>"; return; }
            let h = "<div class=\"table-wrapper\"><table><thead><tr><th>ID</th><th>Nome</th><th>Colore</th><th>Peso</th><th>Azioni</th></tr></thead><tbody>";
            d.data.forEach(p => {
                const dk = storeObj([["ID",p.pid],["Nome",p.pnome],["Colore",p.colore||"\u2014"],["Peso",p.peso||"\u2014"]]);
                const ek = storeObj(p);
                h += "<tr><td><code>" + p.pid + "</code></td><td>" + p.pnome + "</td><td>" + (p.colore||"\u2014") + "</td><td>" + (p.peso||"\u2014") + "</td>";
                h += "<td style=\"display:flex;gap:6px\">";
                h += "<button class=\"btn-sm btn-detail\" onclick=\"detailDlg(\'Dettaglio Pezzo\',window._store[\'"+dk+"\'])\">&#128065;</button>";
                h += "<button class=\"btn-sm btn-edit\" onclick=\"editPezzo(window._store[\'"+ek+"\'])\">&#9999;</button>";
                h += "<button class=\"btn-sm btn-danger\" onclick=\"delPezzo("+p.pid+")\">&#128465;</button>";
                h += "</td></tr>";
            });
            h += "</tbody></table></div>";
            c.innerHTML = h;
            renderPag(c, d, "function(p){pzPg=p;loadPezzi()}");
        } catch(e) { showAlert(e.message,"err"); }
    }
    function addPezzo() {
        openDlg(
            "<button class=\"dlg-close\" onclick=\"closeDlg()\">\u2715</button>" +
            "<h3>Aggiungi Pezzo</h3>" +
            "<div class=\"ff\"><label>Nome *</label><input id=\"f-pn\" placeholder=\"Nome pezzo\"/></div>" +
            "<div class=\"ff\"><label>Colore</label><input id=\"f-co\" placeholder=\"es. rosso\"/></div>" +
            "<div class=\"ff\"><label>Peso</label><input id=\"f-pe\" type=\"number\" step=\"0.1\"/></div>" +
            "<div class=\"dlg-actions\"><button class=\"btn-sm\" onclick=\"closeDlg()\">Annulla</button><button class=\"btn-sm btn-accent\" onclick=\"savePezzo()\">Salva</button></div>"
        );
    }
    function editPezzo(p) {
        openDlg(
            "<button class=\"dlg-close\" onclick=\"closeDlg()\">\u2715</button>" +
            "<h3>Modifica Pezzo #" + p.pid + "</h3>" +
            "<div class=\"ff\"><label>Nome *</label><input id=\"f-pn\" value=\"" + p.pnome + "\"/></div>" +
            "<div class=\"ff\"><label>Colore</label><input id=\"f-co\" value=\"" + (p.colore||"") + "\"/></div>" +
            "<div class=\"ff\"><label>Peso</label><input id=\"f-pe\" type=\"number\" step=\"0.1\" value=\"" + (p.peso||"") + "\"/></div>" +
            "<div class=\"dlg-actions\"><button class=\"btn-sm\" onclick=\"closeDlg()\">Annulla</button><button class=\"btn-sm btn-accent\" onclick=\"savePezzo(" + p.pid + ")\">Salva</button></div>"
        );
    }
    async function savePezzo(pid=null) {
        const b = {
            pnome:  document.getElementById("f-pn").value.trim(),
            colore: document.getElementById("f-co").value.trim() || null,
            peso:   document.getElementById("f-pe").value || null
        };
        if (!b.pnome) { showAlert("Il nome è obbligatorio","err"); return; }
        try {
            await apiCall(pid ? "/api/pezzi/"+pid : "/api/pezzi", pid?"PUT":"POST", b);
            closeDlg();
            showAlert(pid ? "Pezzo aggiornato" : "Pezzo creato");
            pzPg=1; loadPezzi();
        } catch(e) { showAlert(e.message,"err"); }
    }
    async function delPezzo(pid) {
        if (!confirm("Eliminare il pezzo #" + pid + "?")) return;
        try {
            await apiCall("/api/pezzi/"+pid, "DELETE");
            showAlert("Pezzo eliminato");
            loadPezzi();
        } catch(e) { showAlert(e.message,"err"); }
    }


    let fnPg=1, fnQ="";
    async function loadFornitori() {
        try {
            const d = await apiCall("/api/fornitori?page=" + fnPg + "&q=" + encodeURIComponent(fnQ));
            const c = document.getElementById("fn-tbl");
            if (!d.data || !d.data.length) { c.innerHTML = "<div class=\"empty-state\">Nessun fornitore</div>"; return; }
            let h = "<div class=\"table-wrapper\"><table><thead><tr><th>ID</th><th>Nome</th><th>Citt&agrave;</th><th>Azioni</th></tr></thead><tbody>";
            d.data.forEach(f => {
                const dk = storeObj([["ID",f.fid],["Nome",f.fnome],["Citt\u00e0",f.citta||"\u2014"]]);
                const ek = storeObj(f);
                h += "<tr><td><code>" + f.fid + "</code></td><td>" + f.fnome + "</td><td>" + (f.citta||"\u2014") + "</td>";
                h += "<td style=\"display:flex;gap:6px\">";
                h += "<button class=\"btn-sm btn-detail\" onclick=\"detailDlg(\'Dettaglio Fornitore\',window._store[\'"+dk+"\'])\">&#128065;</button>";
                h += "<button class=\"btn-sm btn-edit\" onclick=\"editFornitore(window._store[\'"+ek+"\'])\">&#9999;</button>";
                h += "<button class=\"btn-sm btn-danger\" onclick=\"delFornitore("+f.fid+")\">&#128465;</button>";
                h += "</td></tr>";
            });
            h += "</tbody></table></div>";
            c.innerHTML = h;
            renderPag(c, d, "function(p){fnPg=p;loadFornitori()}");
        } catch(e) { showAlert(e.message,"err"); }
    }
    function addFornitore() {
        openDlg(
            "<button class=\"dlg-close\" onclick=\"closeDlg()\">\u2715</button>" +
            "<h3>Aggiungi Fornitore</h3>" +
            "<div class=\"ff\"><label>Nome *</label><input id=\"f-fn\" placeholder=\"Nome fornitore\"/></div>" +
            "<div class=\"ff\"><label>Citt\u00e0</label><input id=\"f-ci\" placeholder=\"es. Milano\"/></div>" +
            "<div class=\"dlg-actions\"><button class=\"btn-sm\" onclick=\"closeDlg()\">Annulla</button><button class=\"btn-sm btn-accent\" onclick=\"saveFornitore()\">Salva</button></div>"
        );
    }
    function editFornitore(f) {
        openDlg(
            "<button class=\"dlg-close\" onclick=\"closeDlg()\">\u2715</button>" +
            "<h3>Modifica Fornitore #" + f.fid + "</h3>" +
            "<div class=\"ff\"><label>Nome *</label><input id=\"f-fn\" value=\"" + f.fnome + "\"/></div>" +
            "<div class=\"ff\"><label>Citt\u00e0</label><input id=\"f-ci\" value=\"" + (f.citta||"") + "\"/></div>" +
            "<div class=\"dlg-actions\"><button class=\"btn-sm\" onclick=\"closeDlg()\">Annulla</button><button class=\"btn-sm btn-accent\" onclick=\"saveFornitore(" + f.fid + ")\">Salva</button></div>"
        );
    }
    async function saveFornitore(fid=null) {
        const b = {
            fnome: document.getElementById("f-fn").value.trim(),
            citta: document.getElementById("f-ci").value.trim() || null
        };
        if (!b.fnome) { showAlert("Il nome è obbligatorio","err"); return; }
        try {
            await apiCall(fid ? "/api/fornitori/"+fid : "/api/fornitori", fid?"PUT":"POST", b);
            closeDlg();
            showAlert(fid ? "Fornitore aggiornato" : "Fornitore creato");
            fnPg=1; loadFornitori();
        } catch(e) { showAlert(e.message,"err"); }
    }
    async function delFornitore(fid) {
        if (!confirm("Eliminare il fornitore #" + fid + "?")) return;
        try {
            await apiCall("/api/fornitori/"+fid, "DELETE");
            showAlert("Fornitore eliminato");
            loadFornitori();
        } catch(e) { showAlert(e.message,"err"); }
    }
    let caPg=1;
    async function loadCatalogo() {
        try {
            const d = await apiCall("/api/catalogo?page=" + caPg);
            const c = document.getElementById("ca-tbl");
            if (!d.data || !d.data.length) { c.innerHTML = "<div class=\"empty-state\">Catalogo vuoto</div>"; return; }
            let h = "<div class=\"table-wrapper\"><table><thead><tr><th>Fornitore</th><th>Pezzo</th><th>Colore</th><th>Costo</th><th>Azioni</th></tr></thead><tbody>";
            d.data.forEach(r => {
                const dk = storeObj([["Fornitore",r.fnome+" #"+r.fid],["Pezzo",r.pnome+" #"+r.pid],["Colore",r.colore||"\u2014"],["Costo","\u20ac"+r.costo]]);
                h += "<tr>";
                h += "<td>" + r.fnome + " <code style=\"font-size:.7rem;color:var(--muted)\">#" + r.fid + "</code></td>";
                h += "<td>" + r.pnome + " <code style=\"font-size:.7rem;color:var(--muted)\">#" + r.pid + "</code></td>";
                h += "<td>" + (r.colore||"\u2014") + "</td><td>\u20ac" + r.costo + "</td>";
                h += "<td style=\"display:flex;gap:6px\">";
                h += "<button class=\"btn-sm btn-detail\" onclick=\"detailDlg(\'Dettaglio Catalogo\',window._store[\'"+dk+"\'])\">&#128065;</button>";
                h += "<button class=\"btn-sm btn-edit\" onclick=\"editCatalogo("+r.fid+","+r.pid+","+r.costo+")\">&#9999;</button>";
                h += "<button class=\"btn-sm btn-danger\" onclick=\"delCatalogo("+r.fid+","+r.pid+")\">&#128465;</button>";
                h += "</td></tr>";
            });
            h += "</tbody></table></div>";
            c.innerHTML = h;
            renderPag(c, d, "function(p){caPg=p;loadCatalogo()}");
        } catch(e) { showAlert(e.message,"err"); }
    }
    function addCatalogo() {
        openDlg(
            "<button class=\"dlg-close\" onclick=\"closeDlg()\">\u2715</button>" +
            "<h3>Aggiungi voce catalogo</h3>" +
            "<div class=\"ff\"><label>FID Fornitore *</label><input id=\"f-fid\" type=\"number\"/></div>" +
            "<div class=\"ff\"><label>PID Pezzo *</label><input id=\"f-pid\" type=\"number\"/></div>" +
            "<div class=\"ff\"><label>Costo *</label><input id=\"f-cos\" type=\"number\" step=\"0.01\"/></div>" +
            "<div class=\"dlg-actions\"><button class=\"btn-sm\" onclick=\"closeDlg()\">Annulla</button><button class=\"btn-sm btn-accent\" onclick=\"saveCatalogo()\">Salva</button></div>"
        );
    }
    function editCatalogo(fid,pid,costo) {
        openDlg(
            "<button class=\"dlg-close\" onclick=\"closeDlg()\">\u2715</button>" +
            "<h3>Modifica costo (F#"+fid+" / P#"+pid+")</h3>" +
            "<div class=\"ff\"><label>Costo *</label><input id=\"f-cos\" type=\"number\" step=\"0.01\" value=\"" + costo + "\"/></div>" +
            "<div class=\"dlg-actions\"><button class=\"btn-sm\" onclick=\"closeDlg()\">Annulla</button><button class=\"btn-sm btn-accent\" onclick=\"saveCatalogo(" + fid + "," + pid + ")\">Salva</button></div>"
        );
    }
    async function saveCatalogo(fid=null, pid=null) {
        try {
            if (fid && pid) {
                await apiCall("/api/catalogo/"+fid+"/"+pid, "PUT", { costo: parseFloat(document.getElementById("f-cos").value) });
            } else {
                await apiCall("/api/catalogo", "POST", {
                    fid:   parseInt(document.getElementById("f-fid").value),
                    pid:   parseInt(document.getElementById("f-pid").value),
                    costo: parseFloat(document.getElementById("f-cos").value)
                });
            }
            closeDlg();
            showAlert(fid ? "Costo aggiornato" : "Voce aggiunta");
            loadCatalogo();
        } catch(e) { showAlert(e.message,"err"); }
    }
    async function delCatalogo(fid,pid) {
        if (!confirm("Rimuovere dal catalogo?")) return;
        try {
            await apiCall("/api/catalogo/"+fid+"/"+pid, "DELETE");
            showAlert("Rimosso dal catalogo");
            loadCatalogo();
        } catch(e) { showAlert(e.message,"err"); }
    }

    let utPg=1;
    async function loadUtenti() {
        try {
            const d = await apiCall("/api/utenti?page=" + utPg);
            const c = document.getElementById("ut-tbl");
            if (!d.data || !d.data.length) { c.innerHTML = "<div class=\"empty-state\">Nessun utente</div>"; return; }
            let h = "<div class=\"table-wrapper\"><table><thead><tr><th>ID</th><th>Username</th><th>Ruolo</th><th>Fornitore</th><th>Creato il</th><th>Azioni</th></tr></thead><tbody>";
            d.data.forEach(u => {
                const tag  = u.ruolo==="admin" ? "tag-admin" : "tag-forn";
                const fLbl = u.fnome ? u.fnome+" #"+u.fid : "\u2014";
                const dk   = storeObj([["ID",u.uid],["Username",u.username],["Ruolo",u.ruolo],["Fornitore",fLbl],["Creato il",new Date(u.creato_il).toLocaleString("it")]]);
                h += "<tr>";
                h += "<td><code>" + u.uid + "</code></td>";
                h += "<td>" + u.username + "</td>";
                h += "<td><span class=\"tag-pill " + tag + "\">" + u.ruolo + "</span></td>";
                h += "<td>" + fLbl + "</td>";
                h += "<td style=\"color:var(--muted);font-size:.8rem\">" + new Date(u.creato_il).toLocaleDateString("it") + "</td>";
                h += "<td style=\"display:flex;gap:6px\">";
                h += "<button class=\"btn-sm btn-detail\" onclick=\"detailDlg(\'Dettaglio Utente\',window._store[\'"+dk+"\'])\">&#128065;</button>";
                h += "<button class=\"btn-sm btn-danger\" onclick=\"delUtente("+u.uid+")\">&#128465;</button>";
                h += "</td></tr>";
            });
            h += "</tbody></table></div>";
            c.innerHTML = h;
            renderPag(c, d, "function(p){utPg=p;loadUtenti()}");
        } catch(e) { showAlert(e.message,"err"); }
    }
    function addUtente() {
        openDlg(
            "<button class=\"dlg-close\" onclick=\"closeDlg()\">\u2715</button>" +
            "<h3>Nuovo Utente</h3>" +
            "<div class=\"ff\"><label>Username *</label><input id=\"f-un\" placeholder=\"username\"/></div>" +
            "<div class=\"ff\"><label>Password *</label><input id=\"f-pw\" type=\"password\"/></div>" +
            "<div class=\"ff\"><label>Ruolo *</label><select id=\"f-ro\" onchange=\"toggleFidWrap(this.value)\"><option value=\"fornitore\">Fornitore</option><option value=\"admin\">Admin</option></select></div>" +
            "<div class=\"ff\" id=\"fid-wrap\"><label>FID Fornitore *</label><input id=\"f-fi\" type=\"number\" placeholder=\"ID del fornitore\"/></div>" +
            "<div class=\"dlg-actions\"><button class=\"btn-sm\" onclick=\"closeDlg()\">Annulla</button><button class=\"btn-sm btn-accent\" onclick=\"saveUtente()\">Crea</button></div>"
        );
    }
    function toggleFidWrap(val) {
        const el = document.getElementById("fid-wrap");
        if (el) el.style.display = (val === "fornitore") ? "block" : "none";
    }
    async function saveUtente() {
        const ro  = document.getElementById("f-ro").value;
        const fid = ro === "fornitore" ? document.getElementById("f-fi").value : null;
        if (ro === "fornitore" && !fid) { showAlert("FID obbligatorio per ruolo fornitore","err"); return; }
        const b = {
            username: document.getElementById("f-un").value.trim(),
            password: document.getElementById("f-pw").value,
            ruolo: ro,
            fid: fid ? parseInt(fid) : null
        };
        if (!b.username || !b.password) { showAlert("Username e password obbligatori","err"); return; }
        try {
            await apiCall("/api/auth/register", "POST", b);
            closeDlg();
            showAlert("Utente creato con successo");
            loadUtenti();
        } catch(e) { showAlert(e.message,"err"); }
    }
    async function delUtente(uid) {
        if (!confirm("Eliminare l\'utente #" + uid + "?")) return;
        try {
            await apiCall("/api/utenti/"+uid, "DELETE");
            showAlert("Utente eliminato");
            loadUtenti();
        } catch(e) { showAlert(e.message,"err"); }
    }

    let mcPg=1;
    async function loadMioCatalogo(fid) {
        try {
            const d = await apiCall("/api/catalogo?page=" + mcPg + "&fid=" + fid);
            const c = document.getElementById("mc-tbl");
            if (!d.data || !d.data.length) { c.innerHTML = "<div class=\"empty-state\">Il tuo catalogo \u00e8 vuoto. Aggiungi il primo pezzo!</div>"; return; }
            let h = "<div class=\"table-wrapper\"><table><thead><tr><th>Pezzo</th><th>Colore</th><th>Costo</th><th>Azioni</th></tr></thead><tbody>";
            d.data.forEach(r => {
                const dk = storeObj([["Pezzo",r.pnome+" #"+r.pid],["Colore",r.colore||"\u2014"],["Costo","\u20ac"+r.costo]]);
                h += "<tr>";
                h += "<td>" + r.pnome + " <code style=\"font-size:.7rem;color:var(--muted)\">#" + r.pid + "</code></td>";
                h += "<td>" + (r.colore||"\u2014") + "</td>";
                h += "<td>\u20ac" + r.costo + "</td>";
                h += "<td style=\"display:flex;gap:6px\">";
                h += "<button class=\"btn-sm btn-detail\" onclick=\"detailDlg(\'Dettaglio\',window._store[\'"+dk+"\'])\">&#128065;</button>";
                h += "<button class=\"btn-sm btn-edit\" onclick=\"editMioCatalogo("+r.pid+","+r.costo+")\">&#9999;</button>";
                h += "<button class=\"btn-sm btn-danger\" onclick=\"delMioCatalogo("+fid+","+r.pid+")\">&#128465;</button>";
                h += "</td></tr>";
            });
            h += "</tbody></table></div>";
            c.innerHTML = h;
            renderPag(c, d, "function(p){mcPg=p;loadMioCatalogo("+fid+")}");
        } catch(e) { showAlert(e.message,"err"); }
    }
    function addMioCatalogo(fid) {
        openDlg(
            "<button class=\"dlg-close\" onclick=\"closeDlg()\">\u2715</button>" +
            "<h3>Aggiungi pezzo al catalogo</h3>" +
            "<div class=\"ff\"><label>PID Pezzo *</label><input id=\"f-pid\" type=\"number\" placeholder=\"ID del pezzo\"/></div>" +
            "<div class=\"ff\"><label>Costo *</label><input id=\"f-cos\" type=\"number\" step=\"0.01\" placeholder=\"0.00\"/></div>" +
            "<div class=\"dlg-actions\"><button class=\"btn-sm\" onclick=\"closeDlg()\">Annulla</button><button class=\"btn-sm btn-accent\" onclick=\"saveMioCatalogo("+fid+")\">Salva</button></div>"
        );
    }
    function editMioCatalogo(pid, costo) {
        const fid = window._myFid;
        openDlg(
            "<button class=\"dlg-close\" onclick=\"closeDlg()\">\u2715</button>" +
            "<h3>Modifica costo pezzo #" + pid + "</h3>" +
            "<div class=\"ff\"><label>Nuovo costo *</label><input id=\"f-cos\" type=\"number\" step=\"0.01\" value=\"" + costo + "\"/></div>" +
            "<div class=\"dlg-actions\"><button class=\"btn-sm\" onclick=\"closeDlg()\">Annulla</button><button class=\"btn-sm btn-accent\" onclick=\"saveMioCatalogo("+fid+","+pid+")\">Salva</button></div>"
        );
    }
    async function saveMioCatalogo(fid, pid=null) {
        const costo = parseFloat(document.getElementById("f-cos").value);
        if (isNaN(costo)) { showAlert("Costo non valido","err"); return; }
        try {
            if (pid) {
                await apiCall("/api/catalogo/"+fid+"/"+pid, "PUT", { costo });
                showAlert("Costo aggiornato");
            } else {
                const pidVal = parseInt(document.getElementById("f-pid").value);
                if (!pidVal) { showAlert("PID non valido","err"); return; }
                await apiCall("/api/catalogo", "POST", { fid, pid: pidVal, costo });
                showAlert("Pezzo aggiunto al catalogo");
            }
            closeDlg();
            loadMioCatalogo(fid);
        } catch(e) { showAlert(e.message,"err"); }
    }
    async function delMioCatalogo(fid, pid) {
        if (!confirm("Rimuovere il pezzo #"+pid+" dal tuo catalogo?")) return;
        try {
            await apiCall("/api/catalogo/"+fid+"/"+pid, "DELETE");
            showAlert("Pezzo rimosso dal catalogo");
            loadMioCatalogo(fid);
        } catch(e) { showAlert(e.message,"err"); }
    }
    ';

    $html  = '<!DOCTYPE html><html lang="it"><head>';
    $html .= '<meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>';
    $html .= '<title>Catalogo Fornitori</title>';
    $html .= '<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&family=Fira+Code:wght@400;500&display=swap" rel="stylesheet"/>';
    $html .= '<style>' . $css . '</style></head><body>';
    $html .= '<button id="menu-toggle" onclick="document.getElementById(\'sidebar\').classList.toggle(\'open\')">&#9776;</button>';
    $html .= '<nav id="sidebar"><div class="sidebar-header"><h1>Catalogo DB</h1><p>10 endpoint &middot; Slim 4</p></div>';
    $html .= '<ul class="nav-list">' . $navHtml . '</ul>';
    $html .= '<div style="padding:16px 24px;border-top:1px solid rgba(255,255,255,0.06)">';
    $html .= '<a href="/dashboard/login" style="color:var(--sidebar-text);text-decoration:none;font-size:.8rem;font-weight:600">&#128274; Dashboard</a></div>';
    $html .= '</nav>';
    $html .= '<main id="main">' . $pageContent . '</main>';
    $html .= '<script>' . $js . '</script></body></html>';

    $res->getBody()->write($html);
    return $res->withHeader('Content-Type', 'text/html');
}



$app->post('/api/auth/login', function (Request $req, Response $res) use ($pdo) {
    $b = (array)$req->getParsedBody();
    $username = trim($b['username'] ?? '');
    $password = $b['password'] ?? '';
    if (!$username || !$password)
        return jsonRes($res, ['errore' => 'Credenziali mancanti'], 400);
    $user = queryOne($pdo, 'SELECT * FROM Utenti WHERE username = ?', [$username]);
    if (!$user || !password_verify($password, $user['password']))
        return jsonRes($res, ['errore' => 'Credenziali non valide'], 401);
    $payload = [
        'uid'      => $user['uid'],
        'username' => $user['username'],
        'ruolo'    => $user['ruolo'],
        'fid'      => $user['fid'],
        'exp'      => time() + 3600 * 8,
    ];
    $token = JWT::encode($payload, JWT_SECRET, 'HS256');
    return jsonRes($res, [
        'token'    => $token,
        'ruolo'    => $user['ruolo'],
        'username' => $user['username'],
        'fid'      => $user['fid'],
    ]);
});

$app->post('/api/auth/register', function (Request $req, Response $res) use ($pdo) {
    if ($err = requireAuth($req, $res, 'admin')) return $err;
    $b        = (array)$req->getParsedBody();
    $username = trim($b['username'] ?? '');
    $password = $b['password'] ?? '';
    $ruolo    = $b['ruolo'] ?? 'fornitore';
    $fid      = isset($b['fid']) && $b['fid'] !== '' ? (int)$b['fid'] : null;
    if (!$username || !$password)
        return jsonRes($res, ['errore' => 'Username e password obbligatori'], 400);
    if ($ruolo === 'fornitore' && !$fid)
        return jsonRes($res, ['errore' => 'fid obbligatorio per ruolo fornitore'], 400);
    try {
        $pdo->prepare('INSERT INTO Utenti (username, password, ruolo, fid) VALUES (?,?,?,?)')
            ->execute([$username, password_hash($password, PASSWORD_BCRYPT), $ruolo, $fid]);
        return jsonRes($res, ['messaggio' => 'Utente creato'], 201);
    } catch (PDOException $e) {
        return jsonRes($res, ['errore' => 'Username già esistente'], 409);
    }
});


$app->get('/api/pezzi', function (Request $req, Response $res) use ($pdo) {
    $p      = $req->getQueryParams();
    $page   = max(1, (int)($p['page'] ?? 1));
    $q      = '%' . ($p['q'] ?? '') . '%';
    $total  = (int)queryOne($pdo, 'SELECT COUNT(*) as t FROM Pezzi WHERE pnome LIKE ?', [$q])['t'];
    $offset = ($page - 1) * 10;
    $rows   = queryDB($pdo, 'SELECT * FROM Pezzi WHERE pnome LIKE ? ORDER BY pid LIMIT 10 OFFSET ' . $offset, [$q]);
    return jsonRes($res, [
        'data'       => $rows,
        'totale'     => $total,
        'pagina'     => $page,
        'per_pagina' => 10,
        'pagine'     => (int)ceil($total / 10),
    ]);
});

$app->get('/api/pezzi/{id}', function (Request $req, Response $res, array $args) use ($pdo) {
    $p = queryOne($pdo, 'SELECT * FROM Pezzi WHERE pid = ?', [(int)$args['id']]);
    if (!$p) return jsonRes($res, ['errore' => 'Pezzo non trovato'], 404);
    return jsonRes($res, $p);
});

$app->post('/api/pezzi', function (Request $req, Response $res) use ($pdo) {
    if ($err = requireAuth($req, $res, 'admin')) return $err;
    $b = (array)$req->getParsedBody();
    if (empty(trim($b['pnome'] ?? '')))
        return jsonRes($res, ['errore' => 'pnome obbligatorio'], 400);
    $pdo->prepare('INSERT INTO Pezzi (pnome, colore, peso) VALUES (?,?,?)')
        ->execute([trim($b['pnome']), $b['colore'] ?? null, $b['peso'] ?? null]);
    return jsonRes($res, ['messaggio' => 'Pezzo creato', 'pid' => (int)$pdo->lastInsertId()], 201);
});

$app->put('/api/pezzi/{id}', function (Request $req, Response $res, array $args) use ($pdo) {
    if ($err = requireAuth($req, $res, 'admin')) return $err;
    $b = (array)$req->getParsedBody();
    if (empty(trim($b['pnome'] ?? '')))
        return jsonRes($res, ['errore' => 'pnome obbligatorio'], 400);
    $pdo->prepare('UPDATE Pezzi SET pnome=?, colore=?, peso=? WHERE pid=?')
        ->execute([trim($b['pnome']), $b['colore'] ?? null, $b['peso'] ?? null, (int)$args['id']]);
    return jsonRes($res, ['messaggio' => 'Pezzo aggiornato']);
});

$app->delete('/api/pezzi/{id}', function (Request $req, Response $res, array $args) use ($pdo) {
    if ($err = requireAuth($req, $res, 'admin')) return $err;
    $pdo->prepare('DELETE FROM Pezzi WHERE pid=?')->execute([(int)$args['id']]);
    return jsonRes($res, ['messaggio' => 'Pezzo eliminato']);
});


$app->get('/api/fornitori', function (Request $req, Response $res) use ($pdo) {
    $p      = $req->getQueryParams();
    $page   = max(1, (int)($p['page'] ?? 1));
    $q      = '%' . ($p['q'] ?? '') . '%';
    $total  = (int)queryOne($pdo, 'SELECT COUNT(*) as t FROM Fornitori WHERE fnome LIKE ?', [$q])['t'];
    $offset = ($page - 1) * 10;
    $rows   = queryDB($pdo, 'SELECT * FROM Fornitori WHERE fnome LIKE ? ORDER BY fid LIMIT 10 OFFSET ' . $offset, [$q]);
    return jsonRes($res, [
        'data'       => $rows,
        'totale'     => $total,
        'pagina'     => $page,
        'per_pagina' => 10,
        'pagine'     => (int)ceil($total / 10),
    ]);
});

$app->get('/api/fornitori/{id}', function (Request $req, Response $res, array $args) use ($pdo) {
    $f = queryOne($pdo, 'SELECT * FROM Fornitori WHERE fid = ?', [(int)$args['id']]);
    if (!$f) return jsonRes($res, ['errore' => 'Fornitore non trovato'], 404);
    return jsonRes($res, $f);
});

$app->post('/api/fornitori', function (Request $req, Response $res) use ($pdo) {
    if ($err = requireAuth($req, $res, 'admin')) return $err;
    $b = (array)$req->getParsedBody();
    if (empty(trim($b['fnome'] ?? '')))
        return jsonRes($res, ['errore' => 'fnome obbligatorio'], 400);
    $pdo->prepare('INSERT INTO Fornitori (fnome, citta) VALUES (?,?)')
        ->execute([trim($b['fnome']), $b['citta'] ?? null]);
    return jsonRes($res, ['messaggio' => 'Fornitore creato', 'fid' => (int)$pdo->lastInsertId()], 201);
});

$app->put('/api/fornitori/{id}', function (Request $req, Response $res, array $args) use ($pdo) {
    if ($err = requireAuth($req, $res, 'admin')) return $err;
    $b = (array)$req->getParsedBody();
    if (empty(trim($b['fnome'] ?? '')))
        return jsonRes($res, ['errore' => 'fnome obbligatorio'], 400);
    $pdo->prepare('UPDATE Fornitori SET fnome=?, citta=? WHERE fid=?')
        ->execute([trim($b['fnome']), $b['citta'] ?? null, (int)$args['id']]);
    return jsonRes($res, ['messaggio' => 'Fornitore aggiornato']);
});

$app->delete('/api/fornitori/{id}', function (Request $req, Response $res, array $args) use ($pdo) {
    if ($err = requireAuth($req, $res, 'admin')) return $err;
    $pdo->prepare('DELETE FROM Fornitori WHERE fid=?')->execute([(int)$args['id']]);
    return jsonRes($res, ['messaggio' => 'Fornitore eliminato']);
});


$app->get('/api/catalogo', function (Request $req, Response $res) use ($pdo) {
    $p      = $req->getQueryParams();
    $page   = max(1, (int)($p['page'] ?? 1));
    $where  = 'WHERE 1=1';
    $args   = [];
    if (!empty($p['fid'])) { $where .= ' AND c.fid=?'; $args[] = (int)$p['fid']; }
    if (!empty($p['pid'])) { $where .= ' AND c.pid=?'; $args[] = (int)$p['pid']; }
    $sql    = "SELECT c.fid, c.pid, c.costo, f.fnome, p.pnome, p.colore
               FROM Catalogo c
               JOIN Fornitori f ON c.fid = f.fid
               JOIN Pezzi p     ON c.pid = p.pid
               $where
               ORDER BY c.fid, c.pid";
    $cntSql = "SELECT COUNT(*) as t FROM Catalogo c $where";
    $total  = (int)queryOne($pdo, $cntSql, $args)['t'];
    $offset = ($page - 1) * 10;
    $rows   = queryDB($pdo, $sql . ' LIMIT 10 OFFSET ' . $offset, $args);
    return jsonRes($res, [
        'data'       => $rows,
        'totale'     => $total,
        'pagina'     => $page,
        'per_pagina' => 10,
        'pagine'     => (int)ceil($total / 10),
    ]);
});

$app->get('/api/catalogo/{fid}/{pid}', function (Request $req, Response $res, array $args) use ($pdo) {
    $r = queryOne($pdo,
        'SELECT c.*, f.fnome, p.pnome, p.colore
         FROM Catalogo c
         JOIN Fornitori f ON c.fid=f.fid
         JOIN Pezzi p ON c.pid=p.pid
         WHERE c.fid=? AND c.pid=?',
        [(int)$args['fid'], (int)$args['pid']]
    );
    if (!$r) return jsonRes($res, ['errore' => 'Voce non trovata'], 404);
    return jsonRes($res, $r);
});

$app->post('/api/catalogo', function (Request $req, Response $res) use ($pdo) {
    if ($err = requireAuth($req, $res)) return $err;
    $user = getAuthUser($req);
    $b    = (array)$req->getParsedBody();
    $fid  = isset($b['fid']) ? (int)$b['fid'] : 0;
    $pid  = isset($b['pid']) ? (int)$b['pid'] : 0;
    $costo = isset($b['costo']) ? (float)$b['costo'] : null;
    if (!$fid || !$pid || $costo === null)
        return jsonRes($res, ['errore' => 'fid, pid e costo sono obbligatori'], 400);
    // Un fornitore può operare solo sul proprio catalogo
    if ($user['ruolo'] === 'fornitore' && (int)$user['fid'] !== $fid)
        return jsonRes($res, ['errore' => 'Non autorizzato: puoi modificare solo il tuo catalogo'], 403);
    try {
        $pdo->prepare('INSERT INTO Catalogo (fid, pid, costo) VALUES (?,?,?)')
            ->execute([$fid, $pid, $costo]);
        return jsonRes($res, ['messaggio' => 'Voce aggiunta al catalogo'], 201);
    } catch (PDOException $e) {
        return jsonRes($res, ['errore' => 'Voce già presente nel catalogo'], 409);
    }
});

$app->put('/api/catalogo/{fid}/{pid}', function (Request $req, Response $res, array $args) use ($pdo) {
    if ($err = requireAuth($req, $res)) return $err;
    $user = getAuthUser($req);
    $fid  = (int)$args['fid'];
    $pid  = (int)$args['pid'];
    if ($user['ruolo'] === 'fornitore' && (int)$user['fid'] !== $fid)
        return jsonRes($res, ['errore' => 'Non autorizzato: puoi modificare solo il tuo catalogo'], 403);
    $b     = (array)$req->getParsedBody();
    $costo = isset($b['costo']) ? (float)$b['costo'] : null;
    if ($costo === null)
        return jsonRes($res, ['errore' => 'costo obbligatorio'], 400);
    $pdo->prepare('UPDATE Catalogo SET costo=? WHERE fid=? AND pid=?')
        ->execute([$costo, $fid, $pid]);
    return jsonRes($res, ['messaggio' => 'Costo aggiornato']);
});

$app->delete('/api/catalogo/{fid}/{pid}', function (Request $req, Response $res, array $args) use ($pdo) {
    if ($err = requireAuth($req, $res)) return $err;
    $user = getAuthUser($req);
    $fid  = (int)$args['fid'];
    $pid  = (int)$args['pid'];
    if ($user['ruolo'] === 'fornitore' && (int)$user['fid'] !== $fid)
        return jsonRes($res, ['errore' => 'Non autorizzato: puoi eliminare solo dal tuo catalogo'], 403);
    $pdo->prepare('DELETE FROM Catalogo WHERE fid=? AND pid=?')->execute([$fid, $pid]);
    return jsonRes($res, ['messaggio' => 'Voce rimossa dal catalogo']);
});


$app->get('/api/utenti', function (Request $req, Response $res) use ($pdo) {
    if ($err = requireAuth($req, $res, 'admin')) return $err;
    $page   = max(1, (int)($req->getQueryParams()['page'] ?? 1));
    $total  = (int)queryOne($pdo, 'SELECT COUNT(*) as t FROM Utenti')['t'];
    $offset = ($page - 1) * 10;
    $rows   = queryDB($pdo,
        'SELECT u.uid, u.username, u.ruolo, u.fid, f.fnome, u.creato_il
         FROM Utenti u
         LEFT JOIN Fornitori f ON u.fid = f.fid
         ORDER BY u.uid
         LIMIT 10 OFFSET ' . $offset
    );
    return jsonRes($res, [
        'data'       => $rows,
        'totale'     => $total,
        'pagina'     => $page,
        'per_pagina' => 10,
        'pagine'     => (int)ceil($total / 10),
    ]);
});

$app->delete('/api/utenti/{id}', function (Request $req, Response $res, array $args) use ($pdo) {
    if ($err = requireAuth($req, $res, 'admin')) return $err;
    $pdo->prepare('DELETE FROM Utenti WHERE uid=?')->execute([(int)$args['id']]);
    return jsonRes($res, ['messaggio' => 'Utente eliminato']);
});


$app->get('/dashboard/login', function (Request $req, Response $res) {
    $html = <<<'HTML'
<!DOCTYPE html><html lang="it"><head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Login — Catalogo</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&family=Fira+Code&display=swap" rel="stylesheet"/>
<style>
:root{--accent:#5ba08e;--bg:#141718;--surface:#1e2122;--border:#2a2e30;--text:#dde3e6;--muted:#606870;--danger:#e05c5c;}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:"Nunito",sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh}
.card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:40px;width:360px;animation:fadeIn .3s ease}
.logo{text-align:center;font-family:"Fira Code",monospace;color:var(--accent);font-size:.85rem;letter-spacing:.1em;margin-bottom:8px}
h2{font-size:1.4rem;font-weight:700;margin-bottom:24px;text-align:center}
.fg{margin-bottom:16px}.fg label{display:block;font-size:.82rem;font-weight:600;color:var(--muted);margin-bottom:6px}
.fg input{width:100%;background:#252a2c;border:1px solid var(--border);border-radius:8px;padding:10px 14px;color:var(--text);font-size:.9rem;outline:none;font-family:"Nunito",sans-serif;transition:border .2s}
.fg input:focus{border-color:var(--accent)}
.btn{width:100%;background:var(--accent);color:#fff;border:none;border-radius:8px;padding:11px;font-family:"Nunito",sans-serif;font-size:.95rem;font-weight:700;cursor:pointer;margin-top:8px;transition:background .2s}
.btn:hover{background:#3a6459}.btn:disabled{opacity:.6;cursor:not-allowed}
.err{color:var(--danger);font-size:.82rem;text-align:center;margin-top:10px;min-height:1.2em}
@keyframes fadeIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
</style></head><body>
<div class="card">
  <div class="logo">CATALOGO FORNITORI</div>
  <h2>Accedi</h2>
  <div class="fg"><label>Username</label><input type="text" id="u" autocomplete="username" placeholder="Il tuo username"/></div>
  <div class="fg"><label>Password</label><input type="password" id="p" autocomplete="current-password" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;"/></div>
  <button class="btn" id="btnLogin" onclick="doLogin()">Accedi</button>
  <div class="err" id="e"></div>
</div>
<script>
document.getElementById("p").addEventListener("keydown", ev => { if(ev.key==="Enter") doLogin(); });
async function doLogin() {
    const u   = document.getElementById("u").value.trim();
    const p   = document.getElementById("p").value;
    const e   = document.getElementById("e");
    const btn = document.getElementById("btnLogin");
    e.textContent = "";
    if (!u || !p) { e.textContent = "Inserisci username e password"; return; }
    btn.disabled = true;
    btn.textContent = "Accesso in corso...";
    try {
        const r = await fetch("/api/auth/login", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ username: u, password: p })
        });
        const d = await r.json();
        if (!r.ok) throw new Error(d.errore || "Errore " + r.status);
        localStorage.setItem("cat_jwt",  d.token);
        localStorage.setItem("cat_ruolo", d.ruolo);
        localStorage.setItem("cat_fid",   d.fid ?? "");
        localStorage.setItem("cat_user",  d.username);
        window.location.href = (d.ruolo === "admin") ? "/dashboard/admin" : "/dashboard/fornitore";
    } catch(ex) {
        e.textContent = ex.message;
        btn.disabled = false;
        btn.textContent = "Accedi";
    }
}
</script></body></html>
HTML;
    $res->getBody()->write($html);
    return $res->withHeader('Content-Type', 'text/html');
});



$app->get('/dashboard/admin', function (Request $req, Response $res) {
    $content = '
    <div id="dash-content">
      <div class="section-header">
        <div class="tag">Dashboard Admin</div>
        <h2>Gestione Catalogo</h2>
        <p>Gestisci pezzi, fornitori, catalogo e utenti del sistema.
           <span id="user-info" style="color:var(--accent);font-weight:700"></span>
           <a href="/dashboard/login" id="btn-logout" style="color:var(--muted);font-size:.8rem;margin-left:12px">Esci &rarr;</a>
        </p>
      </div>

      <!-- TAB NAVIGATION -->
      <div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap" id="tabs">
        <button class="btn-sm btn-accent" onclick="showTab(\'pezzi\')">&#128299; Pezzi</button>
        <button class="btn-sm btn-edit"   onclick="showTab(\'fornitori\')">&#127981; Fornitori</button>
        <button class="btn-sm btn-detail" onclick="showTab(\'catalogo\')">&#128203; Catalogo</button>
        <button class="btn-sm btn-detail" onclick="showTab(\'utenti\')">&#128100; Utenti</button>
      </div>

      <!-- PEZZI -->
      <div id="tab-pezzi" class="tab-panel">
        <div class="card">
          <div class="toolbar">
            <input class="search-input" placeholder="Cerca per nome..." oninput="pzQ=this.value;pzPg=1;loadPezzi()"/>
            <button class="btn-sm btn-accent" onclick="addPezzo()">+ Aggiungi Pezzo</button>
          </div>
          <div id="pz-tbl"></div>
        </div>
      </div>

      <!-- FORNITORI -->
      <div id="tab-fornitori" class="tab-panel" style="display:none">
        <div class="card">
          <div class="toolbar">
            <input class="search-input" placeholder="Cerca per nome..." oninput="fnQ=this.value;fnPg=1;loadFornitori()"/>
            <button class="btn-sm btn-accent" onclick="addFornitore()">+ Aggiungi Fornitore</button>
          </div>
          <div id="fn-tbl"></div>
        </div>
      </div>

      <!-- CATALOGO -->
      <div id="tab-catalogo" class="tab-panel" style="display:none">
        <div class="card">
          <div class="toolbar">
            <button class="btn-sm btn-accent" onclick="addCatalogo()">+ Aggiungi voce</button>
          </div>
          <div id="ca-tbl"></div>
        </div>
      </div>

      <!-- UTENTI -->
      <div id="tab-utenti" class="tab-panel" style="display:none">
        <div class="card">
          <div class="toolbar">
            <button class="btn-sm btn-accent" onclick="addUtente()">+ Nuovo Utente</button>
          </div>
          <div id="ut-tbl"></div>
        </div>
      </div>
    </div>

    <script>
    (function() {
        const tok   = localStorage.getItem("cat_jwt");
        const ruolo = localStorage.getItem("cat_ruolo");
        if (!tok || ruolo !== "admin") { window.location.href = "/dashboard/login"; return; }
        document.getElementById("user-info").textContent = "Ciao, " + (localStorage.getItem("cat_user") || "");
        document.getElementById("btn-logout").addEventListener("click", function(e) {
            e.preventDefault(); localStorage.clear(); window.location.href = "/dashboard/login";
        });
    })();

    function showTab(name) {
        document.querySelectorAll(".tab-panel").forEach(el => el.style.display = "none");
        document.getElementById("tab-" + name).style.display = "block";
        if (name === "pezzi")     { pzPg=1; loadPezzi(); }
        if (name === "fornitori") { fnPg=1; loadFornitori(); }
        if (name === "catalogo")  { caPg=1; loadCatalogo(); }
        if (name === "utenti")    { utPg=1; loadUtenti(); }
    }
    showTab("pezzi");
    </script>';

    return renderPage($res, '', $content);
});


$app->get('/dashboard/fornitore', function (Request $req, Response $res) {
    $content = '
    <div id="dash-content">
      <div class="section-header">
        <div class="tag">Area Fornitore</div>
        <h2>Il mio catalogo</h2>
        <p>Inserisci, modifica ed elimina i pezzi del tuo catalogo personale.
           <span id="user-info" style="color:var(--accent);font-weight:700"></span>
           <a href="/dashboard/login" id="btn-logout" style="color:var(--muted);font-size:.8rem;margin-left:12px">Esci &rarr;</a>
        </p>
      </div>
      <div class="card">
        <div class="toolbar">
          <button class="btn-sm btn-accent" id="btn-add">+ Aggiungi pezzo</button>
        </div>
        <div id="mc-tbl"></div>
      </div>
    </div>

    <script>
    (function() {
        const tok   = localStorage.getItem("cat_jwt");
        const ruolo = localStorage.getItem("cat_ruolo");
        const fid   = parseInt(localStorage.getItem("cat_fid"));
        if (!tok || ruolo !== "fornitore") { window.location.href = "/dashboard/login"; return; }
        document.getElementById("user-info").textContent = "Ciao, " + (localStorage.getItem("cat_user") || "");
        document.getElementById("btn-logout").addEventListener("click", function(e) {
            e.preventDefault(); localStorage.clear(); window.location.href = "/dashboard/login";
        });
        window._myFid = fid;
        document.getElementById("btn-add").addEventListener("click", function() { addMioCatalogo(fid); });
        loadMioCatalogo(fid);
    })();
    </script>';

    return renderPage($res, '', $content);
});


$app->get('/', function (Request $req, Response $res) {
    return $res->withHeader('Location', '/frontend/q1')->withStatus(302);
});

$app->get('/frontend/q1', function (Request $req, Response $res) {
    $sql = "SELECT DISTINCT p.pnome\nFROM Pezzi p\nJOIN Catalogo c ON p.pid = c.pid";
    $c   = '<div class="section-header"><div class="tag">Query 01</div><h2>Pezzi con fornitori</h2><p>Restituisce i nomi dei pezzi che hanno almeno un fornitore nel catalogo.</p></div>';
    $c  .= configBar() . '<div class="sql-block">' . htmlspecialchars($sql) . '</div>';
    $c  .= '<button class="btn-fetch" onclick="fetchData(\'/api/1\', this, \'result\')">&#9654; Esegui Query</button>';
    $c  .= '<div class="result-area" id="result"></div>';
    return renderPage($res, 'q1', $c);
});

$app->get('/frontend/q2', function (Request $req, Response $res) {
    $sql = "SELECT f.fnome FROM Fornitori f\nWHERE NOT EXISTS (\n    SELECT * FROM Pezzi p\n    WHERE NOT EXISTS (\n        SELECT * FROM Catalogo c WHERE c.pid=p.pid AND c.fid=f.fid\n    )\n)";
    $c   = '<div class="section-header"><div class="tag">Query 02</div><h2>Fornitori con tutti i pezzi</h2><p>Fornitori che forniscono ogni pezzo presente nel catalogo.</p></div>';
    $c  .= configBar() . '<div class="sql-block">' . htmlspecialchars($sql) . '</div>';
    $c  .= '<button class="btn-fetch" onclick="fetchData(\'/api/2\', this, \'result\')">&#9654; Esegui Query</button>';
    $c  .= '<div class="result-area" id="result"></div>';
    return renderPage($res, 'q2', $c);
});

$app->get('/frontend/q3', function (Request $req, Response $res) {
    $sql = "SELECT f.fnome FROM Fornitori f\nWHERE NOT EXISTS (\n    SELECT * FROM Pezzi p WHERE colore='rosso'\n    AND NOT EXISTS (\n        SELECT * FROM Catalogo c WHERE c.pid=p.pid AND c.fid=f.fid\n    )\n)";
    $c   = '<div class="section-header"><div class="tag">Query 03</div><h2>Fornitori con tutti i pezzi rossi</h2><p>Fornitori che forniscono almeno tutti i pezzi di colore rosso.</p></div>';
    $c  .= configBar() . '<div class="sql-block">' . htmlspecialchars($sql) . '</div>';
    $c  .= '<button class="btn-fetch" onclick="fetchData(\'/api/3\', this, \'result\')">&#9654; Esegui Query</button>';
    $c  .= '<div class="result-area" id="result"></div>';
    return renderPage($res, 'q3', $c);
});

$app->get('/frontend/q4', function (Request $req, Response $res) {
    $sql = "SELECT p.pnome FROM Pezzi p\nJOIN Catalogo c ON p.pid=c.pid\nJOIN Fornitori f ON f.fid=c.fid\nWHERE f.fnome='Acme'\nAND NOT EXISTS (\n    SELECT * FROM Catalogo c2 WHERE c2.pid=p.pid AND c2.fid <> f.fid\n)";
    $c   = '<div class="section-header"><div class="tag">Query 04</div><h2>Pezzi forniti solo da Acme</h2><p>Pezzi forniti esclusivamente dal fornitore Acme.</p></div>';
    $c  .= configBar() . '<div class="sql-block">' . htmlspecialchars($sql) . '</div>';
    $c  .= '<button class="btn-fetch" onclick="fetchData(\'/api/4\', this, \'result\')">&#9654; Esegui Query</button>';
    $c  .= '<div class="result-area" id="result"></div>';
    return renderPage($res, 'q4', $c);
});

$app->get('/frontend/q5', function (Request $req, Response $res) {
    $sql = "SELECT DISTINCT c.fid FROM Catalogo c\nWHERE c.costo > (\n    SELECT AVG(c2.costo) FROM Catalogo c2 WHERE c2.pid=c.pid\n)";
    $c   = '<div class="section-header"><div class="tag">Query 05</div><h2>Fornitori con costo sopra la media</h2><p>Fornitori con almeno un pezzo con costo superiore alla media per quel pezzo.</p></div>';
    $c  .= configBar() . '<div class="sql-block">' . htmlspecialchars($sql) . '</div>';
    $c  .= '<button class="btn-fetch" onclick="fetchData(\'/api/5\', this, \'result\')">&#9654; Esegui Query</button>';
    $c  .= '<div class="result-area" id="result"></div>';
    return renderPage($res, 'q5', $c);
});

$app->get('/frontend/q6', function (Request $req, Response $res) {
    $sql = "SELECT p.pid, f.fnome FROM Pezzi p\nJOIN Catalogo c ON p.pid=c.pid\nJOIN Fornitori f ON f.fid=c.fid\nWHERE c.costo = (\n    SELECT MAX(c2.costo) FROM Catalogo c2 WHERE c2.pid=p.pid\n)";
    $c   = '<div class="section-header"><div class="tag">Query 06</div><h2>Fornitori con costo massimo per pezzo</h2><p>Per ogni pezzo, il fornitore che lo vende al prezzo più alto.</p></div>';
    $c  .= configBar() . '<div class="sql-block">' . htmlspecialchars($sql) . '</div>';
    $c  .= '<button class="btn-fetch" onclick="fetchData(\'/api/6\', this, \'result\')">&#9654; Esegui Query</button>';
    $c  .= '<div class="result-area" id="result"></div>';
    return renderPage($res, 'q6', $c);
});

$app->get('/frontend/q7', function (Request $req, Response $res) {
    $sql = "SELECT f.fid FROM Fornitori f\nWHERE EXISTS (\n    SELECT * FROM Catalogo c JOIN Pezzi p ON p.pid=c.pid WHERE c.fid=f.fid\n)\nAND NOT EXISTS (\n    SELECT * FROM Catalogo c JOIN Pezzi p ON p.pid=c.pid\n    WHERE c.fid=f.fid AND p.colore <> 'rosso'\n)";
    $c   = '<div class="section-header"><div class="tag">Query 07</div><h2>Fornitori che vendono solo pezzi rossi</h2><p>Fornitori il cui catalogo contiene esclusivamente pezzi rossi.</p></div>';
    $c  .= configBar() . '<div class="sql-block">' . htmlspecialchars($sql) . '</div>';
    $c  .= '<button class="btn-fetch" onclick="fetchData(\'/api/7\', this, \'result\')">&#9654; Esegui Query</button>';
    $c  .= '<div class="result-area" id="result"></div>';
    return renderPage($res, 'q7', $c);
});

$app->get('/frontend/q8', function (Request $req, Response $res) {
    $sql = "SELECT f.fid FROM Fornitori f\nWHERE EXISTS (\n    SELECT * FROM Catalogo c JOIN Pezzi p ON p.pid=c.pid\n    WHERE c.fid=f.fid AND p.colore='rosso'\n)\nAND EXISTS (\n    SELECT * FROM Catalogo c JOIN Pezzi p ON p.pid=c.pid\n    WHERE c.fid=f.fid AND p.colore='verde'\n)";
    $c   = '<div class="section-header"><div class="tag">Query 08</div><h2>Fornitori con pezzi rossi E verdi</h2><p>Fornitori con almeno un pezzo rosso e almeno uno verde.</p></div>';
    $c  .= configBar() . '<div class="sql-block">' . htmlspecialchars($sql) . '</div>';
    $c  .= '<button class="btn-fetch" onclick="fetchData(\'/api/8\', this, \'result\')">&#9654; Esegui Query</button>';
    $c  .= '<div class="result-area" id="result"></div>';
    return renderPage($res, 'q8', $c);
});

$app->get('/frontend/q9', function (Request $req, Response $res) {
    $sql = "SELECT DISTINCT c.fid FROM Catalogo c\nJOIN Pezzi p ON p.pid=c.pid\nWHERE p.colore='rosso' OR p.colore='verde'";
    $c   = '<div class="section-header"><div class="tag">Query 09</div><h2>Fornitori con pezzi rossi O verdi</h2><p>Fornitori con almeno un pezzo rosso oppure verde.</p></div>';
    $c  .= configBar() . '<div class="sql-block">' . htmlspecialchars($sql) . '</div>';
    $c  .= '<button class="btn-fetch" onclick="fetchData(\'/api/9\', this, \'result\')">&#9654; Esegui Query</button>';
    $c  .= '<div class="result-area" id="result"></div>';
    return renderPage($res, 'q9', $c);
});

$app->get('/frontend/q10', function (Request $req, Response $res) {
    $sql = "SELECT pid FROM Catalogo\nGROUP BY pid\nHAVING COUNT(DISTINCT fid) >= 2";
    $c   = '<div class="section-header"><div class="tag">Query 10</div><h2>Pezzi con almeno 2 fornitori</h2><p>Pezzi presenti nel catalogo di almeno due fornitori distinti.</p></div>';
    $c  .= configBar() . '<div class="sql-block">' . htmlspecialchars($sql) . '</div>';
    $c  .= '<button class="btn-fetch" onclick="fetchData(\'/api/10\', this, \'result\')">&#9654; Esegui Query</button>';
    $c  .= '<div class="result-area" id="result"></div>';
    return renderPage($res, 'q10', $c);
});

// ── ROUTE API ORIGINALI (query 1-10) ──────────────────────────────────────
foreach ($queries as $num => $sql) {
    $app->get("/api/$num", function (Request $request, Response $response) use ($pdo, $sql) {
        $data = queryDB($pdo, $sql);
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    });
}

$app->run();