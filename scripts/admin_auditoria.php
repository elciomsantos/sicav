<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = new database();

// Filtros
$filtro_data_inicio = $_GET['data_inicio'] ?? date('Y-m-d', strtotime('-30 days'));
$filtro_data_fim = $_GET['data_fim'] ?? date('Y-m-d');
$filtro_usuario = $_GET['usuario_id'] ?? '';
$filtro_acao = $_GET['acao'] ?? '';
$filtro_visitante = $_GET['visitante_id'] ?? '';

// Buscar lista de usuários para o filtro
$usuarios = $db->select("SELECT id, nome FROM usuarios ORDER BY nome");

// Construir query com filtros
$sql = "
    SELECT 
        a.*,
        v.nome_completo as visitante_nome,
        v.numero_cpf as visitante_cpf,
        u.nome as usuario_nome,
        u.usuario as usuario_login
    FROM auditoria_visitantes a
    LEFT JOIN visitorinfo v ON a.registro_id = v.id
    LEFT JOIN usuarios u ON a.usuario_id = u.id
    WHERE DATE(a.data_acao) BETWEEN :data_inicio AND :data_fim
";

$params = [
    ':data_inicio' => $filtro_data_inicio,
    ':data_fim' => $filtro_data_fim
];

if (!empty($filtro_usuario)) {
    $sql .= " AND a.usuario_id = :usuario_id";
    $params[':usuario_id'] = $filtro_usuario;
}

if (!empty($filtro_acao)) {
    $sql .= " AND a.acao = :acao";
    $params[':acao'] = $filtro_acao;
}

if (!empty($filtro_visitante)) {
    $sql .= " AND a.registro_id = :visitante_id";
    $params[':visitante_id'] = $filtro_visitante;
}

$sql .= " ORDER BY a.data_acao DESC LIMIT 1000";

$result = $db->select($sql, $params);
$auditorias = $result['status'] === 'success' ? $result['data'] : [];

