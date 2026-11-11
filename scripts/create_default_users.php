<?php
/**
 * Script para criar usuários padrão do sistema
 * 
 * IMPORTANTE: Este script é para ambiente de desenvolvimento/homologação
 * Em produção, criar usuários manualmente via interface administrativa
 * 
 * Uso:
 *   php scripts/create_default_users.php
 * 
 * Ou via MySQL:
 *   mysql -u vpuser -p visitorpass < scripts/create_default_users.sql
 */

/**
 * Saida de erro para o terminal: este script e CLI-only (guard
 * php_sapi_name() !== 'cli'), o texto vai para stdout e nao para um
 * browser — nao existe sink de HTML/atributo em linha de comando.
 *
 * @psalm-cli-only
 */
function cliFail(string $message): never
{
    echo "\n❌ ERRO: {$message}\n\n";
    exit(1);
}

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Este script deve ser executado via linha de comando');
}

// ⚠️ AVISO DE SEGURANÇA ⚠️
// As senhas abaixo são TEMPORÁRIAS e estão versionadas no repositório.
// NUNCA utilize senhas reais neste arquivo.
// Em produção: crie usuários manualmente via interface administrativa.
// Altere as senhas imediatamente após o primeiro login.

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../inc/validators.php';

const DEFAULT_USERS = [
    [
        'name' => 'Administrador Sistema',
        'username' => 'admin',
        'password' => 'Admin@2026Temp!',
        'nivel_acesso' => 'admin',
        'senha_temporaria' => 1,
    ],
    [
        'name' => 'Supervisor Sistema',
        'username' => 'supervisor',
        'password' => 'Supervisor@2026Temp!',
        'nivel_acesso' => 'supervisor',
        'senha_temporaria' => 1,
    ],
];

echo "\n=== Criador de Usuários Padrão - Sistema de Visitantes ===\n\n";

try {
    $db = new database();

    foreach (DEFAULT_USERS as $user) {
        echo "Verificando usuário: {$user['username']} ...";

        // Verificar se usuário já existe
        $result = $db->select(
            "SELECT id FROM usuarios WHERE usuario = ?",
            [$user['username']]
        );

        if ($result['status'] === 'success' && !empty($result['data'])) {
            echo " [JÁ EXISTE - PULANDO]\n";
            continue;
        }

        // Hash da senha
        $senhaHash = password_hash($user['password'], PASSWORD_ARGON2ID);

        // Inserir usuário
        $insertResult = $db->insert(
            "INSERT INTO usuarios (nome, usuario, senha, nivel_acesso, ativo, senha_temporaria) 
             VALUES (?, ?, ?, ?, 1, ?)",
            [
                $user['name'],
                $user['username'],
                $senhaHash,
                $user['nivel_acesso'],
                $user['senha_temporaria']
            ]
        );

        if ($insertResult['status'] === 'success') {
            echo " [CRIADO]\n";
            echo "  ├─ Nível: {$user['nivel_acesso']}\n";
            echo "  ├─ Senha temporária: SIM (mudar no primeiro login)\n";
            echo "  └─ Credenciais: {$user['username']} / {$user['password']}\n";
        } else {
            echo " [ERRO]\n";
            throw new Exception("Falha ao criar usuário {$user['username']}");
        }
    }

    echo "\n✅ Usuários padrão criados com sucesso!\n";
    echo "\n⚠️  IMPORTANTE:\n";
    echo "  1. Altere as senhas temporárias no primeiro login\n";
    echo "  2. Ative autenticação HTTPS em produção\n";
    echo "  3. Remova este script após usar em produção\n";
    echo "  4. Nunca mantenha senhas padrão em produção\n\n";

} catch (Exception $e) {
    cliFail($e->getMessage());
}
