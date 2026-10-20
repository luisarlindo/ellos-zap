<?php
// api.php - Backend proxy para 2Chat, Consulta CNPJ e Busca Inteligente Google Maps (Ellos Zap)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-User-API-Key');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$DEFAULT_2CHAT_KEY = 'UAKe746d687-cbe1-4042-b070-7d81eb24a9a5';
$DEFAULT_SOURCE_PHONE = '+5583994163527'; // WhatsApp conectado de Arlindo na 2Chat

$UF_DDDS = [
    'AC' => ['68'], 'AL' => ['82'], 'AM' => ['92', '97'], 'AP' => ['96'],
    'BA' => ['71', '73', '74', '75', '77'], 'CE' => ['85', '88'], 'DF' => ['61'],
    'ES' => ['27', '28'], 'GO' => ['61', '62', '64'], 'MA' => ['98', '99'],
    'MG' => ['31', '32', '33', '34', '35', '37', '38'], 'MS' => ['67'], 'MT' => ['65', '66'],
    'PA' => ['91', '93', '94'], 'PB' => ['83'], 'PE' => ['81', '87'], 'PI' => ['86', '89'],
    'PR' => ['41', '42', '43', '44', '45', '46'], 'RJ' => ['21', '22', '24'], 'RN' => ['84'],
    'RO' => ['69'], 'RR' => ['95'], 'RS' => ['51', '53', '54', '55'], 'SC' => ['47', '48', '49'],
    'SE' => ['79'], 'SP' => ['11', '12', '13', '14', '15', '16', '17', '18', '19'], 'TO' => ['63']
];

// Obter API key
$apiKey = $DEFAULT_2CHAT_KEY;
if (isset($_SERVER['HTTP_X_USER_API_KEY']) && !empty($_SERVER['HTTP_X_USER_API_KEY'])) {
    $apiKey = trim($_SERVER['HTTP_X_USER_API_KEY']);
} elseif (isset($_GET['api_key']) && !empty($_GET['api_key'])) {
    $apiKey = trim($_GET['api_key']);
}

function request2Chat($endpoint, $apiKey, $timeout = 25) {
    $url = 'https://api.p.2chat.io' . $endpoint;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-User-API-Key: ' . $apiKey,
        'User-Agent: EllosZap-Server/1.0',
        'Accept: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['status' => 504, 'data' => ['error' => true, 'timeout' => true, 'message' => $error]];
    }
    
    $json = json_decode($response, true);
    return ['status' => $httpCode ?: 200, 'data' => $json !== null ? $json : ['raw' => $response]];
}

function stripAccents($str) {
    $str = mb_strtolower((string)$str, 'UTF-8');
    $map = [
        'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'ç'=>'c','ñ'=>'n'
    ];
    return strtr($str, $map);
}

function cleanCompanyName($razao, $fantasia) {
    $name = trim($fantasia ?: $razao);
    $name = preg_replace('/^[\d.\-\/\s]+/', '', $name);
    $name = preg_replace('/\s+\d{11}$/', '', $name);
    $name = preg_replace('/\b(LTDA|ME|EPP|EIRELI|S\/A|S\.A\.|SOCIEDADE SIMPLES|UNIPESSOAL|LIMITADA|EMPRESARIO INDIVIDUAL)\b\.?/i', '', $name);
    return trim(preg_replace('/\s+/', ' ', $name));
}

function normalizeBrPhoneDigits($rawDigits) {
    $d = preg_replace('/\D/', '', (string)$rawDigits);
    if (str_starts_with($d, '55') && strlen($d) >= 12) $d = substr($d, 2);
    if (str_starts_with($d, '0') && strlen($d) >= 11) $d = substr($d, 1);
    if (strlen($d) < 10 || strlen($d) > 11) return '';
    if (strlen($d) === 10 && in_array($d[2], ['7', '8', '9'])) {
        $d = substr($d, 0, 2) . '9' . substr($d, 2);
    }
    return $d;
}

function formatBrPhone($digits) {
    $d = preg_replace('/\D/', '', $digits);
    if (strlen($d) === 11) {
        return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 5), substr($d, 7, 4));
    } elseif (strlen($d) === 10) {
        return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 4), substr($d, 6, 4));
    }
    return $digits;
}

function formatCnpj($digits) {
    $d = preg_replace('/\D/', '', $digits);
    if (strlen($d) === 14) {
        return sprintf('%s.%s.%s/%s-%s', substr($d, 0, 2), substr($d, 2, 3), substr($d, 5, 3), substr($d, 8, 4), substr($d, 12, 2));
    }
    return $digits;
}

function unwrapGoogleUrl($u) {
    if (!$u) return null;
    if (str_starts_with($u, '/url?')) {
        $parts = parse_url($u);
        parse_str($parts['query'] ?? '', $q);
        return $q['q'] ?? null;
    }
    return $u;
}

function getDistinctiveKeywords($name) {
    $norm = preg_replace('/[^a-z0-9\s]/', ' ', stripAccents($name));
    $words = preg_split('/\s+/', trim($norm));
    $stop = [
        'de','da','do','das','dos','em','na','no','nas','nos','para','por','com','sem','sob',
        'ltda','me','epp','eireli','sa','sociedade','simples','unipessoal','limitada','individual',
        'comercio','servicos','servico','comercial','distribuidora','industria','empresa','grupo',
        'restaurante','pizzaria','pizza','pizzas','lanchonete','lanches','bar','cafe','padaria',
        'panificadora','mercado','supermercado','mercadinho','farmacia','drogaria','clinica',
        'consultorio','loja','lojas','casa','cia','centro','posto','auto','hotel','pousada',
        'brasil','norte','sul','leste','oeste','nova','novo','sao','santa','santo','dom','bom','boa'
    ];
    $keywords = [];
    foreach ($words as $w) {
        if (strlen($w) >= 3 && !in_array($w, $stop)) {
            $keywords[] = $w;
        }
    }
    return array_values(array_unique($keywords));
}

