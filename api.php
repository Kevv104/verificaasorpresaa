<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/vendor/autoload.php';

$app = AppFactory::create();

$host    = 'localhost';
$db      = 'verificaasorpresa';
$user    = 'utente_phpmyadmin';
$pass    = 'admin';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

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

$queries = [
    1 => "SELECT DISTINCT p.pnome
          FROM Pezzi p
          JOIN Catalogo c ON p.pid = c.pid",

    2 => "SELECT f.fnome
          FROM Fornitori f
          WHERE NOT EXISTS (
              SELECT * FROM Pezzi p
              WHERE NOT EXISTS (
                  SELECT * FROM Catalogo c
                  WHERE c.fid = f.fid AND c.pid = p.pid
              )
          )",

    3 => "SELECT f.fnome
          FROM Fornitori f
          WHERE NOT EXISTS (
              SELECT * FROM Pezzi p
              WHERE p.colore = 'rosso'
              AND NOT EXISTS (
                  SELECT * FROM Catalogo c
                  WHERE c.fid = f.fid AND c.pid = p.pid
              )
          )",

    4 => "SELECT p.pnome
          FROM Pezzi p
          JOIN Catalogo c ON p.pid = c.pid
          JOIN Fornitori f ON f.fid = c.fid
          WHERE f.fnome = 'Acme'
          AND NOT EXISTS (
              SELECT * FROM Catalogo c2
              WHERE c2.pid = p.pid AND c2.fid <> f.fid
          )",

    5 => "SELECT DISTINCT c.fid
          FROM Catalogo c
          WHERE c.costo > (
              SELECT AVG(c2.costo)
              FROM Catalogo c2
              WHERE c2.pid = c.pid
          )",

    6 => "SELECT p.pid, f.fnome
          FROM Pezzi p
          JOIN Catalogo c ON p.pid = c.pid
          JOIN Fornitori f ON f.fid = c.fid
          WHERE c.costo = (
              SELECT MAX(c2.costo)
              FROM Catalogo c2
              WHERE c2.pid = p.pid
          )",

    7 => "SELECT f.fid
          FROM Fornitori f
          WHERE EXISTS (
              SELECT * FROM Catalogo c
              JOIN Pezzi p ON p.pid = c.pid
              WHERE c.fid = f.fid
          )
          AND NOT EXISTS (
              SELECT * FROM Catalogo c
              JOIN Pezzi p ON p.pid = c.pid
              WHERE c.fid = f.fid AND p.colore <> 'rosso'
          )",

    8 => "SELECT f.fid
          FROM Fornitori f
          WHERE EXISTS (
              SELECT * FROM Catalogo c
              JOIN Pezzi p ON p.pid = c.pid
              WHERE c.fid = f.fid AND p.colore = 'rosso'
          )
          AND EXISTS (
              SELECT * FROM Catalogo c
              JOIN Pezzi p ON p.pid = c.pid
              WHERE c.fid = f.fid AND p.colore = 'verde'
          )",

    9 => "SELECT DISTINCT c.fid
          FROM Catalogo c
          JOIN Pezzi p ON p.pid = c.pid
          WHERE p.colore = 'rosso' OR p.colore = 'verde'",

    10 => "SELECT pid
           FROM Catalogo
           GROUP BY pid
           HAVING COUNT(DISTINCT fid) >= 2",
];

$app->addRoutingMiddleware();
$app->addErrorMiddleware(true, true, true);

$app->add(function (Request $request, $handler) {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Methods', 'GET, OPTIONS')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Accept');
});

$app->options('/{routes:.+}', function (Request $request, Response $response) {
    return $response;
});

foreach ($queries as $num => $sql) {
    $app->get("/api/$num", function (Request $request, Response $response) use ($pdo, $sql) {
        $data = queryDB($pdo, $sql);
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    });
}

$app->run();