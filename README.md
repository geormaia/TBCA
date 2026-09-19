# TBCA Scraper

Scraper PHP não-oficial da **Tabela Brasileira de Composição de Alimentos (TBCA/USP)**.

Coleta a lista completa de alimentos e seus valores nutricionais, gerando um banco SQLite pronto para uso em aplicações de nutrição e pesquisa.

## ⚠️ Aviso

- Projeto **não oficial**, sem vínculo com USP/TBCA.
- Os **dados** são de propriedade da TBCA/USP. Este repositório contém apenas o **código**.
- Uso livre para pesquisa. Redistribuição dos dados requer atribuição à fonte.
- Scraper implementa rate limiting para não sobrecarregar o servidor.

## Fonte

- https://www.tbca.net.br
- TBCA versão 7.2 (2024) — USP / Food Research Center (FoRC)

## Como funciona

1. **`scraper.php`** — varre as ~120 páginas de listagem, baixa cada alimento e extrai 48 campos nutricionais.
2. **`pivot.php`** — converte o formato EAV (1 linha por componente) em wide (1 linha por alimento).

Saídas:
- `tbca.db` — formato final, pronto pra consulta
- `tbca_bruto.db` — formato bruto, para auditoria

## Requisitos

- PHP 7.4+ (testado até 8.3)
- Extensões: `curl`, `dom`, `sqlite3`, `mbstring`
- ~100 MB de disco

## Instalação

```bash
git clone https://github.com/seu-usuario/tbca-scraper.git
cd tbca-scraper
php -m | grep -E "curl|dom|sqlite3|mbstring"