function getCacheItem($key, $ttl = 86400) {
    $dir = __DIR__ . '/cache';
    if (!is_dir($dir)) return null;
    $file = $dir . '/' . md5($key) . '.json';
    if (file_exists($file) && (time() - filemtime($file)) < $ttl) {
        $content = @file_get_contents($file);
        if ($content !== false) {
            $data = json_decode($content, true);
            if ($data !== null) return $data;
        }
    }
    return null;
}

function setCacheItem($key, $data) {
    $dir = __DIR__ . '/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        @file_put_contents($dir . '/.htaccess', "Deny from all\n");
    }
    $file = $dir . '/' . md5($key) . '.json';
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE));
}

function extractPlaceFromGmapsItem($item) {
    $p = $item[14] ?? null;
    if (!$p || empty($p[11])) return null;

    $title = trim($p[11]);
    $addr = trim($p[39] ?? (isset($p[2]) && is_array($p[2]) ? implode(', ', $p[2]) : ''));
    // Remove duplicação eventual de bairro/CEP no endereço do Google Maps
    if (preg_match('/^(.+?,\s*\d{5}-\d{3})\s*-\s*.+$/u', $addr, $am)) {
        $addr = trim($am[1]);
    }

    $pStatus = null;
    $flatJson = json_encode($p, JSON_UNESCAPED_UNICODE);
    if (preg_match_all('/"((?:Aberto|Fechado|Fecha em breve|Abre em breve)(?:\s*[·⋅]\s*[^"\\\\]{1,40}|\s+24\s+horas|\s+temporariamente)?)"/u', $flatJson, $sm)) {
        foreach ($sm[1] as $stCandidate) {
            $stClean = trim($stCandidate);
            if (stripos($stClean, 'inexistente') !== false || stripos($stClean, 'duplicado') !== false) continue;
            if (!$pStatus || mb_strlen($stClean) > mb_strlen($pStatus)) {
                $pStatus = $stClean;
            }
        }
    }

    $phonesMap = [];
    if (!empty($p[178]) && is_array($p[178])) {
        foreach ($p[178] as $telObj) {
            $rawTel = $telObj[3] ?? ($telObj[0] ?? '');
            $norm = normalizeBrPhoneDigits($rawTel);
            if ($norm && !isset($phonesMap[$norm])) {
                $isMob = (strlen($norm) === 11 && $norm[2] === '9');
                $phonesMap[$norm] = [
                    'phone' => $norm,
                    'formatted' => formatBrPhone($norm),
                    'type' => $isMob ? 'MOBILE' : 'LANDLINE'
                ];
            }
        }
    }

    $destQuery = urlencode("$title, $addr");
    $mapsUrl = "https://www.google.com/maps/search/?api=1&query=$destQuery" . (!empty($p[78]) ? "&query_place_id=" . urlencode($p[78]) : "");

    return [
        'name' => $title,
        'address' => $addr,
        'status' => $pStatus,
        'website' => unwrapGoogleUrl($p[7][0] ?? null),
        'maps_url' => $mapsUrl,
        'phones' => array_values($phonesMap)
    ];
}

function queryGoogleMapsRpc($queryStr, $lat = -7.14, $lng = -34.86, $radius = 120000) {
    $pb = "!4m12!1m3!1d{$radius}!2d{$lng}!3d{$lat}!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!7i20!10b1!12m8!1m1!18b1!2m3!5m1!6e2!20e3!10b1!16b1!19m4!2m3!1i360!2i120!4i8!20m57!2m2!1i203!2i100!3m2!2i4!5b1!6m6!1m2!1i86!2i86!1m2!1i408!2i240!7m42!1m3!1e1!2b0!3e3!1m3!1e2!2b1!3e2!1m3!1e2!2b0!3e3!1m3!1e8!2b0!3e3!1m3!1e10!2b0!3e3!1m3!1e10!2b1!3e2!1m3!1e9!2b1!3e2!1m3!1e10!2b0!3e3!1m3!1e10!2b1!3e2!1m3!1e10!2b0!3e4!2b1!4b1!9b0!22m6!1sa!2z!7e81!12e3!17sa!18e1!24m15!2b1!5m4!2b1!3b1!5b1!6b1!10m1!8e3!14m1!3b1!17b1!20m2!1e3!1e6!24b1!26m4!2m3!1i80!2i92!4i8!30m28!1m6!1m2!1i0!2i0!2m2!1i458!2i768!1m6!1m2!1i974!2i0!2m2!1i1024!2i768!1m6!1m2!1i0!2i0!2m2!1i1024!2i20!1m6!1m2!1i0!2i748!2m2!1i1024!2i768!34m13!2b1!3b1!4b1!6b1!8m4!1b1!3b1!4b1!6b1!9b1!12b1!14b1!20b1!37m1!1e81!42b1!47m0!49m1!3b1";
    $url = "https://www.google.com/search?tbm=map&authuser=0&hl=pt-BR&gl=br&pb=$pb&q=" . urlencode($queryStr);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_ENCODING => "",
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 9,
        CURLOPT_HTTPHEADER => [
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36",
            "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
            "Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7"
        ]
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    if (empty($raw)) return [];

    $jsonStr = preg_replace('/^\)\]\}\'\s*/', '', $raw);
    $data = json_decode($jsonStr, true);
    $items = $data[0][1] ?? [];
    if (!is_array($items)) return [];

    $places = [];
    foreach ($items as $it) {
        $parsed = extractPlaceFromGmapsItem($it);
        if ($parsed) {
            $places[] = $parsed;
        }
    }
    return $places;
}

