// server.js - Servidor Local Ellos Zap com Integração Oficial 2Chat
const http = require('http');
const https = require('https');
const fs = require('fs');
const path = require('path');
const url = require('url');

const PORT = process.env.PORT || 3000;
const DEFAULT_2CHAT_KEY = 'UAKe746d687-cbe1-4042-b070-7d81eb24a9a5';

// Tipos MIME para arquivos estáticos
const MIME_TYPES = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css',
  '.js': 'application/javascript; charset=utf-8',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.svg': 'image/svg+xml',
  '.json': 'application/json; charset=utf-8',
  '.webmanifest': 'application/manifest+json; charset=utf-8'
};

// Requisição HTTPS auxiliar para a 2Chat API
function request2Chat(endpoint, apiKey) {
  return new Promise((resolve, reject) => {
    const options = {
      hostname: 'api.p.2chat.io',
      path: endpoint,
      method: 'GET',
      headers: {
        'X-User-API-Key': apiKey || DEFAULT_2CHAT_KEY,
        'User-Agent': 'EllosZap-Server/1.0'
      }
    };

    const req = https.request(options, (res) => {
      let data = '';
      res.on('data', chunk => { data += chunk; });
      res.on('end', () => {
        try {
          const json = JSON.parse(data);
          resolve({ status: res.statusCode, data: json });
        } catch (e) {
          resolve({ status: res.statusCode, raw: data });
        }
      });
    });

    req.on('error', err => reject(err));
    req.end();
  });
}

