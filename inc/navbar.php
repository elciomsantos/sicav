<?php
// Garantir que a variável $page está definida
$current_page = $_GET['page'] ?? 'home';
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
    <div class="container">
        <!-- Brand/Logo -->
        <a class="navbar-brand d-flex align-items-center" href="?page=home">
            <img src="<?= htmlspecialchars($GLOBALS['APP_BASE_PATH'] ?? '', ENT_QUOTES, 'UTF-8') ?>/imagens/Logo_sicav.png"
                alt="Sistema de Gerenciamento e Controle de Visitantes" class="navbar-logo">
        </a>

        <!-- Mobile Toggle -->
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Menu Items -->
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link" href="?page=home">
                        <i class="fas fa-home me-1"></i>Home
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="?page=cadastro_visitantes">
                        <i class="fas fa-user-plus me-1"></i>Cadastrar Visitante
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="?page=listar_visitantes">
                        <i class="fas fa-list me-1"></i>Visitantes Ativos
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="?page=relatorios_visitantes">
                        <i class="fas fa-chart-bar me-1"></i>Relatórios
                    </a>
                </li>

                <!-- ⭐⭐ ATUALIZADO: Mostrar Administração apenas para admin ⭐⭐ -->
                <?php if (isset($_SESSION['usuario']) && isset($_SESSION['usuario']->nivel_acesso) && $_SESSION['usuario']->nivel_acesso === 'admin'): ?>
                <li class="nav-item">
                    <a class="nav-link" href="?page=administracao">
                        <i class="fas fa-cog me-1"></i>Administração
                    </a>
                </li>
                <?php endif; ?>
            </ul>

            <!-- User Info -->
            <div class="navbar-nav">
                <?php if (isset($_SESSION['usuario'])): ?>
                <div class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-1"></i>
                        <strong><?= htmlspecialchars($_SESSION['usuario']->usuario ?? '') ?></strong>
                        <?php if (isset($_SESSION['usuario']->senha_temporaria) && (int)$_SESSION['usuario']->senha_temporaria === 1): ?>
                        <span class="badge bg-warning ms-1">Trocar Senha</span>
                        <?php endif; ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <span class="dropdown-item-text text-muted small">
                                <i class="fas fa-user me-1"></i>
                                <?= htmlspecialchars($_SESSION['usuario']->nome ?? $_SESSION['usuario']->usuario) ?>
                            </span>
                        </li>
                        <li>
                            <span class="dropdown-item-text text-muted small">
                                <i class="fas fa-shield-alt me-1"></i>
                                <?= htmlspecialchars($_SESSION['usuario']->nivel_acesso ?? 'user') ?>
                                <?php if ($_SESSION['usuario']->nivel_acesso === 'supervisor'): ?>
                                <span class="badge bg-info ms-1">Supervisor</span>
                                <?php endif; ?>
                            </span>
                        </li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <?php if (isset($_SESSION['usuario']->senha_temporaria) && (int)$_SESSION['usuario']->senha_temporaria === 1): ?>
                        <li>
                            <a class="dropdown-item text-warning" href="?page=trocar_senha">
                                <i class="fas fa-exclamation-triangle me-1"></i>Trocar Senha
                            </a>
                        </li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <?php endif; ?>
                        <li>
                            <a class="dropdown-item" href="?page=logout">
                                <i class="fas fa-sign-out-alt me-1"></i>Sair
                            </a>
                        </li>
                    </ul>
                </div>
                <?php else: ?>
                <a class="nav-link" href="?page=login">
                    <i class="fas fa-sign-in-alt me-1"></i>Login
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<!-- ⭐⭐ CORREÇÃO: Verificação segura de senha_temporaria ⭐⭐ -->
<?php if (isset($_SESSION['usuario']) && isset($_SESSION['usuario']->senha_temporaria) && (int)$_SESSION['usuario']->senha_temporaria === 1): ?>
<div class="alert alert-warning alert-dismissible fade show mb-0 text-center" role="alert">
    <i class="fas fa-exclamation-triangle me-2"></i>
    <strong>Alerta de Segurança:</strong> Você está usando uma senha temporária.
    <a href="?page=trocar_senha" class="alert-link">Clique aqui para definir sua senha permanente</a>.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>