// Roteamento baseado em URI ou query param 'action'
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$action = $_GET['action'] ?? '';
if (!$action) {
    if (strpos($requestUri, '/api/2chat/info') !== false) {
        $action = 'info';
    } elseif (strpos($requestUri, '/api/2chat/numbers') !== false) {
        $action = 'numbers';
    } elseif (strpos($requestUri, '/api/2chat/check') !== false) {
        $action = 'check';
    } elseif (strpos($requestUri, '/api/2chat/search') !== false || strpos($requestUri, '/api/search') !== false) {
        $action = 'search';
    } elseif (strpos($requestUri, '/api/2chat/cnpj') !== false || strpos($requestUri, '/api/cnpj') !== false) {
        $action = 'cnpj';
    }
}

// 1. Informações da Conta
if ($action === 'info') {
    $result = request2Chat('/open/info', $apiKey, 15);
    http_response_code($result['status']);
    echo json_encode($result['data']);
    exit;
}

// 2. Lista de números conectados na 2Chat
if ($action === 'numbers') {
    $result = request2Chat('/open/whatsapp/get-numbers', $apiKey, 15);
    http_response_code($result['status']);
    echo json_encode($result['data']);
    exit;
}

// 3. Checar número no WhatsApp
if ($action === 'check') {
    $rawPhone = $_GET['phone'] ?? '';
    $sourcePhone = trim($_GET['source'] ?? '');
    $targetPhone = preg_replace('/\D/', '', $rawPhone);

    if (empty($targetPhone)) {
        http_response_code(400);
        echo json_encode(['error' => true, 'message' => 'Parâmetro "phone" é obrigatório.']);
        exit;
    }

    if (!str_starts_with($targetPhone, '55') && strlen($targetPhone) >= 10 && strlen($targetPhone) <= 11) {
        $targetPhone = '55' . $targetPhone;
    }
    $formattedTarget = '+' . $targetPhone;

    if (empty($sourcePhone) && $apiKey === $DEFAULT_2CHAT_KEY) {
        $sourcePhone = $DEFAULT_SOURCE_PHONE;
    }

    if (empty($sourcePhone)) {
        $numbersRes = request2Chat('/open/whatsapp/get-numbers', $apiKey, 15);
        if (isset($numbersRes['data']['numbers']) && is_array($numbersRes['data']['numbers']) && count($numbersRes['data']['numbers']) > 0) {
            $sourcePhone = $numbersRes['data']['numbers'][0]['phone_number'] ?? $numbersRes['data']['numbers'][0]['number'] ?? '';
        }
    }

    if (empty($sourcePhone)) {
        http_response_code(200);
        echo json_encode([
            'requires_source_connection' => true,
            'error' => true,
            'message' => 'Sua conta da 2Chat precisa de um número de WhatsApp conectado (QR Code) para consultar a Meta.'
        ]);
        exit;
    }

    if (!str_starts_with($sourcePhone, '+')) {
        $sourcePhone = '+' . preg_replace('/\D/', '', $sourcePhone);
    }

    $checkEndpoint = '/open/whatsapp/check-number/' . urlencode($sourcePhone) . '/' . urlencode($formattedTarget);
    $result = request2Chat($checkEndpoint, $apiKey, 25);

    http_response_code($result['status']);
    echo json_encode($result['data']);
    exit;
}

