<footer class="footer mt-auto py-2">
    <div class="container d-flex flex-wrap align-items-center justify-content-center gap-2">
        <img src="<?= htmlspecialchars($GLOBALS['APP_BASE_PATH'] ?? '', ENT_QUOTES, 'UTF-8') ?>/imagens/Logo_sicav.png"
            alt="Sistema de Gerenciamento e Controle de Visitantes" class="footer-logo">
        <span class="text-muted small">
            Sistema de Gerenciamento e Controle de Visitantes &copy; <?php echo date('Y'); ?> -
            <span class="developer-name">Beta +</span>
            <span class="badge bg-secondary ms-1" title="Versão do sistema">
                <?= htmlspecialchars(defined('APP_VERSION') ? APP_VERSION : 'v1.0.0', ENT_QUOTES, 'UTF-8') ?>
            </span>
        </span>
    </div>
</footer>

<?php if (isset($_SESSION['usuario'])): ?>
<!-- Modal de sessão -->
<div class=" modal fade" id="sessionModal" tabindex="-1" aria-labelledby="sessionModalLabel" aria-hidden="true"
    data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title" id="sessionModalLabel">Atenção!</h5>
            </div>
            <div class="modal-body">
                <p>Sua sessão vai expirar em <strong><span id="sessionCountdown" aria-live="polite"></span>
                        segundos</strong>.</p>
                <p class="mb-0">Deseja continuar a sessão?</p>
            </div>
            <div class="modal-footer">
                <button type="button" id="logoutBtn" class="btn btn-secondary">Sair</button>
                <button type="button" id="continueBtn" class="btn btn-primary">Continuar Sessão</button>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= htmlspecialchars($GLOBALS['CSP_NONCE'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
let timeLeft = <?= 
        isset($_SESSION['ultimo_acesso']) 
            ? max(0, SESSION_TIMEOUT - (time() - $_SESSION['ultimo_acesso'])) 
            : 0 
    ?>;

const tempoMaximo = <?= SESSION_TIMEOUT ?>;
const alertTime = 60;

document.addEventListener('DOMContentLoaded', function() {
    function getLogoutUrl() {
        const base = BASE_URL.endsWith('/') ? BASE_URL : `${BASE_URL}/`;
        return `${base}index.php?page=logout&timeout=1`;
    }

    function getRenewSessionUrl() {
        const base = BASE_URL.endsWith('/') ? BASE_URL : `${BASE_URL}/`;
        return `${base}index.php?page=api/renovar_sessao`;
    }

    if (timeLeft <= 0) {
        window.location.href = getLogoutUrl();
        return;
    }
    if (timeLeft > tempoMaximo) return;

    let modalShown = false;
    let mainTimer = null;
    let modalTimer = null;
    let renewInFlight = false;
    let lastRenewAt = 0;
    let lastActivityPingAt = 0;

    const modalEl = document.getElementById('sessionModal');
    const sessionModal = new bootstrap.Modal(modalEl, {
        backdrop: 'static',
        keyboard: false
    });
    const countdownEl = document.getElementById('sessionCountdown');
    const continueBtn = document.getElementById('continueBtn');
    const logoutBtn = document.getElementById('logoutBtn');

    function clearAllTimers() {
        if (mainTimer) {
            clearInterval(mainTimer);
            mainTimer = null;
        }
        if (modalTimer) {
            clearInterval(modalTimer);
            modalTimer = null;
        }
    }

    function updateCountdownDisplay() {
        if (countdownEl) countdownEl.textContent = timeLeft;
    }

    function endSession() {
        clearAllTimers();
        window.location.href = getLogoutUrl();
    }

    function resetCountdown() {
        clearAllTimers();
        timeLeft = tempoMaximo;
        modalShown = false;
        startMainTimer();
    }

    function requestSessionRenew(options = {}) {
        const {
            force = false,
                logoutOnError = true
        } = options;

        const now = Date.now();
        if (!force && (now - lastRenewAt) < 30000) {
            return Promise.resolve(false);
        }
        if (renewInFlight) {
            return Promise.resolve(false);
        }

        renewInFlight = true;

        return fetch(getRenewSessionUrl(), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': CSRF_TOKEN
                }
            })
            .then(async response => {
                if (!response.ok) {
                    const errorText = await response.text();
                    throw new Error(`HTTP ${response.status}: ${errorText}`);
                }
                return response.json();
            })
            .then(data => {
                if (!data.success) {
                    throw new Error(data.message || 'Falha ao renovar sessão');
                }

                lastRenewAt = Date.now();
                timeLeft = tempoMaximo;

                if (modalShown) {
                    sessionModal.hide();
                    modalShown = false;
                }

                updateCountdownDisplay();
                return true;
            })
            .catch(error => {
                console.error('Erro ao renovar sessão:', error);
                if (logoutOnError) endSession();
                return false;
            })
            .finally(() => {
                renewInFlight = false;
            });
    }

    function showSessionModal() {
        if (modalShown) return;
        modalShown = true;
        updateCountdownDisplay();
        sessionModal.show();

        modalTimer = setInterval(() => {
            updateCountdownDisplay();
            if (timeLeft <= 0) endSession();
        }, 1000);
    }

    function startMainTimer() {
        mainTimer = setInterval(() => {
            timeLeft--;
            if (timeLeft === alertTime && !modalShown) showSessionModal();
            if (timeLeft <= 0) endSession();
        }, 1000);
    }

    function handleUserActivity() {
        const now = Date.now();
        if (now - lastActivityPingAt < 5000) return;
        lastActivityPingAt = now;

        // Mantém o contador local vivo durante digitação/interação para evitar
        // alerta visual indevido enquanto o ping de renovação é processado.
        timeLeft = tempoMaximo;
        updateCountdownDisplay();

        if (modalShown) {
            sessionModal.hide();
            modalShown = false;
        }

        // Se houver atividade do usuário, renova de forma silenciosa periodicamente
        // para evitar abertura do modal enquanto ele está preenchendo formulários.
        requestSessionRenew({
            force: false,
            logoutOnError: false
        });
    }

    // Continuar sessão
    continueBtn.addEventListener('click', function() {
        continueBtn.disabled = true;
        requestSessionRenew({
                force: true,
                logoutOnError: true
            })
            .then(success => {
                if (success) {
                    resetCountdown();
                }
            })
            .finally(() => {
                setTimeout(() => {
                    continueBtn.disabled = false;
                }, 1000);
            });
    });

    // Logout
    logoutBtn.addEventListener('click', () => endSession());

    modalEl.addEventListener('hidden.bs.modal', function() {
        if (modalTimer) {
            clearInterval(modalTimer);
            modalTimer = null;
        }
        modalShown = false;
    });

    const activityEvents = ['keydown', 'input', 'click', 'mousedown', 'touchstart', 'scroll'];
    activityEvents.forEach(eventName => {
        document.addEventListener(eventName, handleUserActivity);
    });

    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            handleUserActivity();
        }
    });

    // Primeira marcação de atividade para evitar warning precoce após carregar página.
    handleUserActivity();

    startMainTimer();

    // Sincronização periódica
    setInterval(() => {
        fetch(BASE_URL + 'index.php?page=api/session_check', {
                credentials: 'same-origin'
            })
            .then(res => {
                if (res.status === 401) endSession();
                return res.json();
            })
            .then(data => {
                if (data.status === 'expired') endSession();
                else if (data.time_left) {
                    const activeRecently = (Date.now() - lastActivityPingAt) < 15000;
                    if (!activeRecently && Math.abs(data.time_left - timeLeft) > 5) {
                        timeLeft = data.time_left;
                    }
                }
            })
            .catch(() => {});
    }, 30000);
});
</script>
<?php endif; ?>