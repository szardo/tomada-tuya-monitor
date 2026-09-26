-- Schema do banco de dados do monitor de tomada Tuya.
-- Compatível com MySQL 5.7+ e MariaDB 10.3+.
--
-- Uso:
--   mysql -u root -p -e "CREATE DATABASE tomada_db CHARACTER SET utf8mb4;"
--   mysql -u root -p -e "CREATE USER 'tomada_user'@'localhost' IDENTIFIED BY 'sua_senha_aqui';"
--   mysql -u root -p -e "GRANT ALL PRIVILEGES ON tomada_db.* TO 'tomada_user'@'localhost';"
--   mysql -u tomada_user -p tomada_db < schema.sql

CREATE TABLE IF NOT EXISTS consumo_tomada (
  id INT NOT NULL AUTO_INCREMENT,
  data_hora DATETIME DEFAULT CURRENT_TIMESTAMP,
  potencia_w DECIMAL(8,1) DEFAULT NULL,
  corrente_ma INT DEFAULT NULL,
  tensao_v DECIMAL(6,1) DEFAULT NULL,
  energia_acumulada_wh DECIMAL(10,3) DEFAULT NULL,
  ligado TINYINT(1) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_data_hora (data_hora)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
