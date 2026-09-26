<?php
// Copie este arquivo para config.php (mesma pasta) e preencha com os dados
// do SEU banco. Nunca faça commit do config.php de verdade.

// Fuso horário usado nas páginas (lista: https://www.php.net/manual/pt_BR/timezones.php)
date_default_timezone_set('America/Sao_Paulo');

define('DB_HOST', 'localhost');
define('DB_USER', 'tomada_user');
define('DB_PASS', 'coloque_aqui_a_senha_do_banco');
define('DB_NAME', 'tomada_db');
// Offset do mesmo fuso acima, usado nas consultas SQL (ex.: São Paulo = '-03:00')
define('DB_TIMEZONE', '-03:00');

// Opcional: tarifa de energia em R$/kWh, pra o relatório mostrar o custo estimado.
// Deixe comentado se não quiser essa informação.
// define('TARIFA_KWH', 0.95);
