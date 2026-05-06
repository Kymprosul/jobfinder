import { mkdir, writeFile } from "node:fs/promises";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

import collectEchinacities from "./echinacities.js";
import collectJobscina from "./jobscina.js";
import collectHiredchina from "./hiredchina.js";
import collectChinateachjobs from "./chinateachjobs.js";

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);
const outputPath = resolve(__dirname, "../output/jobs.json");

const collectors = {
  echinacities: collectEchinacities,
  jobscina: collectJobscina,
  hiredchina: collectHiredchina,
  chinateachjobs: collectChinateachjobs,
};

function canonicalUrl(input) {
  if (!input || typeof input !== "string") {
    return "";
  }

  try {
    const url = new URL(input.trim());
    url.hash = "";
    if (url.pathname.endsWith("/")) {
      url.pathname = url.pathname.slice(0, -1);
    }
    return url.toString();
  } catch {
    return input.trim();
  }
}

function normalizeJob(job) {
  const rawMeta = {
    salary: null,
    detail_fetched: false,
    search_keyword: "",
    ...(job?.raw_meta || {}),
  };

  return {
    source: String(job?.source || ""),
    title: String(job?.title || "").trim(),
    institution: String(job?.institution || "").trim(),
    location: String(job?.location || "").trim(),
    url: canonicalUrl(String(job?.url || "")),
    description: String(job?.description || "").trim(),
    posted_date: String(job?.posted_date || "").trim(),
    closing_date: String(job?.closing_date || "").trim(),
    raw_meta: rawMeta,
  };
}

async function main() {
  const requested = process.argv[2]?.trim().toLowerCase();
  const selected = requested ? [requested] : Object.keys(collectors);

  const unknown = selected.filter((name) => !collectors[name]);
  if (unknown.length > 0) {
    console.error(`[run] Unknown collector(s): ${unknown.join(", ")}`);
    process.exitCode = 1;
    return;
  }

  const allResults = [];
  const counts = {};

  for (const name of selected) {
    try {
      const jobs = await collectors[name]();
      const normalized = Array.isArray(jobs) ? jobs.map(normalizeJob) : [];
      counts[name] = normalized.length;
      allResults.push(...normalized);
      console.log(`[run] ${name}: ${normalized.length}`);
    } catch (error) {
      counts[name] = 0;
      const message = error instanceof Error ? error.message : String(error);
      console.error(`[run] ${name} failed: ${message}`);
    }
  }

  const deduped = [];
  const seen = new Set();

  for (const job of allResults) {
    if (!job.url) {
      continue;
    }

    if (seen.has(job.url)) {
      continue;
    }

    seen.add(job.url);
    deduped.push(job);
  }

  await mkdir(resolve(__dirname, "../output"), { recursive: true });
  await writeFile(outputPath, JSON.stringify(deduped, null, 2), "utf8");

  console.log(`[run] Total (before dedupe): ${allResults.length}`);
  console.log(`[run] Total (deduped): ${deduped.length}`);
  console.log(`[run] Output: ${outputPath}`);
}

main().catch((error) => {
  const message = error instanceof Error ? error.stack || error.message : String(error);
  console.error(`[run] Fatal error: ${message}`);
  process.exitCode = 1;
});
