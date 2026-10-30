# Segurança da Lynch Store: o que fazer na Hostinger

Guia curto para o Matheus. O código já traz as defesas; aqui está o que depende do painel da hospedagem e o que fazer se algo der errado.

## Antes de publicar

1. **HTTPS**: ative o SSL do domínio no hPanel e deixe "Forçar HTTPS" ligado. O site manda HSTS com `includeSubDomains`: todo subdomínio (www, loja, etc.) precisa ter HTTPS. Não use `preload` sem ter certeza, porque sair da lista dos navegadores leva meses.
2. **PHP 8.3 ou superior** (hPanel, Avançado, Configuração do PHP). Na mesma tela:
   - `display_errors` desligado, `expose_php` desligado;
   - `allow_url_fopen` desligado (a loja não busca nada fora) e `allow_url_include` desligado;
   - em "funções desabilitadas", acrescente `exec, passthru, shell_exec, system, proc_open, popen, show_source` (a loja não usa nenhuma);
   - `upload_max_filesize` 12M, `post_max_size` 16M, `memory_limit` 256M.
   O arquivo `.user.ini` do pacote já pede erros desligados, sessão segura e esses limites. As diretivas acima, que o PHP não aceita em `.user.ini`, só mudam no hPanel.
3. **security.txt**: edite `.well-known/security.txt` e troque `seguranca@SEU-DOMINIO.com.br` por um contato da loja ou da agência (não um e-mail pessoal) e o domínio em `Canonical`. Renove `Expires` todo ano. O build avisa enquanto o exemplo estiver lá.
4. **Senha do painel**: o instalador recusa senhas de exemplo e sequências. Use uma frase longa, só da Ray, guardada no gerenciador de senhas do celular.
5. Depois de instalar, confira que `instalar.php` sumiu (o instalador se apaga junto com a chave e o catálogo). Se continuar lá, apague pelo Gerenciador de Arquivos.

## Backups (responsabilidade da hospedagem, mas confira)

- No hPanel, em Backups, confirme os backups automáticos de **arquivos e banco** e baixe uma cópia por mês para fora da Hostinger.
- A pasta `uploads/` (fotos) e o banco precisam estar no mesmo backup.
- Antes de qualquer atualização da loja: backup manual do banco (phpMyAdmin, Exportar) e da pasta `public_html`.

## Senhas e chaves

- **Banco**: em Bancos de dados, troque a senha do usuário MySQL a cada 6 meses ou se alguém de fora teve acesso. Depois atualize `DB_PASS` no `.env` (Gerenciador de Arquivos). O `.env` fica bloqueado para o navegador.
- **Painel**: a Ray troca em Minha conta. Trocar a senha desconecta todos os outros aparelhos.
- A chave do instalador vale só para aquele pacote e deixa de existir depois da instalação.

## Se suspeitar de invasão

1. Em Minha conta, olhe "Acessos e alterações recentes". "Esta rede" é a conexão de quem está olhando; outras redes com "Entrou no painel" que a Ray não reconhece são o sinal de alerta.
2. Troque a senha do painel (Minha conta) e use **Encerrar os outros aparelhos**. Isso derruba todas as outras sessões na hora.
3. Troque a senha do banco no hPanel e atualize o `.env`.
4. Se algum arquivo do site mudou sem ninguém mexer, restaure o backup de arquivos de antes do problema (e o do banco, se os dados foram alterados). Depois troque as senhas de novo.
5. Emergência sem acesso ao painel: no phpMyAdmin, `UPDATE admin_users SET active = 0` desliga todos os logins e derruba todas as sessões na próxima ação. Para voltar, `active = 1` e troque a senha.

## Logs

- Ficam **fora** do `public_html`, na pasta `lynch-privado` ao lado dela (Gerenciador de Arquivos, um nível acima):
  - `seguranca.log`: login errado ou bloqueado, CSRF inválido, cupom inexistente, upload recusado, limite de acessos e de pedidos, pedido de robô. Uma linha JSON por evento, sem senha nem dado de cliente; o IP aparece só como hash curto. O arquivo gira sozinho em 1 MB (guarda `.1` e `.2`).
  - `php-erros.log`: erros do PHP.
- Se a pasta não puder ser criada, os logs vão para a pasta temporária do PHP. Para fixar outro lugar, defina `LYNCH_PRIVATE_DIR` no `.env`.
- A trilha do painel (quem entrou, o que mudou) fica no banco, tabela `audit_log`, e aparece em Minha conta. Registros com mais de 180 dias são apagados sozinhos.

## Atualizar o PHP

1. Faça backup.
2. Em Configuração do PHP, mude a versão (ex.: 8.3 para 8.4).
3. Abra a loja, o produto, a sacola e o painel (login, salvar produto, enviar foto). Se algo quebrar, volte a versão anterior no mesmo lugar.

## Limites que o site aplica sozinho

| O quê | Limite | Observação |
|---|---|---|
| Login, mesma conta e mesma rede | 5 erros em 15 min | Bloqueia só aquela rede. A Ray, de outra rede, continua entrando |
| Login, mesma rede, qualquer usuário | 10 erros em 15 min | Contra robô que testa vários usuários |
| Login, conta inteira | 30 erros seguidos | Bloqueia 15 min apenas aparelhos onde a Ray nunca entrou |
| Sessão do painel | 2 h sem uso ou 12 h desde o login | Pede a senha de novo |
| Cupom | 5 erros por sessão e 20 por rede em 10 min | |
| Pedidos novos | 10 por sessão e 40 por rede por hora; 15 usos de cupom por rede por hora | Repetir o mesmo envio não conta |
| Páginas da loja | 300 por minuto por rede; busca instantânea 120 por minuto | Responde 429 com Retry-After. Ajustável no `.env` (`LYNCH_RATE_PAGES=300/60`, `LYNCH_RATE_SEARCH=120/60`) |

Os limites por rede são generosos porque, em rede móvel, muitas clientes podem sair pelo mesmo IP (CGNAT). Se a loja crescer muito, aumente `LYNCH_RATE_PAGES`.

## O que fica para decidir

- **2FA (código no celular) para o painel**: não implementado. Ajuda contra senha vazada, mas exige app autenticador e códigos de recuperação que a Ray precisa guardar; perder o celular a tranca para fora. Recomendação: só se o painel ganhar mais usuários ou dados de clientes.
- **"Lembrar neste aparelho"**: não implementado de propósito. O painel pede a senha a cada 2 h sem uso; com o gerenciador de senhas do celular, entrar é um toque. Um login automático de longa duração aumenta o estrago de um celular perdido.
- **TRACE**: o Apache responde TRACE antes do `.htaccess`; só a configuração do servidor desliga. Os cookies são HttpOnly e os navegadores não deixam página nenhuma enviar TRACE, então o risco é baixo. Na Hostinger (LiteSpeed) costuma vir desligado.
