# TBCA Scraper

Scraper em PHP para extrair dados da **Tabela Brasileira de Composição de Alimentos (TBCA)** — USP/FoRC.

Coleta a lista completa de alimentos e seus valores nutricionais, gerando um banco SQLite pronto para uso em aplicações de nutrição, pesquisa e desenvolvimento.

## ⚠️ Aviso importante

- Este projeto é **não oficial** e não tem vínculo com a USP ou com a equipe da TBCA.
- Os **dados** são de propriedade da **TBCA/USP** e estão disponíveis publicamente em [tbca.net.br](https://www.tbca.net.br) para consulta.
- Este repositório contém **apenas o código do scraper**. O banco gerado (`tbca.db`) **não é distribuído** — cada usuário deve rodar o scraper por conta própria.
- O uso dos dados para fins comerciais ou redistribuição requer **atribuição à TBCA** e respeito aos termos do site.
- O scraper implementa **rate limiting** (delay de 300 ms entre requisições) para não sobrecarregar o servidor.

## Fonte

- Site: https://www.tbca.net.br
- Publicação: Tabela Brasileira de Composição de Alimentos (TBCA), versão 7.2 (2024)
- Instituição: Universidade de São Paulo (USP) — Food Research Center (FoRC)

## O que faz

1. **Listagem** — varre as ~120 páginas de `composicao_alimentos.php?pagina=N`, extrai código, nome, nome científico, grupo, marca e URL.
2. **Detalhes** — para cada alimento, abre a página de composição e extrai **48 campos** nutricionais (energia, macros, minerais, vitaminas, ácidos graxos).
3. **Medidas caseiras** — captura as porções (colher, xícara, fatia…) com peso em gramas.
4. **Banco bruto (EAV)** — salva os dados crus em formato longo (`tbca_bruto.db`) para auditoria.
5. **Banco final (wide)** — pivota para uma linha por alimento (`tbca2.db`), pronto para uso.

## Requisitos

- PHP 7.4+ (testado até 8.3)
- Extensões: `curl`, `dom`, `sqlite3`, `mbstring`
- ~100 MB de espaço em disco
- Acesso à internet

## Instalação

```bash
git clone https://github.com/seu-usuario/tbca-scraper.git
cd tbca-scraper

# Verifica dependências
php -m | grep -E "curl|dom|sqlite3|mbstring"
