# Cedu Moodle Analytics

Painel de gestão da plataforma EAD do **Cemaden Educação** (Centro Nacional de Monitoramento e Alertas de Desastres
Naturais), como plugin local para o Moodle 4.5. Criado especificamente para esse projeto.

## O que mostra
- **Estudantes:** visão geral, primeiro acesso, participação, quem precisa de apoio (com mensagens, anotações e
  acompanhamento) e certificados.
- **Cursos:** todos os cursos com saúde, edições e trilhas, percurso e evasão, avaliações e questões, materiais e
  reprodução de vídeos do YouTube, feedback (inclui a pesquisa sobre os territórios).
- **Equipe:** cartões por docente, tempo de resposta, mensagens sem resposta e correções pendentes.
- **Alcance:** mapa do Brasil por UF (malha do IBGE) e perfil do público.
- **Relatórios:** catálogo de todas as listas (CSV/XLSX) e boletim executivo imprimível.
- **Plataforma:** segurança e desempenho, alertas, configurações e a página **Sobre**, com a documentação do painel.

## Acesso e requisitos
- Acesso: administradores do site, por Administração do site › Geral › Estatísticas e dados do Moodle, por
  Administração do site › Relatórios › Cedu Moodle Analytics e, com o tema Stream, pelo menu Gerenciar. As
  capabilities `local/ceduanalytics:*` não têm arquétipos.
- Configurações: Administração do site › Plugins › Plugins locais › Cedu Moodle Analytics.
- Requisitos: Moodle 4.5 (2024100700) ou superior na série 4.5; MySQL/MariaDB (o tamanho das tabelas na aba
  Desempenho usa o `information_schema`; o restante é portável).

## Dados e desempenho
As páginas nunca leem o log padrão diretamente. Três tarefas agendadas preparam resumos:
`aggregate_log` (a cada hora, incremental pelo id do log), `build_snapshot` (a cada hora) e `derive_attributes`
(toda noite). Na instalação, uma tarefa avulsa (`backfill`) processa o histórico.

O rastreamento de vídeos (configuração `videotracking`, ligado por padrão) envia uma chamada pequena a cada
30 segundos enquanto um vídeo do YouTube incorporado toca.

## Privacidade
Implementa a Privacy API (exportação e exclusão dos dados por usuário). Mensagens e anotações são lidas só pelos
metadados; feedbacks anônimos continuam anônimos.

## Desenvolvimento
- JavaScript: `amd/src` (AMD), build com `npx grunt amd` a partir da pasta do plugin.
- Estilo: `phpcs --standard=moodle` (moodle-cs) e `npx grunt stylelint`.
- O mapa (`classes/local/brmap.php`) é gerado da malha de UFs do IBGE (API de malhas v3, qualidade mínima).

## Autoria
**Alexandre Marques Almeida Junior** — https://github.com/alxmqsjr

## Licença
GNU GPL v3 ou posterior.