const server = http.createServer(async (req, res) => {
  // Configuração CORS para permitir requisições de file:/// ou localhost
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type, X-User-API-Key');

  if (req.method === 'OPTIONS') {
    res.writeHead(204);
    res.end();
    return;
  }

  const parsedUrl = url.parse(req.url, true);
  const pathname = parsedUrl.pathname;
  const query = parsedUrl.query;
  const apiKey = req.headers['x-user-api-key'] || query.api_key || DEFAULT_2CHAT_KEY;

  // 1. Endpoint: Informações da Conta 2Chat
  if (pathname === '/api/2chat/info') {
    try {
      const result = await request2Chat('/open/info', apiKey);
      res.writeHead(result.status, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify(result.data));
    } catch (err) {
      res.writeHead(500, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: true, message: err.message }));
    }
    return;
  }

  // 2. Endpoint: Lista de números conectados na 2Chat
  if (pathname === '/api/2chat/numbers') {
    try {
      const result = await request2Chat('/open/whatsapp/get-numbers', apiKey);
      res.writeHead(result.status, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify(result.data));
    } catch (err) {
      res.writeHead(500, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: true, message: err.message }));
    }
    return;
  }

  // 3. Endpoint: Checar número no WhatsApp
  if (pathname === '/api/2chat/check') {
    let targetPhone = (query.phone || '').replace(/\D/g, '');
    let sourcePhone = (query.source || '').trim();

    if (!targetPhone) {
      res.writeHead(400, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: true, message: 'Parâmetro "phone" é obrigatório.' }));
      return;
    }

    if (!targetPhone.startsWith('55') && targetPhone.length >= 10 && targetPhone.length <= 11) {
      targetPhone = '55' + targetPhone;
    }
    const formattedTarget = '+' + targetPhone;

    try {
      // Se não especificou source, descobre automaticamente o primeiro número conectado na 2Chat
      if (!sourcePhone) {
        const numbersRes = await request2Chat('/open/whatsapp/get-numbers', apiKey);
        if (numbersRes.data && numbersRes.data.numbers && numbersRes.data.numbers.length > 0) {
          sourcePhone = numbersRes.data.numbers[0].phone_number || numbersRes.data.numbers[0].number;
        }
      }

      if (!sourcePhone) {
        // Sem número conectado na conta da 2Chat
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({
          requires_source_connection: true,
          error: true,
          message: 'Sua conta da 2Chat precisa de um número de WhatsApp conectado (QR Code) para consultar a Meta.'
        }));
        return;
      }

      if (!sourcePhone.startsWith('+')) {
        sourcePhone = '+' + sourcePhone.replace(/\D/g, '');
      }

      const checkEndpoint = `/open/whatsapp/check-number/${encodeURIComponent(sourcePhone)}/${encodeURIComponent(formattedTarget)}`;
      const result = await request2Chat(checkEndpoint, apiKey);

      res.writeHead(result.status, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify(result.data));
    } catch (err) {
      res.writeHead(500, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: true, message: err.message }));
    }
    return;
  }

  // 4. Endpoint: Trajeto CNPJ -> Nome da Empresa -> Busca Google Maps & Telefone
  if (pathname === '/api/2chat/cnpj' || pathname === '/api/cnpj') {
    const rawCnpj = (query.cnpj || '').replace(/\D/g, '');
    if (rawCnpj.length !== 14) {
      res.writeHead(400, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: true, message: 'Informe um CNPJ válido com 14 dígitos.' }));
      return;
    }

    try {
      const fetchText = (targetUrl) => new Promise((resolve) => {
        https.get(targetUrl, { headers: { 'User-Agent': 'Mozilla/5.0 Chrome/124.0', 'Accept-Language': 'pt-BR' } }, (r) => {
          let body = '';
          r.on('data', chunk => { body += chunk; });
          r.on('end', () => resolve({ status: r.statusCode, body }));
        }).on('error', () => resolve({ status: 500, body: '' }));
      });

      const brRes = await fetchText(`https://brasilapi.com.br/api/cnpj/v1/${rawCnpj}`);
      let cnpjData = brRes.status === 200 ? JSON.parse(brRes.body) : null;
      if (!cnpjData || !cnpjData.razao_social) {
        res.writeHead(404, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: true, message: 'CNPJ não encontrado na Receita Federal.' }));
        return;
      }

      const razao = (cnpjData.razao_social || '').trim();
      const fantasia = (cnpjData.nome_fantasia || '').trim();
      const searchName = (fantasia || razao)
        .replace(/^[\d.\-\/\s]+/, '')
        .replace(/\s+\d{11}$/, '')
        .replace(/\b(LTDA|ME|EPP|EIRELI|S\/A|S\.A\.|SOCIEDADE SIMPLES|UNIPESSOAL|LIMITADA)\b\.?/gi, '')
        .replace(/\s+/g, ' ').trim();

      const city = (cnpjData.municipio || '').trim();
      const uf = (cnpjData.uf || '').trim().toUpperCase();
      const logradouro = [cnpjData.descricao_tipo_de_logradouro, cnpjData.logradouro].filter(Boolean).join(' ').trim();
      const numero = (cnpjData.numero || '').trim();
      const bairro = (cnpjData.bairro || '').trim();
      const cepRaw = (cnpjData.cep || '').replace(/\D/g, '');
      const cepFormatted = cepRaw.length === 8 ? `${cepRaw.slice(0,5)}-${cepRaw.slice(5)}` : cepRaw;

      const qExact = encodeURIComponent(`${searchName} ${logradouro} ${numero} ${cepFormatted} ${bairro} ${city} ${uf}`.trim());
      const qDdg = encodeURIComponent(`${searchName} ${city} ${uf} telefone whatsapp`.trim());

      const [gmapsExact, ddgTel] = await Promise.all([
        fetchText(`https://r.jina.ai/https://www.google.com/maps/search/${qExact}?hl=pt-BR`),
        fetchText(`https://r.jina.ai/https://html.duckduckgo.com/html/?q=${qDdg}`)
      ]);

      const mapsInfo = {
        title: searchName,
        rating: null,
        status: null,
        address: [logradouro + (numero ? `, ${numero}` : ''), bairro, `${city} - ${uf}`, cepFormatted].filter(Boolean).join(' · '),
        maps_url: `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`${searchName} ${logradouro} ${numero} ${city} ${uf}`)}`
      };

      const md = gmapsExact.body || '';
      const hMatch = md.match(/^#\s+([^\n\r]+)/m);
      if (hMatch && !['results', 'resultados'].includes(hMatch[1].trim().toLowerCase())) {
        mapsInfo.title = hMatch[1].trim();
      }
      const rMatch = md.match(/\b([1-5][,.][0-9])\s*\(([\d.]+)\)/);
      if (rMatch) mapsInfo.rating = `${rMatch[1].replace('.', ',')} (${rMatch[2]})`;
      const sMatch = md.match(/((?:Aberto|Fechado|Fecha em breve|Abre em breve)[^\n\r]{0,60})/i);
      if (sMatch) mapsInfo.status = sMatch[1].replace(/[^\w\sÀ-ÿ·:.,-]/g, '').trim();

      const candidates = new Map();
      const addPhone = (raw, source, score) => {
        let d = (raw || '').replace(/\D/g, '');
        if (d.startsWith('55') && d.length >= 12) d = d.slice(2);
        if (d.startsWith('0') && d.length >= 11) d = d.slice(1);
        if (d.length < 10 || d.length > 11) return;
        if (d.length === 10 && ['7', '8', '9'].includes(d[2])) d = d.slice(0, 2) + '9' + d.slice(2);
        const isMobile = d.length === 11 && d[2] === '9';
        const formatted = isMobile ? `(${d.slice(0,2)}) ${d.slice(2,7)}-${d.slice(7)}` : `(${d.slice(0,2)}) ${d.slice(2,6)}-${d.slice(6)}`;
        const finalScore = score + (isMobile ? 25 : 0);
        if (!candidates.has(d) || candidates.get(d).score < finalScore) {
          candidates.set(d, { phone: d, formatted, type: isMobile ? 'MOBILE' : 'LANDLINE', source, score: finalScore });
        }
      };

      for (const [srcLabel, content, base] of [['Google Maps (Ficha Oficial)', md, 200], ['Busca Google / Web', ddgTel.body || '', 150]]) {
        for (const m of content.matchAll(/tel:([+\d\-\s()]+)/gi)) addPhone(m[1], srcLabel, base + 30);
        for (const m of content.matchAll(/\(0?([1-9][0-9])\)\s*(9?[2-9][0-9]{3})[-\s.]?([0-9]{4})\b/g)) addPhone(m[1]+m[2]+m[3], srcLabel, base);
        for (const m of content.matchAll(/(?:\+55\s*)?\b([1-9][0-9])\s+(9?[2-9][0-9]{3})[-\s]([0-9]{4})\b/g)) addPhone(m[1]+m[2]+m[3], srcLabel, base);
      }
      if (cnpjData.ddd_telefone_1) addPhone(cnpjData.ddd_telefone_1, 'Cadastro Receita Federal', 60);
      if (cnpjData.ddd_telefone_2) addPhone(cnpjData.ddd_telefone_2, 'Cadastro Receita Federal', 60);

      const phones = Array.from(candidates.values()).sort((a, b) => b.score - a.score).slice(0, 6);
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({
        success: true,
        company: {
          cnpj: rawCnpj,
          razao_social: razao,
          nome_fantasia: fantasia,
          display_name: mapsInfo.title || searchName,
          cnae: cnpjData.cnae_fiscal_descricao || '',
          situacao: cnpjData.descricao_situacao_cadastral || 'ATIVA',
          city, uf, cep: cepFormatted, address: mapsInfo.address
        },
        google_place: mapsInfo,
        best_phone: phones[0] || null,
        phones
      }));
    } catch (err) {
      res.writeHead(500, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: true, message: err.message }));
    }
    return;
  }

  // Servir arquivos estáticos (index.html, assets, etc.)
  let filePath = path.join(__dirname, pathname === '/' ? 'index.html' : pathname);
  const ext = path.extname(filePath).toLowerCase();
  const contentType = MIME_TYPES[ext] || 'application/octet-stream';

  fs.readFile(filePath, (err, content) => {
    if (err) {
      if (err.code === 'ENOENT') {
        res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
        res.end('404 Arquivo Não Encontrado');
      } else {
        res.writeHead(500);
        res.end(`Erro no servidor: ${err.code}`);
      }
    } else {
      const headers = { 'Content-Type': contentType };
      if (pathname === '/sw.js') {
        headers['Service-Worker-Allowed'] = '/';
        headers['Cache-Control'] = 'no-cache';
      }
      if (pathname === '/manifest.json') {
        headers['Content-Type'] = 'application/manifest+json; charset=utf-8';
      }
      res.writeHead(200, headers);
      res.end(content);
    }
  });
});

server.listen(PORT, () => {
  console.log(`===============================================`);
  console.log(`⚡ Ellos Zap Server rodando em http://localhost:${PORT}`);
  console.log(`🔑 2Chat API Key Ativa: ${DEFAULT_2CHAT_KEY.substring(0, 10)}...`);
  console.log(`===============================================`);
});
