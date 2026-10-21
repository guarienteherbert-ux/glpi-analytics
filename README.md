# Service Desk Analytics (GLPI 11)

Plugin de relatórios gerenciais para o GLPI 11. Fase atual: **Relatório Mensal de Service Desk** (tela, PDF/impressão, CSV e Word, com logos).

- **Autor:** Herbert Guariente
- **Licença:** GPL-2.0-or-later
- **Requisitos:** GLPI >= 11.0.0 e < 11.1.0, PHP >= 8.2
- **Permissão:** direito nativo `ticket` (leitura). Não cria tabelas nem direitos próprios.

## Instalação

1. Copie a pasta `analytics` para `plugins/` (ou `marketplace/`) do GLPI. O nome da pasta precisa ser `analytics`.
2. Ajuste o dono/permissões dos arquivos para o usuário do PHP-FPM/Apache.
3. Em **Configurar > Plugins**, instale e ative **Service Desk Analytics**.
4. O relatório aparece em **Gerência > Relatório Mensal**.

## Estrutura

```
analytics/
├── setup.php                          metadados, requisitos, menu
├── hook.php                           install/uninstall (sem tabelas)
├── inc/
│   ├── monthlyreport.class.php        classe de menu (CommonGLPI)
│   ├── monthlyreportdata.class.php    consultas e agregações (sem HTML)
│   └── monthlyreportdocx.class.php    gerador do Word (.docx), sem bibliotecas
└── front/
    └── monthly_report.php             tela, filtros (GET), CSV, Word, PDF e logos
```

## PDF / impressão

O botão **PDF / Imprimir** abre uma página A4 própria (sem menu, cabeçalho ou rodapé do GLPI) e já chama o
diálogo de impressão. Escolha "Salvar como PDF". Se o diálogo não abrir sozinho, use Ctrl+P.

- Página 1: modelo do relatório. Página 2: indicadores complementares.
- Cabeçalho com a logo da empresa (esquerda) e a do cliente (direita).
- Rodapé com data/hora, usuário e "Página X de Y" (numeração depende do navegador; Chrome/Edge recentes suportam).
- O nome sugerido para o PDF é o título da página: "Relatório Mensal de Service Desk - Cliente - Mês Ano".

## Logos

Em **Logos do relatório**, na tela do relatório (visível para quem tem permissão de alterar a configuração do GLPI):

- **Logo da empresa:** única, vale para todos os relatórios.
- **Logo do cliente:** uma por entidade. Sem logo própria, usa a da entidade-pai mais próxima. A entidade raiz
  representa a empresa e não recebe logo de cliente.

PNG ou JPEG, até 1 MB. A imagem é revalidada, reduzida (máx. 600x240) e regravada como PNG em
`files/_plugins/analytics/`; SVG não é aceito.

## O que o relatório mostra

Página 1 do Word = modelo "Relatório Mensal de Service Desk" (identificação, status, comparativo e SLA,
origem, tipo, tempo de solução, top técnicos). Página 2 = indicadores complementares:

| Bloco | Conteúdo |
|---|---|
| Volume e distribuição | mini gráficos por status (5 estados), tipo e origem |
| Categorias | top 10 categorias mais abertas |
| Solicitantes | top 10 solicitantes |
| Prazos e desempenho | SLA de solução, SLA de 1º atendimento, TMA, tempo de 1º atendimento, tempo em Pendente |
| Produtividade | chamados por grupo e horas trabalhadas (tempo de ação das tarefas) |
| Satisfação (CSAT) | respondidas, nota média, % satisfeitos (nota 4 ou 5) |

Duas visões, sempre rotuladas:

| Visão | Critério de data |
|---|---|
| Chamados **abertos** no mês | `glpi_tickets.date` (status, origem, tipo, categorias, solicitantes, técnicos, grupos) |
| Chamados **solucionados** no mês | `glpi_tickets.solvedate` (SLA, tempos, distribuição) |

Notas:

- **Status:** "Atribuído" inclui Planejado; "Fechado" inclui Solucionado (como no modelo). Nos gráficos os 5 estados aparecem separados.
- **Origem:** agrupada pelo nome em `glpi_requesttypes` nas 4 categorias do modelo (E-mail, Telefone, Portal, WhatsApp/Emergencial).
  O mapeamento está em `ORIGIN_KEYWORDS` (`inc/monthlyreportdata.class.php`). O que não casar vai para "Outras origens".
  A tela mostra abaixo da tabela os nomes reais das origens e as quantidades, para conferir o agrupamento.
- **SLA:** só chamados com prazo definido (`time_to_resolve` / `time_to_own`); sem prazo, ficam fora da conta.
- **TMA:** usa `solve_delay_stat` (desconta tempo em Pendente), com fallback para `solvedate - date`.
- **Horas trabalhadas:** soma `actiontime` das tarefas de chamado criadas no mês.
- **Top técnicos / grupos:** conta chamados abertos no mês com técnico ou grupo atribuído.
- **Entidade:** validada com `Session::haveAccessToEntity()`.
- **Indicadores complementares isolados:** se uma consulta falhar, o relatório continua e o bloco mostra "—".
  O erro vai para `files/_log/analytics.log`.

## Checklist de testes

1. Plugin ativa sem erro; o menu aparece em Gerência.
2. Conferir "Total no mês" com uma busca manual de chamados pela data de abertura.
3. Conferir as origens: a linha "Origens no GLPI" deve estar toda distribuída nas 4 categorias. Se aparecer "Outras origens", ajuste `ORIGIN_KEYWORDS`.
4. SLA: um chamado solucionado depois do prazo deve baixar o percentual.
5. Trocar de entidade e testar "Incluir subentidades".
6. Baixar o Word e o CSV; abrir o Word no Word/LibreOffice (2 páginas, com as logos no cabeçalho).
7. PDF / Imprimir: deve abrir uma página A4 (2 páginas); enviar as duas logos e conferir o cabeçalho.
8. Se aparecer o aviso amarelo de indicadores indisponíveis, ver `files/_log/analytics.log`.
