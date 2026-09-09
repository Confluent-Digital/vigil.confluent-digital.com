#!/usr/bin/env bash
# statusLine command — thin wrapper so the status JSON on stdin flows straight
# through to the node renderer via `exec`.
exec node "${CLAUDE_PROJECT_DIR:-.}/.claude/statusline.mjs"