// 4. Busca Unificada Inteligente: NOME / TELEFONE / CNPJ
if ($action === 'search') {
    $t0 = microtime(true);
    $rawQuery = trim($_GET['q'] ?? '');
    $lat = isset($_GET['lat']) && is_numeric($_GET['lat']) ? (float)$_GET['lat'] : -7.14;
    $lng = isset($_GET['lng']) && is_numeric($_GET['lng']) ? (float)$_GET['lng'] : -34.86;

    if ($rawQuery === '') {
        http_response_code(400);
        echo json_encode(['error' => true, 'message' => 'Informe um Nome, Telefone ou CNPJ.']);
        exit;
    }

    $hasLetters = (bool)preg_match('/[a-zA-ZÀ-ÿ]/u', $rawQuery);
    $digitsOnly = preg_replace('/\D/', '', $rawQuery);

    $searchCacheKey = "search_" . md5(stripAccents($rawQuery) . "_" . round($lat, 2) . "_" . round($lng, 2));
    $cached = getCacheItem($searchCacheKey, 86400);
    if ($cached !== null) {
        $cached['cached'] = true;
        $cached['elapsed_ms'] = round((microtime(true) - $t0) * 1000);
        echo json_encode($cached);
        exit;
    }

    // =========================================================
    // MODO A: TELEFONE (10 ou 11 dígitos, sem letras)
    // =========================================================
    if (!$hasLetters && strlen($digitsOnly) >= 10 && strlen($digitsOnly) <= 11) {
        $normPhone = normalizeBrPhoneDigits($digitsOnly);
        $formattedPhone = formatBrPhone($normPhone ?: $digitsOnly);
        $isMob = (strlen($normPhone) === 11 && $normPhone[2] === '9');

        // Busca no Google Maps RPC pelo telefone para descobrir nome e endereço da empresa (se existir)
        $places = queryGoogleMapsRpc($formattedPhone, $lat, $lng, 150000);
        if (empty($places)) {
            $places = queryGoogleMapsRpc($normPhone, $lat, $lng, 150000);
        }

        $matchedBranches = [];
        foreach ($places as $pl) {
            // Verifica se a ficha retornada tem esse telefone ou se foi resultado único direto
            $hasThisPhone = false;
            foreach ($pl['phones'] as $pObj) {
                if ($pObj['phone'] === $normPhone) {
                    $hasThisPhone = true;
                    break;
                }
            }
            if ($hasThisPhone || count($places) === 1) {
                if (!$hasThisPhone) {
                    array_unshift($pl['phones'], [
                        'phone' => $normPhone,
                        'formatted' => $formattedPhone,
                        'type' => $isMob ? 'MOBILE' : 'LANDLINE'
                    ]);
                }
                $matchedBranches[] = $pl;
            }
        }

        // Se o telefone não pertence a um estabelecimento listado no Google Maps, retorna o cartão direto do número
        if (empty($matchedBranches)) {
            $matchedBranches[] = [
                'name' => $formattedPhone,
                'address' => '',
                'status' => null,
                'website' => null,
                'maps_url' => null,
                'phones' => [[
                    'phone' => $normPhone ?: $digitsOnly,
                    'formatted' => $formattedPhone,
                    'type' => $isMob ? 'MOBILE' : 'LANDLINE'
                ]]
            ];
        }

        $phoneResponse = [
            'success' => true,
            'mode' => 'phone',
            'query' => $rawQuery,
            'resolved_name' => $matchedBranches[0]['name'] ?? $formattedPhone,
            'elapsed_ms' => round((microtime(true) - $t0) * 1000),
            'branches' => $matchedBranches
        ];
        setCacheItem($searchCacheKey, $phoneResponse);
        echo json_encode($phoneResponse);
        exit;
    }

    // =========================================================
    // MODO B: NOME DA EMPRESA (Com correção ortográfica e filiais)
    // =========================================================
    if ($hasLetters) {
        $places = queryGoogleMapsRpc($rawQuery, $lat, $lng, 120000);

        if (empty($places)) {
            echo json_encode([
                'success' => true,
                'mode' => 'name',
                'query' => $rawQuery,
                'resolved_name' => $rawQuery,
                'was_corrected' => false,
                'elapsed_ms' => round((microtime(true) - $t0) * 1000),
                'branches' => []
            ]);
            exit;
        }

        $normQuery = trim(preg_replace('/[^a-z0-9\s]/', ' ', stripAccents($rawQuery)));
        $queryWords = array_values(array_filter(preg_split('/\s+/', $normQuery), fn($w) => strlen($w) >= 2));

        // 1. Verifica se existe exatamente o nome digitado pelo usuário (ex: se existir "Pizza Mestro", mantém "Pizza Mestro")
        $exactPhraseMatches = [];
        $exactMatches = [];
        foreach ($places as $pl) {
            $normTitle = trim(preg_replace('/[^a-z0-9\s]/', ' ', stripAccents($pl['name'])));
            if (str_contains($normTitle, $normQuery)) {
                $exactPhraseMatches[] = $pl;
                $exactMatches[] = $pl;
            } else {
                $allWordsMatch = true;
                foreach ($queryWords as $qw) {
                    if (!preg_match('/\b' . preg_quote($qw, '/') . '/i', $normTitle)) {
                        $allWordsMatch = false;
                        break;
                    }
                }
                if ($allWordsMatch && !empty($queryWords)) {
                    $exactMatches[] = $pl;
                }
            }
        }
        if (!empty($exactPhraseMatches)) {
            $exactMatches = $exactPhraseMatches;
        }

        $wasCorrected = false;
        $resolvedBrand = $rawQuery;
        $filteredBranches = [];

        if (!empty($exactMatches)) {
            $filteredBranches = $exactMatches;
            $resolvedBrand = $exactMatches[0]['name'];
        } else {
            // 2. Correção Ortográfica pelo Google Maps (ex: "Pizza Mestro" -> "Pizza Mestre")
            // Analisa o 1º resultado retornado pelo Google Maps e reconstrói o nome corrigido da marca
            $topPlace = $places[0];
            $topWordsOrig = preg_split('/\s+/', preg_replace('/[|\-–—]/u', ' ', $topPlace['name']));
            $correctedWords = [];

            foreach ($queryWords as $qw) {
                $bestWord = null;
                $bestSim = 0;
                foreach ($topWordsOrig as $tw) {
                    $normTw = preg_replace('/[^a-z0-9]/', '', stripAccents($tw));
                    if (strlen($normTw) < 2) continue;
                    similar_text($qw, $normTw, $sim);
                    if ($sim > $bestSim) {
                        $bestSim = $sim;
                        $bestWord = $tw;
                    }
                }
                if ($bestWord && $bestSim >= 60) {
                    $correctedWords[] = $bestWord;
                }
            }

            if (!empty($correctedWords)) {
                $resolvedBrand = implode(' ', array_unique($correctedWords));
                $wasCorrected = (stripAccents($resolvedBrand) !== stripAccents($rawQuery));
                $normResolved = trim(preg_replace('/[^a-z0-9\s]/', ' ', stripAccents($resolvedBrand)));
                $resolvedWords = array_values(array_filter(preg_split('/\s+/', $normResolved), fn($w) => strlen($w) >= 2));

                $phraseBranches = [];
                foreach ($places as $pl) {
                    $normTitle = trim(preg_replace('/[^a-z0-9\s]/', ' ', stripAccents($pl['name'])));
                    if (str_contains($normTitle, $normResolved)) {
                        $phraseBranches[] = $pl;
                    }
                    $matchAll = true;
                    foreach ($resolvedWords as $rw) {
                        if (!preg_match('/\b' . preg_quote($rw, '/') . '/i', $normTitle)) {
                            $matchAll = false;
                            break;
                        }
                    }
                    if ($matchAll) {
                        $filteredBranches[] = $pl;
                    }
                }
                if (!empty($phraseBranches)) {
                    $filteredBranches = $phraseBranches;
                }
            }

            if (empty($filteredBranches)) {
                $filteredBranches = array_slice($places, 0, 5);
                $resolvedBrand = $places[0]['name'];
            }
        }

        // Se alguma filial da mesma marca veio sem telefone no Maps (ex: Intermares), mas outra filial tem o telefone central da rede, preenche como fallback
        $sharedBrandPhone = null;
        foreach ($filteredBranches as $fb) {
            if (!empty($fb['phones'])) {
                $sharedBrandPhone = $fb['phones'][0];
                break;
            }
        }
        if ($sharedBrandPhone && count($filteredBranches) > 1) {
            foreach ($filteredBranches as &$fb) {
                if (empty($fb['phones'])) {
                    $fb['phones'] = [$sharedBrandPhone];
                }
            }
            unset($fb);
        }

        $nameResponse = [
            'success' => true,
            'mode' => 'name',
            'query' => $rawQuery,
            'resolved_name' => $resolvedBrand,
            'was_corrected' => $wasCorrected,
            'elapsed_ms' => round((microtime(true) - $t0) * 1000),
            'branches' => array_slice($filteredBranches, 0, 10)
        ];
        if (!empty($filteredBranches)) {
            setCacheItem($searchCacheKey, $nameResponse);
        }
        echo json_encode($nameResponse);
        exit;
    }

    // Se for 14 dígitos, delega para o bloco CNPJ abaixo
    if (strlen($digitsOnly) === 14) {
        $_GET['cnpj'] = $digitsOnly;
        $action = 'cnpj';
    } else {
        http_response_code(400);
        echo json_encode(['error' => true, 'message' => 'Digite um Nome, Telefone (10 ou 11 dígitos) ou CNPJ (14 dígitos).']);
        exit;
    }
}