// Estatísticas
$stats = $db->select("
    SELECT 
        acao,
        COUNT(*) as total,
        DATE(data_acao) as data,
        COUNT(DISTINCT usuario_id) as usuarios_distintos
    FROM auditoria_visitantes
    WHERE data_acao >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY acao, DATE(data_acao)
    ORDER BY data DESC, acao
");

require_once __DIR__ . '/../inc/navbar.php';
?>
<style nonce="<?= htmlspecialchars($GLOBALS['CSP_NONCE'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
 
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: #f0f2f5;
        padding: 20px;
    }

    .container {
        max-width: 1400px;
        margin: 0 auto;
    }

    .header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 30px;
        border-radius: 15px;
        margin-bottom: 30px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
    }

    .header-top {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 16px;
    }

    .header h1 {
        font-size: 2.5em;
        margin-bottom: 10px;
    }

    .header p {
        opacity: 0.9;
        font-size: 1.1em;
    }

    .btn-voltar-admin {
        display: inline-block;
        padding: 10px 14px;
        border-radius: 8px;
        text-decoration: none;
        color: #fff;
        background: rgba(255, 255, 255, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.35);
        font-weight: 600;
        white-space: nowrap;
    }

    .btn-voltar-admin:hover {
        background: rgba(255, 255, 255, 0.3);
        color: #fff;
    }

    .filtros {
        background: white;
        padding: 25px;
        border-radius: 15px;
        margin-bottom: 30px;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    }

    .filtros h3 {
        color: #333;
        margin-bottom: 20px;
        font-size: 1.3em;
        border-bottom: 2px solid #f0f2f5;
        padding-bottom: 10px;
    }

    .filtros-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-bottom: 20px;
    }

    .filtro-group {
        display: flex;
        flex-direction: column;
    }

    .filtro-group label {
        font-weight: 600;
        margin-bottom: 5px;
        color: #555;
        font-size: 0.9em;
    }

    .filtro-group input,
    .filtro-group select {
        padding: 10px;
        border: 2px solid #e0e0e0;
        border-radius: 8px;
        font-size: 1em;
        transition: all 0.3s;
    }

    .filtro-group input:focus,
    .filtro-group select:focus {
        border-color: #667eea;
        outline: none;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }

    .btn-filtrar {
        background: #667eea;
        color: white;
        border: none;
        padding: 12px 30px;
        border-radius: 8px;
        font-size: 1em;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        width: 100%;
    }

    .btn-filtrar:hover {
        background: #5a67d8;
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
    }

    .stats-container {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .stat-card {
        background: white;
        padding: 20px;
        border-radius: 15px;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        text-align: center;
    }

    .stat-card h4 {
        color: #666;
        font-size: 0.9em;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 10px;
    }

    .stat-number {
        font-size: 2.5em;
        font-weight: bold;
        color: #333;
    }

    .stat-label {
        color: #999;
        font-size: 0.9em;
        margin-top: 5px;
    }

    .tabela-auditoria {
        background: white;
        border-radius: 15px;
        overflow: hidden;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    th {
        background: #f8f9fa;
        color: #333;
        font-weight: 600;
        padding: 15px;
        text-align: left;
        font-size: 0.9em;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    td {
        padding: 15px;
        border-bottom: 1px solid #f0f2f5;
        color: #555;
    }

    tr:hover {
        background: #f8f9fa;
    }

    .badge {
        display: inline-block;
        padding: 5px 10px;
        border-radius: 20px;
        font-size: 0.8em;
        font-weight: 600;
    }

    .badge-insert {
        background: #d4edda;
        color: #155724;
    }

    .badge-update {
        background: #fff3cd;
        color: #856404;
    }

    .badge-delete {
        background: #f8d7da;
        color: #721c24;
    }

    .dados-json {
        font-size: 0.8em;
        background: #f8f9fa;
        padding: 5px;
        border-radius: 4px;
        max-width: 300px;
        overflow-x: auto;
        white-space: nowrap;
    }

    .pagination {
        display: flex;
        justify-content: center;
        gap: 10px;
        margin-top: 20px;
        padding: 20px;
    }

    .page-btn {
        padding: 8px 15px;
        border: 1px solid #dee2e6;
        background: white;
        color: #667eea;
        cursor: pointer;
        border-radius: 5px;
        transition: all 0.3s;
    }

    .page-btn:hover {
        background: #667eea;
        color: white;
        border-color: #667eea;
    }

    .export-btn {
        background: #28a745;
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 600;
        margin-left: 10px;
    }

    .export-btn:hover {
        background: #218838;
    }

    .auditoria-empty-row {
        text-align: center;
        padding: 50px;
        color: #999;
    }

    .text-muted-dash {
        color: #999;
    }

    .btn-detalhes-auditoria {
        padding: 5px 10px;
        background: #667eea;
        color: white;
        border: none;
        border-radius: 5px;
        cursor: pointer;
    }

    .auditoria-export-actions {
        margin-top: 20px;
        text-align: right;
    }

    .export-btn-json {
        background: #17a2b8;
    }

    .auditoria-modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 1000;
    }

    .auditoria-modal-content {
        background: white;
        max-width: 600px;
        margin: 50px auto;
        padding: 30px;
        border-radius: 15px;
        position: relative;
    }

    .auditoria-modal-close {
        position: absolute;
        top: 10px;
        right: 10px;
        background: none;
        border: none;
        font-size: 20px;
        cursor: pointer;
    }

    .auditoria-modal-title {
        margin-bottom: 20px;
    }

    .auditoria-modal-pre {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 8px;
        overflow-x: auto;
    }
    </style>
    <div class="container">
        <div class="header">
            <div class="header-top">
                <div>
                    <h1>📊 Relatório de Auditoria</h1>
                    <p>Sistema de rastreamento de ações dos visitantes</p>
                </div>
                <a href="?page=administracao" class="btn-voltar-admin">← Voltar para Administração</a>
            </div>
        </div>

        <!-- Filtros -->
        <div class="filtros">
            <h3>🔍 Filtrar Registros</h3>
            <form method="GET" action="">
                <div class="filtros-grid">
                    <div class="filtro-group">
                        <label for="data_inicio">Data Início</label>
                        <input type="date" id="data_inicio" name="data_inicio" value="<?= htmlspecialchars($filtro_data_inicio, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="filtro-group">
                        <label for="data_fim">Data Fim</label>
                        <input type="date" id="data_fim" name="data_fim" value="<?= htmlspecialchars($filtro_data_fim, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="filtro-group">
                        <label for="usuario_id">Usuário</label>
                        <select id="usuario_id" name="usuario_id">
                            <option value="">Todos</option>
                            <?php if ($usuarios['status'] === 'success'): ?>
                            <?php foreach ($usuarios['data'] as $user): ?>
                            <option value="<?= $user->id ?>" <?= $filtro_usuario == $user->id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($user->nome) ?>
                            </option>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <label for="acao">Ação</label>
                        <select id="acao" name="acao">
                            <option value="">Todas</option>
                            <option value="INSERT" <?= $filtro_acao == 'INSERT' ? 'selected' : '' ?>>INSERT</option>
                            <option value="UPDATE" <?= $filtro_acao == 'UPDATE' ? 'selected' : '' ?>>UPDATE</option>
                            <option value="DELETE" <?= $filtro_acao == 'DELETE' ? 'selected' : '' ?>>DELETE</option>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <label for="visitante_id">ID Visitante</label>
                        <input type="number" id="visitante_id" name="visitante_id" placeholder="ID do visitante"
                            value="<?= htmlspecialchars((string) $filtro_visitante, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>
                <button type="submit" class="btn-filtrar">Aplicar Filtros</button>
            </form>
        </div>

        <!-- Estatísticas Rápidas -->
        <div class="stats-container">
            <?php
            $total_registros = count($auditorias);
            $usuarios_unicos = count(array_unique(array_column(array_map(function($item) {
                return (array)$item;
            }, $auditorias), 'usuario_id')));
            
            $acoes = array_count_values(array_column(array_map(function($item) {
                return (array)$item;
            }, $auditorias), 'acao'));
            ?>

            <div class="stat-card">
                <h4>Total de Registros</h4>
                <div class="stat-number"><?= $total_registros ?></div>
                <div class="stat-label">no período filtrado</div>
            </div>

            <div class="stat-card">
                <h4>Usuários Ativos</h4>
                <div class="stat-number"><?= $usuarios_unicos ?></div>
                <div class="stat-label">realizaram ações</div>
            </div>

            <div class="stat-card">
                <h4>INSERTS</h4>
                <div class="stat-number"><?= $acoes['INSERT'] ?? 0 ?></div>
                <div class="stat-label">novos cadastros</div>
            </div>

            <div class="stat-card">
                <h4>UPDATES</h4>
                <div class="stat-number"><?= $acoes['UPDATE'] ?? 0 ?></div>
                <div class="stat-label">alterações</div>
            </div>
        </div>

        <!-- Tabela de Auditoria -->
        <div class="tabela-auditoria">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Data/Hora</th>
                        <th>Usuário</th>
                        <th>Visitante</th>
                        <th>Ação</th>
                        <th>Dados Antigos</th>
                        <th>Dados Novos</th>
                        <th>Detalhes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($auditorias)): ?>
                    <tr>
                        <td colspan="8" class="auditoria-empty-row">
                            Nenhum registro de auditoria encontrado
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($auditorias as $aud): ?>
                    <tr>
                        <td>#<?= $aud->id ?></td>
                        <td><?= date('d/m/Y H:i:s', strtotime($aud->data_acao)) ?></td>
                        <td>
                            <strong><?= htmlspecialchars($aud->usuario_nome ?? 'Sistema') ?></strong>
                            <br>
                            <small><?= htmlspecialchars($aud->usuario_login ?? '') ?></small>
                        </td>
                        <td>
                            <strong><?= htmlspecialchars($aud->visitante_nome ?? 'N/A') ?></strong>
                            <br>
                            <small>CPF: <?= htmlspecialchars($aud->visitante_cpf ?? '') ?></small>
                            <br>
                            <small>ID: <?= $aud->registro_id ?></small>
                        </td>
                        <td>
                            <?php
                                    $badge_class = '';
                                    switch ($aud->acao) {
                                        case 'INSERT':
                                            $badge_class = 'badge-insert';
                                            break;
                                        case 'UPDATE':
                                            $badge_class = 'badge-update';
                                            break;
                                        case 'DELETE':
                                            $badge_class = 'badge-delete';
                                            break;
                                    }
                                    ?>
                            <span class="badge <?= $badge_class ?>"><?= $aud->acao ?></span>
                        </td>
                        <td>
                            <?php if ($aud->dados_antigos): ?>
                            <div class="dados-json"
                                title="<?= htmlspecialchars(json_encode(json_decode($aud->dados_antigos), JSON_PRETTY_PRINT)) ?>">
                                <?= htmlspecialchars($aud->dados_antigos) ?>
                            </div>
                            <?php else: ?>
                            <span class="text-muted-dash">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($aud->dados_novos): ?>
                            <div class="dados-json"
                                title="<?= htmlspecialchars(json_encode(json_decode($aud->dados_novos), JSON_PRETTY_PRINT)) ?>">
                                <?= htmlspecialchars($aud->dados_novos) ?>
                            </div>
                            <?php else: ?>
                            <span class="text-muted-dash">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="btn-ver-detalhes btn-detalhes-auditoria" data-id="<?= (int) $aud->id ?>">
                                Ver
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Botões de Exportação -->
        <div class="auditoria-export-actions">
            <button class="export-btn" id="btnExportarCsvAuditoria">
                📥 Exportar CSV
            </button>
            <button class="export-btn export-btn-json" id="btnExportarJsonAuditoria">
                📄 Exportar JSON
            </button>
        </div>
    </div>

    <!-- Modal para detalhes -->
    <div id="modal" class="auditoria-modal-overlay">
        <div class="auditoria-modal-content">
            <button id="btnFecharDetalhesAuditoria"
                class="auditoria-modal-close">✕</button>
            <h3 class="auditoria-modal-title">📋 Detalhes Completos</h3>
            <pre id="detalhesConteudo" class="auditoria-modal-pre"></pre>
        </div>
    </div>

    <script nonce="<?= htmlspecialchars($GLOBALS['CSP_NONCE'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
    function verDetalhes(id) {
        fetch(`${BASE_URL}index.php?page=api/get_auditoria_detalhe&id=${id}`)
            .then(response => response.json())
            .then(data => {
                document.getElementById('detalhesConteudo').textContent = JSON.stringify(data, null, 2);
                document.getElementById('modal').style.display = 'block';
            });
    }

    function fecharModal() {
        document.getElementById('modal').style.display = 'none';
    }

    function exportarCSV() {
        window.location.href = `${BASE_URL}index.php?page=api/exportar_auditoria&formato=csv&<?= htmlspecialchars($_SERVER['QUERY_STRING'] ?? '', ENT_QUOTES, 'UTF-8') ?>`;
    }

    function exportarJSON() {
        window.location.href = `${BASE_URL}index.php?page=api/exportar_auditoria&formato=json&<?= htmlspecialchars($_SERVER['QUERY_STRING'] ?? '', ENT_QUOTES, 'UTF-8') ?>`;
    }

    document.querySelectorAll('.btn-ver-detalhes').forEach((btn) => {
        btn.addEventListener('click', () => {
            const id = btn.getAttribute('data-id');
            verDetalhes(id);
        });
    });
    document.getElementById('btnExportarCsvAuditoria')?.addEventListener('click', exportarCSV);
    document.getElementById('btnExportarJsonAuditoria')?.addEventListener('click', exportarJSON);
    document.getElementById('btnFecharDetalhesAuditoria')?.addEventListener('click', fecharModal);

    // Fechar modal com ESC
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            fecharModal();
        }
    });
    </script>
