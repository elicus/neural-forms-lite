#!/usr/bin/env bash
# Remove dev-only files from the current release/* branch so only shippable
# plugin code reaches the release branch and main. Run once right after
# cutting release/<version> from dev; commit the result. This script is
# itself under bin/ and is removed by the strip.
set -euo pipefail
cd "$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
branch="$(git branch --show-current)"
case "$branch" in release/*) ;; *) echo "refusing: current branch is '$branch', not release/*" >&2; exit 1 ;; esac
paths=(CLAUDE.md .claude tests bin docs phpcs.xml.dist phpcs.xml phpunit.xml phpunit.xml.dist package.json package-lock.json composer.json composer.lock .phpunit.cache)
removed=()
for p in "${paths[@]}"; do
  if git ls-files --error-unmatch -- "$p" >/dev/null 2>&1; then git rm -rq -- "$p"; removed+=("$p"); fi
done
echo "removed: ${removed[*]:-nothing}"
echo "now: git commit -m 'Strip dev-only files for release'"
