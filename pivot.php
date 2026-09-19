<?php
/**
 * TBCA Pivot — converte o banco bruto (EAV) em wide
 *
 * Lê `tbca_bruto.db` (1 linha por componente) e produz `tbca.db`
 * (1 linha por alimento, com colunas). Adiciona índices e roda
 * validação de consistência energética.
 *
 * Uso:
 *   php pivot.php                     Processa com caminhos padrão
 *   php pivot.php --in=X --out=Y      Customiza caminhos
 *   php pivot.php --no-indices        Pula criação de índices
 *   php pivot.php --help
 *
 * Licença: MIT (ver LICENSE)
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
@set_time_limit(0);
@ini_set('memory_limit', '512M');

$IS_CLI = (PHP_SAPI === 'cli');

function p($name, $default = null) {
    global $argv, $IS_CLI;
    if (!$IS_CLI) return $_GET[$name] ?? $default;
    foreach ((array)$argv as $a) {
        if (preg_match('/^--' . preg_quote($name, '/') . '=(.*)$/', $a, $m)) return $m[1];
        if ($a === '--' . $name) return true;
    }
    return $default;
}

if ($IS_CLI && (p('help') || p('h'))) {
    echo <<<TXT
TBCA Pivot — converte tbca_bruto.db (EAV) em tbca.db (wide)

Uso:
  php pivot.php                     Processa com caminhos padrão
  php pivot.php --in=X --out=Y      Define caminhos customizados
  php pivot.php --no-indices        Pula criação de índices

Padrões:
  --in   tbca_bruto.db
  --out  tbca.db

TXT;
    exit(0);
}

$IN_DB   = p('in',  __DIR__ . '/tbca_bruto.db');
$OUT_DB  = p('out', __DIR__ . '/tbca.db');
$noIdx   = (bool)p('no-indices', false);

if (!file_exists($IN_DB)) {
    fwrite(STDERR, "✗ Entrada não encontrada: $IN_DB\n");
    fwrite(STDERR, "  Rode o scraper primeiro:\n");
    fwrite(STDERR, "    php scraper.php --action=list\n");
    fwrite(STDERR, "    php scraper.php --action=step --n=16\n");
    exit(1);
}

$stderr = function($msg) { fwrite(STDERR, $msg . "\n"); };

$stderr("=== TBCA Pivot ===");
$stderr("Entrada: $IN_DB");
$stderr("Saída:   $OUT_DB");
$stderr("");

// ============================================================
// 1. Abre origem
// ============================================================
$src = new PDO('sqlite:' . $IN_DB);
$src->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$src->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$src->exec('PRAGMA query_only = ON');

$tem = $src->query("SELECT name FROM sqlite_master WHERE type='table' AND name='tbca_bruto'")->fetchColumn();
if (!$tem) {
    $stderr("✗ Tabela 'tbca_bruto' não existe em $IN_DB");
    exit(1);
}

// ============================================================
// 2. Descobre componentes (auto)
// ============================================================
$stderr("── 1. COMPONENTES ──");
$componentes = $src->query('
    SELECT DISTINCT Componente, Unidade
    FROM tbca_bruto
    ORDER BY Componente, Unidade
')->fetchAll();

$colunasFinal = [];
foreach ($componentes as $c) {
    $colunasFinal[] = $c['Componente'] . ' |' . $c['Unidade'] . '|';
}
$stderr("  " . count($colunasFinal) . " componentes encontrados");
foreach (array_slice($colunasFinal, 0, 5) as $c) $stderr("    · $c");
if (count($colunasFinal) > 5) $stderr("    · … e mais " . (count($colunasFinal) - 5));

$colunasMeta  = ['Código', 'Nome', 'Nome científico', 'Grupo', 'Marca', 'slug', 'Outras medidas'];
$todasColunas = array_merge($colunasMeta, $colunasFinal);
$stderr("");

// ============================================================
// 3. Cria destino
// ============================================================
$stderr("── 2. CRIAR $OUT_DB ──");
if (file_exists($OUT_DB)) {
    $bak = $OUT_DB . '.bak_' . date('YmdHis');
    rename($OUT_DB, $bak);
    $stderr("  Backup do anterior: " . basename($bak));
}

$dst = new PDO('sqlite:' . $OUT_DB);
$dst->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dst->exec('PRAGMA journal_mode = MEMORY');
$dst->exec('PRAGMA synchronous = OFF');

$colsSql = [];
foreach ($todasColunas as $c) {
    $colsSql[] = '"' . str_replace('"', '""', $c) . '" TEXT';
}
$dst->exec('CREATE TABLE tbca (' . implode(', ', $colsSql) . ')');
$stderr("  Tabela criada com " . count($todasColunas) . " colunas");
$stderr("");

// ============================================================
// 4. Metadados
// ============================================================
$stderr("── 3. METADADOS ──");
$alimento = [];
foreach ($src->query('SELECT * FROM alimento') as $r) {
    $alimento[$r['Código']] = $r;
}
$stderr("  " . count($alimento) . " itens em 'alimento'");
$stderr("");

// ============================================================
// 5. Pivot EAV → wide
// ============================================================
$stderr("── 4. PIVOT ──");
$porCodigo = [];
$medidasPorCodigo = [];
$total = 0;

$stmt = $src->query('SELECT "Código", "Componente", "Unidade", "Por_100g", "Medidas" FROM tbca_bruto');
while ($r = $stmt->fetch()) {
    $cod = $r['Código'];
    $col = $r['Componente'] . ' |' . $r['Unidade'] . '|';

    if (!isset($porCodigo[$cod])) $porCodigo[$cod] = [];
    $v = $r['Por_100g'];

    // Normaliza vírgula decimal
    if (is_string($v) && preg_match('/^-?\d+,\d+$/', $v)) {
        $v = str_replace(',', '.', $v);
    }
    $porCodigo[$cod][$col] = $v;

    if (!empty($r['Medidas']) && !isset($medidasPorCodigo[$cod])) {
        $medidasPorCodigo[$cod] = $r['Medidas'];
    }

    $total++;
    if ($total % 50000 === 0) $stderr("  " . number_format($total) . " linhas...");
}
$stderr("  Total: " . number_format($total) . " linhas lidas");
$stderr("  " . count($porCodigo) . " códigos únicos");
$stderr("");

// ============================================================
// 6. Insere no destino
// ============================================================
$stderr("── 5. INSERIR ──");
$dst->beginTransaction();
$ph = implode(', ', array_fill(0, count($todasColunas), '?'));
$colNames = '"' . implode('","', array_map(fn($c) => str_replace('"', '""', $c), $todasColunas)) . '"';
$ins = $dst->prepare("INSERT INTO tbca ($colNames) VALUES ($ph)");

$inseridos = 0;
foreach ($porCodigo as $cod => $valores) {
    $meta = $alimento[$cod] ?? [];

    $row = [];
    $row[] = $cod;
    $row[] = $meta['Nome']            ?? '';
    $row[] = $meta['Nome_cientifico'] ?? '';
    $row[] = $meta['Grupo']           ?? '';
    $row[] = $meta['Marca']           ?? '';
    $row[] = $meta['slug']            ?? '';
    $row[] = isset($medidasPorCodigo[$cod])
           ? converterMedidas($medidasPorCodigo[$cod])
           : null;

    foreach ($colunasFinal as $c) {
        $v = $valores[$c] ?? null;
        $row[] = ($v === '') ? null : $v;
    }
    $ins->execute($row);
    $inseridos++;
}
$dst->commit();
$stderr("  $inseridos inseridos");
$stderr("");

// ============================================================
// 7. Índices
// ============================================================
if (!$noIdx) {
    $stderr("── 6. ÍNDICES ──");
    $dst->exec('CREATE INDEX idx_codigo ON tbca("Código")');
    $dst->exec('CREATE INDEX idx_nome   ON tbca("Nome")');
    $dst->exec('CREATE INDEX idx_grupo  ON tbca("Grupo")');
    $dst->exec('VACUUM');
    $dst->exec('PRAGMA journal_mode = DELETE');
    $stderr("  ok");
    $stderr("");
}

// ============================================================
// 8. Validação
// ============================================================
$stderr("── 7. VALIDAÇÃO ──");
$n = (int)$dst->query('SELECT COUNT(*) FROM tbca')->fetchColumn();
$stderr("  Linhas:  " . number_format($n));
$stderr("  Tamanho: " . number_format(filesize($OUT_DB)) . " bytes (" . round(filesize($OUT_DB)/1024/1024, 2) . " MB)");

$stderr("");
$stderr("── Amostra ──");
$st = $dst->query('SELECT "Código", "Nome", "Energia |kcal|", "Proteína |g|" FROM tbca LIMIT 3');
foreach ($st as $r) {
    $stderr("  · [{$r['Código']}] {$r['Nome']}");
    $stderr("    {$r['Energia |kcal|']} kcal · {$r['Proteína |g|']} g proteína");
}

$stderr("");
$stderr("── Consistência energética (P×4 + C×4 + G×9 + Fibra×2) ──");
$incons = 0; $tot = 0;
$st = $dst->query('SELECT * FROM tbca WHERE "Energia |kcal|" > 30 LIMIT 300');
foreach ($st as $r) {
    $tot++;
    $p  = (float)($r['Proteína |g|'] ?? 0);
    $c  = (float)($r['Carboidrato disponível |g|'] ?? 0);
    $g  = (float)($r['Lipídios |g|'] ?? 0);
    $f  = (float)($r['Fibra alimentar |g|'] ?? 0);
    $k  = (float)($r['Energia |kcal|'] ?? 0);
    $calc = $p*4 + $c*4 + $g*9 + $f*2;
    if ($k > 0 && abs($k - $calc) > $k * 0.15) $incons++;
}
$stderr("  $incons de $tot fora de 15% (tolerância normal — fórmula de Atwater)");

$stderr("");
$stderr("=== OK ===");
$stderr("Arquivo: $OUT_DB");

// ============================================================
// Helper — converte Medidas (EAV) → [{label, value}]
// ============================================================
function converterMedidas(?string $json): ?string {
    if (empty($json)) return null;
    $arr = json_decode($json, true);
    if (!is_array($arr)) return null;

    $out = [];
    foreach ($arr as $m) {
        if (!is_array($m)) continue;
        $label  = trim((string)($m['label'] ?? ''));
        $peso_g = (float)($m['peso_g'] ?? 0);
        if ($label === '' || $peso_g <= 0) continue;
        $out[] = [
            'label' => $label,
            'value' => round($peso_g / 100, 4),
        ];
    }
    return $out ? json_encode($out, JSON_UNESCAPED_UNICODE) : null;
}
