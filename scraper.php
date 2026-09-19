<?php
/**
 * TBCA Scraper v17.2
 * Extrai dados da TBCA/USP (tbca.net.br)
 *
 * Saída:
 *   - tbca.db        (wide: 1 linha por alimento)
 *   - tbca_bruto.db  (EAV: 1 linha por componente)
 *
 * Licença: MIT. Dados são da TBCA/USP.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
@set_time_limit(0);
@ini_set('memory_limit', '256M');

if (isset($_GET['diag'])) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/plain; charset=utf-8');
    echo "PHP: " . PHP_VERSION . "\n";
    echo "DIR: " . __DIR__ . "\n\n";
    echo "Funções:\n";
    foreach (['curl_init','fsockopen','stream_socket_client','stream_select'] as $f) {
        echo "  $f: " . (function_exists($f) ? 'OK' : 'FALHOU') . "\n";
    }
    echo "\nExtensões:\n";
    foreach (['curl','dom','sqlite3','mbstring','json'] as $e) {
        echo "  $e: " . (extension_loaded($e) ? 'OK' : 'FALHOU') . "\n";
    }
    echo "\nEscrita: " . (@file_put_contents(__DIR__.'/_w.txt','x') !== false ? 'OK' : 'FALHOU');
    @unlink(__DIR__.'/_w.txt');
    echo "\n";
    exit;
}

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) ob_end_clean();
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['error'=>'FATAL: '.$e['message'], 'file'=>basename($e['file']), 'line'=>$e['line']]);
    }
});

$IS_CLI = (PHP_SAPI === 'cli');

function p($name, $default = null) {
    global $argv, $IS_CLI;
    if ($IS_CLI) {
        foreach ((array)$argv as $a) {
            if (preg_match('/^--'.preg_quote($name, '/').'=(.*)$/', $a, $m)) return $m[1];
            if ($a === '--'.$name) return true;
        }
        return $default;
    }
    return $_GET[$name] ?? $_POST[$name] ?? $default;
}

$action = (string)p('action', $IS_CLI ? 'cli' : 'dashboard');
$n      = (int)   p('n', 16);
$delay  = (float) p('delay', 0.05);
$codIns = p('cod', null);

if ($IS_CLI && (p('help') || p('h') || $action === 'help')) {
    echo "TBCA Scraper v17.2\n\n";
    echo "  php scraper.php --action=list\n";
    echo "  php scraper.php --action=step --n=16\n";
    echo "  php scraper.php --action=stats\n";
    echo "  php scraper.php --action=inspect --cod=BRC0001C\n";
    echo "  php scraper.php --action=reset\n";
    exit(0);
}

$BASE         = 'https://www.tbca.net.br';
$LISTING_URL  = $BASE.'/base-dados/composicao_alimentos.php';
$OUT_DB       = __DIR__.'/tbca.db';
$OUT_DB_BRUTO = __DIR__.'/tbca_bruto.db';
$CACHE_DIR    = __DIR__.'/cache_tbca';
$LIST_JSON    = $CACHE_DIR.'/listing.json';
$STATE_JSON   = $CACHE_DIR.'/liststate.json';
$ITEMS_JSONL  = $CACHE_DIR.'/items.jsonl';
$METODO_JSON  = $CACHE_DIR.'/metodo.state.json';
$UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

if (!is_dir($CACHE_DIR)) @mkdir($CACHE_DIR, 0775, true);

function json_out($data, $code = 200) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function http_get($url, $referer = '') {
    global $UA, $CACHE_DIR;
    static $jar = null;
    if ($jar === null) $jar = $CACHE_DIR.'/cookies.txt';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $h = ['Accept-Language: pt-BR,pt;q=0.9'];
        if ($referer) $h[] = "Referer: $referer";
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_USERAGENT => $UA, CURLOPT_ENCODING => '',
            CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => $h,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false || $code >= 400) throw new RuntimeException("HTTP $code - $err");
        return $body;
    }

    $ctx = stream_context_create([
        'http' => ['method'=>'GET', 'header'=>"User-Agent: $UA\r\n".($referer?"Referer: $referer\r\n":''), 'timeout'=>30, 'ignore_errors'=>true],
        'ssl' => ['verify_peer'=>false, 'verify_peer_name'=>false],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) throw new RuntimeException("file_get_contents falhou em $url");
    return $body;
}

function cached_get($url, $referer = '') {
    global $CACHE_DIR, $delay;
    $path = $CACHE_DIR.'/'.md5($url).'.html';
    if (file_exists($path) && filesize($path) > 1000) {
        return file_get_contents($path);
    }
    $html = http_get($url, $referer);
    file_put_contents($path, $html);
    usleep((int)($delay * 1e6));
    return $html;
}

function cookie_header() {
    global $CACHE_DIR;
    $jar = $CACHE_DIR.'/cookies.txt';
    if (!file_exists($jar)) return '';
    $cookies = [];
    foreach (file($jar, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || $line[0] === '#') continue;
        $p = preg_split('/\t/', $line);
        if (count($p) >= 7 && trim($p[5]) !== '') $cookies[] = $p[5].'='.$p[6];
    }
    return $cookies ? ('Cookie: '.implode('; ', $cookies)."\r\n") : '';
}

function decode_chunked_str($data) {
    $out = ''; $pos = 0; $len = strlen($data);
    while ($pos < $len) {
        $nl = strpos($data, "\r\n", $pos);
        if ($nl === false) break;
        $size = hexdec(substr($data, $pos, $nl - $pos));
        if (!is_int($size) || $size <= 0) break;
        $pos = $nl + 2;
        $out .= substr($data, $pos, $size);
        $pos += $size + 2;
    }
    return $out;
}

function parse_http_body($raw) {
    if (!is_string($raw) || $raw === '') return null;
    $pos = strpos($raw, "\r\n\r\n");
    if ($pos === false) return null;
    $head = substr($raw, 0, $pos);
    $body = substr($raw, $pos + 4);
    if (!preg_match('#^HTTP/\d\.\d\s+(\d+)#', $head, $m)) return null;
    if ((int)$m[1] >= 400) return null;
    if (stripos($head, 'Transfer-Encoding: chunked') !== false) $body = decode_chunked_str($body);
    return strlen($body) > 200 ? $body : null;
}

function metodos_ordenados() {
    return [
        ['tipo'=>'stream', 'paral'=>16],
        ['tipo'=>'stream', 'paral'=>12],
        ['tipo'=>'stream', 'paral'=>8],
        ['tipo'=>'stream', 'paral'=>4],
        ['tipo'=>'fsock',  'paral'=>4],
        ['tipo'=>'curl_seq','paral'=>1],
        ['tipo'=>'fopen_seq','paral'=>1],
    ];
}

function metodo_estado_carregar() {
    global $METODO_JSON;
    if (!file_exists($METODO_JSON)) return ['indice'=>0, 'falhas_seguidas'=>0, 'historico'=>[]];
    $j = json_decode(file_get_contents($METODO_JSON), true);
    return is_array($j) ? $j : ['indice'=>0, 'falhas_seguidas'=>0, 'historico'=>[]];
}

function metodo_estado_salvar($st) {
    global $METODO_JSON;
    @file_put_contents($METODO_JSON, json_encode($st, JSON_UNESCAPED_UNICODE));
}

function metodo_atual() {
    $lista = metodos_ordenados();
    $st = metodo_estado_carregar();
    $i = max(0, min((int)($st['indice'] ?? 0), count($lista)-1));
    return $lista[$i];
}

function metodo_resetar() {
    metodo_estado_salvar(['indice'=>0, 'falhas_seguidas'=>0, 'historico'=>[]]);
}

function metodo_avancar($motivo) {
    $lista = metodos_ordenados();
    $st = metodo_estado_carregar();
    $st['indice'] = min((int)$st['indice'] + 1, count($lista)-1);
    $st['falhas_seguidas'] = 0;
    $st['historico'][] = ['ts'=>time(), 'motivo'=>$motivo, 'novo_indice'=>$st['indice']];
    if (count($st['historico']) > 30) $st['historico'] = array_slice($st['historico'], -30);
    metodo_estado_salvar($st);
}

function cached_get_multi_switch($urls, $referer = '') {
    global $CACHE_DIR, $delay;
    if (empty($urls)) return [];

    $out = []; $faltam = [];
    foreach ($urls as $u) {
        $p = $CACHE_DIR.'/'.md5($u).'.html';
        if (file_exists($p) && filesize($p) > 1000) {
            $out[$u] = file_get_contents($p);
        } else {
            $faltam[] = $u;
        }
    }
    if (empty($faltam)) return $out;

    $met = metodo_atual();
    $res = executar_metodo_coleta($met, $faltam, $referer);

    $ok = 0; $total = count($faltam);
    foreach ($res as $u => $html) {
        if (is_string($html) && $html !== '') {
            file_put_contents($CACHE_DIR.'/'.md5($u).'.html', $html);
            $out[$u] = $html;
            $ok++;
        } else {
            $out[$u] = null;
        }
    }
    foreach ($faltam as $u) if (!isset($out[$u])) $out[$u] = null;

    $st = metodo_estado_carregar();
    $falhou = ($ok < (int)ceil($total / 2));

    if ($falhou) {
        $st['falhas_seguidas'] = (int)($st['falhas_seguidas'] ?? 0) + 1;
        if ($st['falhas_seguidas'] >= 2) {
            $lista = metodos_ordenados();
            if ((int)$st['indice'] < count($lista)-1) {
                metodo_avancar("ok=$ok/$total em {$met['tipo']}@{$met['paral']}");
            } else {
                $st['falhas_seguidas'] = 0;
                metodo_estado_salvar($st);
            }
        } else {
            metodo_estado_salvar($st);
        }
    } else {
        if ((int)($st['falhas_seguidas'] ?? 0) !== 0) {
            $st['falhas_seguidas'] = 0;
            metodo_estado_salvar($st);
        }
    }

    if ($delay > 0) usleep((int)($delay * 1e6));
    return $out;
}

function executar_metodo_coleta($met, $urls, $referer) {
    try {
        switch ($met['tipo']) {
            case 'stream':     return http_get_paralelo_stream($urls, $referer, (int)$met['paral']);
            case 'fsock':      return http_get_paralelo_fsock($urls, $referer, (int)$met['paral']);
            case 'curl_seq':   return http_get_curl_seq($urls, $referer);
            case 'fopen_seq':  return http_get_fopen_seq($urls, $referer);
        }
    } catch (Throwable $e) {}
    $out = [];
    foreach ($urls as $u) $out[$u] = null;
    return $out;
}

function http_get_paralelo_stream($urls, $referer, $paral) {
    global $UA;
    $cookieHeader = cookie_header();
    $out = [];
    foreach (array_chunk($urls, max(1, $paral)) as $chunk) {
        $handles = []; $buffers = [];
        foreach ($chunk as $i => $url) {
            $p = parse_url($url);
            if (!$p || !isset($p['host'])) continue;
            $host = $p['host'];
            $ssl  = ($p['scheme'] === 'https') ? 'ssl://' : 'tcp://';
            $port = ($p['scheme'] === 'https') ? 443 : 80;
            $path = ($p['path'] ?? '/').(isset($p['query']) ? '?'.$p['query'] : '');

            $ctx = stream_context_create(['ssl'=>['verify_peer'=>false, 'verify_peer_name'=>false, 'allow_self_signed'=>true]]);
            $errno = 0; $errstr = '';
            $fp = @stream_socket_client("$ssl$host:$port", $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
            if (!$fp) continue;
            stream_set_blocking($fp, false);
            stream_set_timeout($fp, 15);

            $req  = "GET $path HTTP/1.1\r\nHost: $host\r\n";
            $req .= "User-Agent: $UA\r\nAccept: text/html\r\nAccept-Language: pt-BR\r\n";
            $req .= "Accept-Encoding: identity\r\nConnection: close\r\n";
            if ($referer) $req .= "Referer: $referer\r\n";
            if ($cookieHeader) $req .= $cookieHeader;
            $req .= "\r\n";

            @fwrite($fp, $req);
            $handles[$i] = $fp;
            $buffers[$i] = '';
        }

        $ativos = $handles;
        $deadline = microtime(true) + 20;
        while (!empty($ativos) && microtime(true) < $deadline) {
            $read = array_values($ativos); $w = null; $e = null;
            $k = @stream_select($read, $w, $e, 1, 0);
            if ($k === false || $k === 0) {
                foreach ($ativos as $i => $fp) if (feof($fp)) { @fclose($fp); unset($ativos[$i]); }
                continue;
            }
            foreach ($read as $fp) {
                $i = array_search($fp, $ativos, true);
                if ($i === false) continue;
                $c = @fread($fp, 65536);
                if ($c === '' || $c === false) {
                    if (feof($fp)) { @fclose($fp); unset($ativos[$i]); }
                    continue;
                }
                $buffers[$i] .= $c;
                if (feof($fp)) { @fclose($fp); unset($ativos[$i]); }
            }
        }
        foreach ($ativos as $fp) @fclose($fp);

        foreach ($chunk as $i => $url) {
            $out[$url] = parse_http_body($buffers[$i] ?? '');
        }
    }
    return $out;
}

function http_get_paralelo_fsock($urls, $referer, $paral) {
    global $UA;
    $cookieHeader = cookie_header();
    $out = [];
    foreach (array_chunk($urls, max(1, $paral)) as $chunk) {
        $handles = []; $buffers = [];
        foreach ($chunk as $i => $url) {
            $p = parse_url($url);
            if (!$p || !isset($p['host'])) continue;
            $host = $p['host'];
            $ssl  = ($p['scheme'] === 'https') ? 'ssl://' : '';
            $port = ($p['scheme'] === 'https') ? 443 : 80;
            $path = ($p['path'] ?? '/').(isset($p['query']) ? '?'.$p['query'] : '');

            $errno = 0; $errstr = '';
            $fp = @fsockopen("$ssl$host", $port, $errno, $errstr, 8);
            if (!$fp) continue;
            stream_set_blocking($fp, false);
            stream_set_timeout($fp, 15);

            $req  = "GET $path HTTP/1.1\r\nHost: $host\r\n";
            $req .= "User-Agent: $UA\r\nAccept: text/html\r\n";
            $req .= "Accept-Encoding: identity\r\nConnection: close\r\n";
            if ($referer) $req .= "Referer: $referer\r\n";
            if ($cookieHeader) $req .= $cookieHeader;
            $req .= "\r\n";

            @fwrite($fp, $req);
            $handles[$i] = $fp;
            $buffers[$i] = '';
        }

        $ativos = $handles;
        $deadline = microtime(true) + 20;
        while (!empty($ativos) && microtime(true) < $deadline) {
            $read = array_values($ativos); $w = null; $e = null;
            $k = @stream_select($read, $w, $e, 1, 0);
            if ($k === false || $k === 0) {
                foreach ($ativos as $i => $fp) if (feof($fp)) { @fclose($fp); unset($ativos[$i]); }
                continue;
            }
            foreach ($read as $fp) {
                $i = array_search($fp, $ativos, true);
                if ($i === false) continue;
                $c = @fread($fp, 65536);
                if ($c === '' || $c === false) {
                    if (feof($fp)) { @fclose($fp); unset($ativos[$i]); }
                    continue;
                }
                $buffers[$i] .= $c;
                if (feof($fp)) { @fclose($fp); unset($ativos[$i]); }
            }
        }
        foreach ($ativos as $fp) @fclose($fp);

        foreach ($chunk as $i => $url) {
            $out[$url] = parse_http_body($buffers[$i] ?? '');
        }
    }
    return $out;
}

function http_get_curl_seq($urls, $referer) {
    global $UA, $CACHE_DIR;
    $out = [];
    if (!function_exists('curl_init')) { foreach ($urls as $u) $out[$u] = null; return $out; }
    $jar = $CACHE_DIR.'/cookies.txt';
    foreach ($urls as $url) {
        $ch = curl_init($url);
        $h = ['Accept-Language: pt-BR,pt;q=0.9'];
        if ($referer) $h[] = "Referer: $referer";
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_USERAGENT => $UA, CURLOPT_ENCODING => '',
            CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false, CURLOPT_HTTPHEADER => $h,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $out[$url] = ($body !== false && $code < 400) ? $body : null;
    }
    return $out;
}

function http_get_fopen_seq($urls, $referer) {
    global $UA;
    $out = [];
    foreach ($urls as $url) {
        $ctx = stream_context_create([
            'http' => ['method'=>'GET', 'header'=>"User-Agent: $UA\r\nAccept-Encoding: identity\r\n".($referer?"Referer: $referer\r\n":''), 'timeout'=>15, 'ignore_errors'=>true],
            'ssl' => ['verify_peer'=>false, 'verify_peer_name'=>false],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        $out[$url] = ($body !== false && strlen($body) > 200) ? $body : null;
    }
    return $out;
}

function resolve_url($href, $base) {
    if (stripos($href, 'http') === 0) return $href;
    if ($href[0] === '/') return $base.$href;
    return $base.'/base-dados/'.$href;
}

function load_html($html) {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
    libxml_clear_errors();
    return $dom;
}

function load_itens() {
    global $LIST_JSON;
    if (!file_exists($LIST_JSON)) return [];
    $itens = json_decode(file_get_contents($LIST_JSON), true);
    if (!is_array($itens)) return [];
    foreach ($itens as &$it) {
        if (!isset($it['Código']) && isset($it['Codigo'])) $it['Código'] = $it['Codigo'];
        if (!isset($it['Nome científico']) && isset($it['Nome Cientifico'])) $it['Nome científico'] = $it['Nome Cientifico'];
    }
    unset($it);
    return $itens;
}

function list_step() {
    global $LISTING_URL, $LIST_JSON, $STATE_JSON, $ITEMS_JSONL, $BASE;
    if (file_exists($LIST_JSON)) return ['done'=>true, 'total'=>count(load_itens()), 'page'=>'ok'];

    $state = null;
    if (file_exists($STATE_JSON)) $state = json_decode(file_get_contents($STATE_JSON), true);
    if (!is_array($state) || empty($state)) {
        $state = ['queue'=>[$LISTING_URL], 'visited'=>[], 'page'=>0, 'novos'=>0];
        @unlink($ITEMS_JSONL);
    }
    $state['visited'] = $state['visited'] ?? [];
    $state['queue']   = $state['queue']   ?? [];
    $state['novos']   = $state['novos']   ?? 0;
    $state['page']    = $state['page']    ?? 0;

    if (empty($state['queue'])) return finalizar_listagem($state);

    $url = array_shift($state['queue']);
    if (isset($state['visited'][$url])) {
        file_put_contents($STATE_JSON, json_encode($state, JSON_UNESCAPED_UNICODE));
        return ['done'=>false, 'page'=>$state['page'], 'total'=>$state['novos'], 'skipped'=>true];
    }
    $state['visited'][$url] = true;
    $state['page']++;

    $html = cached_get($url, $LISTING_URL);
    $dom  = load_html($html);
    $xp   = new DOMXPath($dom);

    $existentes = carregar_codigos_existentes();
    $append = [];

    foreach ($xp->query('//table//tr') as $tr) {
        $tds = $xp->query('./td', $tr);
        if ($tds->length < 2) continue;
        $td0 = $tds->item(0);
        $codigo = trim($td0->textContent);
        if ($codigo === '' || strlen($codigo) > 30) continue;
        if (isset($existentes[$codigo])) continue;
        $a = $xp->query('.//a', $td0)->item(0);
        if (!$a) continue;
        $href = $a->getAttribute('href');
        if ($href === '' || $href === '#') continue;
        $full = resolve_url($href, $BASE);
        $append[$codigo] = [
            'Código' => $codigo,
            'Nome' => trim($tds->item(1)->textContent),
            'Nome científico' => $tds->length > 2 ? trim($tds->item(2)->textContent) : '',
            'Grupo' => $tds->length > 3 ? trim($tds->item(3)->textContent) : '',
            'Marca' => $tds->length > 4 ? trim($tds->item(4)->textContent) : '',
            'url' => $full,
        ];
        $existentes[$codigo] = true;
    }
    if ($append) {
        $f = fopen($ITEMS_JSONL, 'a');
        foreach ($append as $it) fwrite($f, json_encode($it, JSON_UNESCAPED_UNICODE)."\n");
        fclose($f);
        $state['novos'] += count($append);
    }

    foreach ($xp->query('//a[contains(@href, "pagina=")]') as $a) {
        $href = $a->getAttribute('href');
        if ($href === '' || $href === '#') continue;
        $full = resolve_url($href, $BASE);
        if (isset($state['visited'][$full])) continue;
        if (in_array($full, $state['queue'], true)) continue;
        $state['queue'][] = $full;
    }

    if (empty($state['queue'])) return finalizar_listagem($state);

    file_put_contents($STATE_JSON, json_encode($state, JSON_UNESCAPED_UNICODE));
    return ['done'=>false, 'page'=>$state['page'], 'total'=>$state['novos'], 'novos'=>count($append), 'fila'=>count($state['queue'])];
}

function carregar_codigos_existentes() {
    global $ITEMS_JSONL;
    $codigos = [];
    if (!file_exists($ITEMS_JSONL)) return $codigos;
    foreach (file($ITEMS_JSONL, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linha) {
        if (preg_match('/"Código":"((?:[^"\\\\]|\\\\.)+)"/', $linha, $m)) {
            $codigos[stripslashes($m[1])] = true;
        }
    }
    return $codigos;
}

function finalizar_listagem($state) {
    global $ITEMS_JSONL, $LIST_JSON, $STATE_JSON;
    $items = [];
    if (file_exists($ITEMS_JSONL)) {
        foreach (file($ITEMS_JSONL, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linha) {
            $it = json_decode($linha, true);
            if ($it && isset($it['Código'])) $items[$it['Código']] = $it;
        }
    }
    file_put_contents($LIST_JSON, json_encode(array_values($items), JSON_UNESCAPED_UNICODE));
    @unlink($STATE_JSON); @unlink($ITEMS_JSONL);
    return ['done'=>true, 'page'=>$state['page'] ?? 0, 'total'=>count($items)];
}

function parse_valor($raw) {
    $raw = trim((string)$raw);
    if ($raw === '' || strtoupper($raw) === 'NA' || strtoupper($raw) === 'ND') return null;
    if (strtolower($raw) === 'tr') return '0';
    $raw = ltrim($raw, '*');
    if (preg_match('/^-?\d{1,3}(\.\d{3})*(,\d+)?$/', $raw)) {
        $raw = str_replace(['.', ','], ['', '.'], $raw);
    } else {
        $raw = str_replace(',', '.', $raw);
    }
    if (!is_numeric($raw)) return null;
    $s = rtrim(rtrim(number_format((float)$raw, 3, '.', ''), '0'), '.');
    return $s === '' ? '0' : $s;
}

function parse_nutrientes($html) {
    $dom = load_html($html);
    $xp  = new DOMXPath($dom);
    $melhor = null; $max = 0;
    foreach ($xp->query('//table') as $t) {
        $q = $xp->query('.//tr', $t)->length;
        if ($q > $max) { $max = $q; $melhor = $t; }
    }
    $dados = [];
    if (!$melhor) return $dados;
    $idx100 = 2;
    foreach ($xp->query('.//tr[1]//th | .//tr[1]//td', $melhor) as $i => $cell) {
        $t = mb_strtolower(trim($cell->textContent), 'UTF-8');
        if (strpos($t, '100') !== false && strpos($t, 'g') !== false) { $idx100 = $i; break; }
    }
    foreach ($xp->query('.//tr', $melhor) as $tr) {
        $tds = $xp->query('.//td|.//th', $tr);
        if ($tds->length < 2) continue;
        $nome = trim($tds->item(0)->textContent);
        if ($nome === '' || mb_strtolower($nome, 'UTF-8') === 'componente') continue;
        $unid = $tds->length > 1 ? trim($tds->item(1)->textContent) : '';
        $idx  = min($idx100, $tds->length - 1);
        $raw  = trim($tds->item($idx)->textContent);
        $v = parse_valor($raw);
        if ($v === null) continue;
        $chave = ($unid !== '' && preg_match('/^[a-zçãéíóú%µ\/]+$/ui', $unid)) ? "$nome |$unid|" : $nome;
        $dados[$chave] = $v;
    }
    return $dados;
}

function parse_tabela_completa($html) {
    $dom = load_html($html);
    $xp  = new DOMXPath($dom);
    $melhor = null; $max = 0;
    foreach ($xp->query('//table') as $t) {
        $q = $xp->query('.//tr', $t)->length;
        if ($q > $max) { $max = $q; $melhor = $t; }
    }
    if (!$melhor) return ['headers'=>[], 'rows'=>[]];
    $headers = [];
    $firstTr = $xp->query('(.//tr)[1]', $melhor)->item(0);
    if ($firstTr) {
        foreach ($xp->query('.//th | .//td', $firstTr) as $i => $c) {
            $headers[$i] = trim(preg_replace('/\s+/u', ' ', $c->textContent));
        }
    }
    $rows = [];
    foreach ($xp->query('.//tr', $melhor) as $tr) {
        $tds = $xp->query('.//td|.//th', $tr);
        if ($tds->length < 2) continue;
        $comp = trim($tds->item(0)->textContent);
        if ($comp === '' || mb_strtolower($comp, 'UTF-8') === 'componente') continue;
        $r = ['componente' => $comp];
        foreach ($tds as $i => $td) {
            if ($i === 0) continue;
            $txt = trim($td->textContent);
            if ($i >= 2 && $txt !== '') {
                $v = parse_valor($txt);
                $txt = ($v === null) ? '' : $v;
            }
            $r[$i] = $txt;
        }
        $rows[] = $r;
    }
    return ['headers'=>$headers, 'rows'=>$rows];
}

function parse_outras_medidas($html) {
    $dom = load_html($html);
    $xp  = new DOMXPath($dom);
    $out = [];
    foreach ($xp->query('//*[not(*)]') as $node) {
        $txt = trim(preg_replace('/\s+/u', ' ', $node->textContent));
        if ($txt === '' || mb_strlen($txt) > 120) continue;
        if (preg_match('/^(.+?)\s*\(\s*(\d+(?:[.,]\d+)?)\s*g\s*\)\s*$/iu', $txt, $m)) {
            $label = trim($m[1]);
            $peso  = parse_valor($m[2]);
            if ($label === '' || $peso === null) continue;
            if (preg_match('/^por\s+100\s*g$/iu', $label)) continue;
            $out[$label] = ['label'=>$label, 'v'=>$peso];
        }
    }
    return array_values($out);
}

function slugify($nome) {
    $s = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nome);
    if ($s === false || $s === '') {
        $s = strtr($nome, ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n','Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A','Ä'=>'A','É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I','Ó'=>'O','Ò'=>'O','Õ'=>'O','Ô'=>'O','Ö'=>'O','Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U','Ç'=>'C','Ñ'=>'N']);
    }
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

function abrir_db($path, $reset = false) {
    if ($reset && file_exists($path)) { @unlink($path); @unlink($path.'-wal'); @unlink($path.'-shm'); }
    $pdo = new PDO('sqlite:'.$path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    @$pdo->exec('PRAGMA journal_mode=WAL');
    @$pdo->exec('PRAGMA busy_timeout=5000');
    $pdo->exec('CREATE TABLE IF NOT EXISTS tbca (
        "Código" VARCHAR(10) PRIMARY KEY,"Nome" VARCHAR(255),"Nome científico" VARCHAR(255),
        "Grupo" VARCHAR(50),"Marca" VARCHAR(50),
        "Energia |kJ|" DECIMAL(5,2),"Energia |kcal|" DECIMAL(5,2),"Umidade |g|" DECIMAL(5,2),
        "Carboidrato total |g|" DECIMAL(5,2),"Carboidrato disponível |g|" DECIMAL(5,2),
        "Proteína |g|" DECIMAL(5,2),"Lipídios |g|" DECIMAL(5,2),"Fibra alimentar |g|" DECIMAL(5,2),
        "Álcool |g|" DECIMAL(5,2),"Cinzas |g|" DECIMAL(5,2),"Colesterol |mg|" DECIMAL(5,2),
        "Ácidos graxos saturados |g|" DECIMAL(5,2),"Ácidos graxos monoinsaturados |g|" DECIMAL(5,2),
        "Ácidos graxos poliinsaturados |g|" DECIMAL(5,2),"Ácidos graxos trans |g|" DECIMAL(5,2),
        "Cálcio |mg|" DECIMAL(5,2),"Ferro |mg|" DECIMAL(5,2),"Sódio |mg|" DECIMAL(5,2),
        "Magnésio |mg|" DECIMAL(5,2),"Fósforo |mg|" DECIMAL(5,2),"Potássio |mg|" DECIMAL(5,2),
        "Manganês |mg|" DECIMAL(5,2),"Zinco |mg|" DECIMAL(5,2),"Cobre |mg|" DECIMAL(5,2),
        "Selênio |mcg|" DECIMAL(5,2),"Vitamina A (RE) |mcg|" DECIMAL(5,2),
        "Vitamina A (RAE) |mcg|" DECIMAL(5,2),"Vitamina D |mcg|" DECIMAL(5,2),
        "Alfa-tocoferol (Vitamina E) |mg|" DECIMAL(5,2),"Tiamina |mg|" DECIMAL(5,2),
        "Riboflavina |mg|" DECIMAL(5,2),"Niacina |mg|" DECIMAL(5,2),"Vitamina B6 |mg|" DECIMAL(5,2),
        "Vitamina B12 |mcg|" DECIMAL(5,2),"Vitamina C |mg|" DECIMAL(5,2),
        "Equivalente de folato |mcg|" DECIMAL(5,2),"Sal de adição |g|" DECIMAL(5,2),
        "Açúcar de adição |g|" DECIMAL(5,2),"Gordura de adição |g|" DECIMAL(5,2),
        "Proteína vegetal |g|" DECIMAL(5,2),"Proteína animal |g|" DECIMAL(5,2),
        "Outras medidas" TEXT,"slug" VARCHAR(255))');
    return $pdo;
}

function abrir_db_bruto($path, $reset = false) {
    if ($reset && file_exists($path)) { @unlink($path); @unlink($path.'-wal'); @unlink($path.'-shm'); }
    $pdo = new PDO('sqlite:'.$path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    @$pdo->exec('PRAGMA journal_mode=WAL');
    @$pdo->exec('PRAGMA busy_timeout=5000');
    $pdo->exec('CREATE TABLE IF NOT EXISTS tbca_bruto (
        "Código" VARCHAR(10),"Nome" VARCHAR(255),"Componente" VARCHAR(255),
        "Unidade" VARCHAR(20),"Por_100g" TEXT,"Medidas" TEXT,
        PRIMARY KEY ("Código","Componente","Unidade"))');
    $pdo->exec('CREATE TABLE IF NOT EXISTS alimento (
        "Código" VARCHAR(10) PRIMARY KEY,"Nome" VARCHAR(255),"Nome_cientifico" VARCHAR(255),
        "Grupo" VARCHAR(50),"Marca" VARCHAR(50),"slug" VARCHAR(255))');
    return $pdo;
}

function inserir(PDO $pdo, $linha) {
    $c = array_keys($linha);
    $cq = array_map(fn($x) => '"'.str_replace('"','',$x).'"', $c);
    $ph = implode(',', array_fill(0, count($c), '?'));
    $st = $pdo->prepare('INSERT OR REPLACE INTO tbca ('.implode(',', $cq).') VALUES ('.$ph.')');
    $st->execute(array_values($linha));
}

function codigos_existentes(PDO $pdo) {
    $done = [];
    try { foreach ($pdo->query('SELECT "Código" FROM tbca') as $r) $done[$r['Código']] = true; } catch (Throwable $e) {}
    return $done;
}

if ($action === 'stats') {
    $hasList = file_exists($LIST_JSON);
    $listTotal = $hasList ? count(load_itens()) : 0;
    $dbDone = 0; $colsList = [];
    if (file_exists($OUT_DB)) {
        try {
            $pdo = abrir_db($OUT_DB);
            $dbDone = (int)$pdo->query('SELECT COUNT(*) FROM tbca')->fetchColumn();
            foreach ($pdo->query('PRAGMA table_info(tbca)') as $r) $colsList[] = $r['name'];
        } catch (Throwable $e) {}
    }
    $listPage = 0; $listParcial = 0; $listFila = 0;
    if (file_exists($STATE_JSON)) {
        $st = json_decode(file_get_contents($STATE_JSON), true) ?: [];
        $listPage = $st['page'] ?? 0;
        $listParcial = $st['novos'] ?? 0;
        $listFila = count($st['queue'] ?? []);
    }
    $met = metodo_atual();
    json_out([
        'listDone' => $hasList, 'listTotal' => $listTotal,
        'listPage' => $listPage, 'listParcial' => $listParcial, 'listFila' => $listFila,
        'dbDone' => $dbDone, 'cols' => $colsList,
        'metodo' => $met['tipo'].'@'.$met['paral'],
    ]);
}

if ($action === 'list') {
    try { json_out(list_step()); }
    catch (Throwable $e) { json_out(['error'=>$e->getMessage()], 500); }
}

if ($action === 'step') {
    if (!file_exists($LIST_JSON)) json_out(['error'=>'Listagem ainda nao pronta'], 400);
    try {
        $itens = load_itens();
        $pdo = abrir_db($OUT_DB);
        $pdoBruto = abrir_db_bruto($OUT_DB_BRUTO);
        $done = codigos_existentes($pdo);
    } catch (Throwable $e) {
        json_out(['error'=>'abertura: '.$e->getMessage()], 500);
    }

    $lote = [];
    foreach ($itens as $it) {
        if (count($lote) >= $n) break;
        $cod = $it['Código'] ?? $it['Codigo'] ?? '';
        if ($cod === '' || isset($done[$cod])) continue;
        $lote[$cod] = $it;
    }

    if (empty($lote)) {
        $doneNow = (int)$pdo->query('SELECT COUNT(*) FROM tbca')->fetchColumn();
        $met = metodo_atual();
        json_out([
            'processed'=>0, 'ok'=>0, 'fails'=>0,
            'done'=>$doneNow, 'total'=>count($itens),
            'remaining'=>max(0, count($itens) - $doneNow),
            'metodo'=>$met['tipo'].'@'.$met['paral'],
            'log'=>[], 'elapsed'=>0,
        ]);
    }

    $inicio = microtime(true);
    $urls = [];
    foreach ($lote as $it) $urls[] = $it['url'];
    $htmls = cached_get_multi_switch($urls, $LISTING_URL);

    $ok = 0; $falhas = 0; $log = [];

    $stA = $pdoBruto->prepare('INSERT OR REPLACE INTO alimento
        ("Código","Nome","Nome_cientifico","Grupo","Marca","slug") VALUES (?,?,?,?,?,?)');
    $stB = $pdoBruto->prepare('INSERT OR REPLACE INTO tbca_bruto
        ("Código","Nome","Componente","Unidade","Por_100g","Medidas") VALUES (?,?,?,?,?,?)');

    foreach ($lote as $cod => $it) {
        $html = $htmls[$it['url']] ?? null;
        if ($html === null) { $log[] = "X $cod - download falhou"; $falhas++; continue; }

        try {
            $linha = ['Código' => $cod];
            foreach (['Nome','Nome científico','Grupo','Marca'] as $k) {
                if (!empty($it[$k])) $linha[$k] = $it[$k];
            }
            if (!empty($it['Nome'])) $linha['slug'] = slugify($it['Nome']);
            foreach (parse_nutrientes($html) as $k => $v) $linha[$k] = $v;
            $med = parse_outras_medidas($html);
            if ($med) $linha['Outras medidas'] = json_encode($med, JSON_UNESCAPED_UNICODE);
            inserir($pdo, $linha);
        } catch (Throwable $e) {
            $log[] = "X $cod - ".$e->getMessage();
            $falhas++;
            continue;
        }

        try {
            $tab = parse_tabela_completa($html);
            if (!empty($tab['rows'])) {
                $stA->execute([
                    $cod,
                    $it['Nome'] ?? '',
                    $it['Nome científico'] ?? ($it['Nome Cientifico'] ?? ''),
                    $it['Grupo'] ?? '',
                    $it['Marca'] ?? '',
                    slugify($it['Nome'] ?? ''),
                ]);
                foreach ($tab['rows'] as $r) {
                    $comp = $r['componente'];
                    $unid = $r[1] ?? '';
                    $p100 = $r[2] ?? '';
                    $medidas = [];
                    for ($i = 3; $i <= 30; $i++) {
                        if (!isset($r[$i])) break;
                        $val = $r[$i];
                        if ($val === '' || $val === '—' || $val === '-') continue;
                        $rotulo = $tab['headers'][$i] ?? ('coluna_'.$i);
                        $peso = null;
                        if (preg_match('/\((\d+(?:[.,]\d+)?)\s*g\)/u', $rotulo, $m)) {
                            $peso = parse_valor($m[1]);
                            $rotulo = trim(preg_replace('/\s*\(\s*\d+(?:[.,]\d+)?\s*g\s*\)\s*$/u', '', $rotulo));
                        }
                        $medidas[] = ['label'=>$rotulo, 'peso_g'=>$peso, 'valor'=>$val];
                    }
                    $stB->execute([
                        $cod,
                        $it['Nome'] ?? '',
                        $comp, $unid, $p100,
                        $medidas ? json_encode($medidas, JSON_UNESCAPED_UNICODE) : null,
                    ]);
                }
            }
        } catch (Throwable $e) {
            $log[] = "! $cod (bruto) - ".$e->getMessage();
        }

        $ok++;
        $log[] = "+ $cod - ".count($linha)." campos";
    }

    $doneNow = (int)$pdo->query('SELECT COUNT(*) FROM tbca')->fetchColumn();
    $total = count($itens);
    $met = metodo_atual();
    json_out([
        'processed'=>count($lote), 'ok'=>$ok, 'fails'=>$falhas,
        'done'=>$doneNow, 'total'=>$total,
        'remaining'=>max(0, $total - $doneNow),
        'metodo'=>$met['tipo'].'@'.$met['paral'],
        'log'=>$log, 'elapsed'=>round(microtime(true) - $inicio, 2),
    ]);
}

if ($action === 'inspect') {
    if (!$codIns) json_out(['error'=>'Falta &cod='], 400);
    if (!file_exists($LIST_JSON)) json_out(['error'=>'Listagem nao pronta'], 400);
    $itens = load_itens();
    $alvo = strtoupper($codIns);
    foreach ($itens as $it) {
        $cod = $it['Código'] ?? '';
        if ($cod === '' || stripos($cod, $alvo) === false) continue;
        try {
            $html = cached_get($it['url'], $LISTING_URL);
            json_out([
                'cod'=>$cod, 'url'=>$it['url'], 'bytes'=>strlen($html),
                'meta'=>array_intersect_key($it, array_flip(['Código','Nome','Nome científico','Grupo','Marca'])),
                'slug'=>slugify($it['Nome'] ?? ''),
                'nutrientes'=>parse_nutrientes($html),
                'outras_medidas'=>parse_outras_medidas($html),
            ]);
        } catch (Throwable $e) { json_out(['error'=>$e->getMessage()], 500); }
    }
    json_out(['error'=>"Nao encontrado: $alvo"], 404);
}

if ($action === 'reset') {
    foreach ([$OUT_DB, $OUT_DB_BRUTO] as $f) { @unlink($f); @unlink($f.'-wal'); @unlink($f.'-shm'); }
    foreach (glob($CACHE_DIR.'/*') as $f) @unlink($f);
    metodo_resetar();
    json_out(['ok'=>true]);
}

if ($action === 'reset-metodo') {
    metodo_resetar();
    $met = metodo_atual();
    json_out(['ok'=>true, 'metodo'=>$met['tipo'].'@'.$met['paral']]);
}

if (!$IS_CLI && $action === 'dashboard'):
?><!DOCTYPE html>
<html lang="pt-BR" class="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>TBCA Scraper</title>
<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
<style type="text/tailwindcss">@variant dark (&:where(.dark, .dark *));</style>
</head>
<body class="min-h-screen bg-zinc-950 text-zinc-100 antialiased font-sans">

<header class="sticky top-0 z-20 border-b border-zinc-800 bg-zinc-900/90 backdrop-blur">
  <div class="mx-auto flex max-w-5xl items-center gap-3 px-5 py-4 flex-wrap">
    <h1 class="text-lg font-bold tracking-tight">TBCA Scraper</h1>
    <span id="modo" class="rounded-full border border-zinc-700 bg-zinc-800 px-3 py-0.5 text-xs text-zinc-400">—</span>
    <span id="tag-tela" class="rounded-full border border-zinc-700 bg-zinc-800 px-3 py-0.5 text-xs text-zinc-400">⚪ tela normal</span>
    <div class="flex-1"></div>
    <span id="timer" class="font-mono text-xs text-zinc-400">00:00</span>
  </div>
</header>

<main class="mx-auto max-w-5xl px-5 py-6">

  <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
    <div class="rounded-xl border border-zinc-800 bg-zinc-900 p-4">
      <div class="text-[10px] uppercase tracking-wider text-zinc-500">Na listagem</div>
      <div id="s-list" class="mt-1 font-mono text-2xl font-bold text-zinc-300">—</div>
    </div>
    <div class="rounded-xl border border-zinc-800 bg-zinc-900 p-4">
      <div class="text-[10px] uppercase tracking-wider text-zinc-500">No banco</div>
      <div id="s-done" class="mt-1 font-mono text-2xl font-bold text-emerald-400">0</div>
    </div>
    <div class="rounded-xl border border-zinc-800 bg-zinc-900 p-4">
      <div class="text-[10px] uppercase tracking-wider text-zinc-500">Restantes</div>
      <div id="s-rest" class="mt-1 font-mono text-2xl font-bold text-amber-400">—</div>
    </div>
    <div class="rounded-xl border border-zinc-800 bg-zinc-900 p-4">
      <div class="text-[10px] uppercase tracking-wider text-zinc-500">Colunas</div>
      <div id="s-cols" class="mt-1 font-mono text-2xl font-bold text-zinc-300">—</div>
    </div>
  </div>

  <div class="mb-6 h-5 overflow-hidden rounded-full bg-zinc-950">
    <div id="bar" class="h-full w-0 rounded-full bg-gradient-to-r from-blue-500 to-violet-500 transition-all duration-300"></div>
  </div>
  <div class="mb-5 flex justify-between text-xs text-zinc-400">
    <span id="p-text">Pronto</span>
    <span id="p-pct">0%</span>
  </div>

  <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
    <div class="rounded-xl border border-zinc-800 bg-zinc-900 p-4">
      <div class="text-[10px] uppercase tracking-wider text-zinc-500">Tempo decorrido</div>
      <div id="t-decorrido" class="mt-1 font-mono text-2xl font-bold text-blue-400">00:00</div>
    </div>
    <div class="rounded-xl border border-zinc-800 bg-zinc-900 p-4">
      <div class="text-[10px] uppercase tracking-wider text-zinc-500">ETA</div>
      <div id="t-restante" class="mt-1 font-mono text-2xl font-bold text-amber-400">—</div>
    </div>
    <div class="rounded-xl border border-zinc-800 bg-zinc-900 p-4">
      <div class="text-[10px] uppercase tracking-wider text-zinc-500">Velocidade</div>
      <div id="t-velocidade" class="mt-1 font-mono text-2xl font-bold text-zinc-200">—</div>
    </div>
    <div class="rounded-xl border border-zinc-800 bg-zinc-900 p-4">
      <div class="text-[10px] uppercase tracking-wider text-zinc-500">Nesta sessão</div>
      <div id="t-sessao" class="mt-1 font-mono text-2xl font-bold text-emerald-400">0</div>
    </div>
  </div>

  <div class="mb-6 flex flex-wrap gap-2">
    <button id="btn-list" class="rounded-lg bg-blue-500 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-blue-400 disabled:cursor-not-allowed disabled:opacity-50">1. Baixar listagem</button>
    <button id="btn-run" class="rounded-lg bg-blue-500 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-blue-400 disabled:cursor-not-allowed disabled:opacity-50" disabled>2. Coletar nutrientes</button>
    <button id="btn-stop" class="rounded-lg border border-zinc-700 bg-zinc-800 px-4 py-2.5 text-sm font-semibold transition hover:bg-zinc-700 disabled:cursor-not-allowed disabled:opacity-50" disabled>Pausar</button>
    <button id="btn-reset-met" class="rounded-lg border border-zinc-700 bg-zinc-800 px-4 py-2.5 text-sm font-semibold transition hover:bg-zinc-700">Resetar método</button>
    <button id="btn-reset" class="rounded-lg bg-rose-500 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-rose-400">Resetar tudo</button>
  </div>

  <section class="mb-5 rounded-2xl border border-zinc-800 bg-zinc-900 p-5">
    <h2 class="mb-3 text-sm font-bold text-zinc-300">Inspecionar</h2>
    <div class="flex flex-wrap items-center gap-2">
      <input id="inspect-cod" type="text" value="BRC0001C" class="w-40 rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm text-zinc-100 outline-none focus:border-blue-500">
      <button id="btn-inspect" class="rounded-lg border border-zinc-700 bg-zinc-800 px-4 py-2 text-sm font-semibold transition hover:bg-zinc-700">Inspecionar</button>
    </div>
    <pre id="inspect-out" class="mt-3 hidden max-h-[380px] overflow-auto rounded-lg bg-zinc-950 p-4 text-xs text-zinc-300"></pre>
  </section>

  <section class="mb-5 rounded-2xl border border-zinc-800 bg-zinc-900 p-5">
    <h2 class="mb-3 text-sm font-bold text-zinc-300">Log</h2>
    <div id="log" class="max-h-[280px] overflow-y-auto rounded-lg bg-zinc-950 p-4 font-mono text-xs leading-relaxed text-zinc-300"></div>
  </section>

  <section class="rounded-2xl border border-zinc-800 bg-zinc-900 p-5">
    <h2 class="mb-3 text-sm font-bold text-zinc-300">Colunas na tabela</h2>
    <div id="cols" class="flex flex-wrap gap-1.5 text-xs"><span class="rounded-full border border-zinc-700 bg-zinc-950 px-2.5 py-0.5 text-zinc-500">Nada ainda</span></div>
  </section>

</main>

<script>
(function(){
  'use strict';

  var $ = function (id) { return document.getElementById(id); };
  var running = false, stopped = false;
  var timerT0 = null, timerDone0 = 0, timerHandle = null, ultimaVel = null;

  // ===== WAKE LOCK =====
  var wakeLock = null;
  var wakeVideo = null;

  function criarVideoWakeLock() {
    var canvas = document.createElement('canvas');
    canvas.width = 1;
    canvas.height = 1;
    var ctx = canvas.getContext('2d');
    ctx.fillStyle = '#000';
    ctx.fillRect(0, 0, 1, 1);

    var stream = canvas.captureStream(1);
    var video = document.createElement('video');
    video.setAttribute('playsinline', '');
    video.muted = true;
    video.loop = true;
    video.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;opacity:0.01;pointer-events:none;';
    video.srcObject = stream;

    return video;
  }

  function pedirWakeLock() {
    // 1) Wake Lock API
    if ('wakeLock' in navigator) {
      try {
        return navigator.wakeLock.request('screen').then(function (wl) {
          wakeLock = wl;
          wl.addEventListener('release', function () {
            wakeLock = null;
            atualizarBadgeTela();
          });
          atualizarBadgeTela();
          return true;
        }).catch(function () {
          return tentarVideo();
        });
      } catch (e) {
        return tentarVideo();
      }
    }
    return tentarVideo();
  }

  function tentarVideo() {
    try {
      if (!wakeVideo) {
        wakeVideo = criarVideoWakeLock();
        document.body.appendChild(wakeVideo);
      }
      return wakeVideo.play().then(function () {
        atualizarBadgeTela();
        return true;
      }).catch(function () {
        atualizarBadgeTela();
        return false;
      });
    } catch (e) {
      atualizarBadgeTela();
      return Promise.resolve(false);
    }
  }

  function liberarWakeLock() {
    if (wakeLock) {
      try { wakeLock.release(); } catch (e) {}
      wakeLock = null;
    }
    if (wakeVideo) {
      try { wakeVideo.pause(); wakeVideo.remove(); } catch (e) {}
      wakeVideo = null;
    }
    atualizarBadgeTela();
  }

  function atualizarBadgeTela() {
    var el = $('tag-tela');
    if (!el) return;
    if (wakeLock) {
      el.textContent = '🟢 tela ativa (API)';
      el.className = 'rounded-full border border-emerald-500/40 bg-emerald-500/10 px-3 py-0.5 text-xs font-semibold text-emerald-400';
    } else if (wakeVideo) {
      el.textContent = '🟡 tela ativa (vídeo)';
      el.className = 'rounded-full border border-amber-500/40 bg-amber-500/10 px-3 py-0.5 text-xs font-semibold text-amber-400';
    } else {
      el.textContent = '⚪ tela normal';
      el.className = 'rounded-full border border-zinc-700 bg-zinc-800 px-3 py-0.5 text-xs text-zinc-400';
    }
  }

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible' && running && !wakeLock && !wakeVideo) {
      pedirWakeLock();
    }
  });

  // ===== LOG =====
  function log(msg, cls) {
    var el = $('log');
    var d = document.createElement('div');
    if (cls) d.className = cls;
    d.textContent = '[' + new Date().toLocaleTimeString('pt-BR') + '] ' + msg;
    el.appendChild(d);
    el.scrollTop = el.scrollHeight;
    while (el.children.length > 800) el.removeChild(el.firstChild);
  }

  function fmtHMS(seg) {
    seg = Math.max(0, Math.round(seg));
    var h = Math.floor(seg / 3600);
    var m = Math.floor((seg % 3600) / 60);
    var s = seg % 60;
    var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
    return h > 0 ? h + ':' + pad(m) + ':' + pad(s) : pad(m) + ':' + pad(s);
  }

  function iniciarTimer() {
    timerT0 = Date.now();
    var dEl = $('s-done').textContent || '0';
    timerDone0 = parseInt(dEl.replace(/\D/g, ''), 10) || 0;
    ultimaVel = null;
    $('t-decorrido').textContent = '00:00';
    $('t-restante').textContent = '—';
    $('t-velocidade').textContent = '—';
    $('t-sessao').textContent = '0';
    if (timerHandle) clearInterval(timerHandle);
    timerHandle = setInterval(atualizarTimer, 1000);
  }

  function atualizarTimer() {
    if (!timerT0) return;
    var el = (Date.now() - timerT0) / 1000;
    var dEl = $('s-done').textContent || '0';
    var rEl = $('s-rest').textContent || '0';
    var feitos = parseInt(dEl.replace(/\D/g, ''), 10) || 0;
    var restantes = parseInt(rEl.replace(/\D/g, ''), 10) || 0;
    var sessao = feitos - timerDone0;

    $('t-decorrido').textContent = fmtHMS(el);
    $('t-sessao').textContent = String(sessao);
    $('timer').textContent = fmtHMS(el) + (sessao > 0 ? ' · ' + sessao + ' ok' : '');

    if (sessao < 3 || el < 4) {
      $('t-velocidade').textContent = '—';
      $('t-restante').textContent = '—';
      return;
    }

    var velAtual = sessao / el;
    ultimaVel = (ultimaVel === null) ? velAtual : (ultimaVel * 0.7 + velAtual * 0.3);
    var segRestantes = ultimaVel > 0 ? restantes / ultimaVel : 0;
    $('t-velocidade').textContent = (ultimaVel * 60).toFixed(1) + '/min';
    $('t-restante').textContent = segRestantes > 0 ? fmtHMS(segRestantes) : '—';
  }

  function pararTimer() {
    if (timerHandle) { clearInterval(timerHandle); timerHandle = null; }
  }

  // ===== AJAX =====
  function refreshStats() {
    return fetch('?action=stats')
      .then(function (r) { return r.json(); })
      .then(function (r) {
        $('s-list').textContent = r.listDone
          ? r.listTotal.toString()
          : (r.listParcial ? r.listParcial + ' (parcial)' : '—');
        $('s-done').textContent = r.dbDone.toString();
        $('s-rest').textContent = r.listDone
          ? Math.max(0, r.listTotal - r.dbDone).toString()
          : '—';
        $('s-cols').textContent = r.cols.length ? String(r.cols.length) : '—';
        if (r.metodo) $('modo').textContent = 'metodo: ' + r.metodo;
        var pct = r.listTotal ? Math.min(100, r.dbDone / r.listTotal * 100) : 0;
        $('bar').style.width = pct.toFixed(2) + '%';
        $('p-pct').textContent = pct.toFixed(1) + '%';
        $('p-text').textContent = r.listDone
          ? (r.dbDone + ' de ' + r.listTotal)
          : (r.listParcial ? ('listagem: ' + r.listParcial + ' itens · fila: ' + r.listFila) : 'Pronto');
        if (r.cols.length) {
          var html = '';
          for (var i = 0; i < r.cols.length; i++) {
            html += '<span class="rounded-full border border-zinc-700 bg-zinc-950 px-2.5 py-0.5">' + r.cols[i] + '</span>';
          }
          $('cols').innerHTML = html;
        }
        $('btn-list').disabled = r.listDone || running;
        $('btn-run').disabled = !r.listDone || running;
        return r;
      })
      .catch(function (e) {
        log('stats: ' + e.message, 'text-rose-400');
      });
  }

  function baixarListagem() {
    $('btn-list').disabled = true;
    log('Baixando listagem...', 'text-blue-400');
    var t = Date.now();
    var g = 0;

    function iterar() {
      if (g++ >= 400) return Promise.resolve();
      return fetch('?action=list')
        .then(function (r) { return r.json(); })
        .then(function (r) {
          if (r.error) {
            log('Erro: ' + r.error, 'text-rose-400');
            return;
          }
          var el = Math.round((Date.now() - t) / 1000);
          log('pag ' + r.page + ' - ' + r.total + ' itens (' + el + 's)' + (r.fila ? ' - fila:' + r.fila : ''), 'text-blue-400');
          if (r.done) {
            log('OK: Listagem completa - ' + r.total + ' alimentos em ' + el + 's', 'text-emerald-400');
            return;
          }
          return new Promise(function (res) { setTimeout(res, 40); }).then(iterar);
        })
        .catch(function (e) {
          log('Falha: ' + e.message, 'text-rose-400');
        });
    }

    return iterar().then(function () {
      return refreshStats();
    });
  }

  function runStep() {
    return fetch('?action=step&n=16&delay=0.05')
      .then(function (resp) {
        return resp.text().then(function (txt) {
          var r;
          try { r = JSON.parse(txt); }
          catch (e) {
            log('step: resposta invalida (HTTP ' + resp.status + ')', 'text-rose-400');
            log(txt.slice(0, 300), 'text-rose-400');
            return null;
          }
          if (r.error) {
            log('Erro: ' + r.error, 'text-rose-400');
            return null;
          }
          var lg = r.log || [];
          for (var i = 0; i < lg.length; i++) {
            var l = lg[i];
            log(l, l.charAt(0) === '+' ? 'text-emerald-400' : 'text-rose-400');
          }
          if (r.metodo) $('modo').textContent = 'metodo: ' + r.metodo;
          $('s-done').textContent = String(r.done);
          $('s-rest').textContent = String(r.remaining);
          var pct = r.total ? r.done / r.total * 100 : 0;
          $('bar').style.width = pct.toFixed(2) + '%';
          $('p-pct').textContent = pct.toFixed(1) + '%';
          $('p-text').textContent = r.done + ' de ' + r.total;
          return r;
        });
      })
      .catch(function (e) {
        log('step: ' + e.message, 'text-rose-400');
        return null;
      });
  }

  function loop() {
    if (running) return;
    running = true;
    stopped = false;
    $('btn-run').disabled = true;
    $('btn-stop').disabled = false;
    $('btn-list').disabled = true;

    // Ativa wake lock ANTES de qualquer await (mantém user gesture)
    pedirWakeLock().then(function (ok) {
      log('Coleta iniciada' + (ok ? ' - tela mantida ligada' : ' - tela normal'), 'text-blue-400');
    });

    iniciarTimer();

    var falhasSeguidas = 0;

    function proximo() {
      if (!running || stopped) return Promise.resolve();
      return runStep().then(function (r) {
        if (!r) {
          falhasSeguidas++;
          if (falhasSeguidas >= 5) {
            log('5 falhas seguidas - parando', 'text-rose-400');
            return;
          }
          return new Promise(function (res) { setTimeout(res, 2500); }).then(proximo);
        }
        falhasSeguidas = 0;
        if (r.remaining <= 0) {
          log('OK: Tudo coletado', 'text-emerald-400');
          return;
        }
        return proximo();
      });
    }

    return proximo().then(function () {
      pararTimer();
      liberarWakeLock();
      running = false;
      $('btn-stop').disabled = true;
      $('btn-run').disabled = false;
      $('btn-list').disabled = false;
      return refreshStats().then(function () {
        log('Pausado', 'text-blue-400');
      });
    });
  }

  function resetAll() {
    if (!confirm('Apaga tudo? Nao tem volta.')) return;
    fetch('?action=reset').then(function () {
      $('log').innerHTML = '';
      $('cols').innerHTML = '<span class="rounded-full border border-zinc-700 bg-zinc-950 px-2.5 py-0.5 text-zinc-500">Nada ainda</span>';
      $('inspect-out').classList.add('hidden');
      $('timer').textContent = '00:00';
      $('t-decorrido').textContent = '00:00';
      $('t-restante').textContent = '—';
      $('t-velocidade').textContent = '—';
      $('t-sessao').textContent = '0';
      log('Reset concluido', 'text-blue-400');
      return refreshStats();
    });
  }

  function resetMetodo() {
    fetch('?action=reset-metodo')
      .then(function () { return fetch('?action=stats').then(function (r) { return r.json(); }); })
      .then(function (r) {
        if (r.metodo) $('modo').textContent = 'metodo: ' + r.metodo;
        log('Metodo resetado para ' + (r.metodo || '?'), 'text-blue-400');
      });
  }

  function inspecionar() {
    var cod = $('inspect-cod').value.trim().toUpperCase();
    if (!cod) return;
    var pre = $('inspect-out');
    pre.classList.remove('hidden');
    pre.textContent = 'Consultando...';
    fetch('?action=inspect&cod=' + encodeURIComponent(cod))
      .then(function (r) { return r.json(); })
      .then(function (r) {
        if (r.error) { pre.textContent = 'Erro: ' + r.error; return; }
        pre.textContent = 'META:\n' + JSON.stringify(r.meta, null, 2)
                        + '\n\nNUTRIENTES:\n' + JSON.stringify(r.nutrientes, null, 2);
      })
      .catch(function (e) { pre.textContent = 'Erro: ' + e.message; });
  }

  $('btn-list').addEventListener('click', baixarListagem);
  $('btn-run').addEventListener('click', loop);
  $('btn-stop').addEventListener('click', function () { stopped = true; });
  $('btn-reset').addEventListener('click', resetAll);
  $('btn-reset-met').addEventListener('click', resetMetodo);
  $('btn-inspect').addEventListener('click', inspecionar);

  refreshStats().then(function (r) {
    if (r && r.listDone) log('Listagem pronta. Clique em "Coletar nutrientes".', 'text-blue-400');
    else log('Clique em "Baixar listagem".', 'text-blue-400');
  });
})();
</script>
</body>
</html>
<?php
exit;
endif;

http_response_code(400);
echo "Use ?diag=1 ou o dashboard.";
