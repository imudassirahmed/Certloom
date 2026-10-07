#!/usr/bin/env bash
# Compare canonical paths consistently across POSIX and Git Bash drive aliases.
certloom_shell_compare_path() {
  local path="$1"
  case "${OSTYPE:-}" in
    msys*|cygwin*)
      path="$(cygpath -au -- "$path")" || return 1
      path="${path,,}"
      ;;
  esac
  printf '%s\n' "$path"
}
