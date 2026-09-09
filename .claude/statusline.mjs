// statusLine renderer — reads the status JSON on stdin and prints a single line:
//   <dir> · ⎇ <branch> · <model> · +adds/-dels · $cost
// Note: Claude Code's statusLine payload does NOT expose a live token count, so
// we surface session cost + lines changed as the closest reliable signal.
import { execSync } from "node:child_process";
import { basename } from "node:path";

let raw = "";
process.stdin.on("data", (d) => (raw += d));
process.stdin.on("end", () => {
  let j = {};
  try {
    j = JSON.parse(raw || "{}");
  } catch {
    /* ignore */
  }

  const dir = j.workspace?.current_dir || j.cwd || process.cwd();
  const model = j.model?.display_name || j.model?.id || "Claude";

  let branch = "";
  try {
    branch = execSync("git rev-parse --abbrev-ref HEAD", {
      cwd: dir,
      stdio: ["ignore", "pipe", "ignore"],
    })
      .toString()
      .trim();
  } catch {
    /* not a git repo */
  }

  const cost = j.cost || {};
  const add = cost.total_lines_added;
  const rem = cost.total_lines_removed;
  const usd = cost.total_cost_usd;

  const c = (code, t) => `\x1b[${code}m${t}\x1b[0m`;
  const sep = c("90", " · ");

  const parts = [c("1;36", basename(dir))];
  if (branch) parts.push(c("35", `⎇ ${branch}`));
  parts.push(c("33", model));
  if (add != null || rem != null) parts.push(`${c("32", `+${add || 0}`)} ${c("31", `-${rem || 0}`)}`);
  if (typeof usd === "number") parts.push(c("90", `$${usd.toFixed(2)}`));

  process.stdout.write(parts.join(sep));
});
