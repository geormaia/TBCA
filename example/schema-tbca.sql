-- Schema do tbca.db gerado pelo pivot.php
CREATE TABLE tbca (
    "Código" VARCHAR(10) PRIMARY KEY,
    "Nome" VARCHAR(255),
    "Nome científico" VARCHAR(255),
    "Grupo" VARCHAR(50),
    "Marca" VARCHAR(50),
    "slug" VARCHAR(255),
    "Outras medidas" TEXT,
    "Energia |kJ|" TEXT,
    "Energia |kcal|" TEXT,
    "Umidade |g|" TEXT,
    -- ... (ver docs/COLUNAS.md para lista completa)
    "Proteína animal |g|" TEXT
);
