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

// ── HELPER FUNCTIONS ───────────────────────────────────────────────────────

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

function getAuthUser(Request $req): ?array {
    $auth = $req->getHeaderLine('Authorization');
    if (!$auth || !preg_match('/Bearer\s(\S+)/', $auth, $m)) return null;
    try {
        $decoded = JWT::decode($m[1], new Key(JWT_SECRET, 'HS256'));
        return (array)$decoded;
    } catch (Exception $e) { return null; }
}

function requireAuth(Request $req, Response $res, ?string $ruolo = null): ?Response {
    $user = getAuthUser($req);
    if (!$user) return jsonRes($res, ['errore' => 'Non autenticato'], 401);
    if ($ruolo && $user['ruolo'] !== $ruolo && $user['ruolo'] !== 'admin') {
        return jsonRes($res, ['errore' => 'Non autorizzato'], 403);
    }
    return null;
}

// ── QUERY ORIGINALI ────────────────────────────────────────────────────────

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

// ── MIDDLEWARE ─────────────────────────────────────────────────────────────

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

// ── FRONTEND ───────────────────────────────────────────────────────
// Le route del frontend (dashboard, query pages) sono in un file separato
require __DIR__ . '/frontend.php';

// ══════════════════════════════════════════════════════════════════════════
// API ROUTES — AUTH
// ══════════════════════════════════════════════════════════════════════════

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

// ══════════════════════════════════════════════════════════════════════════
// API ROUTES — PEZZI
// ══════════════════════════════════════════════════════════════════════════

