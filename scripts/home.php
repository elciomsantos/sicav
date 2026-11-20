<?php
require_once __DIR__ . '/../inc/navbar.php';
?>

<div class="container mt-3">
    <div class="row g-3">
        <div class="col-md-3">
            <div class="card text-center h-100">
                <div class="card-body">
                    <h5 class="card-title">Cadastrar Visitante</h5>
                    <p class="card-text">Registrar Visitante</p>
                    <a href="?page=cadastro_visitantes" class="btn btn-primary">Acessar</a>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center h-100">
                <div class="card-body">
                    <h5 class="card-title">Visitantes Ativos</h5>
                    <p class="card-text">Ver visitas em andamento</p>
                    <a href="?page=listar_visitantes" class="btn btn-info">Visualizar</a>
                </div>
            </div>
        </div>
        <?php if ($_SESSION['usuario']->nivel_acesso === 'admin' || $_SESSION['usuario']->nivel_acesso === 'supervisor'): ?>
        <div class="col-md-3">
            <div class="card text-center h-100">
                <div class="card-body">
                    <h5 class="card-title">Relatórios</h5>
                    <p class="card-text">Relatórios e estatísticas</p>
                    <a href="?page=relatorios_visitantes" class="btn btn-success">Gerar</a>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($_SESSION['usuario']->nivel_acesso === 'admin'): ?>
        <div class="col-md-3">
            <div class="card text-center h-100">
                <div class="card-body">
                    <h5 class="card-title">Administração</h5>
                    <p class="card-text">Gerenciar usuários</p>
                    <a href="?page=administracao" class="btn btn-warning">Administrar</a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="text-center mt-4">
        <img src="<?= htmlspecialchars($GLOBALS['APP_BASE_PATH'] ?? '', ENT_QUOTES, 'UTF-8') ?>/imagens/corporativo.png"
            alt="Sistema de Gerenciamento e Controle de Visitantes" class="img-fluid home-corporativo">
    </div>
</div>
