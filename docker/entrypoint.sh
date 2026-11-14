#!/bin/bash
set -e

# .env é opcional em produção: o Docker Compose injeta as variáveis
# diretamente via environment. Em filesystems read_only (hardening),
# apenas usa o .env se ele já existir e for legível.
if [ -f /var/www/html/.env ] && [ -r /var/www/html/.env ]; then
    echo "--> Sourcing /var/www/html/.env..."
    while IFS='=' read -r key val || [ -n "$key" ]; do
        [[ "$key" =~ ^#.*$ || -z "$key" ]] && continue
        if [ -z "${!key+x}" ]; then
            export "$key=$val"
        fi
    done < /var/www/html/.env
fi

# Corrige permissões apenas quando roda como root (dev/overrides antigos).
# Em produção o container roda como app e o volume já é gravável.
if [ "$(id -u)" = "0" ]; then
    echo "--> Setting permissions..."
    chown -R app:app /var/www/html/fotos_visitantes 2>/dev/null || true
    chmod 755 /var/www/html/fotos_visitantes 2>/dev/null || true
fi

echo "--> Waiting for MySQL..."
until php -r "
    try {
        new PDO('mysql:host=${VISITORPASS_DB_HOST:-db};port=${VISITORPASS_DB_PORT:-3306}', '${VISITORPASS_DB_USER:-vpuser}', '${VISITORPASS_DB_PASS:-visitorpassword}', [PDO::ATTR_TIMEOUT => 3]);
        echo 'ok';
    } catch (PDOException \$e) {
        echo \$e->getMessage();
        exit(1);
    }
" 2>/dev/null | grep -q 'ok'; do
    sleep 2
done

echo "--> MySQL is ready!"

echo "--> Running database migration..."
php /var/www/html/docker/migrate.php || echo "WARNING: Migration may have failed, check database."

# ---------------------------------------------------------------------------
# Proxy confiavel do Apache (mod_remoteip)
# ---------------------------------------------------------------------------
# O container "nginx" e o unico cliente do Apache: o app nao publica porta no
# host e so e alcancado na rede interna "frontend". Sem esta configuracao o
# Apache registra em REMOTE_ADDR o IP do container nginx, e o rate limiting por
# IP e a auditoria passariam a ver um cliente unico (inc/security.php).
#
# A diretiva e RemoteIPInternalProxy (e nao RemoteIPTrustedProxy): os clientes
# da rede interna tem IPs privados RFC1918, que o mod_remoteip descarta quando
# apresentados por um proxy "externo". Declarando as redes Docker como proxies
# internos, o IP real do cliente sobrevive.
#
# A sub-rede Docker varia por host e por projeto, entao ela e detectada no
# start a partir das interfaces deste container (/proc/net/route) e gravada no
# tmpfs /etc/visitantes/remoteip (ver docker-compose.yml). O Apache carrega o
# arquivo via IncludeOptional em docker/apache.conf. Se o tmpfs nao existir
# (ex.: "docker run" sem o compose), segue sem o ajuste.
REMOTEIP_DIR=/etc/visitantes/remoteip
REMOTEIP_CONF="$REMOTEIP_DIR/remoteip.conf"

hex_to_ip() { # 000013AC (hex little-endian) -> 172.19.0.0
    local h=$1
    printf '%d.%d.%d.%d' "$((16#${h:6:2}))" "$((16#${h:4:2}))" "$((16#${h:2:2}))" "$((16#${h:0:2}))"
}

mask_to_prefix() { # 0000FFFF (hex little-endian) -> 16
    local h=$1 pair value bits=0 i
    for pair in "${h:6:2}" "${h:4:2}" "${h:2:2}" "${h:0:2}"; do
        value=$((16#$pair))
        for ((i = 0; i < 8; i++)); do
            if (( value & (1 << i) )); then
                bits=$((bits + 1))
            fi
        done
    done
    printf '%d' "$bits"
}

write_remoteip_conf() {
    if ! mkdir -p "$REMOTEIP_DIR" 2>/dev/null || [ ! -w "$REMOTEIP_DIR" ]; then
        echo "AVISO: $REMOTEIP_DIR indisponivel; o app registrara o IP do proxy."
        return 0
    fi

    # Redes Docker as quais este container esta ligado: em /proc/net/route sao
    # as linhas "no ar" (gateway 00000000), sem a rota default.
    local routes nets_list="" dest mask prefix ip mask_ip net_addr
    local o1 o2 o3 o4 m1 m2 m3 m4
    routes=$(awk 'NR > 1 && $3 == "00000000" && $2 != "00000000" { print $2, $8 }' /proc/net/route 2>/dev/null || true)

    while read -r dest mask; do
        case "$dest$mask" in
            ''|*[!0-9A-Fa-f]*) continue ;;
        esac
        prefix=$(mask_to_prefix "$mask")
        case "$prefix" in
            ''|*[!0-9]*) continue ;;
        esac
        [ "$prefix" -gt 0 ] || continue
        ip=$(hex_to_ip "$dest")
        mask_ip=$(hex_to_ip "$mask")
        IFS='.' read -r o1 o2 o3 o4 <<< "$ip"
        IFS='.' read -r m1 m2 m3 m4 <<< "$mask_ip"
        net_addr="$((o1 & m1)).$((o2 & m2)).$((o3 & m3)).$((o4 & m4))"
        nets_list="$nets_list $net_addr/$prefix"
    done <<< "$routes"

    # Sem rede detectada, cai nas faixas privadas (RFC1918): quem alcanca o
    # app sao apenas os containers das redes internas deste Compose.
    if [ -z "${nets_list//[[:space:]]/}" ]; then
        nets_list=" 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16"
    fi
    local proxies="127.0.0.1 ::1$nets_list"

    cat > "$REMOTEIP_CONF" <<EOF
# Gerado por docker/entrypoint.sh no start do container - nao editar a mao.
# Redes Docker deste container; o nginx e o unico proxy a frente do Apache.
#
# RemoteIPInternalProxy (e nao RemoteIPTrustedProxy): o cliente da rede interna
# tem IP privado (RFC1918 - 10/8, 172.16/12, 192.168/16), e o mod_remoteip so
# aceita esses valores no header quando o proxy que os apresenta e declarado
# interno. Sem isso o IP real era descartado e REMOTE_ADDR ficava com o IP do
# container nginx.
<IfModule mod_remoteip.c>
    RemoteIPHeader X-Forwarded-For
    RemoteIPInternalProxy 127.0.0.1 ::1$nets_list
</IfModule>
EOF
    echo "--> Proxies internos do mod_remoteip:$proxies"
}

echo "--> Configurando proxy confiavel (mod_remoteip)..."
write_remoteip_conf

echo "--> Starting Apache..."
exec "$@"
