#!/usr/bin/env bash
# Builds the OJS 3.3 version of the theme into <out>/manuscriptDaffa/
# (and, with a second argument, packs it as a .tar.gz):
#
#   tools/build-ojs33.sh <out-dir> [archive.tar.gz]
#
# ojs33/ holds what differs from the OJS 3.4/3.5 version (PHP code for
# OJS 3.3 and PHP 7.3+, Manuscript 3.3 styles, 3.3 locale folders). Shared
# with the root: js/, flags/, fonts/, styles/manuscriptDaffa.css, LICENSE.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
out="${1:?usage: tools/build-ojs33.sh <out-dir> [archive.tar.gz]}"
dest="$out/manuscriptDaffa"

rm -rf "$dest"
mkdir -p "$dest/styles"
cp -R "$root/ojs33/." "$dest/"
cp -R "$root/js" "$root/flags" "$root/fonts" "$dest/"
cp "$root/styles/manuscriptDaffa.css" "$dest/styles/"
cp "$root/LICENSE" "$root/README.md" "$dest/"

# The two builds must carry the same version
v_root=$(grep -o '<release>[0-9][^<]*' "$root/version.xml" | cut -d'>' -f2)
v_33=$(grep -o '<release>[0-9][^<]*' "$dest/version.xml" | cut -d'>' -f2)
if [ "$v_root" != "$v_33" ]; then
    echo "version.xml ($v_root) and ojs33/version.xml ($v_33) differ" >&2
    exit 1
fi

if [ -n "${2:-}" ]; then
    tar -C "$out" -czf "$2" manuscriptDaffa
    echo "Built $2 (OJS 3.3, version $v_33)"
else
    echo "Built $dest (OJS 3.3, version $v_33)"
fi