// 5. Trajeto CNPJ -> Nome da Empresa -> Busca Google Maps & Telefone Comercial
if ($action === 'cnpj') {
    $t0 = microtime(true);
    $rawCnpj = $_GET['cnpj'] ?? ($_GET['q'] ?? '');
    $cnpj = preg_replace('/\D/', '', $rawCnpj);

    if (strlen($cnpj) !== 14) {
        http_response_code(400);
        echo json_encode(['error' => true, 'message' => 'Informe um CNPJ válido com 14 dígitos.']);
        exit;
    }

    $cnpjCacheKey = "cnpj_" . $cnpj;
    $cached = getCacheItem($cnpjCacheKey, 7 * 86400);
    if ($cached !== null) {
        $cached['cached'] = true;
        $cached['elapsed_ms'] = round((microtime(true) - $t0) * 1000);
        http_response_code(200);
        echo json_encode($cached);
        exit;
    }

    // Etapa 1: Consultar CNPJ em paralelo (BrasilAPI + publica.cnpj.ws) e mesclar dados para garantir endereço completo
    $mhCnpj = curl_multi_init();
    $hBrasilApi = curl_init("https://brasilapi.com.br/api/cnpj/v1/$cnpj");
    $hCnpjWs    = curl_init("https://publica.cnpj.ws/cnpj/$cnpj");
    foreach ([$hBrasilApi, $hCnpjWs] as $ch) {
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 7,
            CURLOPT_USERAGENT => "EllosZap-Server/2.5"
        ]);
        curl_multi_add_handle($mhCnpj, $ch);
    }
    $running = null;
    do {
        curl_multi_exec($mhCnpj, $running);
        if ($running > 0) curl_multi_select($mhCnpj, 0.25);
    } while ($running > 0);

    $resBrasil = curl_multi_getcontent($hBrasilApi);
    $codeBrasil = curl_getinfo($hBrasilApi, CURLINFO_HTTP_CODE);
    $resWs = curl_multi_getcontent($hCnpjWs);
    $codeWs = curl_getinfo($hCnpjWs, CURLINFO_HTTP_CODE);
    curl_multi_remove_handle($mhCnpj, $hBrasilApi);
    curl_multi_remove_handle($mhCnpj, $hCnpjWs);
    curl_close($hBrasilApi);
    curl_close($hCnpjWs);
    curl_multi_close($mhCnpj);

    $bData = ($codeBrasil === 200 && $resBrasil) ? json_decode($resBrasil, true) : [];
    $wData = ($codeWs === 200 && $resWs) ? json_decode($resWs, true) : [];
    $est = $wData['estabelecimento'] ?? [];

    $razao = trim(($bData['razao_social'] ?? '') ?: ($wData['razao_social'] ?? ''));
    if (empty($razao)) {
        http_response_code(404);
        echo json_encode(['error' => true, 'message' => 'CNPJ não encontrado na base da Receita Federal.']);
        exit;
    }

    $fantasia = trim(($bData['nome_fantasia'] ?? '') ?: ($est['nome_fantasia'] ?? ''));
    $searchName = cleanCompanyName($razao, $fantasia);
    $city = trim(($bData['municipio'] ?? '') ?: ($est['cidade']['nome'] ?? ''));
    $uf = strtoupper(trim(($bData['uf'] ?? '') ?: ($est['estado']['sigla'] ?? '')));
    $tipoLog = trim(($bData['descricao_tipo_de_logradouro'] ?? '') ?: ($est['tipo_logradouro'] ?? ''));
    $logradouroBare = trim(($bData['logradouro'] ?? '') ?: ($est['logradouro'] ?? ''));
    $logradouro = trim(($tipoLog ? "$tipoLog " : '') . $logradouroBare);
    $numero = trim(($bData['numero'] ?? '') ?: ($est['numero'] ?? ''));
    $bairro = trim(($bData['bairro'] ?? '') ?: ($est['bairro'] ?? ''));
    $cepRaw = preg_replace('/\D/', '', ($bData['cep'] ?? '') ?: ($est['cep'] ?? ''));
    $cepFormatted = (strlen($cepRaw) === 8) ? substr($cepRaw, 0, 5) . '-' . substr($cepRaw, 5, 3) : $cepRaw;
    $cnae = trim(($bData['cnae_fiscal_descricao'] ?? '') ?: ($est['atividade_principal']['descricao'] ?? ''));
    $situacao = trim(($bData['descricao_situacao_cadastral'] ?? '') ?: ($est['situacao_cadastral'] ?? 'ATIVA'));

    $allowedDdds = $UF_DDDS[$uf] ?? [];

    // Etapa 2: Buscar a Empresa diretamente no Google Maps RPC (tbm=map) + Web em paralelo
    $pbSearch = '!4m12!1m3!1d15000!2d-34.86!3d-7.14!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!7i20!10b1!12m8!1m1!18b1!2m3!5m1!6e2!20e3!10b1!16b1!19m4!2m3!1i360!2i120!4i8!20m57!2m2!1i203!2i100!3m2!2i4!5b1!6m6!1m2!1i86!2i86!1m2!1i408!2i240!7m42!1m3!1e1!2b0!3e3!1m3!1e2!2b1!3e2!1m3!1e2!2b0!3e3!1m3!1e8!2b0!3e3!1m3!1e10!2b0!3e3!1m3!1e10!2b1!3e2!1m3!1e9!2b1!3e2!1m3!1e10!2b0!3e3!1m3!1e10!2b1!3e2!1m3!1e10!2b0!3e4!2b1!4b1!9b0!22m6!1sa!2z!7e81!12e3!17sa!18e1!24m15!2b1!5m4!2b1!3b1!5b1!6b1!10m1!8e3!14m1!3b1!17b1!20m2!1e3!1e6!24b1!26m4!2m3!1i80!2i92!4i8!30m28!1m6!1m2!1i0!2i0!2m2!1i458!2i768!1m6!1m2!1i974!2i0!2m2!1i1024!2i768!1m6!1m2!1i0!2i0!2m2!1i1024!2i20!1m6!1m2!1i0!2i748!2m2!1i1024!2i768!34m13!2b1!3b1!4b1!6b1!8m4!1b1!3b1!4b1!6b1!9b1!12b1!14b1!20b1!37m1!1e81!42b1!47m0!49m1!3b1';

    $collapse = fn($s) => trim(preg_replace('/\s+/', ' ', $s));
    $qExactStr = $collapse("$searchName $logradouro $numero $bairro $city $uf");
    $qNameStr  = $collapse("$searchName $bairro $city $uf");
    $qCityStr  = $collapse("$searchName $city $uf");

    $urls = [
        'gmaps_exact' => "https://www.google.com/search?tbm=map&authuser=0&hl=pt-BR&gl=br&pb=$pbSearch&q=" . urlencode($qExactStr),
        'gmaps_name'  => "https://www.google.com/search?tbm=map&authuser=0&hl=pt-BR&gl=br&pb=$pbSearch&q=" . urlencode($qNameStr),
        'gmaps_city'  => "https://www.google.com/search?tbm=map&authuser=0&hl=pt-BR&gl=br&pb=$pbSearch&q=" . urlencode($qCityStr),
        'bing_web'    => "https://www.bing.com/search?q=" . urlencode($collapse("\"$searchName\" $city $uf telefone whatsapp")) . "&setlang=pt-br&cc=BR"
    ];

    if (!empty($logradouroBare) && !empty($numero) && strtoupper($numero) !== 'S/N') {
        $cnaeFirstWord = strtok($cnae, ' ,;-/') ?: '';
        $qAddrStr = $collapse("$cnaeFirstWord $logradouro $numero $bairro $city $uf");
        $urls['gmaps_addr'] = "https://www.google.com/search?tbm=map&authuser=0&hl=pt-BR&gl=br&pb=$pbSearch&q=" . urlencode($qAddrStr);
    }

    $mh = curl_multi_init();
    $handles = [];
    foreach ($urls as $key => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING => "",
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36",
                "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
                "Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7"
            ]
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }

    $running = null;
    do {
        curl_multi_exec($mh, $running);
        if ($running > 0) curl_multi_select($mh, 0.25);
    } while ($running > 0);

    $responses = [];
    foreach ($handles as $key => $ch) {
        $responses[$key] = curl_multi_getcontent($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    $addressParts = array_filter([
        trim("$logradouro" . ($numero ? ", $numero" : '')),
        $bairro,
        trim("$city - $uf"),
        $cepFormatted
    ]);
    $defaultAddress = implode(', ', $addressParts);

    $mapsInfo = [
        'title' => $searchName,
        'rating' => null,
        'status' => null,
        'address' => $defaultAddress,
        'website' => null,
        'maps_url' => "https://www.google.com/maps/search/?api=1&query=" . urlencode($collapse("$searchName $logradouro $numero $cepFormatted $city $uf")),
        'is_exact_panel' => false
    ];

    $candidates = [];
    $addCandidate = function($rawDigits, $source, $baseScore, $isExactPlace = false) use (&$candidates, $allowedDdds) {
        $d = normalizeBrPhoneDigits($rawDigits);
        if (empty($d)) return;

        $ddd = substr($d, 0, 2);
        $isLocalDdd = empty($allowedDdds) || in_array($ddd, $allowedDdds);
        $isMobile = (strlen($d) === 11 && $d[2] === '9');
        $score = $baseScore + ($isLocalDdd ? 50 : -80) + ($isMobile ? 30 : 0);

        if (!isset($candidates[$d]) || $candidates[$d]['score'] < $score) {
            $candidates[$d] = [
                'phone' => $d,
                'formatted' => formatBrPhone($d),
                'type' => $isMobile ? 'MOBILE' : 'LANDLINE',
                'is_local_ddd' => $isLocalDdd,
                'is_exact_place' => $isExactPlace || ($candidates[$d]['is_exact_place'] ?? false),
                'source' => $source,
                'score' => round($score)
            ];
        }
    };

    // Etapa 3: Analisar fichas estruturadas do Google Maps RPC (tbm=map)
    $normSearch = stripAccents($searchName);
    $normStreet = stripAccents($logradouroBare);
    $normBairro = stripAccents($bairro);
    $normCity   = stripAccents($city);
    $cepPrefix  = (strlen($cepRaw) >= 5) ? substr($cepRaw, 0, 5) : '';
    $keywords   = getDistinctiveKeywords($searchName);

    $bestPlaceScore = -1;
    $foundExactAddressPlace = false;

    foreach (['gmaps_exact', 'gmaps_name', 'gmaps_city', 'gmaps_addr'] as $gKey) {
        $rawBody = $responses[$gKey] ?? '';
        if (empty($rawBody)) continue;

        $jsonStr = preg_replace('/^\)\]\}\'\s*/', '', $rawBody);
        $data = json_decode($jsonStr, true);
        $items = $data[0][1] ?? [];
        if (!is_array($items)) continue;

        $validPlacesCount = 0;
        foreach ($items as $it) {
            if (!empty($it[14][11])) $validPlacesCount++;
        }

        foreach ($items as $item) {
            $p = $item[14] ?? null;
            if (!$p || empty($p[11])) continue;

            $pTitle = trim($p[11]);
            $pAddr  = trim($p[39] ?? (isset($p[2]) && is_array($p[2]) ? implode(', ', $p[2]) : ''));

            $normTitle = stripAccents($pTitle);
            $normAddr  = stripAccents($pAddr);

            if (!empty($uf) && preg_match('/\b([a-z]{2}),\s*\d{5}/', $normAddr, $ufM)) {
                if (strtoupper($ufM[1]) !== $uf) {
                    continue;
                }
            }

            $hasStreet = (!empty($normStreet) && strlen($normStreet) >= 4 && str_contains($normAddr, $normStreet));
            $hasNumber = (!empty($numero) && strtoupper($numero) !== 'S/N' && preg_match('/\b' . preg_quote($numero, '/') . '\b/', $normAddr));
            $hasExactAddress = ($hasStreet && $hasNumber);
            $hasBairro = (!empty($normBairro) && strlen($normBairro) >= 3 && str_contains($normAddr, $normBairro));
            $hasCepPrefix = (!empty($cepPrefix) && str_contains($normAddr, $cepPrefix));
            $hasCity = (!empty($normCity) && str_contains($normAddr, $normCity));

            if (!empty($normCity) && !$hasCity && !$hasCepPrefix && preg_match('/-\s*[a-z]{2}\b/', $normAddr)) {
                continue;
            }

            similar_text($normSearch, $normTitle, $simPct);
            $matchedKeywords = 0;
            foreach ($keywords as $kw) {
                if (preg_match('/\b' . preg_quote($kw, '/') . '\b/', $normTitle)) {
                    $matchedKeywords++;
                }
            }
            $hasNameMatch = ($simPct >= 68) || ($matchedKeywords > 0 && ($matchedKeywords >= count($keywords) || $simPct >= 52)) || str_contains($normTitle, $normSearch) || str_contains($normSearch, $normTitle);

            if (!$hasNameMatch && !$hasExactAddress && !($validPlacesCount === 1 && $hasCity && $gKey === 'gmaps_exact')) {
                continue;
            }

            if ($hasExactAddress) {
                $foundExactAddressPlace = true;
            }

            $placeScore = $simPct;
            if ($hasNameMatch) $placeScore += 55;
            if ($matchedKeywords > 0) $placeScore += ($matchedKeywords * 25);
            if ($hasExactAddress) $placeScore += 120;
            elseif ($hasStreet) $placeScore += 40;
            if ($hasBairro) $placeScore += 20;
            if ($hasCepPrefix) $placeScore += 25;
            if ($hasCity) $placeScore += 20;
            if ($validPlacesCount === 1 && $gKey === 'gmaps_exact') $placeScore += 35;

            $pStatus = null;
            $flatJson = json_encode($p, JSON_UNESCAPED_UNICODE);
            if (preg_match_all('/"((?:Aberto|Fechado|Fecha em breve|Abre em breve)(?:\s*[·⋅]\s*[^"\\\\]{1,40}|\s+24\s+horas|\s+temporariamente)?)"/u', $flatJson, $sm)) {
                foreach ($sm[1] as $stCandidate) {
                    $stClean = trim($stCandidate);
                    if (stripos($stClean, 'inexistente') !== false || stripos($stClean, 'duplicado') !== false) continue;
                    if (!$pStatus || mb_strlen($stClean) > mb_strlen($pStatus)) {
                        $pStatus = $stClean;
                    }
                }
            }

            $ratingVal = $p[4][7] ?? null;
            $reviewsCount = $p[4][8] ?? null;
            $pRating = ($ratingVal !== null) ? str_replace('.', ',', (string)$ratingVal) . ($reviewsCount ? " ($reviewsCount)" : '') : null;
            $pWebsite = unwrapGoogleUrl($p[7][0] ?? null);

            if ($placeScore > $bestPlaceScore) {
                $bestPlaceScore = $placeScore;
                $mapsInfo['title'] = $pTitle;
                if ($pRating) $mapsInfo['rating'] = $pRating;
                if ($pStatus) $mapsInfo['status'] = $pStatus;
                if ($pAddr) $mapsInfo['address'] = $pAddr;
                if ($pWebsite) $mapsInfo['website'] = $pWebsite;
                $mapsInfo['is_exact_panel'] = true;

                $destQuery = urlencode("$pTitle, $pAddr");
                $mapsInfo['maps_url'] = "https://www.google.com/maps/search/?api=1&query=$destQuery" . (!empty($p[78]) ? "&query_place_id=" . urlencode($p[78]) : "");
            }

            if (!empty($p[178]) && is_array($p[178])) {
                foreach ($p[178] as $telObj) {
                    $rawTel = $telObj[3] ?? ($telObj[0] ?? '');
                    if (!empty($rawTel)) {
                        $label = ($hasExactAddress || $simPct >= 75) ? "Google Maps ($pTitle)" : "Google Maps";
                        $addCandidate($rawTel, $label, 160 + min(130, $placeScore * 0.4), $hasExactAddress);
                    }
                }
            }
        }
    }

    if ($foundExactAddressPlace) {
        foreach ($candidates as $k => $c) {
            if (str_starts_with($c['source'], 'Google Maps') && empty($c['is_exact_place'])) {
                unset($candidates[$k]);
            }
        }
    }

    if (!empty($mapsInfo['website'])) {
        $siteUrl = $mapsInfo['website'];
        if (!preg_match('/(facebook\.com|instagram\.com)/i', $siteUrl)) {
            $chSite = curl_init($siteUrl);
            curl_setopt_array($chSite, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_ENCODING => "",
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 4,
                CURLOPT_USERAGENT => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36"
            ]);
            $siteHtml = curl_exec($chSite);
            curl_close($chSite);

            if (!empty($siteHtml)) {
                if (preg_match_all('/(?:wa\.me\/|phone=)(?:55)?([1-9][0-9]9?[2-9][0-9]{7})\b/i', $siteHtml, $waM)) {
                    foreach ($waM[1] as $p) $addCandidate($p, "Google / Site Oficial (WhatsApp)", 260, true);
                }
                if (preg_match_all('/tel:([+\d\-\s()]+)/i', $siteHtml, $telM)) {
                    foreach ($telM[1] as $p) $addCandidate($p, "Google / Site da Empresa", 210, true);
                }
                if (preg_match_all('/\(?0?([1-9][0-9])\)?\s*(9[6-9][0-9]{3})[-\s.]?([0-9]{4})\b/', $siteHtml, $m, PREG_SET_ORDER)) {
                    foreach ($m as $match) $addCandidate($match[1].$match[2].$match[3], "Google / Site da Empresa", 200, true);
                }
            }
        }
    }

    if (empty($candidates)) {
        $bingHtml = $responses['bing_web'] ?? '';
        if (!empty($bingHtml)) {
            if (preg_match_all('/(?:wa\.me\/|phone=)(?:55)?([1-9][0-9]9?[2-9][0-9]{7})\b/i', $bingHtml, $waM)) {
                foreach ($waM[1] as $p) $addCandidate($p, "Google / Web (WhatsApp)", 175);
            }
            if (preg_match_all('/\(0?([1-9][0-9])\)\s*(9?[2-9][0-9]{3})[-\s.]?([0-9]{4})\b/', $bingHtml, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($m as $match) {
                    $digits = $match[1][0] . $match[2][0] . $match[3][0];
                    $offset = $match[0][1];
                    $context = stripAccents(substr($bingHtml, max(0, $offset - 300), 600));
                    if (str_contains($context, $normCity) || (!empty($keywords[0]) && str_contains($context, $keywords[0]))) {
                        $addCandidate($digits, "Busca Google / Web", 135);
                    }
                }
            }
        }
    }

    $localCandidates = array_filter($candidates, fn($c) => $c['is_local_ddd']);
    $finalList = !empty($localCandidates) ? array_values($localCandidates) : array_values($candidates);
    usort($finalList, fn($a, $b) => $b['score'] <=> $a['score']);
    $slicedPhones = array_slice($finalList, 0, 6);

    $receitaDisplayName = $fantasia ?: ($searchName ?: $razao);
    $formattedCnpj = formatCnpj($cnpj);

    $cnpjResponse = [
        'success' => true,
        'mode' => 'cnpj',
        'query' => $formattedCnpj,
        'resolved_name' => $receitaDisplayName,
        'elapsed_ms' => round((microtime(true) - $t0) * 1000),
        'company' => [
            'cnpj' => $cnpj,
            'formatted_cnpj' => $formattedCnpj,
            'razao_social' => $razao,
            'nome_fantasia' => $fantasia,
            'display_name' => $receitaDisplayName,
            'google_title' => $mapsInfo['title'] ?: $receitaDisplayName,
            'search_name' => $searchName,
            'cnae' => $cnae,
            'situacao' => $situacao,
            'city' => $city,
            'uf' => $uf,
            'cep' => $cepFormatted,
            'address' => $mapsInfo['address']
        ],
        'google_place' => $mapsInfo,
        'best_phone' => $slicedPhones[0] ?? null,
        'phones' => $slicedPhones,
        'branches' => [[
            'name' => $receitaDisplayName,
            'cnpj' => $formattedCnpj,
            'address' => $mapsInfo['address'],
            'status' => $mapsInfo['status'],
            'maps_url' => $mapsInfo['maps_url'],
            'phones' => $slicedPhones
        ]]
    ];
    setCacheItem($cnpjCacheKey, $cnpjResponse);

    http_response_code(200);
    echo json_encode($cnpjResponse);
    exit;
}

// Fallback se rota não encontrada
http_response_code(404);
echo json_encode(['error' => true, 'message' => 'Endpoint da API não encontrado.']);
