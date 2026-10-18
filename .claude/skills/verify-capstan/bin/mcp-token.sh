#!/usr/bin/env bash
# Mint an unbound protocol-purpose `mcp` bearer for the owner into ${STATE}/tokens/mcp (0600).
# This is a DIRECT MCP credential principal. It does not exercise the delegated (Scalpels-relayed)
# principal, which needs a console-signed assertion an agent cannot produce locally.
source "$(dirname "$0")/_env.sh"
# shellcheck disable=SC1091
source "${STATE}/owner.env"
umask 077
mkdir -p "${STATE}/tokens"
out="$(art bfc:credential:mint user_principal "capstan-user:${OWNER_ID}" \
    --purpose=mcp --user="${OWNER_ID}" --name="verify mcp" --local --no-interaction)"
printf '%s' "${out}" | sed -n 's/.*shown once: //p' | tr -d '\n' > "${STATE}/tokens/mcp"
[[ -s "${STATE}/tokens/mcp" ]] || die "mint printed no token"
echo "${STATE}/tokens/mcp"
