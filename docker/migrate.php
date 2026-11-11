<?php
/**
 * Database migration script for Docker deployment.
 * Creates tables if they don't exist, without dropping anything.
 */

$host = getenv('VISITORPASS_DB_HOST') ?: 'db';
$port = getenv('VISITORPASS_DB_PORT') ?: '3306';
$dbname = getenv('VISITORPASS_DB_NAME') ?: 'visitorpass';
$user = getenv('VISITORPASS_DB_USER') ?: 'vpuser';
$pass = getenv('VISITORPASS_DB_PASS') ?: 'visitorpassword';

try {
    $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    // Alinha o timezone da sessao ao do PHP (mesmo comportamento de src/Database.php)
    $pdo->exec("SET time_zone = " . $pdo->quote(date('P')));

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbname}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $pdo->exec("USE `{$dbname}`");

    echo "Creating tables...\n";

    // Tabela usuarios
    $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(100) NOT NULL,
        usuario VARCHAR(50) UNIQUE NOT NULL,
        senha VARCHAR(255) NOT NULL,
        nivel_acesso ENUM('user', 'supervisor', 'admin') NOT NULL DEFAULT 'user',
        ativo TINYINT(1) DEFAULT 1,
        senha_temporaria TINYINT(1) DEFAULT 1,
        data TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Tabela visitorinfo
    $pdo->exec("CREATE TABLE IF NOT EXISTS visitorinfo (
        id BIGINT(12) NOT NULL AUTO_INCREMENT,
        nome_completo VARCHAR(255) NOT NULL,
        numero_cpf CHAR(11) NOT NULL,
        veiculo_placa CHAR(7) NULL,
        encontrar_com VARCHAR(255) NOT NULL,
        empresa VARCHAR(255) DEFAULT NULL,
        tipo_visita VARCHAR(50) NOT NULL,
        telefone VARCHAR(20) DEFAULT NULL,
        email VARCHAR(255) DEFAULT NULL,
        foto_caminho VARCHAR(500) DEFAULT NULL,
        data_entrada DATETIME NOT NULL,
        data_saida DATETIME DEFAULT NULL,
        status TINYINT(1) DEFAULT 1,
        cadastro_original_id BIGINT(12) NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_data_entrada (data_entrada),
        KEY idx_status (status),
        KEY idx_visitor_cpf (numero_cpf),
        KEY idx_visitor_placa (veiculo_placa),
        KEY idx_visitorinfo_cadastro_original (cadastro_original_id),
        KEY idx_visitorinfo_cpf_status (numero_cpf, status),
        KEY idx_status_data (status, data_entrada)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Tabela login_attempts
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario VARCHAR(100) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        attempt_count INT NOT NULL DEFAULT 0,
        lock_until DATETIME NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_usuario_ip (usuario, ip_address),
        INDEX idx_lock_until (lock_until),
        INDEX idx_updated_at (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Tabela user_sessions
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL UNIQUE,
        session_token_hash CHAR(64) NOT NULL,
        session_id VARCHAR(128) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        user_agent VARCHAR(255) NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NULL,
        last_activity_at DATETIME NULL,
        revoked_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_user_sessions_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
        INDEX idx_user_sessions_active (active),
        INDEX idx_user_sessions_expires (expires_at),
        INDEX idx_user_sessions_updated_at (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Migracoes incrementais idempotentes (tabelas existentes de versoes anteriores)
    $userSessionColumns = $pdo->query("SHOW COLUMNS FROM user_sessions")->fetchAll(PDO::FETCH_COLUMN);
    $userSessionColumnMap = array_flip($userSessionColumns);
    if (!isset($userSessionColumnMap['expires_at'])) {
        $pdo->exec("ALTER TABLE user_sessions ADD COLUMN expires_at DATETIME NULL AFTER last_seen");
    }
    if (!isset($userSessionColumnMap['last_activity_at'])) {
        $pdo->exec("ALTER TABLE user_sessions ADD COLUMN last_activity_at DATETIME NULL AFTER expires_at");
    }
    if (!isset($userSessionColumnMap['revoked_at'])) {
        $pdo->exec("ALTER TABLE user_sessions ADD COLUMN revoked_at DATETIME NULL AFTER last_activity_at");
    }

    // Tabela rate_limits (rate limiting generico por IP + rota + janela)
    $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rate_key VARCHAR(191) NOT NULL,
        request_count INT NOT NULL DEFAULT 0,
        window_started_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_rate_key (rate_key),
        INDEX idx_rate_limits_window (window_started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Tabela auditoria_visitantes
    $pdo->exec("CREATE TABLE IF NOT EXISTS auditoria_visitantes (
        id BIGINT(12) AUTO_INCREMENT PRIMARY KEY,
        tabela VARCHAR(50) NOT NULL,
        registro_id BIGINT(12) NOT NULL,
        acao ENUM('INSERT', 'UPDATE', 'DELETE') NOT NULL,
        dados_antigos JSON,
        dados_novos JSON,
        usuario_id INT,
        data_acao DATETIME NOT NULL,
        INDEX idx_registro (tabela, registro_id),
        INDEX idx_usuario (usuario_id),
        INDEX idx_data (data_acao)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Triggers (only if not exist — drop first to recreate safely)
    $pdo->exec("DROP TRIGGER IF EXISTS visitorinfo_after_insert");
    $pdo->exec("DROP TRIGGER IF EXISTS visitorinfo_after_update");
    $pdo->exec("DROP TRIGGER IF EXISTS visitorinfo_after_delete");
    $pdo->exec("DROP TRIGGER IF EXISTS before_insert_visitorinfo");
    $pdo->exec("DROP TRIGGER IF EXISTS before_update_visitorinfo");

    $pdo->exec("
        CREATE TRIGGER visitorinfo_after_insert
        AFTER INSERT ON visitorinfo
        FOR EACH ROW
        BEGIN
            INSERT INTO auditoria_visitantes (tabela, registro_id, acao, dados_novos, usuario_id, data_acao)
            VALUES ('visitorinfo', NEW.id, 'INSERT',
                JSON_OBJECT('nome_completo', NEW.nome_completo, 'numero_cpf', NEW.numero_cpf, 'status', NEW.status),
                @usuario_id, NOW());
        END");

    $pdo->exec("
        CREATE TRIGGER visitorinfo_after_update
        AFTER UPDATE ON visitorinfo
        FOR EACH ROW
        BEGIN
            INSERT INTO auditoria_visitantes (tabela, registro_id, acao, dados_antigos, dados_novos, usuario_id, data_acao)
            VALUES ('visitorinfo', NEW.id, 'UPDATE',
                JSON_OBJECT('status', OLD.status, 'data_saida', OLD.data_saida),
                JSON_OBJECT('status', NEW.status, 'data_saida', NEW.data_saida),
                @usuario_id, NOW());
        END");

    $pdo->exec("
        CREATE TRIGGER visitorinfo_after_delete
        AFTER DELETE ON visitorinfo
        FOR EACH ROW
        BEGIN
            INSERT INTO auditoria_visitantes (tabela, registro_id, acao, dados_antigos, usuario_id, data_acao)
            VALUES ('visitorinfo', OLD.id, 'DELETE',
                JSON_OBJECT('nome_completo', OLD.nome_completo, 'numero_cpf', OLD.numero_cpf, 'status', OLD.status),
                @usuario_id, NOW());
        END");

    $pdo->exec("
        CREATE TRIGGER before_insert_visitorinfo
        BEFORE INSERT ON visitorinfo
        FOR EACH ROW
        BEGIN
            SET NEW.numero_cpf = REPLACE(REPLACE(NEW.numero_cpf, '.', ''), '-', '');
            IF NEW.veiculo_placa IS NOT NULL AND NEW.veiculo_placa != '' THEN
                SET NEW.veiculo_placa = UPPER(REPLACE(NEW.veiculo_placa, '-', ''));
            END IF;
            IF NOT (NEW.numero_cpf REGEXP '^[0-9]{11}$') THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CPF invalido: deve conter 11 digitos numericos.';
            END IF;
            IF NEW.veiculo_placa IS NOT NULL AND NEW.veiculo_placa != '' THEN
                IF NOT (NEW.veiculo_placa REGEXP '^[A-Z]{3}[0-9]{4}$' OR NEW.veiculo_placa REGEXP '^[A-Z]{3}[0-9][A-Z][0-9]{2}$') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Placa invalida: formato incorreto. Use AAA9999 ou AAA9A99.';
                END IF;
            END IF;
        END");

    $pdo->exec("
        CREATE TRIGGER before_update_visitorinfo
        BEFORE UPDATE ON visitorinfo
        FOR EACH ROW
        BEGIN
            SET NEW.numero_cpf = REPLACE(REPLACE(NEW.numero_cpf, '.', ''), '-', '');
            IF NEW.veiculo_placa IS NOT NULL AND NEW.veiculo_placa != '' THEN
                SET NEW.veiculo_placa = UPPER(REPLACE(NEW.veiculo_placa, '-', ''));
            END IF;
            IF NOT (NEW.numero_cpf REGEXP '^[0-9]{11}$') THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CPF invalido: deve conter 11 digitos numericos.';
            END IF;
            IF NEW.veiculo_placa IS NOT NULL AND NEW.veiculo_placa != '' THEN
                IF NOT (NEW.veiculo_placa REGEXP '^[A-Z]{3}[0-9]{4}$' OR NEW.veiculo_placa REGEXP '^[A-Z]{3}[0-9][A-Z][0-9]{2}$') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Placa invalida: formato incorreto. Use AAA9999 ou AAA9A99.';
                END IF;
            END IF;
        END");

    // Criar usuario admin padrao se nao existir.
    // Em producao cria somente o admin; o supervisor (e demais usuarios)
    // deve ser criado depois pelo admin na interface administrativa.
    $isProduction = (getenv('VISITORPASS_ENV') ?: 'production') === 'production';
    $check = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE usuario = 'admin'")->fetchColumn();
    if ($check == 0) {
        $hash = password_hash('Admin@2026Temp!', PASSWORD_ARGON2ID);
        $pdo->prepare("INSERT INTO usuarios (nome, usuario, senha, nivel_acesso, senha_temporaria)
            VALUES ('Administrador Sistema', 'admin', ?, 'admin', 1)")->execute([$hash]);

        if (!$isProduction) {
            $hash = password_hash('Supervisor@2026Temp!', PASSWORD_ARGON2ID);
            $pdo->prepare("INSERT INTO usuarios (nome, usuario, senha, nivel_acesso, senha_temporaria)
                VALUES ('Supervisor Sistema', 'supervisor', ?, 'supervisor', 1)")->execute([$hash]);
        }

        echo $isProduction
            ? "Default admin user created. CHANGE PASSWORD!\n"
            : "Default users created (admin/supervisor). CHANGE PASSWORDS!\n";
    }

    echo "Migration completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
    exit(1);
}
