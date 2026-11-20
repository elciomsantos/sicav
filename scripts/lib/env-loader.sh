#!/usr/bin/env bash
# Carrega um arquivo .env sem executar seu conteudo (diferente de "source").
# Uso: _sicav_load_env /caminho/para/.env
_sicav_load_env() {
    local file="$1" line key value

    if [[ ! -r "$file" ]]; then
        echo "ERRO: arquivo de ambiente nao legivel: $file" >&2
        return 1
    fi

    while IFS= read -r line || [[ -n "$line" ]]; do
        line="${line%$'\r'}"
        [[ -z "$line" || "$line" =~ ^[[:space:]]*# ]] && continue
        [[ "$line" == *=* ]] || continue

        key="${line%%=*}"
        value="${line#*=}"

        # Remove espacos ao redor de chave/valor.
        key="${key#"${key%%[![:space:]]*}"}"
        key="${key%"${key##*[![:space:]]}"}"
        value="${value#"${value%%[![:space:]]*}"}"
        value="${value%"${value##*[![:space:]]}"}"

        # Remove aspas simples ou duplas envolventes.
        if [[ "$value" == \"*\" && ${#value} -ge 2 ]]; then
            value="${value:1:${#value}-2}"
        elif [[ "$value" == \'*\' && ${#value} -ge 2 ]]; then
            value="${value:1:${#value}-2}"
        fi

        # Precedencia igual a do Compose: variavel ja exportada no shell vence.
        [[ -n "$key" && -z "${!key+x}" ]] && export "$key=$value"
    done < "$file"
}
