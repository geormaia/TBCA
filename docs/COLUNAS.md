# Colunas do banco `tbca.db`

O banco gerado pelo `pivot.php` tem **48 colunas** — 7 de metadados e 41 nutricionais.
Cada linha representa **1 alimento** (formato wide), com todos os valores **por 100 g**.

Todos os campos são `TEXT` no SQLite. Valores numéricos usam ponto decimal (ex: `16.2`).
Campos vazios (`NULL`) indicam que a TBCA não mediu aquele nutriente para aquele alimento.

---

## Sumário

1. [Metadados](#1-metadados) — 7 colunas
2. [Energia e macros](#2-energia-e-macros) — 11 colunas
3. [Ácidos graxos](#3-ácidos-graxos) — 4 colunas
4. [Minerais](#4-minerais) — 10 colunas
5. [Vitaminas](#5-vitaminas) — 11 colunas
6. [Composição](#6-composição) — 5 colunas

---

## 1. Metadados

Informações que identificam o alimento. Não são valores nutricionais.

| Coluna | Tipo | Descrição | Exemplo |
|---|---|---|---|
| `Código` | TEXT | Identificador único TBCA (chave primária) | `BRC0147T` |
| `Nome` | TEXT | Nome completo do alimento | `Soja, grão, cozido, drenado, s/ óleo, s/ sal, Brasil` |
| `Nome científico` | TEXT | Nome em latim (quando aplicável) | `Glycine max (L.) Merr.` |
| `Grupo` | TEXT | Categoria do alimento | `Leguminosas e derivados` |
| `Marca` | TEXT | Marca (para industrializados) | `Nestlé` ou vazio |
| `slug` | TEXT | Nome normalizado para URLs (sem acento, minúsculo) | `soja-grao-cozido-drenado-s-oleo-s-sal-brasil` |
| `Outras medidas` | TEXT (JSON) | Porções caseiras com peso em gramas | `[{"label":"Colher sopa","value":0.15}]` |

### Formato de `Outras medidas`

JSON com array de objetos. Cada objeto tem:

- `label` — nome da porção (`"Colher de sopa"`, `"Xícara de chá"`, `"Fatia"`)
- `value` — **fração de 100 g** (não é o peso em gramas!)

**Exemplo:** uma colher de sopa pesa 15 g. No JSON:
```json
[{"label":"Colher de sopa","value":0.15}]