$app->get('/api/pezzi', function (Request $req, Response $res) use ($pdo) {
    $p      = $req->getQueryParams();
    $page   = max(1, (int)($p['page'] ?? 1));
    $q      = '%' . ($p['q'] ?? '') . '%';
    $total  = (int)queryOne($pdo, 'SELECT COUNT(*) as t FROM Pezzi WHERE pnome LIKE ?', [$q])['t'];
    $offset = ($page - 1) * 10;
    $rows   = queryDB($pdo, 'SELECT * FROM Pezzi WHERE pnome LIKE ? ORDER BY pid LIMIT 10 OFFSET ' . $offset, [$q]);
    return jsonRes($res, ['data' => $rows, 'totale' => $total, 'pagina' => $page, 'per_pagina' => 10, 'pagine' => (int)ceil($total / 10)]);
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

// ══════════════════════════════════════════════════════════════════════════
// API ROUTES — FORNITORI
// ══════════════════════════════════════════════════════════════════════════

$app->get('/api/fornitori', function (Request $req, Response $res) use ($pdo) {
    $p      = $req->getQueryParams();
    $page   = max(1, (int)($p['page'] ?? 1));
    $q      = '%' . ($p['q'] ?? '') . '%';
    $total  = (int)queryOne($pdo, 'SELECT COUNT(*) as t FROM Fornitori WHERE fnome LIKE ?', [$q])['t'];
    $offset = ($page - 1) * 10;
    $rows   = queryDB($pdo, 'SELECT * FROM Fornitori WHERE fnome LIKE ? ORDER BY fid LIMIT 10 OFFSET ' . $offset, [$q]);
    return jsonRes($res, ['data' => $rows, 'totale' => $total, 'pagina' => $page, 'per_pagina' => 10, 'pagine' => (int)ceil($total / 10)]);
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

// ══════════════════════════════════════════════════════════════════════════
// API ROUTES — CATALOGO
// ══════════════════════════════════════════════════════════════════════════

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
               $where ORDER BY c.fid, c.pid";
    $total  = (int)queryOne($pdo, "SELECT COUNT(*) as t FROM Catalogo c $where", $args)['t'];
    $offset = ($page - 1) * 10;
    $rows   = queryDB($pdo, $sql . ' LIMIT 10 OFFSET ' . $offset, $args);
    return jsonRes($res, ['data' => $rows, 'totale' => $total, 'pagina' => $page, 'per_pagina' => 10, 'pagine' => (int)ceil($total / 10)]);
});

$app->get('/api/catalogo/{fid}/{pid}', function (Request $req, Response $res, array $args) use ($pdo) {
    $r = queryOne($pdo,
        'SELECT c.*, f.fnome, p.pnome, p.colore FROM Catalogo c
         JOIN Fornitori f ON c.fid=f.fid JOIN Pezzi p ON c.pid=p.pid
         WHERE c.fid=? AND c.pid=?',
        [(int)$args['fid'], (int)$args['pid']]
    );
    if (!$r) return jsonRes($res, ['errore' => 'Voce non trovata'], 404);
    return jsonRes($res, $r);
});

$app->post('/api/catalogo', function (Request $req, Response $res) use ($pdo) {
    if ($err = requireAuth($req, $res)) return $err;
    $user  = getAuthUser($req);
    $b     = (array)$req->getParsedBody();
    $fid   = isset($b['fid']) ? (int)$b['fid'] : 0;
    $pid   = isset($b['pid']) ? (int)$b['pid'] : 0;
    $costo = isset($b['costo']) ? (float)$b['costo'] : null;
    if (!$fid || !$pid || $costo === null)
        return jsonRes($res, ['errore' => 'fid, pid e costo sono obbligatori'], 400);
    if ($user['ruolo'] === 'fornitore' && (int)$user['fid'] !== $fid)
        return jsonRes($res, ['errore' => 'Non autorizzato: puoi modificare solo il tuo catalogo'], 403);
    try {
        $pdo->prepare('INSERT INTO Catalogo (fid, pid, costo) VALUES (?,?,?)')->execute([$fid, $pid, $costo]);
        return jsonRes($res, ['messaggio' => 'Voce aggiunta al catalogo'], 201);
    } catch (PDOException $e) {
        return jsonRes($res, ['errore' => 'Voce già presente nel catalogo'], 409);
    }
});

$app->put('/api/catalogo/{fid}/{pid}', function (Request $req, Response $res, array $args) use ($pdo) {
    if ($err = requireAuth($req, $res)) return $err;
    $user  = getAuthUser($req);
    $fid   = (int)$args['fid'];
    $pid   = (int)$args['pid'];
    if ($user['ruolo'] === 'fornitore' && (int)$user['fid'] !== $fid)
        return jsonRes($res, ['errore' => 'Non autorizzato: puoi modificare solo il tuo catalogo'], 403);
    $b     = (array)$req->getParsedBody();
    $costo = isset($b['costo']) ? (float)$b['costo'] : null;
    if ($costo === null) return jsonRes($res, ['errore' => 'costo obbligatorio'], 400);
    $pdo->prepare('UPDATE Catalogo SET costo=? WHERE fid=? AND pid=?')->execute([$costo, $fid, $pid]);
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

// ══════════════════════════════════════════════════════════════════════════
// API ROUTES — UTENTI
// ══════════════════════════════════════════════════════════════════════════

$app->get('/api/utenti', function (Request $req, Response $res) use ($pdo) {
    if ($err = requireAuth($req, $res, 'admin')) return $err;
    $page   = max(1, (int)($req->getQueryParams()['page'] ?? 1));
    $total  = (int)queryOne($pdo, 'SELECT COUNT(*) as t FROM Utenti')['t'];
    $offset = ($page - 1) * 10;
    $rows   = queryDB($pdo,
        'SELECT u.uid, u.username, u.ruolo, u.fid, f.fnome, u.creato_il
         FROM Utenti u LEFT JOIN Fornitori f ON u.fid = f.fid
         ORDER BY u.uid LIMIT 10 OFFSET ' . $offset
    );
    return jsonRes($res, ['data' => $rows, 'totale' => $total, 'pagina' => $page, 'per_pagina' => 10, 'pagine' => (int)ceil($total / 10)]);
});

$app->delete('/api/utenti/{id}', function (Request $req, Response $res, array $args) use ($pdo) {
    if ($err = requireAuth($req, $res, 'admin')) return $err;
    $pdo->prepare('DELETE FROM Utenti WHERE uid=?')->execute([(int)$args['id']]);
    return jsonRes($res, ['messaggio' => 'Utente eliminato']);
});

// ── ROUTE API ORIGINALI /api/1 ... /api/10 ────────────────────────────────
foreach ($queries as $num => $sql) {
    $app->get("/api/$num", function (Request $request, Response $response) use ($pdo, $sql) {
        $data = queryDB($pdo, $sql);
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    });
}

$app->run();
