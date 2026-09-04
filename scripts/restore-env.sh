#!/bin/sh
set -eu

# Keep production configuration outside the Git worktree, then restore it after checkout.
source_path="${ITAPIRU_ENV_SOURCE:-/var/www/.itapiru-config/itapiru.env}"
repo_dir="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
target_path="${ITAPIRU_ENV_TARGET:-$repo_dir/.env}"
owner="${ITAPIRU_ENV_OWNER:-$(id -un)}"
group="${ITAPIRU_ENV_GROUP:-www-data}"

if [ ! -f "$source_path" ]; then
    printf 'Environment source not found: %s\n' "$source_path" >&2
    exit 1
fi

if [ ! -d "$(dirname -- "$target_path")" ]; then
    printf 'Environment target directory not found: %s\n' "$(dirname -- "$target_path")" >&2
    exit 1
fi

if [ "$(id -u)" -eq 0 ]; then
    install -o "$owner" -g "$group" -m 640 "$source_path" "$target_path"
elif sudo -n true 2>/dev/null; then
    sudo install -o "$owner" -g "$group" -m 640 "$source_path" "$target_path"
else
    printf 'Cannot restore %s with the required %s:%s ownership. Run with sudo.\n' \
        "$target_path" "$owner" "$group" >&2
    exit 1
fi

printf 'Environment restored to %s.\n' "$target_path"
