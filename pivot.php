<?php
/**
 * TBCA Pivot v1.2
 * Converte tbca_bruto.db (EAV) em tbca.db (wide)
 *
 * Modos:
 *   ?action=dashboard   → UI com botão (padrão no navegador)
 *   ?action=check       → JSON com status do ambiente
 *   ?action=run         → executa o pivot, streaming de log
 *
 * CLI:
 *   php pivot.php
 *   php pivot.php --in=X --out=Y
 *   php pivot.php --no-indices
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
@set_time_limit(0);
@ini_set('memory_limit', '512M');

$IS_CLI = (PHP_SAPI === 'cli');

// ============================================================
// CLI
// ============================================================
if ($IS_CLI) {
    $argv_ = $argv ?? [];
    $opts = [];
    foreach ($argv_ as $a) {
        if (preg_match('/^--([^=]+)=(.*)$/', $a, $m)) $opts[$m[1]] = $m[2];
        elseif (preg_match('/^--(.+)$/', $a, $m)) $opts[$m[1]] = true;
    }

    if (!empty($opts['help']) || !empty($opts['h'])) {
        echo "TBCA Pivot v1.2\n\n";
        echo "  php pivot.php\n";
        echo "  php pivot.php --in=X --out=Y\n";
        echo "  php pivot.php --no-indices\n";
        exit(0);
    }

    $IN  = $opts['in']  ?? __DIR__ . '/tbca_bruto.db';
    $OUT = $opts['out'] ?? __DIR__ . '/tbca.db';
    $noIdx = !empty($opts['no-indices']);

    executar_pivot($IN, $OUT, $noIdx, function ($msg) {
        fwrite(STDERR, $msg . "\n");
    });
    exit(0);
}

@ob_end_clean();

// ============================================================
// AJAX: check
// ============================================================
if (isset($_GET['action']) && $_GET['action'] === 'check') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $IN_DB  = __DIR__ . '/tbca_bruto.db';
    $OUT_DB = __DIR__ . '/tbca.db';

    $info = [
        'brutoExiste'    => file_exists($IN_DB),
        'brutoTamanho'   => file_exists($IN_DB) ? filesize($IN_DB) : 0,
        'brutoLinhas'    => 0,
        'brutoCodigos'   => 0,
        'destinoExiste'  => file_exists($OUT_DB),
        'destinoTamanho' => file_exists($OUT_DB) ? filesize($OUT_DB) : 0,
        'destinoLinhas'  => 0,
        'backups'        => [],
    ];

    if (file_exists($IN_DB)) {
        try {
            $pdo = new PDO('sqlite:' . $IN_DB);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $info['brutoLinhas']  = (int)$pdo->query('SELECT COUNT(*) FROM tbca_bruto')->fetchColumn();
            $info['brutoCodigos'] = (int)$pdo->query('SELECT COUNT(DISTINCT "Código") FROM tbca_bruto')->fetchColumn();
        } catch (Throwable $e) {
            $info['brutoErro'] = $e->getMessage();
        }
    }

    if (file_exists($OUT_DB)) {
        try {
            $pdo = new PDO('sqlite:' . $OUT_DB);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $info['destinoLinhas'] = (int)$pdo->query('SELECT COUNT(*) FROM tbca')->fetchColumn();
        } catch (Throwable $e) {}
    }

    foreach (glob(__DIR__ . '/tbca.db.bak_*') as $f) {
        $info['backups'][] = [
            'arquivo'    => basename($f),
            'tamanho'    => filesize($f),
            'modificado' => filemtime($f),
        ];
    }

    echo json_encode(['ok' => true, 'info' => $info], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// AJAX: run (streaming)
// ============================================================
if (isset($_GET['action']) && $_GET['action'] === 'run') {
    while (ob_get_level() > 0) ob_end_clean();

    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Accel-Buffering: no');
    @ob_implicit_flush(true);

    $IN_DB  = __DIR__ . '/tbca_bruto.db';
    $OUT_DB = __DIR__ . '/tbca.db';
    $noIdx  = !empty($_GET['no_indices']);

    $emit = function ($msg, $tipo = 'log') {
        echo $tipo . '|' . str_replace("\n", ' ', $msg) . "\n";
        @flush();
        @ob_flush();
    };

    try {
        executar_pivot($IN_DB, $OUT_DB, $noIdx, $emit);
    } catch (Throwable $e) {
        $emit('ERRO: ' . $e->getMessage(), 'erro');
    }
    exit;
}

// ============================================================
// FUNÇÃO PRINCIPAL
// ============================================================
function executar_pivot($IN_DB, $OUT_DB, $noIdx, $emit) {
    $emit('=== TBCA Pivot ===', 'titulo');
    $emit("Entrada: " . basename($IN_DB), 'info');
    $emit("Saida:   " . basename($OUT_DB), 'info');
    $emit('', 'blank');

    if (!file_exists($IN_DB)) {
        $emit('X Entrada nao encontrada: ' . $IN_DB, 'erro');
        return;
    }

    // ---------- 1. Abre origem ----------
    $emit('-- 1. ABRIR ORIGEM --', 'section');
    $src = new PDO('sqlite:' . $IN_DB);
    $src->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $src->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $src->exec('PRAGMA query_only = ON');

    $tem = $src->query("SELECT name FROM sqlite_master WHERE type='table' AND name='tbca_bruto'")->fetchColumn();
    if (!$tem) {
        $emit("X Tabela 'tbca_bruto' nao existe em " . basename($IN_DB), 'erro');
        return;
    }
    $emit('  OK', 'ok');
    $emit('', 'blank');

    // ---------- 2. Componentes ----------
    $emit('-- 2. COMPONENTES --', 'section');
    $componentes = $src->query('
        SELECT DISTINCT Componente, Unidade
        FROM tbca_bruto
        ORDER BY Componente, Unidade
    ')->fetchAll();

    $colunasFinal = [];
    foreach ($componentes as $c) {
        $colunasFinal[] = $c['Componente'] . ' |' . $c['Unidade'] . '|';
    }

    $emit('  ' . count($colunasFinal) . ' componentes encontrados', 'ok');
    foreach (array_slice($colunasFinal, 0, 6) as $c) {
        $emit('    - ' . $c, 'dim');
    }
    if (count($colunasFinal) > 6) {
        $emit('    - ... e mais ' . (count($colunasFinal) - 6), 'dim');
    }

    $colunasMeta  = ['Código', 'Nome', 'Nome científico', 'Grupo', 'Marca', 'slug', 'Outras medidas'];
    $todasColunas = array_merge($colunasMeta, $colunasFinal);
    $emit('', 'blank');

    // ---------- 3. Cria destino (com backup verificado) ----------
    $emit('-- 3. CRIAR DESTINO --', 'section');
    if (file_exists($OUT_DB)) {
        // Timestamp com microssegundos pra evitar colisão em cliques rápidos
        $micro = substr(str_replace('.', '', microtime(true)), -4);
        $bak = $OUT_DB . '.bak_' . date('YmdHis') . '_' . $micro;
        if (!@rename($OUT_DB, $bak)) {
            $emit('X Nao consegui fazer backup de ' . basename($OUT_DB) . ' - abortando', 'erro');
            return;
        }
        $emit('  Backup do anterior: ' . basename($bak), 'warn');
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
    $emit('  Tabela criada com ' . count($todasColunas) . ' colunas', 'ok');
    $emit('', 'blank');

    // ---------- 4. Metadados ----------
    $emit('-- 4. METADADOS --', 'section');
    $alimento = [];
    foreach ($src->query('SELECT * FROM alimento') as $r) {
        $alimento[$r['Código']] = $r;
    }
    $emit('  ' . count($alimento) . ' itens em "alimento"', 'ok');
    $emit('', 'blank');

    // ---------- 5. Pivot ----------
    $emit('-- 5. PIVOT --', 'section');
    $porCodigo = [];
    $medidasPorCodigo = [];
    $total = 0;

    $stmt = $src->query('SELECT "Código", "Componente", "Unidade", "Por_100g", "Medidas" FROM tbca_bruto');
    while ($r = $stmt->fetch()) {
        $cod = $r['Código'];
        $col = $r['Componente'] . ' |' . $r['Unidade'] . '|';

        if (!isset($porCodigo[$cod])) $porCodigo[$cod] = [];
        $v = $r['Por_100g'];
        if (is_string($v) && preg_match('/^-?\d+,\d+$/', $v)) {
            $v = str_replace(',', '.', $v);
        }
        $porCodigo[$cod][$col] = $v;

        if (!empty($r['Medidas']) && !isset($medidasPorCodigo[$cod])) {
            $medidasPorCodigo[$cod] = $r['Medidas'];
        }

        $total++;
        if ($total % 50000 === 0) {
            $emit('  ' . number_format($total) . ' linhas...', 'dim');
        }
    }
    $emit('  Total: ' . number_format($total) . ' linhas lidas', 'ok');
    $emit('  ' . count($porCodigo) . ' codigos unicos', 'ok');
    $emit('', 'blank');

    // ---------- 6. Insere ----------
    $emit('-- 6. INSERIR --', 'section');
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

        if ($inseridos % 1000 === 0) {
            $emit('  ' . number_format($inseridos) . ' inseridos...', 'dim');
        }
    }
    $dst->commit();
    $emit('  ' . $inseridos . ' inseridos', 'ok');
    $emit('', 'blank');

    // ---------- 7. Índices ----------
    if (!$noIdx) {
        $emit('-- 7. INDICES --', 'section');
        $dst->exec('CREATE INDEX idx_codigo ON tbca("Código")');
        $emit('  idx_codigo OK', 'dim');
        $dst->exec('CREATE INDEX idx_nome ON tbca("Nome")');
        $emit('  idx_nome OK', 'dim');
        $dst->exec('CREATE INDEX idx_grupo ON tbca("Grupo")');
        $emit('  idx_grupo OK', 'dim');

        $emit('  Compactando (VACUUM)...', 'dim');
        $dst->exec('VACUUM');
        $dst->exec('PRAGMA journal_mode = DELETE');
        $emit('  OK', 'ok');
        $emit('', 'blank');
    }

    // ---------- 8. Validação ----------
    $emit('-- 8. VALIDACAO --', 'section');
    $n = (int)$dst->query('SELECT COUNT(*) FROM tbca')->fetchColumn();
    $tam = filesize($OUT_DB);
    $emit('  Linhas:  ' . number_format($n), 'ok');
    $emit('  Tamanho: ' . number_format($tam) . ' bytes (' . round($tam / 1024 / 1024, 2) . ' MB)', 'ok');

    $emit('', 'blank');
    $emit('  Amostra:', 'section');
    $st = $dst->query('SELECT "Código", "Nome", "Energia |kcal|", "Proteína |g|" FROM tbca LIMIT 3');
    foreach ($st as $r) {
        $emit('    - [' . $r['Código'] . '] ' . $r['Nome'], 'dim');
        $emit('      ' . $r['Energia |kcal|'] . ' kcal - ' . $r['Proteína |g|'] . ' g proteina', 'dim');
    }

    $emit('', 'blank');
    $emit('  Consistencia energetica (P*4 + C*4 + G*9 + Fibra*2):', 'section');
    $incons = 0; $tot = 0;
    $st = $dst->query('SELECT * FROM tbca WHERE "Energia |kcal|" > 30 LIMIT 300');
    foreach ($st as $r) {
        $tot++;
        $p = (float)($r['Proteína |g|'] ?? 0);
        $c = (float)($r['Carboidrato disponível |g|'] ?? 0);
        $g = (float)($r['Lipídios |g|'] ?? 0);
        $f = (float)($r['Fibra alimentar |g|'] ?? 0);
        $k = (float)($r['Energia |kcal|'] ?? 0);
        $calc = $p * 4 + $c * 4 + $g * 9 + $f * 2;
        if ($k > 0 && abs($k - $calc) > $k * 0.15) $incons++;
    }
    $emit('    ' . $incons . ' de ' . $tot . ' fora de 15% (tolerancia normal)', $incons > 30 ? 'warn' : 'ok');

    $emit('', 'blank');
    $emit('=== CONCLUIDO ===', 'sucesso');
    $emit('Arquivo: ' . basename($OUT_DB), 'sucesso');
}

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

// ============================================================
// DASHBOARD
// ============================================================
?><!DOCTYPE html>
<html lang="pt-BR" class="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>TBCA Pivot</title>
<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
<style type="text/tailwindcss">@variant dark (&:where(.dark, .dark *));</style>
</head>
<body class="min-h-screen bg-zinc-950 text-zinc-100 antialiased font-sans">

<header class="sticky top-0 z-20 border-b border-zinc-800 bg-zinc-900/90 backdrop-blur">
  <div class="mx-auto flex max-w-5xl items-center gap-3 px-5 py-4 flex-wrap">
    <h1 class="text-lg font-bold tracking-tight">TBCA Pivot</h1>
    <span id="tag-status" class="rounded-full border border-zinc-700 bg-zinc-800 px-3 py-0.5 text-xs text-zinc-400">verificando…</span>
    <div class="flex-1"></div>
    <span id="tag-tempo" class="font-mono text-xs text-zinc-400">—</span>
  </div>
</header>

<main class="mx-auto max-w-5xl px-5 py-6">

  <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
    <div class="rounded-xl border border-zinc-800 bg-zinc-900 p-4">
      <div class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500">tbca_bruto.db</div>
      <div id="i-bruto" class="mt-1 block font-mono text-xl font-bold text-zinc-300">—</div>
      <div id="i-bruto-sub" class="mt-0.5 text-[11px] text-zinc-500">—</div>
    </div>
    <div class="rounded-xl border border-zinc-800 bg-zinc-900 p-4">
      <div class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500">tbca.db (atual)</div>
      <div id="i-dest" class="mt-1 block font-mono text-xl font-bold text-zinc-300">—</div>
      <div id="i-dest-sub" class="mt-0.5 text-[11px] text-zinc-500">—</div>
    </div>
    <div class="rounded-xl border border-zinc-800 bg-zinc-900 p-4">
      <div class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500">Backups</div>
      <div id="i-bkp" class="mt-1 block font-mono text-xl font-bold text-zinc-300">—</div>
      <div id="i-bkp-sub" class="mt-0.5 text-[11px] text-zinc-500">—</div>
    </div>
    <div class="rounded-xl border border-zinc-800 bg-zinc-900 p-4">
      <div class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500">Status</div>
      <div id="i-status" class="mt-1 block font-mono text-xl font-bold text-zinc-300">—</div>
      <div id="i-status-sub" class="mt-0.5 text-[11px] text-zinc-500">—</div>
    </div>
  </div>

  <div class="mb-5 h-1.5 overflow-hidden rounded-full bg-zinc-800">
    <div id="bar" class="h-full w-0 rounded-full bg-gradient-to-r from-emerald-500 to-blue-500 transition-all duration-300"></div>
  </div>

  <div class="mb-6 flex flex-wrap gap-2">
    <button id="btn-run" disabled
            class="rounded-lg bg-emerald-500 px-5 py-2.5 text-sm font-bold text-zinc-950 transition hover:bg-emerald-400 disabled:cursor-not-allowed disabled:opacity-50">
      ▶ Executar pivot
    </button>
    <button id="btn-check"
            class="rounded-lg border border-zinc-700 bg-zinc-800 px-4 py-2.5 text-sm font-semibold text-zinc-200 transition hover:bg-zinc-700">
      ↻ Reverificar
    </button>
    <button id="btn-clear"
            class="rounded-lg border border-zinc-700 bg-zinc-800 px-4 py-2.5 text-sm font-semibold text-zinc-200 transition hover:bg-zinc-700">
      Limpar log
    </button>
  </div>

  <section class="rounded-2xl border border-zinc-800 bg-zinc-900 p-4">
    <h2 class="mb-3 text-sm font-bold text-zinc-300">Log</h2>
    <div id="log"
         class="max-h-[500px] overflow-y-auto rounded-lg bg-zinc-950 p-4 font-mono text-[12px] leading-relaxed">
      <div class="text-zinc-500">Aguardando…</div>
    </div>
  </section>

</main>

<script>
(function () {
  'use strict';

  var $ = function (id) { return document.getElementById(id); };
  var running = false;
  var t0 = null;
  var timerHandle = null;

  var LOG_CLASSES = {
    titulo:  'text-purple-400 font-bold',
    section: 'text-blue-400 font-bold mt-2',
    ok:      'text-emerald-400',
    warn:    'text-amber-400',
    erro:    'text-rose-400 font-bold',
    info:    'text-zinc-400',
    dim:     'text-zinc-500',
    blank:   'h-1.5',
    sucesso: 'text-emerald-400 font-bold text-[13px]'
  };

  function fmtTam(b) {
    if (b < 1024) return b + ' B';
    if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
    return (b / 1048576).toFixed(2) + ' MB';
  }

  function fmtHMS(s) {
    s = Math.max(0, Math.round(s));
    var m = Math.floor(s / 60);
    var sec = s % 60;
    var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
    return m + ':' + pad(sec);
  }

  function addLog(texto, tipo) {
    var log = $('log');
    if (log.children.length === 1 && log.children[0].textContent === 'Aguardando…') {
      log.innerHTML = '';
    }
    var d = document.createElement('div');
    d.className = LOG_CLASSES[tipo] || 'text-zinc-300';
    d.textContent = texto || ' ';
    log.appendChild(d);
    log.scrollTop = log.scrollHeight;
  }

  function limparLog() {
    $('log').innerHTML = '<div class="text-zinc-500">Log limpo. Aguardando…</div>';
  }

  function iniciarTimer() {
    t0 = Date.now();
    if (timerHandle) clearInterval(timerHandle);
    timerHandle = setInterval(function () {
      if (t0) $('tag-tempo').textContent = fmtHMS((Date.now() - t0) / 1000);
    }, 500);
  }

  function pararTimer() {
    if (timerHandle) { clearInterval(timerHandle); timerHandle = null; }
  }

  function setStatus(txt, cor) {
    var el = $('tag-status');
    el.textContent = txt;
    el.className = 'rounded-full border px-3 py-0.5 text-xs font-semibold ' + (cor || 'border-zinc-700 bg-zinc-800 text-zinc-400');
  }

  function check() {
    $('btn-check').disabled = true;
    $('btn-run').disabled = true;

    fetch('?action=check&_=' + Date.now())
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok || !j.info) throw new Error('check falhou');
        var i = j.info;

        if (i.brutoExiste) {
          $('i-bruto').textContent = fmtTam(i.brutoTamanho);
          $('i-bruto').className = 'mt-1 block font-mono text-xl font-bold text-emerald-400';
          $('i-bruto-sub').textContent = i.brutoLinhas + ' linhas · ' + i.brutoCodigos + ' códigos';
        } else {
          $('i-bruto').textContent = 'ausente';
          $('i-bruto').className = 'mt-1 block font-mono text-xl font-bold text-rose-400';
          $('i-bruto-sub').textContent = 'rode o scraper antes';
        }

        if (i.destinoExiste) {
          $('i-dest').textContent = fmtTam(i.destinoTamanho);
          $('i-dest').className = 'mt-1 block font-mono text-xl font-bold text-blue-400';
          $('i-dest-sub').textContent = i.destinoLinhas + ' linhas';
        } else {
          $('i-dest').textContent = 'ausente';
          $('i-dest').className = 'mt-1 block font-mono text-xl font-bold text-zinc-500';
          $('i-dest-sub').textContent = 'será criado';
        }

        var nBkp = i.backups.length;
        $('i-bkp').textContent = nBkp;
        $('i-bkp').className = 'mt-1 block font-mono text-xl font-bold ' + (nBkp > 0 ? 'text-blue-400' : 'text-zinc-500');
        $('i-bkp-sub').textContent = nBkp > 0 ? ('mais recente: …' + i.backups[nBkp - 1].arquivo.slice(-20)) : 'nenhum';

        if (!i.brutoExiste) {
          $('i-status').textContent = 'faltando';
          $('i-status').className = 'mt-1 block font-mono text-xl font-bold text-rose-400';
          $('i-status-sub').textContent = 'tbca_bruto.db não encontrado';
          setStatus('não pode executar', 'border-rose-500/40 bg-rose-500/10 text-rose-400');
        } else {
          $('i-status').textContent = 'pronto';
          $('i-status').className = 'mt-1 block font-mono text-xl font-bold text-emerald-400';
          $('i-status-sub').textContent = 'clique em executar';
          setStatus('pronto', 'border-emerald-500/40 bg-emerald-500/10 text-emerald-400');
          $('btn-run').disabled = false;
        }
      })
      .catch(function (e) {
        addLog('check: ' + e.message, 'erro');
        setStatus('erro no check', 'border-rose-500/40 bg-rose-500/10 text-rose-400');
      })
      .finally(function () {
        $('btn-check').disabled = false;
      });
  }

  function atualizarProgresso(texto, tipo) {
    var bar = $('bar');
    if (tipo === 'erro') {
      bar.style.background = 'linear-gradient(to right, #f43f5e, #e11d48)';
      return;
    }
    if (texto.indexOf('COMPONENTES') !== -1)        bar.style.width = '15%';
    else if (texto.indexOf('CRIAR DESTINO') !== -1) bar.style.width = '25%';
    else if (texto.indexOf('METADADOS') !== -1)     bar.style.width = '35%';
    else if (texto.indexOf('5. PIVOT') !== -1)      bar.style.width = '50%';
    else if (texto.indexOf('6. INSERIR') !== -1)    bar.style.width = '70%';
    else if (texto.indexOf('7. INDICES') !== -1)    bar.style.width = '85%';
    else if (texto.indexOf('8. VALIDACAO') !== -1)  bar.style.width = '95%';
    else if (texto.indexOf('CONCLUIDO') !== -1) {
      bar.style.width = '100%';
      bar.style.background = 'linear-gradient(to right, #10b981, #059669)';
    }
  }

  function executar() {
    if (running) return;
    running = true;
    $('btn-run').disabled = true;
    $('btn-check').disabled = true;

    limparLog();
    addLog('=== INICIANDO PIVOT ===', 'titulo');
    addLog('Conectando…', 'info');

    var bar = $('bar');
    bar.style.width = '0%';
    bar.style.background = 'linear-gradient(to right, #10b981, #3b82f6)';

    setStatus('executando…', 'border-amber-500/40 bg-amber-500/10 text-amber-400');
    iniciarTimer();

    fetch('?action=run&_=' + Date.now())
      .then(function (resp) {
        if (!resp.ok) throw new Error('HTTP ' + resp.status);

        var reader = resp.body.getReader();
        var decoder = new TextDecoder('utf-8');
        var buffer = '';

        function processarLinhas() {
          var idx;
          while ((idx = buffer.indexOf('\n')) !== -1) {
            var linha = buffer.slice(0, idx);
            buffer = buffer.slice(idx + 1);

            if (linha === '') continue;

            var sep = linha.indexOf('|');
            var tipo, texto;
            if (sep >= 0) {
              tipo = linha.slice(0, sep);
              texto = linha.slice(sep + 1);
            } else {
              tipo = 'log';
              texto = linha;
            }

            addLog(texto, tipo);
            atualizarProgresso(texto, tipo);
          }
        }

        function pump() {
          return reader.read().then(function (result) {
            if (result.done) {
              processarLinhas();
              return;
            }
            buffer += decoder.decode(result.value, { stream: true });
            processarLinhas();
            return pump();
          });
        }

        return pump();
      })
      .then(function () {
        addLog('--- Fim do stream ---', 'dim');
        setStatus('concluído', 'border-emerald-500/40 bg-emerald-500/10 text-emerald-400');
        return check();
      })
      .catch(function (e) {
        addLog('ERRO: ' + e.message, 'erro');
        setStatus('erro', 'border-rose-500/40 bg-rose-500/10 text-rose-400');
      })
      .finally(function () {
        running = false;
        pararTimer();
        $('btn-check').disabled = false;
      });
  }

  $('btn-run').addEventListener('click', executar);
  $('btn-check').addEventListener('click', check);
  $('btn-clear').addEventListener('click', limparLog);

  check();
})();
</script>
</body>
</html>
