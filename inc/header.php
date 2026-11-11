<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="<?= htmlspecialchars($GLOBALS['APP_BASE_PATH'] ?? '', ENT_QUOTES, 'UTF-8') ?>/imagens/favicon-96x96.png" sizes="96x96" />
    <link rel="shortcut icon" href="<?= htmlspecialchars($GLOBALS['APP_BASE_PATH'] ?? '', ENT_QUOTES, 'UTF-8') ?>/imagens/favicon.ico" type="image/x-ico">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= htmlspecialchars($GLOBALS['APP_BASE_PATH'] ?? '', ENT_QUOTES, 'UTF-8') ?>/imagens/apple-touch-icon.png" />
    <link rel="manifest" href="<?= htmlspecialchars($GLOBALS['APP_BASE_PATH'] ?? '', ENT_QUOTES, 'UTF-8') ?>/imagens/site.webmanifest" />
    <link rel="icon" type="image/png" sizes="192x192" href="<?= htmlspecialchars($GLOBALS['APP_BASE_PATH'] ?? '', ENT_QUOTES, 'UTF-8') ?>/imagens/web-app-manifest-192x192.png" />

    <title>Sistema de Controle de Visitantes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($GLOBALS['APP_BASE_PATH'] ?? '', ENT_QUOTES, 'UTF-8') ?>/assets/css/main.css">
    <style nonce="<?= htmlspecialchars($GLOBALS['CSP_NONCE'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        :root {
            --bg-image-path: url('<?= htmlspecialchars($GLOBALS['APP_BASE_PATH'] ?? '', ENT_QUOTES, 'UTF-8') ?>/imagens/tela_fundo.png');
        }
    </style>
    <script nonce="<?= htmlspecialchars($GLOBALS['CSP_NONCE'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        // Define a BASE_URL para ser usada em requisições AJAX
        const BASE_URL = '<?= htmlspecialchars(rtrim($GLOBALS['APP_PUBLIC_PATH'] ?? '/public', '/') . '/', ENT_QUOTES, 'UTF-8') ?>';
        const CSRF_TOKEN = '<?= htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8') ?>';
        const IS_PRODUCTION = <?= $GLOBALS['IS_PRODUCTION'] ? 'true' : 'false' ?>;
    </script>
</head>
<body class="<?= htmlspecialchars($GLOBALS['body_class'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>









