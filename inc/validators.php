<?php

/**
 * Validadores de dados para o sistema
 */

/**
 * Valida se email tem formato válido
 */
function validateEmail(string $email): bool
{
    $email = trim($email);
    if (empty($email)) {
        return false;
    }
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Valida CPF com verificação de dígitos verificadores
 * Aceita com ou sem máscara
 */
function validateCPF(string $cpf): bool
{
    // Remover caracteres não numéricos
    $cpf = preg_replace('/\D/', '', $cpf);

    // Verificar se tem 11 dígitos
    if (strlen($cpf) !== 11) {
        return false;
    }

    // Verificar se não é sequência repetida (ex: 111.111.111-11)
    if (preg_match('/^(\d)\1{10}$/', $cpf)) {
        return false;
    }

    // Calcular primeiro dígito verificador
    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $sum += (int)$cpf[$i] * (10 - $i);
    }
    $firstDigit = 11 - ($sum % 11);
    $firstDigit = $firstDigit >= 10 ? 0 : $firstDigit;

    if ((int)$cpf[9] !== $firstDigit) {
        return false;
    }

    // Calcular segundo dígito verificador
    $sum = 0;
    for ($i = 0; $i < 10; $i++) {
        $sum += (int)$cpf[$i] * (11 - $i);
    }
    $secondDigit = 11 - ($sum % 11);
    $secondDigit = $secondDigit >= 10 ? 0 : $secondDigit;

    if ((int)$cpf[10] !== $secondDigit) {
        return false;
    }

    return true;
}

/**
 * Valida placa de veículo (placa padrão ou Mercosul)
 * 
 * Padrão: ABC-1234 (3 letras + 4 números)
 * Mercosul: ABC1D23 (3 letras + 1 número + 1 letra + 2 números)
 */
function validatePlaca(string $placa): bool
{
    $placa = strtoupper(trim($placa));

    // Remover hífens e espaços
    $placa = str_replace(['-', ' '], '', $placa);

    // Verificar placa padrão (3 letras + 4 números)
    if (preg_match('/^[A-Z]{3}\d{4}$/', $placa)) {
        return true;
    }

    // Verificar placa Mercosul (3 letras + 1 número + 1 letra + 2 números)
    if (preg_match('/^[A-Z]{3}\d[A-Z]\d{2}$/', $placa)) {
        return true;
    }

    return false;
}

/**
 * Valida telefone brasileiro
 * Aceita: (11) 98765-4321 ou 11987654321 ou (11) 3333-4321 ou 1133334321
 */
function validateTelefone(string $telefone): bool
{
    $telefone = preg_replace('/\D/', '', $telefone);

    // Deve ter 10 ou 11 dígitos
    if (strlen($telefone) !== 10 && strlen($telefone) !== 11) {
        return false;
    }

    // DDD válido (11 a 99)
    $ddd = (int)substr($telefone, 0, 2);
    if ($ddd < 11 || $ddd > 99) {
        return false;
    }

    return true;
}

/**
 * Sanitiza e valida nome (apenas letras, números, espaços e pontuação básica)
 */
function validateNome(string $nome): bool
{
    $nome = trim($nome);

    if (strlen($nome) < 3 || strlen($nome) > 255) {
        return false;
    }

    // Permitir letras, números, espaços, hífens, apóstrofos
    if (!preg_match("/^[\p{L}\p{N}\s\-'\.]+$/u", $nome)) {
        return false;
    }

    return true;
}

/**
 * Sanitiza input genérico removendo caracteres perigosos
 */
function sanitizeString(string $input): string
{
    $input = trim($input);
    // Remove quebras de linha e caracteres de controle
    $input = preg_replace('/[\x00-\x1F\x7F]/', '', $input);
    return $input;
}
