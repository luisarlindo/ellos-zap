# Ellos Zap ⚡

Disparador rápido e inteligente de conversas no WhatsApp para a rede **ellos connecTI** com verificação oficial via **2Chat API** e suporte completo a **PWA (Progressive Web App)**, otimizado especialmente para uso em celulares (mobile-first).

---

## 📌 Funcionalidades

### 📱 1. Experiência Mobile-First Sofisticada
- **Design Nativo para Celulares**: Campos amplos, toques confortáveis (mínimo de 48px), tipografia elegante e sem zoom indesejado no iOS Safari.
- **Prévia Realista do WhatsApp**: Interface reproduzindo fielmente o aplicativo oficial da Meta (foto/iniciais do contato, status online, balão verde autêntico com horário e confirmação dupla azul `✓✓`).
- **PWA (Progressive Web App)**: Pode ser instalado direto na tela inicial do celular (Android e iOS) ou desktop pelo botão **"Instalar App"** no topo. Funciona em tela cheia (standalone) como app nativo.
- **Service Worker (`sw.js`)**: Cache v2 veloz e carregamento imediato.

### 💾 2. Controle Destacado: "Salvar este contato"
- **Design Visual Atraente & Prominente**:
  - Card estilizado com cantos arredondados, gradiente sutil e ícone em destaque.
  - **Switch estilo iOS**: Toque fácil em qualquer ponto do card para ativar/desativar.
  - Substitui o antigo checkbox pequeno por um controle moderno e convidativo.
  - Lembra sua preferência automaticamente entre sessões.
- **Agenda Rápida Local**:
  - Salva contatos no navegador com contador dinâmico.
  - **Busca em tempo real**: Filtre contatos instantaneamente por nome ou número.
  - **Reuso com 1 toque**: Ao clicar em "Reusar", o aplicativo preenche o formulário e rola suavemente para o topo da tela pronto para o envio.
  - **Exclusão individual**: Remova contatos específicos com o botão de lixeira.

### 🔍 3. Verificação Automática 2Chat & Anatel
- **Configuração 100% Interna**: A integração com a 2Chat API já vem ativa e transparente (chave oficial `UAKe746d687-cbe1-4042-b070-7d81eb24a9a5` rodando no backend).
- **Sem poluição visual**: Removido botão e modais de configuração manual, simplificando o uso.
- **Validação Algorítmica Anatel**: Reconhecimento automático dos 67 DDDs brasileiros, celulares (9 dígitos) e linhas fixas.
- **Botão "Testar Existência Real no WhatsApp"**: Permite teste oficial em um clique.

### ⚡ 4. Agilidade no Atendimento
- **Máscara Automática**: Formatação brasileira `(DDD) 9XXXX-XXXX`.
- **Mensagem com Tag Dinâmica `{nome}`**: O nome digitado entra automaticamente na saudação.
- **Templates Rápidos**: Pílulas de mensagens prontas para *Padrão Ellos Zap*, *Orçamento Farma* e *Representante Ellos*.
- **Botão Copiar Link**: Copia o link `wa.me/55...` direto para a área de transferência.

---

## 🚀 Como Executar e Acessar

### 1. Iniciar o Servidor Local
```powershell
cd C:\Users\Windows\.gemini\antigravity\scratch\ellos-zap
node server.js
```

### 2. Acessar o Aplicativo
Abra seu navegador em:
👉 **[http://localhost:3000](http://localhost:3000)**

### 3. Como Instalar no Celular
- **No Android (Chrome)**: Toque em **"Instalar App"** no topo ou nos 3 pontinhos do navegador > **"Instalar aplicativo"** / **"Adicionar à tela inicial"**.
- **No iPhone / iPad (Safari)**: Toque no botão de compartilhamento (quadrado com seta para cima) e escolha **"Adicionar à Tela de Início"**.
